<?php
// Install outside every document root. Only the isolated staging DB user imports data.
umask(0077);
$state = '/home/eqlwikdq/deploy/EQLWiki-staging';
$production = '/home/eqlwikdq/public_html';
$mode = $argv[1] ?? '';
if (!in_array($mode, ['if-stale', 'force'], true)) { throw new RuntimeException('Expected if-stale or force'); }
$lock = fopen("$state/deploy.lock", 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) { throw new RuntimeException('Another staging operation is running'); }
$recovering = is_file("$state/refresh-in-progress");
if ($mode === 'if-stale' && $recovering) { throw new RuntimeException('Previous refresh did not complete; run the manual forced refresh'); }
$last = is_file("$state/last-refresh") ? (int)file_get_contents("$state/last-refresh") : 0;
if ($mode === 'if-stale' && time() - $last < 86400) {
    echo 'Database refresh skipped: last successful refresh was less than 24 hours ago.' . PHP_EOL;
    exit(0);
}
$settings = json_decode(file_get_contents("$state/settings.json"), true, 512, JSON_THROW_ON_ERROR);
$root = $settings['root'];
if ($root !== '/home/eqlwikdq/test.eqlwiki.com' || $settings['wikiDatabase'] !== 'eqlwikdq_stagewiki' || $settings['forumDatabase'] !== 'eqlwikdq_stageforum') {
    throw new RuntimeException('Staging target verification failed');
}
function literal(string $source, string $name): string {
    $pattern = '/\$' . preg_quote($name, '/') . '\s*=\s*((?:\x27(?:[^\x27\\\\]|\\\\.)*\x27)|(?:"(?:[^"\\\\]|\\\\.)*"))\s*;/s';
    if (!preg_match($pattern, $source, $match)) { throw new RuntimeException("Missing literal setting: $name"); }
    return eval('return ' . $match[1] . ';');
}
function assignment(string $source, string $name, string $value): string {
    $pattern = '/\$' . preg_quote($name, '/') . '\s*=\s*((?:\x27(?:[^\x27\\\\]|\\\\.)*\x27)|(?:"(?:[^"\\\\]|\\\\.)*"))\s*;/s';
    $source = preg_replace_callback($pattern, fn() => '$' . $name . ' = ' . var_export($value, true) . ';', $source, -1, $count);
    if ($count !== 1) { throw new RuntimeException("Expected one setting: $name"); }
    return $source;
}
function run(array $command, string $input, string $output, string $log): void {
    $process = proc_open($command, [0 => ['file', $input, 'r'], 1 => ['file', $output, 'w'], 2 => ['file', $log, 'a']], $pipes);
    if (!is_resource($process) || proc_close($process) !== 0) { throw new RuntimeException('Database operation failed; see private refresh.log'); }
}
function publicDirectory(string $path): void {
    if (!is_dir($path) && !mkdir($path, 0755, true)) { throw new RuntimeException('Cannot create staging directory'); }
    chmod($path, 0755);
}
// Block requests while tables are replaced. On failure leave maintenance protection in place.
require_once "$state/staging-access-lib.php";
$access = eql_staging_access_rules(file_get_contents("$state/htaccess-base"));
file_put_contents("$state/pre-refresh.htaccess", $access);
file_put_contents("$state/refresh-in-progress", (string)time());
file_put_contents("$root/.htaccess", "Require all denied\n");
chmod("$root/.htaccess", 0644);
register_shutdown_function(static function() use ($state, $root) {
    if (is_file("$state/refresh-in-progress")) {
        file_put_contents("$root/.htaccess", "Require all denied\n"); chmod("$root/.htaccess", 0644);
    }
});
$work = "$state/refresh";
if (!is_dir($work)) { mkdir($work, 0700); }
$wiki = file_get_contents("$production/LocalSettings.php");
$forum = file_get_contents("$production/bb/Settings.php");
foreach ([['wiki', $wiki, 'wgDBserver', 'wgDBuser', 'wgDBpassword', 'wgDBname', $settings['wikiDatabase']],
          ['forum', $forum, 'db_server', 'db_user', 'db_passwd', 'db_name', $settings['forumDatabase']]] as [$label, $source, $host, $user, $password, $database, $target]) {
    $escape = fn($value) => '"' . str_replace(["\\", '"', "\n", "\r"], ["\\\\", '\\"', '\\n', '\\r'], $value) . '"';
    $config = "$work/source-client.cnf";
    file_put_contents($config, "[client]\nhost=" . $escape(literal($source, $host)) . "\nuser=" . $escape(literal($source, $user)) . "\npassword=" . $escape(literal($source, $password)) . "\n");
    try {
        // The previous staging dump provides a private recovery point before a refresh.
        if (!$recovering || !is_file("$work/$label-previous.sql")) {
            run(['/bin/mysqldump', "--defaults-extra-file=$state/client.cnf", '--single-transaction', '--quick', '--hex-blob', '--no-tablespaces', $target], '/dev/null', "$work/$label-previous.sql", "$state/refresh.log");
        }
        run(['/bin/mysqldump', "--defaults-extra-file=$config", '--single-transaction', '--quick', '--hex-blob', '--skip-lock-tables', '--no-tablespaces', literal($source, $database)], '/dev/null', "$work/$label-current.sql", "$state/refresh.log");
        $targetConnection = new mysqli('localhost', $settings['databaseUser'], $settings['databasePassword'], $target);
        $targetConnection->query('SET FOREIGN_KEY_CHECKS=0');
        $tables = $targetConnection->query('SHOW FULL TABLES');
        while ($table = $tables->fetch_row()) {
            $name = str_replace('`', '``', $table[0]);
            $kind = $table[1] === 'VIEW' ? 'VIEW' : 'TABLE';
            $targetConnection->query("DROP $kind `$name`");
        }
        $targetConnection->close();
        run(['/bin/mysql', "--defaults-extra-file=$state/client.cnf", $target], "$work/$label-current.sql", '/dev/null', "$state/refresh.log");
        unlink("$work/$label-current.sql");
        echo "$label database refreshed\n";
    } finally { unlink($config); }
}
// Copy only missing runtime files; never hard-link staging to production.
$copied = 0;
foreach (['images', 'bb/attachments', 'bb/custom_avatar', 'bb/uploads', 'eql-static'] as $directory) {
    if (!is_dir("$production/$directory")) { continue; }
    publicDirectory("$root/$directory");
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$production/$directory", FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($iterator as $file) {
        if ($file->isLink()) { continue; }
        $relative = substr($file->getPathname(), strlen($production) + 1);
        $target = "$root/$relative";
        if ($file->isDir()) { publicDirectory($target); }
        elseif (!file_exists($target)) {
            if (!copy($file->getPathname(), $target)) { throw new RuntimeException("Cannot copy $relative"); }
            chmod($target, 0644); touch($target, $file->getMTime()); $copied++;
        }
    }
}
$keys = is_file("$state/keys.json") ? json_decode(file_get_contents("$state/keys.json"), true) : array_combine(['wiki', 'upgrade', 'forum', 'proxy', 'auth', 'session'], array_map(fn() => bin2hex(random_bytes(32)), range(1,6)));
file_put_contents("$state/keys.json", json_encode($keys));
publicDirectory("$root/bb/Packages");
foreach (["$state/cache/mediawiki/tmp", "$state/cache/forum"] as $directory) { if (!is_dir($directory)) { mkdir($directory, 0700, true); } }
$wiki = str_replace(['https://www.eqlwiki.com', 'https://eqlwiki.com', '/home/eqlwikdq/private-cache/mediawiki'], [$settings['url'], $settings['url'], "$state/cache/mediawiki"], $wiki);
foreach (['wgDBname'=>$settings['wikiDatabase'], 'wgDBuser'=>$settings['databaseUser'], 'wgDBpassword'=>$settings['databasePassword'], 'wgCookieDomain'=>'test.eqlwiki.com', 'wgSecretKey'=>$keys['wiki'], 'wgUpgradeKey'=>$keys['upgrade'], 'wgTmpDirectory'=>"$state/cache/mediawiki/tmp"] as $name=>$value) { $wiki = assignment($wiki, $name, $value); }
$wiki .= "\nrequire_once __DIR__ . '/EQLStaging.php';\n\$wgCookiePrefix = 'eqlwiki_staging_';\n\$wgEnableEmail = false;\n\$wgEnableUserEmail = false;\n\$wgEmailAuthentication = false;\n\$wgEmailConfirmToEdit = false;\n\$wgJobRunRate = 0;\n\$wgPingback = false;\nforeach (array_keys(\$wgCaptchaTriggers) as \$trigger) { \$wgCaptchaTriggers[\$trigger] = false; }\n\$wgCacheEpoch = '" . gmdate('YmdHis') . "';\n\$wgDefaultRobotPolicy = 'noindex,nofollow';\n\$wgHooks['OutputPageBeforeHTML'][] = static function(\$out, &\$text) { \$text = eql_staging_html(\$text); return true; };\n";
file_put_contents("$root/LocalSettings.php", $wiki);
foreach (['db_name'=>$settings['forumDatabase'], 'db_user'=>$settings['databaseUser'], 'db_passwd'=>$settings['databasePassword'], 'boardurl'=>$settings['url'].'/bb', 'boarddir'=>"$root/bb", 'sourcedir'=>"$root/bb/Sources", 'packagesdir'=>"$root/bb/Packages", 'tasksdir'=>"$root/bb/Sources/tasks", 'cachedir'=>"$state/cache/forum", 'cachedir_sqlite'=>"$state/cache/forum", 'cookiename'=>'SMFCookieEQLStaging', 'auth_secret'=>$keys['forum'], 'image_proxy_secret'=>$keys['proxy']] as $name=>$value) { $forum = assignment($forum, $name, $value); }
$extra = "\nrequire_once dirname(__DIR__) . '/EQLStaging.php';\n\$cache_enable = 0;\nfunction eql_staging_block_mail() { return false; }\ndefine('SMF_INTEGRATION_SETTINGS', json_encode(['integrate_outgoing_email'=>'eql_staging_block_mail']));\nob_start('eql_staging_html');\n";
$forum = str_contains($forum, '?>') ? str_replace('?>', $extra . '?>', $forum) : $forum . $extra;
file_put_contents("$root/bb/Settings.php", $forum);
$bridge = ['wikiAuth'=>$keys['auth'], 'forumAuth'=>$keys['auth'], 'wikiSession'=>$keys['session'], 'forumSession'=>$keys['session']];
file_put_contents("$root/BridgeSecrets.php", "<?php\nreturn " . var_export($bridge, true) . ";\n");
$bootstrap = <<<'PHP'
<?php
function eql_staging_headers(string $headers): string {
    return $headers . 'Authorization: Basic ' . EQL_STAGING_HTTP_AUTH . "\r\n";
}
function eql_staging_html(string $html): string {
    return str_replace(['https://www.eqlwiki.com', 'https://eqlwiki.com'], 'https://test.eqlwiki.com', $html);
}
if (session_status() === PHP_SESSION_NONE) { session_name('EQLStagingSession'); }
require_once '/home/eqlwikdq/deploy/EQLWiki-staging/staging-login-policy.php';
putenv('EQL_WIKI_URL=https://test.eqlwiki.com');
PHP;
$bootstrap .= "\ndefine('EQL_STAGING_HTTP_AUTH', " . var_export(base64_encode($settings['httpUser'].':'.$settings['httpPassword']), true) . ");\n";
file_put_contents("$root/EQLStaging.php", $bootstrap);
foreach (['LocalSettings.php', 'bb/Settings.php', 'BridgeSecrets.php', 'EQLStaging.php'] as $file) { chmod("$root/$file", 0600); }
$db = new mysqli('localhost', $settings['databaseUser'], $settings['databasePassword'], $settings['forumDatabase']);
$prefix = literal($forum, 'db_prefix');
if (!preg_match('/^[a-zA-Z0-9_]+$/', $prefix)) { throw new RuntimeException('Unsafe forum table prefix'); }
$url = $db->real_escape_string($settings['url']);
$path = $db->real_escape_string($root);
foreach (['settings', 'themes'] as $table) {
    $db->query("UPDATE `{$prefix}{$table}` SET value=REPLACE(REPLACE(REPLACE(value,'https://www.eqlwiki.com','$url'),'https://eqlwiki.com','$url'),'/home/eqlwikdq/public_html','$path') WHERE variable IN ('attachmentUploadDir','avatar_directory','avatar_url','custom_avatar_dir','custom_avatar_url','smileys_dir','smileys_url','theme_dir','theme_url','images_url')");
}
$db->query("UPDATE `{$prefix}settings` SET value='test.eqlwiki.com' WHERE variable='globalCookiesDomain'");
$db->query("UPDATE `{$prefix}settings` SET value='0' WHERE variable IN ('globalCookies','enableCompressedOutput')");
$db->query("TRUNCATE TABLE `{$prefix}sessions`");
$db->close();
// Restore the last working access rules only after every refresh operation succeeds.
file_put_contents("$root/.htaccess", $access); chmod("$root/.htaccess", 0644);
if (is_file("$root/index.php")) {
    run(['/bin/curl', '--fail', '--silent', '--show-error', '--netrc-file', "$state/health.netrc", $settings['url'].'/api.php?action=query&meta=siteinfo&format=json'], '/dev/null', "$work/health.json", "$state/refresh.log");
    $health = json_decode(file_get_contents("$work/health.json"), true);
    if (!isset($health['query']['general']['sitename'])) {
        file_put_contents("$root/.htaccess", "Require all denied\n");
        throw new RuntimeException('Staging API check failed');
    }
}
file_put_contents("$state/last-refresh", (string)time());
unlink("$state/refresh-in-progress");
echo "Refresh complete; copied $copied missing runtime files. Existing files retained.\n";
