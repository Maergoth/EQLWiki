<?php
/**
 * MediaWiki session bridge for SMF auto-login.
 *
 * Checks whether the current browser is already logged into MediaWiki.
 */

$sharedSecret = (require __DIR__ . '/BridgeSecrets.php')['wikiSession'];

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

$secret = isset($_POST['secret']) ? $_POST['secret'] : '';

if (!hash_equals($sharedSecret, $secret)) {
    http_response_code(403);
    echo json_encode([
        'status' => 'error',
        'stage' => 'secret',
        'message' => 'Unauthorised',
    ]);
    exit;
}

// Do NOT define MW_NO_SESSION here.
// This script needs to read the active MediaWiki session.
require_once __DIR__ . '/includes/WebStart.php';

$user = RequestContext::getMain()->getUser();

if (!$user || $user->getId() === 0) {
    echo json_encode([
        'status' => 'error',
        'stage' => 'wiki_session',
        'message' => 'Not logged into MediaWiki',
    ]);
    exit;
}

echo json_encode([
    'status' => 'OK',
    'stage'  => 'success',
    'id'     => $user->getId(),
    'name'   => $user->getName(),
    'email'  => $user->getEmail(),
]);