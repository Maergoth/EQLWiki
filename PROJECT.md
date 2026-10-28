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

GitHub Actions reads the actual installed commit through the private receiver's
`status` command, packages only changed tracked application files plus the
generated deployment asset marker, and streams it to the cPanel hosting account.
A complete manifest tracks removals; a null/unavailable baseline uses a full
repair bundle. It uses a dedicated restricted SSH key;
the key can invoke only `/home/eqlwikdq/deploy/EQLWiki/deploy-receive.sh` with a
full commit SHA, or read deployment status. It cannot start an interactive shell
or use SFTP.

The receiver validates protected paths and custom PHP syntax, saves the previous
managed files that will change, updates each changed file by rename, removes
previously managed files that were deleted from Git, and checks the public
MediaWiki API. On failure it restores
the backup and prior manifest/revision state. It refuses a delta whose base no
longer matches the installed revision. A multi-file release is not globally
atomic; use a maintenance window for changes that require all files to switch
simultaneously.

The packager installs the asset marker last. The skin registration callback uses
its commit SHA in a query-free `/eql-assets/<commit>/load.php` path; the tracked
rewrite rule preserves native ResourceLoader behavior. Normal reloads obtain new
stylesheet URLs without a hard refresh. The marker also participates in HTML
Last-Modified validation and existing application backups. See
[deployment asset caching](docs/DEPLOYMENT_ASSETS.md) for the route, rollback,
custom-endpoint limitations and verification contract.

Interactive Character sheets also require two separately published database
templates. Deploy the reviewed skin module first; review current template IDs,
make a fresh private backup and use the fixed-target publisher only with owner
approval. `ops/` is not deployed. See [the publication/recovery procedure and
unsaved capability checks](docs/CHARACTER_SHEETS.md). Skin deployment alone does
not activate the new talk-page template contract.

Deployments never run database schema migrations. Plan those separately with a
database backup and a compatible MediaWiki/extension upgrade procedure.

Server deployment state and backups:

```
/home/eqlwikdq/deploy/EQLWiki/current-sha
/home/eqlwikdq/deploy/EQLWiki/manifest
/home/eqlwikdq/deploy/EQLWiki/backups/<timestamp>-<commit>/plan.json
/home/eqlwikdq/deploy/EQLWiki/backups/<timestamp>-<commit>/files/
```

Use `ops/rollback.sh <backup-folder-name>` through the local SSH key to restore
the immediately preceding release. Incremental backups must be walked in reverse
order; rollback refuses a backup for a different current release. Receiver updates
are operational changes: review and install `ops/deploy-receive.sh` and its private
`deploy-files.php` helper explicitly; a normal release does not replace its own
receiver. Install the reviewed receiver/helper and staging companion scripts
before enabling the new workflow. See [incremental deployment](docs/INCREMENTAL_DEPLOYMENT.md)
for installation order, full repair, staging transformations and checks.
Legacy full backups retain `files.tar.gz` and are supported by the reviewed
rollback tool; new backups contain only touched files under `files/` plus their
recovery plan and previous deployment state.

Repository secrets: `CPANEL_DEPLOY_KEY`, `CPANEL_KNOWN_HOSTS`.
Keep the host fingerprint pinned; verify any host-key change through cPanel or
the hosting provider before replacing it.

## Dependency cache maintenance

Production web requests do not execute the background queue (`JobRunRate = 0`).
The owner-installed `ops/host-bin/eqlwiki-run-jobs.sh` is invoked by the existing
minute cron. Native HTML invalidation jobs advance dependent pages' freshness
after template/File changes; link-refresh jobs may parse pages and enqueue more
work. An edited page's own core invalidation is a separate path. A large queue
can therefore leave dependent pages stale even when browser responses require
revalidation. Do not compensate with a whole-wiki purge or periodic rebuild.

The reviewed wrapper prioritizes `htmlCacheUpdate` (at most 100 jobs/10 seconds),
then gives `refreshLinks` and `refreshLinksDynamic` ten jobs/five seconds each,
and all other native work five jobs/five seconds. These are job limits, not page
counts or a recovery ETA: native recursive jobs can fan out. One inherited flock
covers every phase and child process. Native throttling remains enabled, memory
is 192 MiB, CPU priority is nice 15 and idle I/O priority is used when available.
Per-phase hard deadlines total 38 seconds; the whole invocation has a 48-second
deadline and a two-second kill grace. No additional overlapping worker is needed.

The wrapper validates native JSON results, including failed jobs, exceptions,
memory stops and timeouts; shell exit zero alone is insufficient. It writes an
atomic private `eql-jobrunner-status.json` and at most 64 KiB of sanitized history
under `$HOME/private-cache/mediawiki`. Status includes actual phase counts,
termination reason and elapsed time, without job titles, parameters or native
error text. Interrupted work reports unknown counts. A successful bounded pass
does not mean the queue is empty; native backoff can report no ready jobs.

`ops/` is excluded from normal application releases. Install only the reviewed
canonical Git blob separately at `$HOME/bin/eqlwiki-run-jobs.sh`, retaining the
current cron line. Before atomic replacement, verify the current installed hash,
deployment and cron entry; hold the shared deploy lock and exclusive existing
runner lock; save a fresh private, fsynced backup. Preserve owner/executable mode,
verify syntax and installed bytes, then observe one bounded run or cron result.
Use read-only queue counts/oldest age plus phase status to assess catch-up;
do not start repeated manual drains. Roll back only to the verified backup if
the new wrapper fails. Source push alone is not proof of installation or queue
progress. Standard queue mechanics are documented in MediaWiki's
[job queue manual](https://www.mediawiki.org/wiki/Manual:Job_queue).

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

`skins/EQLImmersive/resources/sky-rewards.js` is the reviewed Plane of Sky section of
`MediaWiki:Common.js`. Reward field identities use item page titles and exclude
hover descriptions and item-level stats. Existing numbers migrate from matching
old account/browser field keys or the original browser-only key; old keys remain
intact. Unrecognizable old hashed identities cannot be reconstructed after their
source text has already changed. Established account buckets never import browser
values; first-login migration still follows the existing EQLUserState policy.

GitHub releases exclude `ops`, so merging this change does not itself replace
Common.js. Copy `ops/sync-sky-rewards.php` and the reviewed section to a private
host directory, then use the target wiki's maintenance runner with `--source`,
`--expected-revision` (its current Common.js revision) and `--backup` (a fresh
private file). The helper refuses concurrent edits, saves a recovery copy, and
replaces only this section, preserving all other Common.js code. Apply to staging
and verify its raw script first; production requires separate owner approval.
Staging deployment and refresh commands reapply the reviewed reward section from the deployed skin file. Install the reviewed staging command wrapper and sync helper privately before enabling this operation; these host operations remain excluded from application archives.

To run regression checks against a private Common.js snapshot, install pinned
`jsdom@27.0.0` in an ignored local test directory, put its `node_modules` directory
on `NODE_PATH`, and run `node ops/test-sky-rewards.cjs <Common.js snapshot>`.
The test uses the actual EQLUserState service and simulated API:Options responses
to cover tooltip changes, legacy migration, a fresh DOM with persisted storage,
zero/cleared numbers, and independent anonymous/account buckets.

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

## Unified data refresh policy

Production wiki pages and revisions are authoritative. Staging and Eye of Zomm
are separate consumers of that source; the desktop never pulls a private SQL
database, and its checks do not start exports on the host. Private database dumps,
accounts, settings, and uploads must never be published to the dataset branch.

| Consumer | Automatic eligibility checks | Expensive work | Manual behavior |
| --- | --- | --- | --- |
| Eye of Zomm public dataset | Host cron at minute 17 every hour, UTC | Build from production only when the last successful GitHub publication is at least 24 hours old | Default wrapper invocation forces a build/publication; `--local-only` forces a build/stage without publication |
| Staging wiki/forum copy | GitHub Actions at minute 47 every hour, UTC, and before staging deployments | Replace staging DBs when the last successful replacement is at least 24 hours old | Explicit **Refresh staging data** dispatch uses `force`; scheduled runs use `if-stale` |
| Eye of Zomm desktop cache | Startup and hourly while the app is running | Check the small public manifest, download only a changed dataset, and validate before replacing the cache | **Sync with Wiki** requests a check immediately; it does not rebuild the source dataset |

Hourly eligibility checks avoid the roughly 48-hour gap that can occur when a
once-daily job tests a 24-hour threshold measured from yesterday's completion.
They do not mean hourly database replacements or full exports. Healthy automatic
rebuilds are normally 24–25 hours apart, plus work time and scheduler delay; this
is not a strict publication-time guarantee. The two host consumers use separate
locks and success markers, and do not claim one atomic snapshot of the wiki,
forum, and desktop data. Keep their start times offset to reduce coincident load.

`ops/host-bin` files are private host sources, excluded from application releases.
After review, install `eoz-pack-refresh.sh` into `/home/eqlwikdq/bin` separately,
preserving executable permissions. Replace only the existing Eye of Zomm cron
entry with the following rule; preserve every unrelated host job:

```cron
17 * * * * /bin/bash /home/eqlwikdq/bin/eoz-pack-refresh.sh --if-stale >> /home/eqlwikdq/private-cache/mediawiki/eoz-pack-refresh.log 2>&1
```

Under the existing `eoz-pack.lock`, the wrapper reads the private plain epoch
`eoz-pack-last-success`. It atomically advances that marker **only after** the
validated pack has been staged and `eoz-sync-github.sh` has succeeded. Local-only,
failed, skipped, and overlapping runs never advance it. Missing, malformed, or
future markers cause a rebuild rather than suppressing refreshes. Never infer
publication from the staged manifest's age: a successful local-only build or
failed Git push can leave an unpublished staged pack. When introducing the marker
after a separately audited manual publication, seed it only after verifying the
public manifest and pack hash against the reviewed pair; otherwise leave it absent.

Failed builds/validation do not publish, and failed synchronization leaves the
previous public dataset available. Keep the old success time so the next hourly
check retries. Desktop download/validation failures retain the last usable cache.
The dedicated force-pushed `Maergoth/EQL-EOZ` dataset branch contains only the
filtered public manifest and compressed pack, independently of application deploys.

The scheduled staging workflow must also be promoted as an isolated reviewed
change to default branch `main`: [GitHub runs scheduled workflows only from the
default branch](https://docs.github.com/en/actions/reference/workflows-and-actions/events-that-trigger-workflows#schedule).
Merging the workflow into `staging` alone does not activate its schedule. Scheduled
and manual refreshes retain the same `eqlwiki-staging` concurrency group as staging
deploys. Do not use forced scheduled refreshes to bypass a failed replacement;
review its private log and recover explicitly. Source changes and host installation
are separate operations and must be verified separately.

The refresh wrapper validates the completed filtered JSON in a separate PHP CLI
process, with a bounded `768M` allowance before decoding and errors on stderr.
MediaWiki's own memory allowance does not carry into that process; the host CLI
default can be `128M`. Validator code is passed through stdin rather than a
multiline `-r` argument, because cPanel's PHP selector stats command arguments as
candidate filenames. Failed validation keeps the previously published pack and
does not invoke the publisher/syncer. `EQL_PHP_BIN` remains an optional override.
`bash ops/test-eoz-refresh-wrapper.sh` exercises the actual wrapper with synthetic
files, a low-memory selector fixture, and stub publisher/syncer commands; it does
not contact MediaWiki/GitHub or establish that a host copy has been installed.

The application-side producer is `maintenance/BuildEyeOfZommPack.php` with
`maintenance/zone-catalog.json` and `maintenance/ItemLootSourceCounts.php`; the
loot helper also uses `extensions/DynamicZoneList/NpcLootWikitext.php` from the
same reviewed application release. Eye of Zomm retains a matching source copy in
its `server/` directory. Review the
[companion desktop integration changes](https://github.com/Maergoth/EQL-EOZ/tree/codex/wiki-map-integration-audit)
when updating this contract. `ALL except` class restrictions export an explicit
playable-class complement; `NONE` is distinct from unknown `[]`. Exact wiki zone
aliases retain the viewer's existing canonical names, and NPC locations retain
displayed `Y, X, Z` order. These are JSON-content changes, with no SQL migration
or wiki-page edits.

Explicit recognized NPC template zones take precedence over categories, retain
all listed zones, and fall back to categories/title suffixes only when unresolved.
When rebuilding after alias changes, check conflicting categories and multi-zone
pages as well as blank-zone recovery; record multiplicity can change legitimately.

The producer also discovers Raid Instance sections from categorized zone pages,
preserving normal/raid NPC and item-source variants with public revision
provenance. Shared public coordinates are withheld from derived raid copies.
The presence-only `wikiEntities` index covers NPC/item candidates, template name
aliases and main-namespace page/redirect titles; only full exports mark it
complete. It contains no account data or private namespaces. After deployment,
verify King Tranix is raid-only, Fire Giant Warrior retains both variants, the
entrance Voidling stays normal, and a known skipped item remains in the presence
index. Check actual refreshed production counts rather than local snapshot totals.
The `kerraridge` alias resolves the Legends map/log stem to Kerra Island without
renaming the canonical viewer entry.

A staging merge does not install production producer files or publish corrected
records. After owner-approved promotion of the reviewed producer/catalog, the
next scheduled rebuild (or separately authorized manual rebuild) publishes the
filtered dataset. Keep host-script installation separate from application file
deployment. Verify the resulting manifest version, SHA-256, and aggregate output
before reporting the new data available to clients. Keep database snapshots,
settings, generated packs, and other private data outside the source PR.

`php ops/test-eoz-exporter.php` runs synthetic source-only contract checks without
bootstrapping MediaWiki, contacting a database, writing a pack, or publishing data.
It does not substitute for checking an approved rebuilt dataset.

Global classification metadata uses `meta.lootSourceCountsVersion = 1` and the
top-level `lootSourceCounts` catalog, including statless and missing item titles;
rich item records mirror its count/completeness/canonical-NPC fields. Verify
the published gzip contains these fields after rebuilding, along with public
NPC redirect aliases. `meta.lootSetsVersion = 1` identifies the additive
`lootSets` map: each canonical set title has explicit canonical `items` and a
`complete` flag. Public top-level `Gear Set` members supply membership; NPC set
references contribute each member's canonical mob once. Verify that the rebuilt
Mistress of Scorn set source covers its members without duplicating item rows.
Reviewed metadata badges and independently verified static legacy item hovers
cannot make the whole count catalog incomplete, while unknown/dynamic wrappers
still do. Check both complete small counts and confirmed Common lower bounds.
No new wiki API, schema or host wrapper is needed. A
manual invocation of the already installed `eoz-pack-refresh.sh` without
`--if-stale` forces a build under its existing lock; `--local-only` does not
publish to GitHub. Source deployment and the later filtered dataset publication
must be verified separately.

## Staging and contributions

`main` deploys production; `staging` deploys https://test.eqlwiki.com. Forks open
PRs against `staging`. CODEOWNERS names @Maergoth for every file, and staging
requires an owner approval, resolved review threads, and the `syntax` check.
Only @Maergoth can update `main`. Both branches reject force pushes and deletion.
The owner can directly update staging for administration and can promote tested
changes to main. Never automatically merge staging into production.

Each staging push and scheduled hourly check requests `refresh if-stale`, unless
the last successful database refresh was less than 24 hours ago. The manual
**Refresh staging data** GitHub Actions workflow explicitly uses `force` and
bypasses the time limit. A refresh replaces the entire staging
wiki and forum databases, including any staging-only edits or accounts. Snapshots
use separate `mysqldump --single-transaction` snapshots; production databases are read only during
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
only by staging's ignored `EQLStaging.php`; the source `ops/staging-login-policy.php`
is excluded from every deployment archive. Production and local development do
not load it. Server operations must be installed privately by the owner.

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
`main`; `staging` accepts `staging` and `main` (for manual/scheduled refreshes).
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
refresh failures also block deployments and bypassing the failure via the hourly
skip check. A forced retry keeps the original recovery dumps until it succeeds.
Previous staging
database dumps are retained at `refresh/wiki-previous.sql` and
`refresh/forum-previous.sql`; only the latest recovery point is kept. Access
credentials and SQL dumps are stored outside document roots and outside Git.
The staging `last-refresh` epoch advances after databases, isolated settings,
missing-file copying, and API health succeed. The command wrapper then separately
reapplies the reviewed Sky Common.js section. A failure in that final script sync
fails the workflow without rolling back the successful database timestamp; a later
`if-stale` call still retries the script sync even if the database copy is skipped.
