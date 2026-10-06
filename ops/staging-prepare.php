<?php
// Environment substitutions in a staged release, never in production files.
$root = $argv[1] ?? '';
if (!is_file("$root/.eql-deployment-manifest")) { throw new RuntimeException('Missing release manifest'); }
$files = ['eql_logout.php', 'bb/smf_mediawiki_login.php', 'bb/smf_mediawiki_auto_login.php',
    'bb/smf_mediawiki_logout.php', 'bb/eql_inline_image_upload.php', 'bb/eql_inline_image_finalize.php',
    'bb/Themes/PurpleHaze/index.template.php', 'skins/EQLImmersive/resources/contribute.js',
    'skins/EQLImmersive/resources/item-hover.js', 'skins/EQLImmersive/resources/main.js',
    'skins/EQLImmersive/resources/spellblade-indicator.js', 'bb/.htaccess'];
foreach ($files as $file) {
    if (!is_file("$root/$file")) { throw new RuntimeException("Missing staging source: $file"); }
    $source = file_get_contents("$root/$file");
    $source = str_replace(['https://www.eqlwiki.com', 'https://eqlwiki.com'], 'https://test.eqlwiki.com', $source);
    if (str_ends_with($file, '.php')) {
        $bootstrap = str_starts_with($file, 'bb/Themes/') ? "dirname(__DIR__, 3)" : (str_starts_with($file, 'bb/') ? 'dirname(__DIR__)' : '__DIR__');
        $source = preg_replace('/<\?php/', "<?php\nrequire_once $bootstrap . '/EQLStaging.php';", $source, 1);
        $source = preg_replace('/([\x27\"]header[\x27\"]\s*=>\s*)(\$headers|\"Content-Type: application\/x-www-form-urlencoded\\\\r\\\\n\")/', '$1eql_staging_headers($2)', $source);
    }
    if ($file === 'eql_logout.php') {
        $source = preg_replace('/\$domains\s*=\s*\[.*?\];/s', "\$domains = ['', 'test.eqlwiki.com', '.test.eqlwiki.com'];", $source, 1);
        $source = str_replace(['eqlwikdq_mw14188_mw_', "'PHPSESSID'", "'SMFCookie912'"], ['eqlwiki_staging_', "'EQLStagingSession'", "'SMFCookieEQLStaging'"], $source);
    }
    if ($file === 'bb/smf_mediawiki_logout.php') {
        $source = str_replace("['', 'eqlwiki.com', '.eqlwiki.com']", "['', 'test.eqlwiki.com', '.test.eqlwiki.com']", $source);
    }
    file_put_contents("$root/$file", $source);
}
// Persist access protection when a release replaces the production .htaccess.
require_once '/home/eqlwikdq/deploy/EQLWiki-staging/staging-access-lib.php';
file_put_contents("$root/.htaccess", eql_staging_access_rules(file_get_contents("$root/.htaccess")));
echo "Staging URLs, authentication headers, and cookie isolation prepared\n";
