<?php
// Private receiver helper. Never install this file below the document root.
declare(strict_types=1);
const EQL_DEPLOY_MARKER = 'skins/EQLImmersive/deployment-version.txt';
const EQL_DEPLOY_META = ['.eql-deployment-manifest', '.eql-deployment-payload', '.eql-deployment.json'];

function eql_deploy_error(string $message): never { throw new RuntimeException($message); }
function eql_deploy_sha(?string $value): ?string { return is_string($value) && preg_match('/^[a-f0-9]{40}$/D', $value) ? $value : null; }
function eql_deploy_host_owned(string $path): bool { return $path === 'static/eql-icon-index' || str_starts_with($path, 'static/eql-icon-index/'); }
function eql_deploy_path(string $path, bool $hostOwned = false, bool $metadata = false): bool {
    if ($path === '' || strlen($path) > 4096 || preg_match('/[\\\\\x00-\x1f\x7f]/', $path) || !preg_match('//u', $path) || $path[0] === '/') { eql_deploy_error('Invalid deployment path'); }
    foreach (explode('/', $path) as $part) { if ($part === '' || $part === '.' || $part === '..') { eql_deploy_error('Noncanonical deployment path'); } }
    if (in_array($path, EQL_DEPLOY_META, true)) { if ($metadata) { return true; } eql_deploy_error('Metadata cannot be an application path'); }
    if ($hostOwned && eql_deploy_host_owned($path)) { return false; }
    if (preg_match('~^(?:\.git(?:/|$)|\.github(?:/|$)|\.local(?:/|$)|ops(?:/|$)|images(?:/|$)|cache(?:/|$)|static/eql-icon-index(?:/|$)|bb/(?:uploads|attachments|custom_avatar|cache|exports|Packages)(?:/|$))~', $path) || in_array($path, ['LocalSettings.php','BridgeSecrets.php','EQLStaging.php','bb/Settings.php','.user.ini','php.ini'], true)) { eql_deploy_error('Protected deployment path'); }
    return true;
}
function eql_deploy_list(string $file, bool $hostOwned = false, bool $metadata = false): array {
    if (!is_file($file) || is_link($file) || filesize($file) > 32 * 1024 * 1024) { eql_deploy_error('Missing or oversized path list'); }
    $raw = file_get_contents($file); if ($raw === false || ($raw !== '' && !str_ends_with($raw, "\n"))) { eql_deploy_error('Path list must end with a newline'); }
    $paths = $raw === '' ? [] : explode("\n", substr($raw, 0, -1));
    if (count($paths) > 200000) { eql_deploy_error('Too many deployment paths'); }
    $seen = []; $result = [];
    foreach ($paths as $path) { if (isset($seen[$path])) { eql_deploy_error('Duplicate deployment path'); } $seen[$path] = true; if (eql_deploy_path($path, $hostOwned, $metadata)) { $result[] = $path; } }
    return $result;
}
function eql_deploy_safe(string $root, string $path): ?array {
    eql_deploy_path($path); $cursor = rtrim($root, '/');
    if (is_link($cursor) || !is_dir($cursor)) { eql_deploy_error('Unsafe deployment root'); }
    $parts = explode('/', $path);
    foreach ($parts as $index => $part) {
        $cursor .= '/' . $part; $stat = @lstat($cursor); if ($stat === false) { return null; }
        $kind = $stat['mode'] & 0170000;
        if ($kind === 0120000 || ($index < count($parts) - 1 && $kind !== 0040000) || ($index === count($parts) - 1 && $kind !== 0100000)) { eql_deploy_error('Symlink or file/directory deployment conflict'); }
    }
    return $stat;
}
function eql_deploy_mkdir(string $directory, int $mode): void {
    if (is_dir($directory) && !is_link($directory)) { return; }
    if (file_exists($directory) || is_link($directory)) { eql_deploy_error('Unsafe deployment directory'); }
    eql_deploy_mkdir(dirname($directory), $mode);
    if (!mkdir($directory, $mode) || !chmod($directory, $mode)) { eql_deploy_error('Cannot create deployment directory'); }
}
function eql_deploy_copy(string $source, string $target, int $mode, int $directoryMode = 0755): void {
    eql_deploy_mkdir(dirname($target), $directoryMode);
    $temporary = tempnam(dirname($target), '.eql-deploy-'); if ($temporary === false) { eql_deploy_error('Cannot prepare deployment file'); }
    try { if (!copy($source, $temporary) || !chmod($temporary, $mode) || !rename($temporary, $target)) { eql_deploy_error('Cannot install deployment file'); } }
    finally { if (is_file($temporary)) { @unlink($temporary); } }
}
function eql_deploy_write(string $target, string $bytes): void {
    $temporary = tempnam(dirname($target), '.eql-state-'); if ($temporary === false) { eql_deploy_error('Cannot prepare deployment state'); }
    try { if (file_put_contents($temporary, $bytes) !== strlen($bytes) || !chmod($temporary, 0600) || !rename($temporary, $target)) { eql_deploy_error('Cannot commit deployment state'); } }
    finally { if (is_file($temporary)) { @unlink($temporary); } }
}
function eql_deploy_current(string $state): ?string {
    $path = "$state/current-sha"; if (!file_exists($path) && !is_link($path)) { return null; }
    if (is_link($path) || !is_file($path) || filesize($path) > 100) { eql_deploy_error('Invalid current revision state'); }
    $sha = eql_deploy_sha(trim((string)file_get_contents($path))); if ($sha === null) { eql_deploy_error('Invalid current revision state'); } return $sha;
}
function eql_deploy_ready(string $state): void {
    if (file_exists("$state/pending-deployment") || is_link("$state/pending-deployment")) { eql_deploy_error('An interrupted deployment needs explicit recovery before status or another deployment'); }
}
function eql_deploy_begin(string $state, string $backup): void {
    eql_deploy_ready($state); eql_deploy_read_plan($backup);
    eql_deploy_write("$state/pending-deployment", basename($backup) . "\n");
}
function eql_deploy_archive(string $list, string $types, bool $legacy = false): void {
    $paths = eql_deploy_list($list, $legacy, true);
    $lines = file($types, FILE_IGNORE_NEW_LINES); if ($lines === false || count($lines) !== count(file($list, FILE_IGNORE_NEW_LINES))) { eql_deploy_error('Archive type/path count mismatch'); }
    foreach ($lines as $line) { if (!preg_match('/^-[rwx-]{9}\s/', $line)) { eql_deploy_error('Archive contains a link, directory, special file, or unsafe mode'); } }
    if (!$paths && !$legacy) { eql_deploy_error('Empty deployment archive'); }
}
function eql_deploy_bundle(string $stage, string $sha): array {
    if (eql_deploy_sha($sha) === null) { eql_deploy_error('Invalid deployment revision'); }
    $manifest = eql_deploy_list("$stage/.eql-deployment-manifest");
    if (!$manifest || end($manifest) !== EQL_DEPLOY_MARKER || !in_array('index.php', $manifest, true)) { eql_deploy_error('Incomplete manifest or activation marker not last'); }
    $protocol = 1; $base = null; $payload = $manifest;
    if (is_file("$stage/.eql-deployment.json")) {
        $raw = file_get_contents("$stage/.eql-deployment.json"); if ($raw === false || strlen($raw) > 4096) { eql_deploy_error('Invalid deployment metadata'); }
        preg_match_all('/"([^"\\\\]*)"\s*:/', $raw, $keys);
        if (count($keys[1]) !== 3 || count(array_unique($keys[1])) !== 3 || array_diff($keys[1], ['protocol','revision','base_revision'])) { eql_deploy_error('Duplicate or noncanonical deployment metadata keys'); }
        $meta = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($meta) || count($meta) !== 3 || array_diff(array_keys($meta), ['protocol','revision','base_revision']) || ($meta['protocol'] ?? null) !== 2 || ($meta['revision'] ?? null) !== $sha || !array_key_exists('base_revision', $meta) || $meta['base_revision'] !== null && eql_deploy_sha($meta['base_revision']) === null) { eql_deploy_error('Invalid deployment metadata'); }
        $protocol = 2; $base = $meta['base_revision']; $payload = eql_deploy_list("$stage/.eql-deployment-payload");
        if (!$payload || end($payload) !== EQL_DEPLOY_MARKER || array_diff($payload, $manifest)) { eql_deploy_error('Payload is not a subset of the final manifest with marker last'); }
        if ($base === null && (count($payload) !== count($manifest) || array_diff($manifest, $payload))) { eql_deploy_error('Full deployment has an incomplete payload'); }
    } elseif (file_exists("$stage/.eql-deployment-payload")) { eql_deploy_error('Payload without protocol metadata'); }
    $expected = array_fill_keys(array_merge($payload, $protocol === 2 ? EQL_DEPLOY_META : [EQL_DEPLOY_META[0]]), true); $actual = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isLink() || !$file->isFile()) { eql_deploy_error('Nonregular staged application file'); }
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen(rtrim($stage, '/\\')) + 1)); if (!isset($expected[$relative])) { eql_deploy_error('Unlisted staged application file'); } $actual[$relative] = true;
    }
    if (count($actual) !== count($expected)) { eql_deploy_error('Missing staged payload file'); }
    if (file_get_contents($stage . '/' . EQL_DEPLOY_MARKER) !== "$sha\n") { eql_deploy_error('Activation marker differs from deployment revision'); }
    return ['protocol'=>$protocol,'revision'=>$sha,'base_revision'=>$base,'manifest'=>$manifest,'payload'=>$payload];
}
function eql_deploy_already_applied(string $stage, string $root, string $state, string $sha): bool {
    eql_deploy_ready($state); $bundle = eql_deploy_bundle($stage, $sha);
    if ($bundle['base_revision'] === null || eql_deploy_current($state) !== $sha || !is_file("$state/manifest")) { return false; }
    eql_deploy_list("$state/manifest", true);
    if (file_get_contents("$state/manifest") !== file_get_contents("$stage/.eql-deployment-manifest")) { return false; }
    if (eql_deploy_safe($root, EQL_DEPLOY_MARKER) === null || file_get_contents($root . '/' . EQL_DEPLOY_MARKER) !== "$sha\n") { return false; }
    // Verify only the transferred, environment-prepared payload. A later live
    // edit to one of these paths needs an explicit full repair, not a false ACK.
    foreach ($bundle['payload'] as $path) {
        $stat = eql_deploy_safe($root, $path); $source = "$stage/$path"; $mode = 0644 | (fileperms($source) & 0111);
        if ($stat === null || ($stat['mode'] & 0777) !== $mode || $stat['size'] !== filesize($source) || hash_file('sha256', "$root/$path") !== hash_file('sha256', $source)) { eql_deploy_error('Already-applied delta payload drifted; request an explicit full repair'); }
    }
    return true;
}
function eql_deploy_plan(string $stage, string $root, string $state, string $sha, string $backup): array {
    eql_deploy_ready($state);
    $bundle = eql_deploy_bundle($stage, $sha); $current = eql_deploy_current($state); $hasManifest = file_exists("$state/manifest");
    $previous = $hasManifest ? eql_deploy_list("$state/manifest", true) : [];
    if ($bundle['base_revision'] !== null) {
        $marker = eql_deploy_safe($root, EQL_DEPLOY_MARKER);
        if ($bundle['base_revision'] !== $current || !$hasManifest || !$previous || end($previous) !== EQL_DEPLOY_MARKER || $marker === null || trim((string)file_get_contents($root . '/' . EQL_DEPLOY_MARKER)) !== $current) { eql_deploy_error('Stale delta or missing previous managed manifest; request a fresh deployment'); }
        if (array_diff(array_diff($bundle['manifest'], $previous), $bundle['payload'])) { eql_deploy_error('An added path is missing from the payload'); }
    }
    $write = []; $existing = []; $new = []; $payloadBytes = 0; $backupBytes = 0;
    foreach ($bundle['payload'] as $path) {
        $source = "$stage/$path"; $stat = eql_deploy_safe($root, $path); $mode = 0644 | (fileperms($source) & 0111); $payloadBytes += filesize($source);
        if ($stat !== null && ($stat['mode'] & 0777) === $mode && $stat['size'] === filesize($source) && hash_file('sha256', "$root/$path") === hash_file('sha256', $source)) { continue; }
        $write[] = $path; if ($stat === null) { $new[] = $path; } else { $existing[] = $path; $backupBytes += $stat['size']; }
    }
    $deleted = [];
    foreach (array_diff($previous, $bundle['manifest']) as $path) { $stat = eql_deploy_safe($root, $path); if ($stat !== null) { $deleted[] = $path; $existing[] = $path; $backupBytes += $stat['size']; } }
    // Refuse topology transitions instead of recursively deleting unmanaged contents.
    foreach ($bundle['manifest'] as $path) { $parts = explode('/', $path); array_pop($parts); $prefix = ''; foreach ($parts as $part) { $prefix .= ($prefix === '' ? '' : '/') . $part; if (in_array($prefix, $deleted, true)) { eql_deploy_error('File/directory topology change requires reviewed manual cleanup before deployment'); } } }
    $plan = ['format'=>2,'revision'=>$sha,'base_revision'=>$bundle['base_revision'],'previousRevision'=>$current,'writePaths'=>$write,'deletePaths'=>$deleted,'newPaths'=>$new,'existingPaths'=>$existing,'stateFiles'=>[],'changed'=>count($write)-count($new),'added'=>count($new),'deleted'=>count($deleted),'unchanged'=>count($bundle['manifest'])-count($write),'payloadFiles'=>count($bundle['payload']),'payloadBytes'=>$payloadBytes,'backupBytes'=>$backupBytes];
    foreach (['manifest','current-sha','current-deployment','htaccess-base'] as $file) {
        if (is_link("$state/$file") || file_exists("$state/$file") && !is_file("$state/$file")) { eql_deploy_error('Unsafe private deployment state'); }
        $plan['stateFiles'][$file] = is_file("$state/$file");
    }
    foreach ($existing as $path) { eql_deploy_copy("$root/$path", "$backup/files/$path", fileperms("$root/$path") & 0777, 0700); }
    foreach ($plan['stateFiles'] as $file => $exists) { if ($exists) { eql_deploy_copy("$state/$file", "$backup/previous-$file", 0600, 0700); } }
    eql_deploy_write("$backup/new-manifest", (string)file_get_contents("$stage/.eql-deployment-manifest"));
    eql_deploy_write("$backup/plan.json", json_encode($plan, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n"); return $plan;
}
function eql_deploy_read_plan(string $backup): array {
    if (!is_file("$backup/plan.json") || is_link("$backup/plan.json") || filesize("$backup/plan.json") > 32 * 1024 * 1024) { eql_deploy_error('Missing or unsafe recovery plan'); }
    $plan = json_decode((string)file_get_contents("$backup/plan.json"), true, 32, JSON_THROW_ON_ERROR);
    if (($plan['format'] ?? null) !== 2 || eql_deploy_sha($plan['revision'] ?? null) === null || !array_key_exists('previousRevision', $plan) || $plan['previousRevision'] !== null && eql_deploy_sha($plan['previousRevision']) === null) { eql_deploy_error('Invalid recovery plan'); }
    foreach (['writePaths','deletePaths','newPaths','existingPaths'] as $field) { if (!is_array($plan[$field] ?? null)) { eql_deploy_error('Invalid recovery paths'); } foreach ($plan[$field] as $path) { eql_deploy_path($path); } }
    if (!is_array($plan['stateFiles'] ?? null) || count($plan['stateFiles']) !== 4 || array_diff(array_keys($plan['stateFiles']), ['manifest','current-sha','current-deployment','htaccess-base'])) { eql_deploy_error('Invalid recovery state'); }
    foreach ($plan['stateFiles'] as $exists) { if (!is_bool($exists)) { eql_deploy_error('Invalid recovery state'); } }
    return $plan;
}
function eql_deploy_apply(string $stage, string $root, string $backup): void {
    $plan = eql_deploy_read_plan($backup);
    foreach ($plan['writePaths'] as $path) { if ($path === EQL_DEPLOY_MARKER) { continue; } eql_deploy_safe($root, $path); eql_deploy_copy("$stage/$path", "$root/$path", 0644 | (fileperms("$stage/$path") & 0111)); }
    foreach ($plan['deletePaths'] as $path) { if (eql_deploy_safe($root, $path) !== null && !unlink("$root/$path")) { eql_deploy_error('Cannot remove a retired managed file'); } }
    if (in_array(EQL_DEPLOY_MARKER, $plan['writePaths'], true)) { eql_deploy_safe($root, EQL_DEPLOY_MARKER); eql_deploy_copy($stage . '/' . EQL_DEPLOY_MARKER, $root . '/' . EQL_DEPLOY_MARKER, 0644); }
}
function eql_deploy_activate(string $stage, string $state, string $backup, string $sha): void {
    $plan = eql_deploy_read_plan($backup); if ($plan['revision'] !== $sha) { eql_deploy_error('Recovery revision mismatch'); }
    if (!is_file("$state/pending-deployment") || is_link("$state/pending-deployment") || trim((string)file_get_contents("$state/pending-deployment")) !== basename($backup)) { eql_deploy_error('Deployment transaction is not current'); }
    eql_deploy_write("$state/manifest", (string)file_get_contents("$stage/.eql-deployment-manifest"));
    if (is_file(dirname($stage) . '/htaccess-base')) { eql_deploy_write("$state/htaccess-base", (string)file_get_contents(dirname($stage) . '/htaccess-base')); }
    eql_deploy_write("$state/current-deployment", basename($backup) . "\n"); eql_deploy_write("$state/current-sha", "$sha\n");
    if (!unlink("$state/pending-deployment")) { eql_deploy_error('Cannot finish deployment transaction'); }
}
function eql_deploy_restore(string $root, string $state, string $backup, bool $automatic): void {
    $plan = eql_deploy_read_plan($backup); $current = eql_deploy_current($state);
    $pending = is_file("$state/pending-deployment") && !is_link("$state/pending-deployment") ? trim((string)file_get_contents("$state/pending-deployment")) : null;
    $recovering = $pending === basename($backup);
    if ($pending !== null && !$recovering || $recovering && !in_array($current, [$plan['revision'],$plan['previousRevision']], true) || !$recovering && ($current !== $plan['revision'] || is_file("$state/current-deployment") && trim((string)file_get_contents("$state/current-deployment")) !== basename($backup))) { eql_deploy_error('Backup is not the current deployment; restore the chain in reverse order'); }
    if (is_link("$state/pending-deployment") || file_exists("$state/pending-deployment") && !is_file("$state/pending-deployment")) { eql_deploy_error('Unsafe interrupted deployment state'); }
    foreach (array_unique(array_merge($plan['newPaths'], $plan['existingPaths'])) as $path) { eql_deploy_safe($root, $path); }
    // A damaged recovery copy must fail before removing any newly added path.
    foreach ($plan['existingPaths'] as $path) { if (eql_deploy_safe("$backup/files", $path) === null) { eql_deploy_error('Missing recovery file'); } }
    foreach ($plan['stateFiles'] as $file => $exists) { if ($exists && (!is_file("$backup/previous-$file") || is_link("$backup/previous-$file"))) { eql_deploy_error('Missing recovery state file'); } if (is_link("$state/$file") || file_exists("$state/$file") && !is_file("$state/$file")) { eql_deploy_error('Unsafe private recovery destination'); } }
    // Explicit rollback is a transaction too: a killed restore must not expose
    // a mixed tree as a clean baseline for the next incremental deployment.
    if (!$recovering) { eql_deploy_write("$state/pending-deployment", basename($backup) . "\n"); $recovering = true; }
    foreach ($plan['newPaths'] as $path) { if ($path !== EQL_DEPLOY_MARKER && file_exists("$root/$path") && !unlink("$root/$path")) { eql_deploy_error('Cannot remove a new deployment file'); } }
    $ordered = array_values(array_diff($plan['existingPaths'], [EQL_DEPLOY_MARKER])); if (in_array(EQL_DEPLOY_MARKER, $plan['existingPaths'], true)) { $ordered[] = EQL_DEPLOY_MARKER; }
    foreach ($ordered as $path) { if (!is_file("$backup/files/$path") || is_link("$backup/files/$path")) { eql_deploy_error('Missing recovery file'); } eql_deploy_copy("$backup/files/$path", "$root/$path", fileperms("$backup/files/$path") & 0777); }
    if (in_array(EQL_DEPLOY_MARKER, $plan['newPaths'], true) && file_exists($root . '/' . EQL_DEPLOY_MARKER) && !unlink($root . '/' . EQL_DEPLOY_MARKER)) { eql_deploy_error('Cannot restore the previous absent activation marker'); }
    foreach ($plan['stateFiles'] as $file => $exists) { if (!in_array($file, ['manifest','current-sha','current-deployment','htaccess-base'], true)) { eql_deploy_error('Invalid recovery state'); } if ($exists) { eql_deploy_copy("$backup/previous-$file", "$state/$file", 0600); } elseif (file_exists("$state/$file") && !unlink("$state/$file")) { eql_deploy_error('Cannot restore prior deployment state'); } }
    if ($recovering && !unlink("$state/pending-deployment")) { eql_deploy_error('Cannot finish interrupted deployment recovery'); }
}
function eql_deploy_legacy_restore(string $root, string $state, string $backup, string $stage): void {
    if (file_exists("$state/current-deployment") || is_link("$state/current-deployment")) { eql_deploy_error('Restore the current version-2 deployment chain before a legacy backup'); }
    $name = basename($backup); $sha = substr($name, -40);
    $existing = eql_deploy_list("$backup/existing-paths", true); $new = eql_deploy_list("$backup/new-paths", true); eql_deploy_list("$backup/previous-manifest", true);
    if (is_link("$backup/previous-sha") || file_exists("$backup/previous-sha") && (!is_file("$backup/previous-sha") || eql_deploy_sha(trim((string)file_get_contents("$backup/previous-sha"))) === null)) { eql_deploy_error('Invalid legacy previous revision'); }
    $previous = is_file("$backup/previous-sha") ? trim((string)file_get_contents("$backup/previous-sha")) : null;
    if (is_link("$state/pending-deployment") || file_exists("$state/pending-deployment") && !is_file("$state/pending-deployment")) { eql_deploy_error('Unsafe legacy recovery state'); }
    $pending = is_file("$state/pending-deployment") ? trim((string)file_get_contents("$state/pending-deployment")) : null;
    $current = eql_deploy_current($state);
    if ($pending !== null && $pending !== $name || $pending === null && $current !== $sha || $pending === $name && !in_array($current, [$sha,$previous], true)) { eql_deploy_error('Legacy backup is not the current revision or matching recovery transaction'); }
    foreach (array_unique(array_merge($new, $existing)) as $path) { eql_deploy_safe($root, $path); }
    foreach ($existing as $path) { if (!is_file("$stage/$path") || is_link("$stage/$path")) { eql_deploy_error('Missing legacy recovery file'); } }
    if ($pending === null) { eql_deploy_write("$state/pending-deployment", "$name\n"); }
    foreach ($new as $path) { if ($path !== EQL_DEPLOY_MARKER && file_exists("$root/$path") && !unlink("$root/$path")) { eql_deploy_error('Cannot remove new legacy file'); } }
    $ordered = array_values(array_diff($existing, [EQL_DEPLOY_MARKER])); if (in_array(EQL_DEPLOY_MARKER, $existing, true)) { $ordered[] = EQL_DEPLOY_MARKER; }
    foreach ($ordered as $path) { eql_deploy_copy("$stage/$path", "$root/$path", fileperms("$stage/$path") & 0777); }
    if (in_array(EQL_DEPLOY_MARKER, $new, true) && file_exists($root . '/' . EQL_DEPLOY_MARKER) && !unlink($root . '/' . EQL_DEPLOY_MARKER)) { eql_deploy_error('Cannot restore the previous absent legacy marker'); }
    eql_deploy_write("$state/manifest", (string)file_get_contents("$backup/previous-manifest"));
    if (is_file("$backup/previous-sha")) { if (eql_deploy_sha(trim((string)file_get_contents("$backup/previous-sha"))) === null) { eql_deploy_error('Invalid legacy previous revision'); } eql_deploy_write("$state/current-sha", (string)file_get_contents("$backup/previous-sha")); } elseif (file_exists("$state/current-sha")) { unlink("$state/current-sha"); }
    if (file_exists("$state/current-deployment")) { unlink("$state/current-deployment"); }
    if (!unlink("$state/pending-deployment")) { eql_deploy_error('Cannot finish legacy recovery transaction'); }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $command = $argv[1] ?? ''; $args = array_slice($argv, 2);
        switch ($command) {
            case 'status': eql_deploy_ready($args[0]); $sha = eql_deploy_current($args[0]); echo json_encode(['protocol'=>2,'revision'=>$sha], JSON_THROW_ON_ERROR) . "\n"; break;
            case 'ready': eql_deploy_ready($args[0]); break;
            case 'validate-archive': eql_deploy_archive($args[0], $args[1], ($args[2] ?? '') === 'legacy'); break;
            case 'validate': $bundle = eql_deploy_bundle($args[0], $args[1]); echo json_encode(['protocol'=>$bundle['protocol'],'revision'=>$bundle['revision'],'base_revision'=>$bundle['base_revision']], JSON_THROW_ON_ERROR) . "\n"; break;
            case 'already-applied': if (!eql_deploy_already_applied(...$args)) { exit(10); } break;
            case 'plan': echo json_encode(eql_deploy_plan(...$args), JSON_THROW_ON_ERROR) . "\n"; break;
            case 'apply': eql_deploy_apply(...$args); break;
            case 'begin': eql_deploy_begin(...$args); break;
            case 'activate': eql_deploy_activate(...$args); break;
            case 'restore': eql_deploy_restore($args[0], $args[1], $args[2], ($args[3] ?? '') === 'automatic'); break;
            case 'restore-legacy': eql_deploy_legacy_restore(...$args); break;
            default: eql_deploy_error('Unknown private deployment operation');
        }
    } catch (Throwable $error) { fwrite(STDERR, $error->getMessage() . "\n"); exit(1); }
}
