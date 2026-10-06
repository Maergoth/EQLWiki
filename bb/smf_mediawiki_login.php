<?php

/**
 * SMF + MediaWiki login bridge.
 *
 * This script handles logging into the Simple Machines Forum using
 * MediaWiki credentials. It should live in the forum root (e.g. /path/to/bb/)
 * and be linked from your forum’s login page instead of the default
 * action=login2 handler.
 *
 * It works by calling a helper script (wiki_auth_bridge.php) hosted on
 * your wiki, which verifies the username/password against MediaWiki’s
 * user database. If the credentials are valid, the script ensures a
 * corresponding SMF account exists (creating one if necessary) and then
 * sets an SMF login cookie so the user is logged in.
 *
 * Before using this script you must:
 *   1. Deploy wiki_auth_bridge.php in your MediaWiki root, set
 *      a strong shared secret there, and copy the same secret here.
 *   2. Place this file in your SMF directory (e.g. eqlwiki.com/bb/).
 *   3. Update the $wikiBridgeUrl and $sharedSecret values below.
 *   4. Modify your forum’s login template to post to this script
 *      (for example, change the form action from index.php?action=login2 to
 *      smf_mediawiki_login.php).
 *
 * IMPORTANT: This script assumes SMF 2.1+. It uses the $smcFunc
 * database helper and registerMember(). If you are running SMF 2.0,
 * the API may be slightly different.
 */

// Path to SMF SSI.php (adjust if necessary).
$ssi_path = __DIR__ . '/SSI.php';

// URL to the wiki authentication bridge (must be publicly accessible).
$wikiBridgeUrl = (getenv('EQL_WIKI_URL') ?: 'https://eqlwiki.com') . '/wiki_auth_bridge.php';

// Shared secret (must match the secret defined in wiki_auth_bridge.php).
$sharedSecret = (require dirname(__DIR__) . '/BridgeSecrets.php')['forumAuth'];

// Include SMF environment via SSI. This sets up globals like $smcFunc,
// $modSettings, $sourcedir, etc.
require_once($ssi_path);

// Only process POST requests with user/pass.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['user'], $_POST['passwrd'])) {
    die('Invalid request.');
}

$username = trim($_POST['user']);
$password = trim($_POST['passwrd']);

if ($username === '' || $password === '') {
    die('Username and password required.');
}

// -----------------------------------------------------------------------------
// Step 1: Verify the credentials against MediaWiki
// -----------------------------------------------------------------------------

// Build query to the wiki bridge.
$postData = 'secret=' . rawurlencode($sharedSecret)
    . '&username=' . rawurlencode($username)
    . '&password=' . rawurlencode($password);

$context = stream_context_create([
    'http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => $postData,
        'timeout' => 15,
    ],
]);

$bridgeResponse = @file_get_contents($wikiBridgeUrl, false, $context);

if ($bridgeResponse === false) {
    header('Content-Type: text/plain');

    echo "Unable to contact MediaWiki authentication service.\n\n";
    echo "Bridge URL:\n" . $wikiBridgeUrl . "\n\n";
    echo "POST data sent:\n";
    echo "  secret: [hidden]\n";
    echo "  username: " . $username . "\n";
    echo "  password: [hidden]\n\n";

    echo "allow_url_fopen: " . (ini_get('allow_url_fopen') ? 'ON' : 'OFF') . "\n";
    echo "curl available: " . (function_exists('curl_init') ? 'YES' : 'NO') . "\n\n";

    $error = error_get_last();

    if ($error) {
        echo "Last PHP error:\n";
        print_r($error);
    } else {
        echo "No PHP error returned.\n";
    }

    exit;
}

$authData = json_decode($bridgeResponse, true);
if (!is_array($authData) || !isset($authData['status']) || $authData['status'] !== 'OK') {
    die('Invalid MediaWiki credentials.');
}

// Extract user info from MediaWiki response.
$wikiUserId   = (int) $authData['id'];
$wikiUserName = $authData['name'];
$wikiEmail    = $authData['email'];

// -----------------------------------------------------------------------------
// Step 2: Ensure the SMF account exists (create if not)
// -----------------------------------------------------------------------------

// Query the SMF database for a member with the same username.
$memberQuery = $smcFunc['db_query']('',
    'SELECT id_member, member_name, passwd, password_salt
     FROM {db_prefix}members
     WHERE member_name = {string:username}
     LIMIT 1',
    [
        'username' => $wikiUserName,
    ]
);
$memberRow = $smcFunc['db_fetch_assoc']($memberQuery);
$smcFunc['db_free_result']($memberQuery);

// If no existing SMF account, create one.
if (!$memberRow) {
    // Need functions to register a member.
    require_once($sourcedir . '/Subs-Members.php');

    // Generate a random password (not used for login, but SMF requires one).
    $randomPassword = bin2hex(random_bytes(8));

    $regOptions = [
        'interface' => 'guest',
        'username'  => $wikiUserName,
        'email'     => $wikiEmail,
        'password'  => $randomPassword,
        'password_check' => $randomPassword,
        'require'   => 'nothing',
        'check_password_strength' => false,
        'check_reserved_name' => false,
        'send_welcome_email' => false,
        'generate_validation_code' => false,
        'email_activation' => false,
    ];

    // Attempt to register the member.  Pass true to return errors instead of fatal.
    $result = registerMember($regOptions, true);
    if (is_array($result)) {
        // Registration failed; output the first error.
        die('Could not create forum account: ' . implode('; ', $result));
    }

    $memberId = (int) $result;

    // Fetch the newly created member data to compute the login cookie.
    $memberQuery = $smcFunc['db_query']('',
        'SELECT id_member, member_name, passwd, password_salt
         FROM {db_prefix}members
         WHERE id_member = {int:id_member}
         LIMIT 1',
        [
            'id_member' => $memberId,
        ]
    );
    $memberRow = $smcFunc['db_fetch_assoc']($memberQuery);
    $smcFunc['db_free_result']($memberQuery);

    if (!$memberRow) {
        die('Failed to fetch newly created member.');
    }
}

// -----------------------------------------------------------------------------
// Step 3: Log the user into SMF
// -----------------------------------------------------------------------------

// Compute the SMF 2.1 login cookie token using SMF's own helper.
require_once($sourcedir . '/Subs-Auth.php');

if (!function_exists('hash_salt')) {
    die('SMF hash_salt() function is unavailable.');
}

$loginToken = hash_salt($memberRow['passwd'], $memberRow['password_salt']);

// Set the login cookie and session.  Use the forum’s configured cookie length.
// The cookie length is defined in minutes; multiply by 60 for seconds.
$cookieLengthSeconds = 60 * $modSettings['cookieTime'];

// Set the SMF login cookie manually to avoid setLoginCookie() integration hook issues.
$cookieName = $cookiename ?? 'SMFCookie912';

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
        'samesite' => 'Lax',
    ]
);

// Also update $_COOKIE for this request.
$_COOKIE[$cookieName] = $cookieData;

// Redirect to the forum index.
header('Location: https://eqlwiki.com/bb/');
exit;