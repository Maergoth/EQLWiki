<?php
// The public sign-in page exists only on the staging hostname.
if (strtolower($_SERVER['HTTP_HOST'] ?? '') !== 'test.eqlwiki.com') {
    http_response_code(404); exit;
}
require '/home/eqlwikdq/deploy/EQLWiki-staging/access-handler.php';
