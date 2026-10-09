# EQLWiki development and deployment

This repository captures the live eqlwiki.com application as of October 6, 2026:
MediaWiki 1.45.3, its bundled dependencies, extensions, skins, custom maintenance
scripts, and the SMF forum. Bundled vendor files are intentionally committed to
reproduce the installed release; dependency upgrades should be reviewed separately.

## Daily workflow

1. Pull `staging`, then create a feature branch: `git switch -c feature/my-change`.
2. Develop and test locally. Commit the changes and push the feature branch.
3. Open a pull request into `staging`. The check workflow lints changed PHP and shell
   files and verifies release packaging.
4. The owner reviews and merges the pull request. Staging deploys to the test wiki.
5. After testing, the owner separately approves promotion of the reviewed changes
   to `main`. Every main push deploys to production over SSH, including docs changes.
6. Check the **Deploy EQLWiki** workflow for its API health check and backup path.

For contributor onboarding and component ownership, start with [README.md](README.md),
[CONTRIBUTING.md](CONTRIBUTING.md), [the development guide](docs/EQL_DEVELOPMENT.md),
and [the custom component map](docs/EQL_COMPONENTS.md). Never promote the entire
staging branch to production; it contains environment-specific operational work.

The deployment workflow can also be rerun manually on `main` from Actions.
Avoid editing production application code in File Manager after this import;
the next deployment overwrites managed files. Commit emergency fixes to Git too.

## What stays outside Git

Pages, revisions, users, preferences, and forum posts are in databases, not this
repository. Uploaded images, forum attachments, avatars, generated `eql-static`
assets, and caches remain on the host. Refresh local data using a private database
export and file download; deployment never copies the local database to production.

`LocalSettings.php`, `bb/Settings.php`, and `BridgeSecrets.php` are ignored.
Complete sanitized templates are provided alongside them. Changes to site settings
must be reviewed in the template and applied separately to the relevant ignored
settings file. Never replace production settings with local development settings.

The optional [`EQLTalkContributions`](extensions/EQLTalkContributions/README.md)
policy also requires separate activation in ignored settings; it is not enabled
on production by publishing source or the sanitized example. Deploy the reviewed
files before applying its exact settings block with owner approval. Verify local
permissions and the anonymous browser flow first: staging's independent live-login
gate remains in place. For rollback, restore the previous email/group/temporary
account restrictions before unloading the extension or restoring older files,
so its absence cannot open anonymous subject-page editing. No database-page
publication or schema migration is required.

The four wiki/forum bridge scripts and the forum theme read their existing shared keys from
`BridgeSecrets.php`. Each wiki key must match the corresponding forum key.
Production secrets remain on the server. Local development uses separate keys.

Host-generated `php.ini`, `.user.ini`, logs, backups, and archive files are ignored.
The original cPanel `.htaccess` is versioned because it contains live routing rules.
The built-in local PHP server uses `ops/local-router.php` instead.

## Deployment implementation

GitHub Actions creates an archive containing only tracked application files and
streams it to the cPanel hosting account. It uses a dedicated restricted SSH key;
the key can invoke only `/home/eqlwikdq/deploy/EQLWiki/deploy-receive.sh` with a
full commit SHA. It cannot start an interactive shell or use SFTP.

The receiver validates protected paths and custom PHP syntax, saves the previous
managed files, updates each file by rename, removes previously managed files that
were deleted from Git, and checks the public MediaWiki API. On failure it restores
the backup. A multi-file release is not globally atomic; use a maintenance window
for changes that require all files to switch simultaneously.

Deployments never run database schema migrations. Plan those separately with a
database backup and a compatible MediaWiki/extension upgrade procedure.

Server deployment state and backups:

```
/home/eqlwikdq/deploy/EQLWiki/current-sha
/home/eqlwikdq/deploy/EQLWiki/manifest
/home/eqlwikdq/deploy/EQLWiki/backups/<timestamp>-<commit>/files.tar.gz
```

Use `ops/rollback.sh <backup-folder-name>` through the local SSH key to restore
a previous release. Receiver updates are operational changes: review and copy
`ops/deploy-receive.sh` to its server location explicitly; a normal release does
not replace its own receiver.

Repository secrets: `CPANEL_DEPLOY_KEY`, `CPANEL_KNOWN_HOSTS`.
Keep the host fingerprint pinned; verify any host-key change through cPanel or
the hosting provider before replacing it.

## Local Windows development

For the already configured private Windows installation, run
`powershell -ExecutionPolicy Bypass -File ops/start-local.ps1` from this folder.
This is a launcher, not a fresh-clone installer. It requires the ignored runtime
configuration, local database/tool files, and private local site settings; see
[the development guide](docs/EQL_DEVELOPMENT.md) for fresh-clone prerequisites.
The site is at http://127.0.0.1:8080 and its private MariaDB instance is bound to
127.0.0.1:3308. Tool paths and ports are recorded in the ignored
`ops/local-runtime.json`; database and bridge credentials belong in the private
database/site configuration files. Keep these files private.
An additional PHP process on 127.0.0.1:8081 handles internal wiki/forum bridge and
REST requests, so the Windows development server does not wait on its own request.

The local configuration disables email, production CAPTCHA requests, and automatic
jobs, and uses local databases and cache paths. The production database snapshot
contains private account data; keep the local database and migration backup private.
Stop the site and database with `ops/stop-local.ps1`.

All local install files now live beneath this checkout. `.local/runtime` holds
the private database, connection settings and process logs; `.local/tools/mariadb`
holds MariaDB; `.local/recovery` holds the previous install and migration recovery
files. These directories are ignored by Git, excluded from releases and blocked
by the local HTTP router. `ops/local-runtime.json` accepts paths relative to this
checkout. The supplied game icon library is retained privately in `eql_icons`.

### Full game icon library

Run `python ops/audit-icon-library.py eql_icons --output .local/icons/audit`
to compare game IDs against current uploaded files. This requires Pillow. The
audit writes a full manifest, missing-only import directory and conflict CSV.
Compare pixels as well as file hashes: encoding or artwork differences do not
necessarily indicate a wrong ID. Never renumber existing files speculatively.

Import missing icons with MediaWiki's `importImages` maintenance command, without
`--overwrite` or `--skip-dupes`: every game ID must retain its own file. The initial
library import preserves existing canonical uploads; differing supplied versions
remain only in the local source library. Only previously missing IDs are uploaded,
always as `Item_<ID>.png`. Existing names, bytes and references remain unchanged.
The audit and import manifests remain under `.local/icons` for review.

The active finder cache is built by `ops/host-bin/eql-icon-static-builder.php`,
with `eql-icon-catalog.php` beside it. Install both reviewed files into the host's
private `bin` directory explicitly. It indexes all uploaded `Item_<ID>.png`
files plus the existing Icon List media, so the display page's
range does not limit search. Schema 3 groups exact decoded pixels, retains every
filename as an alias, and fingerprints each unique image once in browser search.
The two-minute cron checks upload hashes even when Icon List is unchanged.
It also publishes a small `catalog-<generation>.json` without fingerprints.
`Icon List` uses this catalog to render at most 100 lazy-loaded images per page,
with an exact ID search and separate item/spell libraries. Every filename is
listed, including aliases grouped by the finder. The API is a metadata-only
fallback when the display catalog is unavailable.
Deploy the page from `ops/icon-list.wiki` using `ops/sync-icon-list.php`, with the
reviewed revision ID and a fresh private backup path. Load the skin module before
publishing this page content. Enumerating uploaded spell files preserves legacy
spell icons independently of the page markup.
For staging, the same cache wrapper supports `EQL_ICON_WIKI_ROOT`,
`EQL_ICON_PRIVATE_DIR`, `EQL_ICON_PUBLIC_DIR`, `EQL_ICON_LOCK`, and `EQL_ICON_LOG`;
use separate paths and locks for each environment.
Generation files are immutable and the latest three remain available.

The live JavaScript is the database page `MediaWiki:IconFinder.js`. Updating the
versioned copy alone does not update that page. Use `ops/sync-icon-finder.php`
through the maintenance runner with `--source`, `--expected-revision` and a fresh
private `--backup` path; it refuses to overwrite a concurrently edited revision.
Test on staging before applying the same source and cache builder to production.
For a local build, set `EQL_ICON_PRIVATE_DIR` and `EQL_ICON_PUBLIC_DIR` to private
state and `static/eql-icon-index` paths respectively. Run custom scripts as
`php maintenance/run.php ./ops/host-bin/eql-icon-static-builder.php` on Windows.

### Plane of Sky reward fields

`skins/EQLImmersive/resources/sky-rewards.js` is the reviewed Plane of Sky
section of `MediaWiki:Common.js`. Reward identities use item page titles instead
of hover descriptions, item levels, or stats. Recognizable old account/browser
field keys and the original browser-only keys migrate without deleting old values.
Previously lost hashed identities cannot be reconstructed when their original
text is no longer identifiable. Existing account buckets remain independent of
anonymous state; first-login migration follows the existing EQLUserState policy.

The resource file is deployed with the application, but Common.js is stored in
the database. After an approved production deployment, run the reviewed private
copy of `ops/sync-sky-rewards.php` through that wiki's maintenance runner with
`--source` pointing to the deployed resource, `--expected-revision` set to the
reviewed Common.js revision, and `--backup` pointing to a fresh private file.
The helper saves a recovery copy and replaces only the reward section, refusing
concurrent edits. Verify both raw Common.js and ResourceLoader's site module.
Staging's separately installed command wrapper reapplies the section after its
deployments and database refreshes. Production script updates require owner approval.

For regression checks, install `jsdom@27.0.0` in an ignored test directory, put
its `node_modules` directory on `NODE_PATH`, and run
`node ops/test-sky-rewards.cjs <private Common.js snapshot>` against the original
reward section. Tests exercise the actual EQLUserState service with controlled
API:Options responses, including tooltip changes, legacy migration, fresh DOM
sessions with persisted storage, zero/cleared numbers, and account isolation.

## Weapon damage bonus defaults

[`ops/itembox.wiki`](ops/itembox.wiki) is the reviewed `Template:Itembox` source,
starting from live revision 157242. It generates missing damage bonuses at
character level 50 using the Dual Wield calculator's working model and floor
display rounding, while retaining explicit values and the existing Backstab
default. The template uses listed base weapon stats; item ranks do not alter
the generated value. No item articles need to be edited or backfilled.
ItemLevelSlider displays generated and existing plain-text bonuses between DMG
and Ratio. This placement runs from the slider's Git files; it does not require
another template publication once these defaults are installed.

Application deployment does not publish this database template. After owner
approval, reread the current template revision, review any intervening changes,
and run the reviewed private copy of `ops/sync-itembox.php` through the target
wiki's maintenance runner with `--source` set to the reviewed wikitext,
`--expected-revision` set to that freshly reviewed revision ID, and `--backup`
set to a new file in an existing private directory outside the web root. Local
development can use the router-blocked `.local/` directory. The helper targets
only Itembox, refuses concurrent edits, and saves a recovery copy before writing.
Inspect `--help` first; do not run this publishing helper as a routine test.

Use `php maintenance/run.php ./ops/test-itembox.php` for read-only parser checks:
the harness supplies an unsaved template revision in memory. After approved
publication, verify weapon articles and hovers, explicit overrides, and Rogue
Backstab. If rollback is needed, reread the current revision, save a fresh
private copy, and restore the original wikitext with the wiki's
[source editor and edit-conflict protection](https://www.mediawiki.org/wiki/Help:Edit_conflict).
The publishing helper validates the new damage-bonus contracts and does not
accept the original template as rollback input. A full staging database refresh
replaces staging-only template changes; republish the reviewed template after refresh.

## Existing scheduled jobs

`ops/host-bin` records the current host scripts for review. They are not copied to
`public_html` by deployment. The weekly Eye of Zomm job remains:

```
/bin/bash /home/eqlwikdq/bin/eoz-pack-refresh.sh >> /home/eqlwikdq/private-cache/mediawiki/eoz-pack-refresh.log 2>&1
```

That job publishes its dataset to the separate `Maergoth/EQL-EOZ` repository.
It remains independent of application deployment. Review and copy host-bin changes
to `/home/eqlwikdq/bin` deliberately, preserving executable permissions.

## Staging and contributions

`main` deploys production; `staging` deploys https://test.eqlwiki.com. Forks open
PRs against `staging`. CODEOWNERS names @Maergoth for every file, and staging
requires an owner approval, resolved review threads, and the `syntax` check.
Only @Maergoth can update `main`. Both branches reject force pushes and deletion.
The owner can directly update staging for administration and can promote tested
changes to main. Never automatically merge staging into production.

Each staging push runs a refresh before deployment, unless the last successful
refresh was less than 24 hours ago. The manual **Refresh staging data** GitHub
Actions workflow bypasses the time limit. A refresh replaces the entire staging
wiki and forum databases, including any staging-only edits or accounts. Snapshots
use `mysqldump --single-transaction`; production databases are read only during
this operation. Uploaded files are independent copies: missing files are copied,
existing staging files are kept, and production deletions are not propagated.
Replaced production images can therefore remain older on staging.

Staging uses a separate document root, SQL user restricted to two staging
databases, login cookies, bridge secrets, cache directories, and access gate.
Outgoing wiki/forum email, automatic wiki jobs, and CAPTCHA challenges are
disabled. Staging is excluded from indexing. Production cron jobs remain separate.
Server-side forum bridge calls authenticate through the password gate.

Administrators enter staging with their existing live wiki login. The access page
verifies their live MediaWiki session through the production session bridge, then
checks current live groups: `sysop` (administrator) or `bureaucrat`.
Ordinary users, bots, and blocked users cannot enter. Passwords and two-factor
authentication remain on the live wiki; staging does not request or verify them.
The matching staging user is signed in automatically, and missing staging users
are provisioned. Elevated staging groups are synchronized from the live identity.
This works even when the staging database snapshot is older than the live account.

Host-only, Secure, HttpOnly access cookies last one hour. A minute cron expires
their web-server allow-list entries and rechecks the live session and roles.
Logging out of production, losing these roles, or becoming blocked revokes staging
access at the next check (normally within a minute plus the server cache delay).
The verification cookie is kept in private, mode-0600 server token files, outside
Git and the web root, and deleted when access expires or is revoked.
LiteSpeed briefly caches access rules, so the
sign-in page waits 12 seconds before opening staging. Existing production jobs
are preserved. Refresh/deployment locks also protect access-rule updates.
The private Basic Auth account remains for deployment health checks and internal
forum bridge calls; wiki administrators do not need its shared password.
Normal staging wiki/API/forum password logins and registration routes redirect
to the live-login gate or reject authentication requests. This policy is loaded
only by staging's ignored `EQLStaging.php`; its source is excluded from every
deployment archive. Production and local development do not load it.

Current staging-specific operational sources are maintained on the `staging`
branch: [command wrapper](https://github.com/Maergoth/EQLWiki/blob/staging/ops/staging-command.sh),
[release preparation](https://github.com/Maergoth/EQLWiki/blob/staging/ops/staging-prepare.php),
[live-session cleanup](https://github.com/Maergoth/EQLWiki/blob/staging/ops/staging-access-cleanup.php),
[login policy](https://github.com/Maergoth/EQLWiki/blob/staging/ops/staging-login-policy.php),
and [Sky script synchronization](https://github.com/Maergoth/EQLWiki/blob/staging/ops/staging-sync-sky-rewards.php).
The `ops/` copies on `main` can lag these changes; use the reviewed staging sources
for staging host maintenance. These scripts must still be installed privately.

Staging's optional [Talk contribution policy](extensions/EQLTalkContributions/README.md)
uses the reviewed activation block in the private, mode-0600 file
`/home/eqlwikdq/deploy/EQLWiki-staging/talk-contributions-settings.php`.
The refresh generator appends a conditional include at the end of staging
`LocalSettings.php`, after its existing isolation settings; an absent policy file
leaves the prior behavior unchanged. This retains activation through subsequent
database refreshes without changing the independent live-login gate. Staging
keeps email delivery/authentication disabled, so accounts with valid email do
not exercise production's confirmation-timestamp requirement; verify that
boundary separately with isolated fixtures.

Deploy the extension before activation. Separately install the reviewed
`staging-refresh.php` host copy, policy file, and matching include in the current
ignored staging settings under `deploy.lock`, with no `refresh-in-progress` marker.
Take fresh private backups of the current settings and host generator first;
application deployment does not install these private files. Verify staging
permissions and credentialed API health after activation. For rollback, restore
the prior rights/settings before disabling the policy hook, following the
[extension rollback guide](extensions/EQLTalkContributions/README.md#rollback),
and restore the generator/include if required. Keep staging login and isolation
overrides intact; these changes do not apply to production settings.

Deployment keys are scoped to GitHub environments. `production` accepts only
`main`; `staging` accepts `staging` and `main` (for the manual refresh workflow).
Each host SSH key has a forced command and cannot run arbitrary shell commands.
Pull-request checks receive no deployment secrets.

Server staging operations are installed at `/home/eqlwikdq/deploy/EQLWiki-staging`:
`staging-command.sh`, `staging-refresh.php`, `staging-prepare.php`, and
`deploy-receive.sh`, with `staging.htaccess` providing the HTTPS redirect and
access gate. The `access-handler.php`, `staging-access-lib.php`, and
`staging-access-cleanup.php` files provide the live-login flow and token expiry.
Changes to these operational scripts must be reviewed and
installed by the owner over administrator SSH; application deploys do not install
them. `staging-prepare.php` substitutes production URLs in the custom bridge,
theme, logout, image-upload, and skin files in the staging release only. It also
isolates logout cookie clearing and preserves password protection. Extend that
explicit file list when introducing other custom files with absolute site URLs.

During database replacement staging returns HTTP 403. A failed refresh leaves
staging in maintenance mode and does not advance its timestamp. Review the private
`refresh.log`, fix the problem, then rerun the manual refresh. Previous staging
refresh failures also block deployments and bypassing the failure via the daily
skip check. A forced retry keeps the original recovery dumps until it succeeds.
Previous staging
database dumps are retained at `refresh/wiki-previous.sql` and
`refresh/forum-previous.sql`; only the latest recovery point is kept. Access
credentials and SQL dumps are stored outside document roots and outside Git.
