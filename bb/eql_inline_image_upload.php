<?php
/**
 * EQL temporary inline image uploader for SMF editor paste/drop.
 *
 * Uploads pasted/dropped images to a temporary location.
 * Finalization happens when the post form is submitted.
 */

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/Settings.php';

$maxBytes = 10 * 1024 * 1024; // 10 MB
$tempMaxAgeSeconds = 24 * 60 * 60; // 24 hours

$allowedMimeTypes = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/gif'  => 'gif',
    'image/webp' => 'webp',
];

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
        eql_json_error('You must be logged in to upload images.', 403);
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

function eql_cleanup_old_temp_uploads($baseTmpDir, $maxAgeSeconds)
{
    if (!is_dir($baseTmpDir)) {
        return;
    }

    $now = time();

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($baseTmpDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $item) {
        $path = $item->getPathname();

        if ($item->isFile() && ($now - $item->getMTime()) > $maxAgeSeconds) {
            @unlink($path);
        }

        if ($item->isDir()) {
            @rmdir($path);
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    eql_json_error('POST required.', 405);
}

$member = eql_get_logged_in_smf_member();

if (empty($_FILES['image']) || !is_array($_FILES['image'])) {
    eql_json_error('No image uploaded.');
}

$file = $_FILES['image'];

if (!empty($file['error'])) {
    eql_json_error('Upload failed with error code ' . (int) $file['error']);
}

if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
    eql_json_error('Invalid upload.');
}

if ((int) $file['size'] <= 0 || (int) $file['size'] > $maxBytes) {
    eql_json_error('Image is too large. Maximum size is 10 MB.');
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mimeType = $finfo->file($file['tmp_name']);

if (empty($allowedMimeTypes[$mimeType])) {
    eql_json_error('Unsupported image type. Use JPG, PNG, GIF, or WebP.');
}

$extension = $allowedMimeTypes[$mimeType];

$uploadBaseDir = __DIR__ . '/uploads/inline_images';
$tmpBaseDir = $uploadBaseDir . '/tmp';

eql_cleanup_old_temp_uploads($tmpBaseDir, $tempMaxAgeSeconds);

$userTmpDir = $tmpBaseDir . '/' . (int) $member['id_member'];

if (!is_dir($userTmpDir) && !mkdir($userTmpDir, 0755, true)) {
    eql_json_error('Could not create temporary upload directory.', 500);
}

$randomName = bin2hex(random_bytes(16)) . '.' . $extension;
$targetPath = $userTmpDir . '/' . $randomName;

if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
    eql_json_error('Could not save uploaded image.', 500);
}

@chmod($targetPath, 0644);

$imageUrl = 'https://eqlwiki.com/bb/uploads/inline_images/tmp/' . (int) $member['id_member'] . '/' . $randomName;

echo json_encode([
    'success' => true,
    'temporary' => true,
    'url' => $imageUrl,
]);
exit;