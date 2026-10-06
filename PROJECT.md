# EQLWiki development and deployment

This repository captures the live eqlwiki.com application as of October 6, 2026:
MediaWiki 1.45.3, its bundled dependencies, extensions, skins, custom maintenance
scripts, and the SMF forum. Bundled vendor files are intentionally committed to
reproduce the installed release; dependency upgrades should be reviewed separately.

## Daily workflow

1. Pull `main`, then create a feature branch: `git switch -c feature/my-change`.
2. Develop and test locally. Commit the changes and push the feature branch.
3. Open a pull request into `main`. The check workflow lints changed PHP and shell
   files and verifies release packaging.
4. Merge the pull request. Every push to `main` deploys to production over SSH.
5. Check the **Deploy EQLWiki** workflow for its API health check and backup path.

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

Run `powershell -ExecutionPolicy Bypass -File ops/start-local.ps1` from this folder.
The site is at http://127.0.0.1:8080 and its private MariaDB instance is bound to
127.0.0.1:3308. Paths and credentials are recorded in the ignored
`ops/local-runtime.json`; keep that file private.
An additional PHP process on 127.0.0.1:8081 handles internal wiki/forum bridge and
REST requests, so the Windows development server does not wait on its own request.

The local configuration disables email, production CAPTCHA requests, and automatic
jobs, and uses local databases and cache paths. The production database snapshot
contains private account data; keep the local database and migration backup private.
Stop the site and database with `ops/stop-local.ps1`.

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
databases, login cookies, bridge secrets, cache directories, and password gate.
Outgoing wiki/forum email, automatic wiki jobs, and CAPTCHA challenges are
disabled. Staging is excluded from indexing. Production cron jobs remain separate.
Server-side forum bridge calls authenticate through the password gate.

Deployment keys are scoped to GitHub environments. `production` accepts only
`main`; `staging` accepts `staging` and `main` (for the manual refresh workflow).
Each host SSH key has a forced command and cannot run arbitrary shell commands.
Pull-request checks receive no deployment secrets.

Server staging operations are installed at `/home/eqlwikdq/deploy/EQLWiki-staging`:
`staging-command.sh`, `staging-refresh.php`, `staging-prepare.php`, and
`deploy-receive.sh`, with `staging.htaccess` providing the HTTPS redirect and
password gate. Changes to these operational scripts must be reviewed and
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
