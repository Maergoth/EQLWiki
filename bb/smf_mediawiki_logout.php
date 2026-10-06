<?php
/**
 * Clears the SMF login cookie.
 *
 * This does not log the user out of MediaWiki.
 * The wiki remains the source of truth.
 */

require_once __DIR__ . '/Settings.php';

$cookieName = !empty($cookiename) ? $cookiename : 'SMFCookie912';

$returnUrl = isset($_GET['return']) ? $_GET['return'] : '/bb/';

if (
    strpos($returnUrl, '/bb/') !== 0 &&
    strpos($returnUrl, 'https://eqlwiki.com/bb/') !== 0
) {
    $returnUrl = '/bb/';
}

/**
 * Clear the SMF cookie using several likely combinations.
 * The live cookie is Secure, HttpOnly, path /, domain eqlwiki.com.
 */
$expire = time() - 3600;

$paths = ['/', '/bb', '/bb/'];
$domains = ['', 'eqlwiki.com', '.eqlwiki.com'];

foreach ($paths as $path) {
    foreach ($domains as $domain) {
        $options = [
            'expires' => $expire,
            'path' => $path,
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ];

        if ($domain !== '') {
            $options['domain'] = $domain;
        }

        setcookie($cookieName, '', $options);
        setcookie($cookieName, 'deleted', $options);
    }
}

unset($_COOKIE[$cookieName]);

header('Location: ' . $returnUrl);
exit;