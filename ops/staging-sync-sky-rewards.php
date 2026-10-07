<?php
// Install privately beside staging-command.sh and sync-sky-rewards.php.
// Keep database-backed reward code current after a staging deploy or refresh.
umask(0077);
$state = '/home/eqlwikdq/deploy/EQLWiki-staging';
$root = '/home/eqlwikdq/test.eqlwiki.com';
$source = "$root/skins/EQLImmersive/resources/sky-rewards.js";
$lock = fopen("$state/deploy.lock", 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) { throw new RuntimeException('Another staging operation is running'); }
if (is_file("$state/refresh-in-progress")) { throw new RuntimeException('Staging refresh must finish before script sync'); }
// Compatible with the release preceding the first reward-script deployment.
if (!is_file($source)) { echo "No deployed reward section; staging script sync skipped.\n"; exit(0); }
$settings = json_decode(file_get_contents("$state/settings.json"), true, 512, JSON_THROW_ON_ERROR);
if ($settings['root'] !== $root || $settings['wikiDatabase'] !== 'eqlwikdq_stagewiki' ||
    $settings['url'] !== 'https://test.eqlwiki.com') {
    throw new RuntimeException('Staging target verification failed');
}
$sha = trim(file_get_contents("$state/current-sha"));
if (!preg_match('/^[a-f0-9]{40}$/', $sha)) { throw new RuntimeException('Missing valid deployed revision'); }
$url = $settings['url'] . '/api.php?action=query&prop=revisions&titles=MediaWiki%3ACommon.js&rvprop=ids&format=json&formatversion=2';
$process = proc_open(['/bin/curl', '--fail', '--silent', '--show-error', '--max-time', '30',
    '--netrc-file', "$state/health.netrc", $url], [0=>['file','/dev/null','r'], 1=>['pipe','w'], 2=>STDERR], $pipes);
if (!is_resource($process)) { throw new RuntimeException('Could not query staging script revision'); }
$data = stream_get_contents($pipes[1]); fclose($pipes[1]);
if (proc_close($process) !== 0) { throw new RuntimeException('Staging revision query failed'); }
$revision = json_decode($data, true, 512, JSON_THROW_ON_ERROR)['query']['pages'][0]['revisions'][0]['revid'] ?? null;
if (!is_int($revision) || $revision <= 0) { throw new RuntimeException('Missing staging Common.js revision'); }
$backups = "$state/cache/mediawiki/common-script-backups";
if (!is_dir($backups) && !mkdir($backups, 0700, true)) { throw new RuntimeException('Cannot create private script backups'); }
$backup = "$backups/" . gmdate('Ymd-His') . "-$sha-" . bin2hex(random_bytes(4)) . '.js';
$process = proc_open([PHP_BINARY, "$root/maintenance/run.php", "$state/sync-sky-rewards.php",
    '--source', $source, '--expected-revision', (string)$revision, '--backup', $backup],
    [0=>['file','/dev/null','r'], 1=>STDOUT, 2=>STDERR], $pipes, $root);
if (!is_resource($process) || proc_close($process) !== 0) { throw new RuntimeException('Staging reward script sync failed'); }
echo "Staging reward section synchronized from deployed commit $sha.\n";
