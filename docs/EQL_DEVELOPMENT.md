# Developing EQLWiki

Start with [contributing](../CONTRIBUTING.md) and use the
[component map](EQL_COMPONENTS.md) to locate the feature you are changing.
[PROJECT.md](../PROJECT.md) covers host operations and recovery in more detail.

## Choose the environment you need

| Environment | Suitable work | What a clone does not supply |
| --- | --- | --- |
| Source-only checkout | PHP/JS syntax, source review, dependency-free regressions, synthetic UI tests | Wiki pages, uploaded assets, accounts, a running database |
| New local wiki with synthetic content | Extension/skin development and isolated feature reproduction | Production templates, Lua modules, interface scripts, custom configuration pages, forum content |
| Owner-prepared local snapshot | Tests close to the installed wiki | Permission to redistribute its private account/database contents |
| [Staging](https://test.eqlwiki.com) | Browser and integration tests against a recent production database copy | General contributor access; entry requires a live wiki admin/bureaucrat session |
| [Production](https://eqlwiki.com) | Owner-approved releases and read-only reproduction | Authorization for unreviewed code, data, login, or configuration changes |

The repository contains the installed MediaWiki application and bundled vendor
files, not a content export or a turnkey development container. There is no
tracked fresh-clone bootstrap or public production-data fixture. Production
settings, databases, uploads, browser state, and host-generated caches are separate.

## Fresh-clone setup

1. Install PHP and MySQL/MariaDB suitable for this MediaWiki release. Start with
   the official [installation guide](https://www.mediawiki.org/wiki/Manual:Installation_guide)
   and the checked-in [`INSTALL`](../INSTALL) and
   [`composer.json`](../composer.json). This checkout requires PHP 8.2 or newer.
   The custom forum bridge uses `mysqli`; a core-only SQLite installation does
   not reproduce that integration. Icon fingerprint generation requires GD.
2. Use local-only databases, secrets, mail settings, and URLs. The sanitized
   [`LocalSettings.example.php`](../LocalSettings.example.php),
   [`BridgeSecrets.example.php`](../BridgeSecrets.example.php), and
   [`bb/Settings.example.php`](../bb/Settings.example.php) explain the installation,
   but contain production paths/URLs and are **not drop-in local settings**.
   Configure your own ignored real settings. The forum example uses SQLite
   caching; provide SQLite3 or choose a reviewed local cache alternative.
3. For a new wiki, use the official installer and enable the required EQL
   extensions/skin after configuring local credentials. Keep Vector loaded:
   EQLImmersive depends on its services and modules. Do not enable every directory
   under `extensions/`; see the active/inactive inventory in the component map.
4. Create minimal test pages for the component's markup and configuration
   contracts. Full integration tests need compatible database templates, Lua
   modules, category membership, and `MediaWiki:` pages. Ask the owner for a
   suitable sanitized content export if synthetic pages are insufficient.
   Never put a private snapshot in a PR or public artifact.
5. If forum behavior is in scope, create/configure a separate local SMF database
   and local bridge secrets. Otherwise clearly state that forum integration has
   not been tested. A clean clone cannot sign in using production accounts.
6. Disable outgoing email, production CAPTCHA calls, and automatic production
   jobs in local settings. Use local cookie names/domains and local service URLs.
   Check custom files for absolute live URLs before assuming complete isolation.

Bundled dependencies are committed to reproduce the imported installation.
Do not run a blanket `composer update` as a setup shortcut. Dependency upgrades
need a separate review. There is no root `package.json` and no project-wide
`npm install` step; Node is used by focused tests and the Zone Viewer build.

Normal installation, configuration, database import/backup, and upgrades belong
in the [MediaWiki manual](https://www.mediawiki.org/wiki/Manual:Contents),
[configuration reference](https://www.mediawiki.org/wiki/Manual:Configuration_settings),
[backup guide](https://www.mediawiki.org/wiki/Manual:Backing_up_a_wiki), and
[upgrade guide](https://www.mediawiki.org/wiki/Manual:Upgrading).

## Existing Windows installation

The owner-prepared workspace keeps its private runtime beneath `.local/`.
[`ops/start-local.ps1`](../ops/start-local.ps1) and
[`ops/stop-local.ps1`](../ops/stop-local.ps1) launch/stop an existing installation;
they do not download MariaDB, initialize databases, create site settings, or
import content.

Required inputs include PHP on `PATH`, initialized MariaDB data, database
server/client configs, a writable log directory, local site/bridge/forum settings,
and ignored `ops/local-runtime.json`. This illustrative runtime schema shows the
field names consumed by the scripts; create the tools/configs first and substitute
your actual paths:

```json
{
  "databaseExecutable": ".local/tools/mariadb/bin/mariadbd.exe",
  "databaseConfig": ".local/runtime/my.ini",
  "clientExecutable": ".local/tools/mariadb/bin/mariadb.exe",
  "clientConfig": ".local/runtime/client.cnf",
  "logDirectory": ".local/runtime",
  "databasePort": 3308,
  "httpPort": 8080,
  "backendHttpPort": 8081
}
```

Paths can be relative to the checkout. Keep credentials inside private configs,
not this JSON example or a committed file.

```powershell
powershell -ExecutionPolicy Bypass -File ops/start-local.ps1
# Develop at http://127.0.0.1:8080 with the usual configuration.
powershell -ExecutionPolicy Bypass -File ops/stop-local.ps1
```

The usual database listens on `127.0.0.1:3308`. A second PHP server at
`127.0.0.1:8081` handles internal bridge/REST requests. `EQL_WIKI_URL` directs
forum session requests to that server; a single Windows PHP server can otherwise
deadlock when it calls back into itself. Preserve this distinction when changing
local URLs or process setup.

[`ops/local-router.php`](../ops/local-router.php) implements short wiki URLs,
forum routing, REST paths, and private-directory blocking. It does not execute
Apache `.htaccess` rules. HTTPS redirects, forum route restrictions, and cPanel
behavior therefore need staging verification. Private fixtures under `.local/`
cannot be served by this router; keep any temporary public test fixture synthetic
and remove it from public routes after verification.

## Find the source that actually runs

| Change | Source of execution | Required release step |
| --- | --- | --- |
| Registered skin/extension PHP, JS, CSS | Git files plus module/hook registration | Normal application deployment; confirm the module is loaded on the target page |
| Icon Finder | Database `MediaWiki:IconFinder.js` | Deploy reviewed file, then guarded [`sync-icon-finder.php`](../ops/sync-icon-finder.php) |
| Blueprint tools | Database `MediaWiki:BlueprintLoader.js` | Separately publish the reviewed filesystem source with a revision check and recovery copy |
| Plane of Sky reward fields | A section of database `MediaWiki:Common.js` | [`sync-sky-rewards.php`](../ops/sync-sky-rewards.php), replacing only that section |
| Icon List container | Database `Icon List` | [`sync-icon-list.php`](../ops/sync-icon-list.php) plus the skin listing module |
| Other Common.js/CSS or ClassGuideDropdown behavior | Database interface pages | Review and publish the affected page; no complete canonical Git mirror currently exists |
| Template/Lua/category/verification/era data | Wiki database | An explicit content change/export/import, separate from file deployment |
| Host cache builders, cron wrappers, staging policies, deployment receiver | Owner-installed private host files | Separate installation; `ops/` is excluded from application releases |
| Ignored settings or secrets | Environment-specific files | Separate owner-reviewed local/staging/production configuration |

For database changes, read the current page revision immediately before applying
the change, save a fresh private backup, and refuse concurrent edits. The sync
helpers require `--source`, `--expected-revision`, and `--backup`; inspect each
helper's `--help` and [PROJECT.md](../PROJECT.md) before use. Never replace the
entire Common.js page with `sky-rewards.js`.

For browser changes, inspect ResourceLoader registration, conditional hooks, and
rendered template markers. A source file existing in Git does not mean it executes.
Use [`skin.json`](../skins/EQLImmersive/skin.json), extension registration, the
network/module inspector, and the actual database script to verify ownership.

## Checks by component

Run the checks relevant to your change and report results and limitations.
CI currently lints changed PHP/shell files and checks release packaging;
it does **not** automatically run JavaScript syntax or custom regression tests.

| Check | Command from repository root | Prerequisites / coverage |
| --- | --- | --- |
| JS syntax | `node --check path/to/changed.js` | Node; syntax only |
| PHP syntax | `php -l path/to/changed.php` | PHP; syntax only |
| Shell syntax | `bash -n path/to/changed.sh` | Bash; syntax only |
| Whitespace | `git diff --check` | Git |
| Icon metadata | `node ops/test-icon-finder.cjs` | No third-party package; IDs, parameters, aliases |
| Icon result UI | `node ops/test-icon-finder-ui.cjs` | jsdom; 12-result batches, 100 reachable candidates, selected-alias copying, flyout formats/dismissal |
| Era controls | `node ops/test-era-controls.cjs` | No third-party package; excludes editor and page navigation controls |
| Sky/account state | `node ops/test-sky-rewards.cjs .local/Common.js` | jsdom and a reviewed Common.js snapshot containing the real state service |
| Exact-artwork catalog | `php ops/test-icon-catalog.php` | GD and private `eql_icons/500.png`, `3470.png`, `3471.png` |
| Full icon coverage | `php ops/verify-icon-coverage.php <library> <index-json> <audit-manifest>` | GD and the private source library/current index/audit |
| Search integration | `python ops/check-search.py http://127.0.0.1:8080` | A running populated wiki with the expected item/title fixtures; use `--help` for staging options |
| Release archive | `python ops/build-release.py --output .local/release-review.tar.gz` | Python; create `.local/` first. Tracked files only, so stage new files before checking inclusion |

To run jsdom tests, install the pinned dependency in an ignored directory using
a Node version supported by jsdom 27:

```powershell
npm install --prefix .local/test-runtime --no-save --package-lock=false jsdom@27.0.0
$env:NODE_PATH = (Resolve-Path .local/test-runtime/node_modules).Path
node ops/test-icon-finder-ui.cjs
```

On a Unix shell, use `NODE_PATH="$PWD/.local/test-runtime/node_modules"` for the
test process. A reviewed public raw Common.js snapshot may be used privately for
the Sky regression; a full private database is not needed for that fixture.

UI smoke checks should match the component: source editor and VisualEditor;
partial/mixed-case search; item/spell/merchant hovers; dynamic sections after
`wikipage.content`; era modes without disabling editor controls; anonymous and
account persistence; mobile menu/flyout containment; and the relevant page-specific
view. Zone Viewer changes need supported browsers and local game files; use its
[build guide](../extensions/EQLZoneViewer/build/README.md) and
[validation notes](../extensions/EQLZoneViewer/docs/VALIDATION_1.15.0.txt).

## Staging and release quirks

- Contributions target `staging`; the owner separately approves `main` releases.
  Promote specific reviewed changes, not the entire staging branch.
- Every push to either deployment branch runs a file deployment, even docs-only
  pushes. The receiver validates, backs up managed files, and checks the wiki API.
  It does not apply database schema migrations or publish arbitrary wiki pages.
- A staging push copies the entire live wiki/forum database when the previous
  successful refresh is at least 24 hours old. Manual refresh bypasses the limit.
  This is a full replacement, not a database diff: staging-only DB edits disappear.
- Only missing uploads/attachments and related mutable assets are copied.
  Existing staging files stay; replacements can remain older and production
  deletions do not propagate. Compare the relevant image bytes when reproducing
  an asset bug.
- Staging isolates databases, cookies, bridge secrets, caches and login policy,
  disables outbound mail/CAPTCHA/automatic jobs, and checks a live admin session.
  The access policy is loaded from ignored `EQLStaging.php`, not shared production
  code. Do not copy it into normal login code.
- Staging preparation rewrites an explicit list of production URLs. Local and
  new custom files can still contain live links or fetch live hover data. Check
  the browser network requests and [`staging-prepare.php`](../ops/staging-prepare.php)
  before claiming an integration test is completely isolated.
- Staging deployment/refresh reapplies the reviewed Sky Common.js section. It
  does not automatically publish Icon Finder or Icon List database pages.
- Host-script changes are installed separately. A receiver rollback restores
  managed application files, not arbitrary DB changes; [`rollback.sh`](../ops/rollback.sh)
  currently targets production. Keep separate recovery copies for data edits.

## Maintenance scripts are not routine tests

The [component map](EQL_COMPONENTS.md) lists historical data-repair and dataset
tools. Read flags and defaults before executing them. Most repair scripts offer
dry runs, but [`backfill_smf_accounts.php`](../backfill_smf_accounts.php) writes
unless `--dry-run` is supplied. Its output can contain private account information.
`migrateItemPages.php` uses `--save` for writes and defaults to a preview directory
outside the checkout; explicitly choose `.local/` for previews.

Normal platform testing and maintenance mechanics are documented upstream:
[extension development](https://www.mediawiki.org/wiki/Manual:Developing_extensions),
[hooks](https://www.mediawiki.org/wiki/Manual:Hooks),
[ResourceLoader](https://www.mediawiki.org/wiki/ResourceLoader),
[Action API](https://www.mediawiki.org/wiki/API:Main_page), and
[maintenance scripts](https://www.mediawiki.org/wiki/Manual:Maintenance_scripts).
