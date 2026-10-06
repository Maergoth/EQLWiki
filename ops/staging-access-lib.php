<?php
const EQL_STAGING_PRIVILEGED_GROUPS = ['sysop', 'bureaucrat', 'interface-admin', 'suppress'];
const EQL_STAGING_ACCESS_TOKENS = '/home/eqlwikdq/staging-access/tokens';

function eql_staging_access_rules(string $base): string {
    $tokens = [];
    foreach (new DirectoryIterator(EQL_STAGING_ACCESS_TOKENS) as $file) {
        if (!$file->isFile() || !preg_match('/^([0-9]{14})-[a-f0-9]{64}$/', $file->getFilename(), $match)) { continue; }
        if ($match[1] < gmdate('YmdHis')) { unlink($file->getPathname()); }
        else { $tokens[] = $file->getFilename(); }
    }
    $rule = $tokens ? 'SetEnvIfNoCase Cookie "EQLStagingAccess=(' . implode('|', $tokens) . ')(;|$)" EQL_STAGING_ALLOW' : '# No active administrator access tokens';
    return str_replace('# EQL_STAGING_TOKEN_ALLOWLIST', $rule, file_get_contents('/home/eqlwikdq/deploy/EQLWiki-staging/staging.htaccess')) . $base;
}

function eql_staging_access_render(): void {
    $state = '/home/eqlwikdq/deploy/EQLWiki-staging';
    if (is_file("$state/refresh-in-progress")) { throw new RuntimeException('Staging refresh incomplete'); }
    $lock = fopen("$state/access-render.lock", 'c'); flock($lock, LOCK_EX);
    $rules = eql_staging_access_rules(file_get_contents("$state/htaccess-base"));
    $path = '/home/eqlwikdq/test.eqlwiki.com/.htaccess';
    file_put_contents($path . '.access-tmp', $rules); chmod($path . '.access-tmp', 0644); rename($path . '.access-tmp', $path);
}

function eql_staging_access_identity(array $session, array $user): ?array {
    if (($session['status'] ?? '') !== 'OK' || empty($session['id']) ||
        ($user['userid'] ?? 0) !== $session['id'] || ($user['name'] ?? '') !== ($session['name'] ?? '') ||
        isset($user['blockedby']) || isset($user['blockid'])) { return null; }
    $groups = array_values(array_intersect(EQL_STAGING_PRIVILEGED_GROUPS, $user['groups'] ?? []));
    return $groups ? ['name'=>$user['name'], 'groups'=>$groups, 'email'=>$session['email'] ?? ''] : null;
}

function eql_staging_access_login(array $identity): string {
    // Only the staging database is bootstrapped. The caller has verified a live session and roles.
    if (!defined('MEDIAWIKI')) { throw new RuntimeException('Staging MediaWiki must be bootstrapped in global scope'); }
    $operation = fopen('/home/eqlwikdq/deploy/EQLWiki-staging/deploy.lock', 'c');
    flock($operation, LOCK_SH);
    $services = MediaWiki\MediaWikiServices::getInstance();
    $user = $services->getUserFactory()->newFromName($identity['name']);
    if (!$user) { throw new RuntimeException('Invalid verified user name'); }
    if (!$user->isRegistered()) {
        $user->setEmail($identity['email']);
        $status = $user->addToDatabase();
        if (!$status->isOK()) { throw new RuntimeException('Cannot provision staging user'); }
    }
    $manager = $services->getUserGroupManager();
    $current = $manager->getUserGroups($user);
    foreach (EQL_STAGING_PRIVILEGED_GROUPS as $group) {
        if (in_array($group, $identity['groups'], true)) {
            if (!in_array($group, $current, true)) { $manager->addUserToGroup($user, $group); }
        } elseif (in_array($group, $current, true)) { $manager->removeUserFromGroup($user, $group); }
    }
    $request = RequestContext::getMain()->getRequest();
    $user->setCookies($request, true, false);
    $services->getDBLoadBalancerFactory()->commitPrimaryChanges(__METHOD__);
    // Expiring tokens feed the web-server allow list; assets retain their normal request path.
    $expires = time() + 3600;
    $token = gmdate('YmdHis', $expires) . '-' . bin2hex(random_bytes(32));
    foreach (new DirectoryIterator(EQL_STAGING_ACCESS_TOKENS) as $file) {
        if ($file->isFile() && preg_match('/^([0-9]{14})-[a-f0-9]{64}$/', $file->getFilename(), $match) && $match[1] < gmdate('YmdHis')) { unlink($file->getPathname()); }
    }
    file_put_contents(EQL_STAGING_ACCESS_TOKENS . '/' . $token, $identity['name'], LOCK_EX);
    chmod(EQL_STAGING_ACCESS_TOKENS . '/' . $token, 0600);
    eql_staging_access_render();
    setcookie('EQLStagingAccess', $token, ['expires'=>$expires, 'path'=>'/', 'secure'=>true, 'httponly'=>true, 'samesite'=>'Lax']);
    flock($operation, LOCK_UN);
    return $token;
}
