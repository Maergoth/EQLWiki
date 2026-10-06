<?php
/**
 * MediaWiki authentication bridge script for SMF login integration.
 *
 * Place this file in the MediaWiki root directory, where LocalSettings.php lives.
 *
 * Testing URL:
 * https://eqlwiki.com/wiki_auth_bridge.php?secret=test&username=testuser&password=testpassword
 */

define('MW_NO_SESSION', true);

$mwPath = __DIR__;

$sharedSecret = (require __DIR__ . '/BridgeSecrets.php')['wikiAuth'];

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'status' => 'error',
        'stage' => 'method',
        'message' => 'POST required',
    ]);
    exit;
}

$secret   = isset($_POST['secret']) ? $_POST['secret'] : '';
$username = isset($_POST['username']) ? $_POST['username'] : '';
$password = isset($_POST['password']) ? $_POST['password'] : '';

if (!hash_equals($sharedSecret, $secret)) {
    http_response_code(403);
    echo json_encode([
        'status' => 'error',
        'stage' => 'secret',
        'message' => 'Unauthorised',
    ]);
    exit;
}

if ($username === '' || $password === '') {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'stage' => 'input',
        'message' => 'Missing username or password',
    ]);
    exit;
}

// Bootstrap MediaWiki.
require_once $mwPath . '/includes/WebStart.php';

$services = MediaWiki\MediaWikiServices::getInstance();

// Normalize and load the user.
$user = User::newFromName($username);

if (!$user) {
    echo json_encode([
        'status' => 'error',
        'stage' => 'user_lookup',
        'message' => 'User::newFromName returned false/null',
        'username_received' => $username,
    ]);
    exit;
}

$userId = (int) $user->getId();

if ($userId === 0) {
    echo json_encode([
        'status' => 'error',
        'stage' => 'user_lookup',
        'message' => 'User object exists, but user ID is 0',
        'username_received' => $username,
        'normalized_name' => $user->getName(),
    ]);
    exit;
}

// Fetch the stored password hash directly from MediaWiki's user table.
// MediaWiki 1.45 removed getConnectionRef(), so use getConnection().
$lb = $services->getDBLoadBalancer();

$dbRole = defined('DB_REPLICA') ? DB_REPLICA : DB_PRIMARY;
$db = $lb->getConnection($dbRole);

$row = $db->selectRow(
    'user',
    [
        'user_id',
        'user_name',
        'user_email',
        'user_password',
    ],
    [
        'user_id' => $userId,
    ],
    __METHOD__
);

if (!$row) {
    echo json_encode([
        'status' => 'error',
        'stage' => 'db_lookup',
        'message' => 'Could not load user row from database',
        'user_id' => $userId,
        'normalized_name' => $user->getName(),
    ]);
    exit;
}

if ($row->user_password === '') {
    echo json_encode([
        'status' => 'error',
        'stage' => 'password_hash',
        'message' => 'User has no local password hash stored',
        'user_id' => $userId,
        'normalized_name' => $user->getName(),
    ]);
    exit;
}

// Verify the password using MediaWiki's own password factory.
$passwordFactory = $services->getPasswordFactory();
$passwordObject = $passwordFactory->newFromCiphertext($row->user_password);

if (!$passwordObject || !$passwordObject->verify($password)) {
    echo json_encode([
        'status' => 'error',
        'stage' => 'password_check',
        'message' => 'MediaWiki found the user, but password check failed',
        'user_id' => $userId,
        'normalized_name' => $user->getName(),
    ]);
    exit;
}

// Success.
echo json_encode([
    'status' => 'OK',
    'stage'  => 'success',
    'id'     => (int) $row->user_id,
    'name'   => $row->user_name,
    'email'  => $row->user_email,
]);