# EQL Talk Contributions

EQLTalkContributions separates talk contributions from subject-page editing.
It is an optional extension for MediaWiki 1.45 or newer; adding its source or
changing the sanitized settings example does not enable it on the live site.

With the configuration below, logged-out visitors and named users without a
confirmed email can edit or create talk pages. Subject pages require a named
account with confirmed email. The policy includes every MediaWiki talk namespace,
including Magelo Blue/Red talk (501/503), and their subpages.

## Activation

Deploy the reviewed extension files first. Then apply this block from
[`LocalSettings.example.php`](../../LocalSettings.example.php) to the relevant
ignored environment settings, replacing the previous `EmailConfirmToEdit`
assignment. Production configuration changes require owner approval.

```php
// Talk pages allow IP-attributed edits; all subject pages require a named account.
// The extension preserves confirmed-email editing and required signup email.
wfLoadExtension( 'EQLTalkContributions' );
$wgEmailConfirmToEdit = false;
$wgEQLTalkContributionsRequireConfirmedEmail = true;
$wgGroupPermissions['*']['edit'] = true;
$wgGroupPermissions['*']['createtalk'] = true;
$wgGroupPermissions['*']['createpage'] = false;
$wgGroupPermissions['user']['createpage'] = true;
$wgAutoCreateTempUser['enabled'] = false;
```

MediaWiki's global `edit` right is needed even for talk editing. The extension's
`getUserPermissionsErrors` hook denies subject-page edits and creation by unnamed
users. Temporary accounts are registered but unnamed, so they also cannot edit
subject pages. Disabling automatic temporary accounts retains ordinary IP
attribution for logged-out edits in page history and recent changes.

Core `EmailConfirmToEdit` must be false because it would otherwise reject
anonymous talk edits. `EQLTalkContributionsRequireConfirmedEmail` defaults to true
and applies email confirmation to subject-page editing. Its `AuthChangeFormFields`
hook preserves the required email field on the web signup form. Setting the
extension option to false allows named accounts to edit subject pages without
email confirmation; it does not allow anonymous subject edits. Login, password,
2FA, and wiki/forum account integration continue through their existing paths.

These hooks add denials and leave ordinary MediaWiki checks in place. Protected
talk pages, namespace restrictions, blocked contributors, restricted sessions,
CAPTCHA, and rate limits can still prevent a contribution. Core's existing
permission to edit one's own user talk page while blocked also remains subject
to the block's options. Talk editing is normal wiki editing, including existing
sections; this extension does not limit contributors to appending comments.

Standard mechanics are documented in MediaWiki's [user rights manual](https://www.mediawiki.org/wiki/Manual:User_rights),
[`getUserPermissionsErrors` hook](https://www.mediawiki.org/wiki/Manual:Hooks/getUserPermissionsErrors),
[`EmailConfirmToEdit` setting](https://www.mediawiki.org/wiki/Manual:$wgEmailConfirmToEdit),
and [temporary account configuration](https://www.mediawiki.org/wiki/Manual:$wgAutoCreateTempUser).

## Verification

Run the source-only policy suite from the repository root:

```powershell
php ops/test-talk-contributions.php
```

That suite uses synthetic title/user/configuration fixtures; it does not prove
live permissions, browser behavior, or saved revision attribution. For a
configured local wiki, prepare an ignored
`.local/talk-contributions/LocalSettings.php` overlay using local settings and the
activation block, then run the read-only permission integration check:

```powershell
php maintenance/run.php ./ops/test-talk-permissions.php --conf .local/talk-contributions/LocalSettings.php
```

The integration check should exercise core permissions without saving pages or
changing accounts, protections, or blocks. Record its actual observations and any
missing fixtures. Follow with local browser checks for anonymous talk editing and
new topics, denied subject editing, confirmed and unconfirmed account behavior,
Verify2Edit links, protected talk pages, and signup email validation. Verify IP
attribution using a synthetic local saved talk revision if that check is performed;
do not claim it from permission probes alone.

The staging login gate is separate and still requires a live administrator or
bureaucrat session. A staging browser session therefore cannot establish the
logged-out visitor flow; use an isolated local wiki for that check. File deployment
does not publish a database page or install private configuration. No schema or
content migration is required by this extension.

## Rollback

Keep a private copy of the previous environment settings. Restore the prior
`EmailConfirmToEdit`, group permissions, and temporary account settings **before**
unloading the extension or rolling back its files. In particular, never leave
anonymous `edit` enabled with core email confirmation disabled while the policy
hook is absent; that would permit anonymous subject edits. Then remove the
`wfLoadExtension` call and extension option, and verify the restored permissions.
