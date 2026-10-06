<?php
/**
 * EQL inline image finalizer.
 *
 * Moves temporary pasted images into permanent storage when a post is submitted.
 */

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/Settings.php';

function eql_json_error($message, $status = 400)
{
    http_response_code($status);
    echo json_encode([
        'success' => false,
        'message' => $message,
    ]);
    exit;
}

function eql_get_logged_in_smf_member()
{
    global $cookiename, $db_server, $db_user, $db_passwd, $db_name, $db_prefix, $auth_secret;

    $cookieName = !empty($cookiename) ? $cookiename : 'SMFCookie912';

    if (empty($_COOKIE[$cookieName])) {
        eql_json_error('You must be logged in to finalize images.', 403);
    }

    $cookieData = json_decode($_COOKIE[$cookieName], true);

    if (!is_array($cookieData)) {
        eql_json_error('Invalid login cookie.', 403);
    }

    $memberId = isset($cookieData[0]) ? (int) $cookieData[0] : 0;
    $cookieToken = isset($cookieData[1]) ? (string) $cookieData[1] : '';
    $cookieExpires = isset($cookieData[2]) ? (int) $cookieData[2] : 0;

    if ($memberId <= 0 || $cookieToken === '' || $cookieExpires < time()) {
        eql_json_error('Login expired.', 403);
    }

    $mysqli = new mysqli($db_server, $db_user, $db_passwd, $db_name);

    if ($mysqli->connect_errno) {
        eql_json_error('Database connection failed.', 500);
    }

    $mysqli->set_charset('utf8mb4');

    $stmt = $mysqli->prepare(
        "SELECT id_member, member_name, passwd, password_salt
         FROM {$db_prefix}members
         WHERE id_member = ?
         LIMIT 1"
    );

    if (!$stmt) {
        $mysqli->close();
        eql_json_error('Member lookup failed.', 500);
    }

    $stmt->bind_param('i', $memberId);
    $stmt->execute();

    $result = $stmt->get_result();
    $memberRow = $result ? $result->fetch_assoc() : null;

    $stmt->close();
    $mysqli->close();

    if (!$memberRow) {
        eql_json_error('Member not found.', 403);
    }

    if (!defined('SMF')) {
        define('SMF', 1);
    }

    if (!function_exists('get_auth_secret')) {
        function get_auth_secret()
        {
            global $auth_secret;
            return !empty($auth_secret) ? $auth_secret : '';
        }
    }

    require_once __DIR__ . '/Sources/Subs-Auth.php';

    if (!function_exists('hash_salt')) {
        eql_json_error('SMF auth helper unavailable.', 500);
    }

    $expectedToken = hash_salt($memberRow['passwd'], $memberRow['password_salt']);

    if (!hash_equals($expectedToken, $cookieToken)) {
        eql_json_error('Invalid login token.', 403);
    }

    return [
        'id_member' => (int) $memberRow['id_member'],
        'member_name' => $memberRow['member_name'],
    ];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    eql_json_error('POST required.', 405);
}

$member = eql_get_logged_in_smf_member();

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data) || empty($data['urls']) || !is_array($data['urls'])) {
    echo json_encode([
        'success' => true,
        'map' => [],
    ]);
    exit;
}

$memberId = (int) $member['id_member'];

$baseUrl = 'https://eqlwiki.com/bb/uploads/inline_images';
$baseDir = __DIR__ . '/uploads/inline_images';
$tmpUrlPrefix = $baseUrl . '/tmp/' . $memberId . '/';
$tmpDir = $baseDir . '/tmp/' . $memberId;

$year = gmdate('Y');
$month = gmdate('m');

$finalDir = $baseDir . '/' . $year . '/' . $month;

if (!is_dir($finalDir) && !mkdir($finalDir, 0755, true)) {
    eql_json_error('Could not create final upload directory.', 500);
}

$map = [];

foreach ($data['urls'] as $url) {
    if (!is_string($url)) {
        continue;
    }

    $url = trim($url);

    if ($url === '' || strpos($url, $tmpUrlPrefix) !== 0) {
        continue;
    }

    $filename = basename(parse_url($url, PHP_URL_PATH));

    if (!preg_match('/^[a-f0-9]{32}\.(jpg|jpeg|png|gif|webp)$/i', $filename)) {
        continue;
    }

    $sourcePath = $tmpDir . '/' . $filename;

    if (!is_file($sourcePath)) {
        continue;
    }

    $finalName = bin2hex(random_bytes(16)) . '.' . pathinfo($filename, PATHINFO_EXTENSION);
    $targetPath = $finalDir . '/' . $finalName;

    if (@rename($sourcePath, $targetPath)) {
        @chmod($targetPath, 0644);

        $finalUrl = $baseUrl . '/' . $year . '/' . $month . '/' . $finalName;
        $map[$url] = $finalUrl;
    }
}

@rmdir($tmpDir);

echo json_encode([
    'success' => true,
    'map' => $map,
]);
exit;