<?php
require_once __DIR__ . '/staging-access-lib.php';
header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header_remove('WWW-Authenticate');
http_response_code(200);
$state = '/home/eqlwikdq/deploy/EQLWiki-staging';
$return = $_GET['return'] ?? $_SERVER['REDIRECT_URL'] ?? '/Main_Page';
if (!is_string($return) || !str_starts_with($return, '/') || str_starts_with($return, '//') || preg_match('/[\r\n]/', $return) || str_starts_with($return, '/staging-access.php')) { $return = '/Main_Page'; }
$message = 'Sign in to the live wiki with your regular account, then continue here.';
$cookie = $_SERVER['HTTP_COOKIE'] ?? '';
if (preg_match('/(?:^|;\s*)eqlwikdq_mw14188_[^=;]*=/', $cookie)) {
    try {
        $rates = "$state/access-rates";
        if (!is_dir($rates)) { mkdir($rates, 0700); }
        $rateFile = $rates . '/' . hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $rate = fopen($rateFile, 'c+'); flock($rate, LOCK_EX);
        $counts = json_decode(stream_get_contents($rate), true) ?: ['start'=>time(), 'count'=>0];
        if (time() - $counts['start'] > 60) { $counts = ['start'=>time(), 'count'=>0]; }
        $counts['count']++; rewind($rate); ftruncate($rate, 0); fwrite($rate, json_encode($counts)); fclose($rate);
        if ($counts['count'] > 30) { throw new RuntimeException('Too many access checks'); }
        $secret = (require '/home/eqlwikdq/public_html/BridgeSecrets.php')['wikiSession'];
        $context = stream_context_create(['http'=>['method'=>'POST', 'header'=>"Content-Type: application/x-www-form-urlencoded\r\nCookie: $cookie\r\n", 'content'=>http_build_query(['secret'=>$secret]), 'timeout'=>20, 'follow_location'=>0, 'ignore_errors'=>true]]);
        $session = json_decode(file_get_contents('https://eqlwiki.com/wiki_session_bridge.php', false, $context), true) ?: [];
        if (($session['status'] ?? '') === 'OK') {
            $url = 'https://eqlwiki.com/api.php?' . http_build_query(['action'=>'query', 'list'=>'users', 'ususers'=>$session['name'], 'usprop'=>'groups|blockinfo', 'format'=>'json']);
            $response = json_decode(file_get_contents($url, false, stream_context_create(['http'=>['timeout'=>20, 'follow_location'=>0]])), true);
            $identity = eql_staging_access_identity($session, $response['query']['users'][0] ?? []);
            if ($identity) {
                require_once '/home/eqlwikdq/test.eqlwiki.com/includes/WebStart.php';
                eql_staging_access_login($identity);
                // LiteSpeed caches .htaccess briefly; allow the new access rule to become active.
                header('Refresh: 12; url=' . $return);
                echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="robots" content="noindex,nofollow"><title>Opening staging</title><body style="background:#101b29;color:#e4ebf4;font:20px system-ui;padding:12vh 24px"><p>Signed in. Opening staging…</p></body></html>'; exit;
            }
            $message = 'Your live wiki account must be an administrator or bureaucrat to access staging.';
        }
    } catch (Throwable $error) {
        // Never display bridge secrets, cookie values, or database diagnostics to visitors.
        error_log('Staging access check failed: ' . get_class($error));
        $message = 'The access check could not complete. Please try again shortly.';
    }
}
$continue = '/staging-access.php?' . http_build_query(['return'=>$return]);
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>EQLWiki staging access</title>
<style>body{background:#101b29;color:#e4ebf4;font:17px/1.6 system-ui;margin:0;padding:12vh 24px}main{max-width:600px;margin:auto}h1{font-size:32px}a{color:#80c6ff}.button{display:inline-block;padding:10px 20px;border:1px solid #80c6ff;border-radius:6px;margin:12px 14px 0 0;text-decoration:none}.muted{color:#aab9ca}</style></head>
<body><main><h1>EQLWiki staging</h1><p><?=htmlspecialchars($message, ENT_QUOTES, 'UTF-8')?></p>
<a class="button" href="https://eqlwiki.com/index.php?title=Special:UserLogin" target="_blank" rel="noopener">Sign in to live wiki</a>
<a class="button" href="<?=htmlspecialchars($continue, ENT_QUOTES, 'UTF-8')?>">Continue to staging</a>
<p class="muted">Staging changes can be reset during a data refresh.</p></main></body></html>
