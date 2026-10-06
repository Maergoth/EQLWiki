<?php
// Router for PHP's development server; production routing stays in .htaccess.
$root = dirname(__DIR__);
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');
$candidate = realpath($root . $path);
if ($candidate !== false && str_starts_with($candidate, $root . DIRECTORY_SEPARATOR) && is_file($candidate)) {
    return false;
}
if ($path === '/bb/' || $path === '/bb') {
    require $root . '/bb/index.php';
    return;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $root . '/index.php';
require $root . '/index.php';
