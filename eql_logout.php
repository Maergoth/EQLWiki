<?php
/**
 * Unified EQL logout.
 *
 * Logs out of MediaWiki and clears the SMF forum cookie.
 * Place this file in the MediaWiki root directory, same place as LocalSettings.php.
 */

ob_start();

require_once __DIR__ . '/includes/WebStart.php';

$request = RequestContext::getMain()->getRequest();
$user = RequestContext::getMain()->getUser();

/**
 * 1. Log out of MediaWiki.
 */
if ($user && $user->getId() !== 0) {
    if (method_exists($user, 'doLogout')) {
        $user->doLogout();
    } elseif (method_exists($user, 'logout')) {
        $user->logout();
    }
}

/**
 * 2. Clear MediaWiki session.
 */
try {
    $session = $request->getSession();

    if ($session) {
        $session->clear();

        if (method_exists($session, 'persist')) {
            $session->persist();
        }

        if (method_exists($session, 'save')) {
            $session->save();
        }
    }
} catch (Throwable $e) {
    // Continue with cookie clearing.
}

/**
 * 3. Get MediaWiki cookie prefix.
 */
$cookiePrefix = '';

try {
    $services = MediaWiki\MediaWikiServices::getInstance();
    $config = $services->getMainConfig();
    $cookiePrefix = $config->get('CookiePrefix');
} catch (Throwable $e) {
    $cookiePrefix = '';
}

/**
 * 4. Clear output buffers MediaWiki may have started.
 */
while (ob_get_level() > 0) {
    @ob_end_clean();
}

/**
 * 5. Cookie clearing helper.
 */
function eql_clear_cookie_everywhere($name)
{
    if ($name === '' || $name === null) {
        return;
    }

    $expire = time() - 86400;

    $paths = [
        '/',
        '/bb',
        '/bb/',
    ];

    $domains = [
        '',
        'eqlwiki.com',
        '.eqlwiki.com',
        'www.eqlwiki.com',
        '.www.eqlwiki.com',
    ];

    foreach ($paths as $path) {
        foreach ($domains as $domain) {
            foreach ([false, true] as $secure) {
                $options = [
                    'expires' => $expire,
                    'path' => $path,
                    'secure' => $secure,
                    'httponly' => true,
                    'samesite' => 'Lax',
                ];

                if ($domain !== '') {
                    $options['domain'] = $domain;
                }

                setcookie($name, '', $options);
                setcookie($name, 'deleted', $options);
            }
        }
    }

    unset($_COOKIE[$name]);
}

/**
 * 6. Clear MediaWiki cookies.
 */
$knownMwPrefix = 'eqlwikdq_mw14188_mw_';

$mwPrefixes = array_unique(array_filter([
    $cookiePrefix,
    $knownMwPrefix,
]));

foreach ($mwPrefixes as $prefix) {
    $mwCookies = [
        $prefix . 'UserID',
        $prefix . 'UserName',
        $prefix . 'Token',
        $prefix . '_session',
        $prefix . 'session',
    ];

    foreach ($mwCookies as $cookieName) {
        eql_clear_cookie_everywhere($cookieName);
    }
}

/**
 * 7. Clear PHP session cookie.
 */
eql_clear_cookie_everywhere('PHPSESSID');

if (session_status() === PHP_SESSION_ACTIVE) {
    $_SESSION = [];
    session_destroy();
}

/**
 * 8. Clear SMF forum cookie.
 */
eql_clear_cookie_everywhere('SMFCookie912');

/**
 * 9. Send browser to MediaWiki logout page.
 */
$redirectUrl = 'https://www.eqlwiki.com/index.php?title=Special:UserLogout&returnto=Main+Page';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

/**
 * Use 303 instead of default 302.
 */
header('Location: ' . $redirectUrl, true, 303);
exit;