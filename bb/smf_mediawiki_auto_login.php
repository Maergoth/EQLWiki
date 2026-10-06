<?php

/**
 * SMF auto-login bridge from an existing MediaWiki session.
 *
 * This version avoids loading SMF's SSI.php because this SMF install has
 * a broken integration hook that causes SSI.php to fatal.
 *
 * Flow:
 * 1. Ask MediaWiki whether the browser has a valid wiki session.
 * 2. If yes, find or create the matching SMF account.
 * 3. If an SMF account with that username already exists, require matching email.
 * 4. Set the SMF login cookie.
 */

$wikiSessionBridgeUrl = (getenv('EQL_WIKI_URL') ?: 'https://eqlwiki.com') . '/wiki_session_bridge.php';
$sharedSecret = (require dirname(__DIR__) . '/BridgeSecrets.php')['forumSession'];

$debug = isset($_GET['debug']) && $_GET['debug'] == '1';

$returnUrl = isset($_GET['return']) ? $_GET['return'] : '/bb/';

// Prevent open redirects.
if (
    strpos($returnUrl, '/bb/') !== 0 &&
    strpos($returnUrl, 'https://eqlwiki.com/bb/') !== 0
) {
    $returnUrl = '/bb/';
}

/**
 * Ask MediaWiki whether this browser is already logged into the wiki.
 * We forward the browser's cookies so MediaWiki can see its own session.
 */
$postData = 'secret=' . rawurlencode($sharedSecret);

$headers = "Content-Type: application/x-www-form-urlencoded\r\n";

if (!empty($_SERVER['HTTP_COOKIE'])) {
    $headers .= "Cookie: " . $_SERVER['HTTP_COOKIE'] . "\r\n";
}

$context = stream_context_create([
    'http' => [
        'method'  => 'POST',
        'header'  => $headers,
        'content' => $postData,
        'timeout' => 15,
    ],
]);

$bridgeResponse = @file_get_contents($wikiSessionBridgeUrl, false, $context);

if ($bridgeResponse === false) {
    redirect_to_wiki_or_return($returnUrl);
}

$authData = json_decode($bridgeResponse, true);

if (!is_array($authData) || !isset($authData['status']) || $authData['status'] !== 'OK') {
    redirect_to_wiki_or_return($returnUrl);
}

$wikiUserId   = (int) $authData['id'];
$wikiUserName = isset($authData['name']) ? trim($authData['name']) : '';
$wikiEmail    = isset($authData['email']) ? trim($authData['email']) : '';

if ($wikiUserId <= 0 || $wikiUserName === '') {
    redirect_to_wiki_or_return($returnUrl);
}

// Load SMF database settings directly, without SSI.php.
require_once __DIR__ . '/Settings.php';

// Connect to SMF database.
$mysqli = new mysqli($db_server, $db_user, $db_passwd, $db_name);

if ($mysqli->connect_errno) {
    die('SMF database connection failed: ' . htmlspecialchars($mysqli->connect_error, ENT_QUOTES, 'UTF-8'));
}

$mysqli->set_charset('utf8mb4');

// Load SMF settings we need.
$modSettings = [];

$settingsResult = $mysqli->query(
    "SELECT variable, value FROM {$db_prefix}settings WHERE variable IN ('cookieTime')"
);

if ($settingsResult) {
    while ($row = $settingsResult->fetch_assoc()) {
        $modSettings[$row['variable']] = $row['value'];
    }
    $settingsResult->free();
}

$cookieTime = !empty($modSettings['cookieTime']) ? (int) $modSettings['cookieTime'] : 3153600;
$cookieLengthSeconds = 60 * $cookieTime;

// Find existing SMF member by username.
$stmt = $mysqli->prepare(
    "SELECT id_member, member_name, real_name, email_address, passwd, password_salt
     FROM {$db_prefix}members
     WHERE member_name = ?
     LIMIT 1"
);

if (!$stmt) {
    die('SMF member lookup prepare failed: ' . htmlspecialchars($mysqli->error, ENT_QUOTES, 'UTF-8'));
}

$stmt->bind_param('s', $wikiUserName);
$stmt->execute();

$result = $stmt->get_result();
$memberRow = $result ? $result->fetch_assoc() : null;

$stmt->close();

/**
 * Security check:
 *
 * If an SMF account with this username already exists, only allow the
 * MediaWiki user to claim/login to it if the email address matches.
 *
 * This prevents a new wiki account from taking over an old forum account
 * simply by registering the same username.
 */
if ($memberRow) {
    $wikiEmailNormalized = trim(strtolower($wikiEmail));
    $forumEmailNormalized = trim(strtolower($memberRow['email_address'] ?? ''));

    if (
        $wikiEmailNormalized === '' ||
        $forumEmailNormalized === '' ||
        $wikiEmailNormalized !== $forumEmailNormalized
    ) {
        $mysqli->close();

        header('Content-Type: text/html; charset=UTF-8');
        http_response_code(403);

        echo '<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Forum account link required</title>
</head>
<body>
    <h1>Forum account link required</h1>
    <p>A forum account already exists with this username, but its email address does not match your wiki account email.</p>
    <p>For security, this forum account must be linked by an administrator before it can be used through wiki login.</p>
    <p><strong>Wiki username:</strong> ' . htmlspecialchars($wikiUserName, ENT_QUOTES, 'UTF-8') . '</p>
    <p><strong>Wiki email:</strong> ' . htmlspecialchars($wikiEmail !== '' ? $wikiEmail : '[empty]', ENT_QUOTES, 'UTF-8') . '</p>
    <p><a href="https://eqlwiki.com/">Return to EQL Wiki</a></p>
</body>
</html>';

        exit;
    }
}

// Create SMF shadow account if missing.
if (!$memberRow) {
    require_once __DIR__ . '/smf_create_member.php';

    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $memberId = smf_create_shadow_member($wikiUserName, $wikiEmail, $ip);

    if ($memberId === false) {
        die('Failed to create SMF shadow account.');
    }

    // Re-fetch the newly created member so we can set the login cookie.
    $stmt = $mysqli->prepare(
        "SELECT id_member, member_name, real_name, email_address, passwd, password_salt
         FROM {$db_prefix}members
         WHERE id_member = ?
         LIMIT 1"
    );

    if (!$stmt) {
        die('SMF new member lookup prepare failed: ' . htmlspecialchars($mysqli->error, ENT_QUOTES, 'UTF-8'));
    }

    $stmt->bind_param('i', $memberId);
    $stmt->execute();

    $result = $stmt->get_result();
    $memberRow = $result ? $result->fetch_assoc() : null;

    $stmt->close();

    if (!$memberRow) {
        die('Failed to fetch newly created SMF member.');
    }
}

// Compute the SMF login cookie token using SMF's own helper.
if (!defined('SMF')) {
    define('SMF', 1);
}

// Subs-Auth.php expects get_auth_secret() to exist in a normally bootstrapped SMF.
// Since this script intentionally avoids SSI.php, provide the needed fallback.
if (!function_exists('get_auth_secret')) {
    function get_auth_secret()
    {
        global $auth_secret;

        return !empty($auth_secret) ? $auth_secret : '';
    }
}

require_once __DIR__ . '/Sources/Subs-Auth.php';

if (!function_exists('hash_salt')) {
    die('SMF hash_salt() function is unavailable.');
}

$loginToken = hash_salt($memberRow['passwd'], $memberRow['password_salt']);

// Determine SMF cookie name.
$cookieName = !empty($cookiename) ? $cookiename : 'SMFCookie912';

$cookieData = json_encode([
    '0' => (int) $memberRow['id_member'],
    '1' => $loginToken,
    '2' => time() + $cookieLengthSeconds,
    '3' => '',
    '4' => '/',
], JSON_FORCE_OBJECT);

setcookie(
    $cookieName,
    $cookieData,
    [
        'expires' => time() + $cookieLengthSeconds,
        'path' => '/',
        'httponly' => true,
        'secure' => true,
        'samesite' => 'Lax',
    ]
);

$_COOKIE[$cookieName] = $cookieData;

if ($debug) {
    header('Content-Type: text/plain; charset=UTF-8');

    echo "SMF MediaWiki auto-login debug\n\n";

    echo "Wiki user:\n";
    echo "  ID: " . $wikiUserId . "\n";
    echo "  Name: " . $wikiUserName . "\n";
    echo "  Email: " . $wikiEmail . "\n\n";

    echo "SMF member row:\n";
    print_r([
        'id_member' => $memberRow['id_member'],
        'member_name' => $memberRow['member_name'],
        'real_name' => $memberRow['real_name'],
        'email_address' => $memberRow['email_address'],
        'password_salt_present' => $memberRow['password_salt'] !== '',
        'passwd_present' => $memberRow['passwd'] !== '',
    ]);

    echo "\nCookie name:\n";
    echo $cookieName . "\n\n";

    echo "Login token length:\n";
    echo strlen($loginToken) . "\n\n";

    echo "Cookie length seconds:\n";
    echo $cookieLengthSeconds . "\n\n";

    echo "Headers queued:\n";
    print_r(headers_list());

    echo "\nCookies received by this request:\n";
    print_r(array_keys($_COOKIE));

    echo "\nNow manually open:\n";
    echo "https://eqlwiki.com/bb/\n";

    $mysqli->close();
    exit;
}

$mysqli->close();

header('Location: ' . $returnUrl);
exit;

/**
 * If this was an explicit login click, send to wiki login.
 * If this was a silent auto-check, return to the forum.
 */
function redirect_to_wiki_or_return($returnUrl)
{
    if (!empty($_GET['login'])) {
        header('Location: https://eqlwiki.com/index.php?title=Special:UserLogin');
        exit;
    }

    $separator = strpos($returnUrl, '?') === false ? '?' : '&';
    header('Location: ' . $returnUrl . $separator . 'mw_autologin_failed=1');
    exit;
}