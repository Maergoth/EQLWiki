<?php
// Loaded only by the private staging bootstrap, never by production releases.
if (PHP_SAPI === 'cli') { return; }
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '');
$action = strtolower((string)($_POST['action'] ?? $_GET['action'] ?? ''));
$title = str_replace(' ', '_', (string)($_POST['title'] ?? $_GET['title'] ?? $path));
$passwordRoute = preg_match('~(?:^|/)(?:wiki_auth_bridge|smf_mediawiki_login)[.]php$~i', $path)
    || preg_match('~(?:^|[/ :])Special:(?:UserLogin|CreateAccount|PasswordReset|ChangeCredentials|RemoveCredentials)(?:/|$)~i', $title)
    || (str_contains($path, '/bb/') && in_array($action, ['login', 'login2', 'register', 'register2', 'reminder', 'reminder2'], true));
$apiPassword = str_ends_with($path, '/api.php') && in_array($action, ['login', 'clientlogin', 'createaccount', 'changeauthenticationdata', 'removeauthenticationdata', 'linkaccount'], true);
if ($apiPassword) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error'=>['code'=>'staging-live-login-required', 'info'=>'Sign in on eqlwiki.com as an administrator or bureaucrat, then use the staging access page.']]);
    exit;
}
if ($passwordRoute) {
    header('Location: /staging-access.php', true, 303);
    exit;
}
