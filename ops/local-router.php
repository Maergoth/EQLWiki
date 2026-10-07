<?php
// Router for PHP's development server; production routing stays in .htaccess.
$root = dirname(__DIR__);
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');
// The runtime contains private databases, configuration and recovery copies.
if (preg_match('#(?:^|/)[.]local(?:/|$)#i', str_replace('\\', '/', $path))) {
    http_response_code(404);
    return;
}
$candidate = realpath($root . $path);
if ($candidate !== false && str_starts_with($candidate, $root . DIRECTORY_SEPARATOR) && is_file($candidate)) {
    return false;
}
if ($path === '/bb/' || $path === '/bb') {
    require $root . '/bb/index.php';
    return;
}
if (str_starts_with($path, '/rest.php/')) {
    $_SERVER['SCRIPT_NAME'] = '/rest.php';
    $_SERVER['SCRIPT_FILENAME'] = $root . '/rest.php';
    $_SERVER['PATH_INFO'] = substr($path, strlen('/rest.php'));
    require $root . '/rest.php';
    return;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $root . '/index.php';
require $root . '/index.php';
