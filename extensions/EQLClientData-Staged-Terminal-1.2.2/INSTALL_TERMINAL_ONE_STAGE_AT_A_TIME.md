# EQLClientData staged terminal rollout — cPanel-compatible 1.2.2

This package is designed for the EQL Wiki cPanel/LiteSpeed host.

## Compatibility change from 1.1.x

The installer and rollback scripts no longer use Python at all. They use:

- Bash
- PHP CLI
- curl
- ordinary Unix file commands
- Node only if it happens to be installed, and only for an optional JavaScript syntax check

Version 1.2.2 includes the MediaWiki 1.45 categorylinks schema fix, a classification-equivalence fix that preserves the original era-filter category semantics, server/client era-cache namespace bumps, and automatic LiteSpeed PHP-worker refresh after configuration changes.

The scripts keep the wiki online. They prepare and validate replacement files before moving them into place and do not perform a whole-wiki purge. After any `LocalSettings.php` change they also touch `~/.lsphp_restart.txt` and warm one request so LiteSpeed does not keep serving stale PHP-worker configuration.

---

## 0. Install the package itself

Upload `EQLClientData-Staged-Terminal-1.2.2.zip` to:

```text
/home/eqlwikdq/public_html/extensions/
```

Then run:

```bash
cd ~/public_html/extensions
rm -rf EQLClientData-Staged-Terminal-1.2.2
unzip -q EQLClientData-Staged-Terminal-1.2.2.zip
chmod +x ~/public_html/extensions/EQLClientData-Staged-Terminal-1.2.2/terminal/*.sh
```

You do **not** need to `cd` into the package for later commands. The commands below use the full path so they work regardless of your current terminal directory.

Run the compatibility check:

```bash
bash ~/public_html/extensions/EQLClientData-Staged-Terminal-1.2.2/terminal/preflight.sh
```

Then inspect current state:

```bash
bash ~/public_html/extensions/EQLClientData-Staged-Terminal-1.2.2/terminal/show-current-status.sh
```

A previous failed Stage 00 attempt that created only a backup is harmless. The new scripts create a new rollback copy before making any live change.

---

# Stage 00 — prevent global cache invalidation from routine LocalSettings edits

## Apply

```bash
bash ~/public_html/extensions/EQLClientData-Staged-Terminal-1.2.2/terminal/apply-stage-00.sh
```

Expected change:

```php
$wgInvalidateCacheOnLocalSettingsChange = false;
```

## Test

Open:

```text
https://eqlwiki.com/Main_Page
https://eqlwiki.com/Special:Version
```

Normal browsing should work exactly as before.

## Roll back Stage 00

Only do this **before Stage 01 is applied**:

```bash
bash ~/public_html/extensions/EQLClientData-Staged-Terminal-1.2.2/terminal/rollback-stage-00.sh
```

This may restore `$wgInvalidateCacheOnLocalSettingsChange = true;` because that is the literal pre-Stage-00 state.

---


---

# If Stage 01 was already installed from package 1.2.0

Package 1.2.0 used the pre-1.45 `categorylinks.cl_to` field. MediaWiki 1.45 removed that field in favor of `categorylinks.cl_target_id` joined to `linktarget`. If `action=eqlmetadata` returns `internal_api_error_DBQueryError`, do **not** reapply Stage 01 and do not roll it back. Run the repair once:

```bash
bash ~/public_html/extensions/EQLClientData-Staged-Terminal-1.2.2/terminal/repair-stage-01-mediawiki145.sh
```

The repair:

- backs up only the currently installed Stage 01 server files;
- replaces the outdated category query;
- restarts only this account's LiteSpeed PHP workers;
- verifies `EQLClientData` is loaded;
- calls `action=eqlmetadata`;
- automatically restores the pre-repair files if verification fails.

It deliberately does **not** create another `stage-01-*` backup, so the original pre-Stage-01 rollback point remains authoritative.

# If Stage 01 is already active and Necklace of Superiority is incorrectly shown in-era

Do **not** roll Stage 01 back first. Package 1.2.2 includes a targeted correctness repair that preserves the batching optimization while restoring the old category-matching semantics. Run:

```bash
bash ~/public_html/extensions/EQLClientData-Staged-Terminal-1.2.2/terminal/repair-stage-01-era-classification.sh
```

The repair verifies two known pages before it stays installed:

- `Necklace of Superiority` must be **out-of-era**.
- `Green Silken Drape` must be **in-era**.

It also bumps both the WAN cache namespace and browser cache namespace so incorrect cached classifications from earlier Stage 01 builds are ignored immediately. If either known-page test fails, the repair automatically restores the pre-repair files.

# Stage 01 — era-filter request refactor

This is the highest-value request-reduction stage. The era filter still defaults to **On** and keeps On / Outline / Hide / Off.

## Apply

```bash
bash ~/public_html/extensions/EQLClientData-Staged-Terminal-1.2.2/terminal/apply-stage-01.sh
```

The script will:

- install `extensions/EQLClientData/`;
- replace only `era-filter.js`;
- add the staged EQLClientData configuration to `LocalSettings.php`;
- verify MediaWiki loaded the extension;
- test the new `action=eqlmetadata` API endpoint;
- purge only Main Page.

## Test

Hard-refresh a link-heavy page such as `Monk`.

Confirm:

1. Era filter defaults to **On**.
2. On, Outline, Hide, and Off all work.
3. Browser DevTools → Network → filter `api.php`.
4. You see `action=eqlmetadata` for era metadata.
5. You no longer see the old large fan-out of `prop=categories` requests for every batch of links.
6. On `Enchanter`, confirm `Necklace of Superiority` is marked out-of-era.
7. Confirm a known Classic item such as `Green Silken Drape` remains in-era.
8. Browse several pages normally before continuing.

## Roll back Stage 01

Only do this **before Stage 02 is applied**:

```bash
bash ~/public_html/extensions/EQLClientData-Staged-Terminal-1.2.2/terminal/rollback-stage-01.sh
```

It restores the old era-filter file, restores LocalSettings, and returns `extensions/EQLClientData` to whatever state it had before Stage 01.

---

# Stage 02 — VerifiedPages optimization

This removes repeated full reads of `VerifiedPages` during normal page navigation while preserving the existing verified-page behavior.

## Apply

```bash
bash ~/public_html/extensions/EQLClientData-Staged-Terminal-1.2.2/terminal/apply-stage-02.sh
```

## Test

1. Open a known verified article: no verification banner.
2. Open a known unverified article: banner appears.
3. In Network, confirm normal page navigation no longer fetches the entire `VerifiedPages` revision.
4. Verify one test page and reload it; the state should update promptly.
5. Open a Special page and ensure the normal article-verification banner does not appear.

## Roll back Stage 02

Only do this **before Stage 03 is applied**:

```bash
bash ~/public_html/extensions/EQLClientData-Staged-Terminal-1.2.2/terminal/rollback-stage-02.sh
```

---

# Stage 03 — SpellLevelSlider override optimization

This removes the normal browser-side read of `SpellLevelSliderOverrides` while keeping overrides editable through the wiki.

## Apply

```bash
bash ~/public_html/extensions/EQLClientData-Staged-Terminal-1.2.2/terminal/apply-stage-03.sh
```

## Test

1. Open a spell page with a known override.
2. Confirm the level slider still works.
3. Confirm spell classification still works.
4. In Network, confirm normal loading no longer fetches `SpellLevelSliderOverrides`.
5. Edit one test override, save it, then reload the affected spell page and confirm the change appears.
6. Check one class/guide page that contains lazy spell content.

## Roll back Stage 03

Only do this **before Stage 04 is applied**:

```bash
bash ~/public_html/extensions/EQLClientData-Staged-Terminal-1.2.2/terminal/rollback-stage-03.sh
```

---

# Stage 04 — ResourceLoader module split

This is intentionally last because it changes how EQLImmersive loads page-specific JavaScript and CSS.

## Apply

```bash
bash ~/public_html/extensions/EQLClientData-Staged-Terminal-1.2.2/terminal/apply-stage-04.sh
```

## Test

Hard-refresh and test at least:

```text
Main_Page
Special:Search
Special:RecentChanges
Special:Upload
Class_Guides
one Magelo page
one mob page
one faction page
one spell page
one item page with an effect
one merchant page selling spells
one verified article
one unverified article
```

Also check the browser console for:

```text
unknown ResourceLoader module
missing module
404 resource
JavaScript exception
```

## Roll back Stage 04

```bash
bash ~/public_html/extensions/EQLClientData-Staged-Terminal-1.2.2/terminal/rollback-stage-04.sh
```

Stage 03 and earlier remain installed.

---

# Check status at any time

```bash
bash ~/public_html/extensions/EQLClientData-Staged-Terminal-1.2.2/terminal/show-current-status.sh
```

Backups are stored under:

```text
~/eqlclientdata-stage-backups/
```

Every individual rollback also creates a `pre-rollback-stage-*` rescue copy, so the rollback itself has a recovery point.

---

# Roll back everything at once

## Recommended emergency rollback

This restores Stages 04 → 01 but intentionally keeps:

```php
$wgInvalidateCacheOnLocalSettingsChange = false;
```

Run:

```bash
bash ~/public_html/extensions/EQLClientData-Staged-Terminal-1.2.2/terminal/rollback-all.sh
```

It will show the backup chain and ask you to type:

```text
ROLLBACK
```

## Literal rollback including Stage 00

To restore the exact pre-rollout LocalSettings state as well:

```bash
bash ~/public_html/extensions/EQLClientData-Staged-Terminal-1.2.2/terminal/rollback-all.sh --including-stage-00
```

This can restore the old global cache-invalidation behavior.

For a non-interactive emergency rollback, add `--yes`:

```bash
bash ~/public_html/extensions/EQLClientData-Staged-Terminal-1.2.2/terminal/rollback-all.sh --yes
```

---

# Important rollback rule

Individual stage rollbacks must be performed in reverse order. For example, if Stage 04 is installed, `rollback-stage-03.sh` will refuse to run until Stage 04 is rolled back. This prevents a LocalSettings backup from an earlier stage from creating a mismatched hybrid deployment.

---

# What was validated before packaging

The package was tested against a simulated copy of the EQL MediaWiki filesystem with:

1. Stage 00 → 04 applied in order.
2. Stage 04 → 00 rolled back individually.
3. All stages reapplied.
4. `rollback-all.sh` tested while retaining Stage 00.
5. All stages reapplied again.
6. `rollback-all.sh --including-stage-00` tested for an exact LocalSettings restore.
7. All shell scripts checked with `bash -n`.
8. All supplied PHP checked with `php -l`.
9. JSON manifests parsed with PHP.
10. Installer source scanned to confirm there is no Python dependency.
