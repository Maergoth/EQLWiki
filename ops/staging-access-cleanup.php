<?php
// Expire administrator access tokens without changing production or staging data.
require __DIR__ . '/staging-access-lib.php';
$state = '/home/eqlwikdq/deploy/EQLWiki-staging';
$lock = fopen("$state/deploy.lock", 'c');
if (!flock($lock, LOCK_SH | LOCK_NB) || is_file("$state/refresh-in-progress")) { exit(0); }
eql_staging_access_render();
