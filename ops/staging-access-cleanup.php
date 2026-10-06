<?php
// Expire administrator access tokens without changing production or staging data.
require_once __DIR__ . '/staging-access-lib.php';
$state = '/home/eqlwikdq/deploy/EQLWiki-staging';
$cleanup = fopen("$state/access-cleanup.lock", 'c');
if (!flock($cleanup, LOCK_EX | LOCK_NB)) { exit(0); }
$lock = fopen("$state/deploy.lock", 'c');
if (!flock($lock, LOCK_SH | LOCK_NB) || is_file("$state/refresh-in-progress")) { exit(0); }
foreach (new DirectoryIterator(EQL_STAGING_ACCESS_TOKENS) as $file) {
    if (!$file->isFile() || !preg_match('/^[0-9]{14}-[a-f0-9]{64}$/', $file->getFilename())) { continue; }
    $session = json_decode(file_get_contents($file->getPathname()), true) ?: [];
    $identity = eql_staging_live_identity($session['cookie'] ?? '');
    if (!$identity || $identity['name'] !== ($session['name'] ?? '')) { unlink($file->getPathname()); }
}
eql_staging_access_render();
