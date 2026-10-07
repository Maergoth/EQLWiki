# EQLIconIndex 0.1.0

**Status in EQLWiki (October 7, 2026): inactive.** This older API/job-based approach
is commented out in the sanitized configuration and absent from the live enabled
extension inventory. The active finder uses the static JSON builder under
`ops/host-bin/` instead. See the [current component map](../../docs/EQL_COMPONENTS.md)
and [operations guide](../../PROJECT.md). The installation/patch scripts below
describe this optional historical implementation; they are not the current
EQLWiki setup procedure.

Server-side precomputed fingerprint cache for the existing EQL Icon Finder.

## Safety / continuity

- Does not replace the browser matcher.
- Does not build fingerprints during ordinary page views.
- If the server index is missing, stale, broken, or GD is unavailable, the current browser-side IndexedDB build remains the fallback.
- Editing `Icon List` only marks the cache stale and enqueues a background rebuild job.
- The rebuild is incremental: unchanged icon files reuse their existing fingerprints.
- Uploading/reuploading/deleting/undeleting a listed icon updates only that icon when possible.
- The cache lives beneath `$wgCacheDirectory` (outside `public_html` on the current EQL setup).
- A tiny `meta.json` is read for normal generation checks; the multi-megabyte fingerprint index is read only when a browser actually needs a new generation.
- No database schema changes.
- No LSPHP restart is required.

## Install

Extract as:

`/home/eqlwikdq/public_html/extensions/EQLIconIndex`

Then:

```bash
cd ~/public_html/extensions/EQLIconIndex
./install.sh
```

Build the first server index manually:

```bash
cd ~/public_html
php maintenance/run.php EQLIconIndex:rebuildIconIndex
```

Check status:

```bash
php maintenance/run.php EQLIconIndex:rebuildIconIndex --status
```

Patch the existing `MediaWiki:IconFinder.js` to prefer the server cache:

```bash
cd ~/public_html/extensions/EQLIconIndex
./patch-client-server-cache.sh
```

## Job behavior

`Icon List` save:
- stale marker is written immediately;
- one `eqlIconIndexRebuild` background job is queued;
- the job parses the current rendered media list;
- unchanged SHA-1s reuse fingerprints;
- additions/removals are reflected;
- a new immutable generation is written.

Listed file upload/reupload/delete/undelete:
- stale marker is written immediately;
- a one-file job is queued;
- the affected record is updated/removed;
- generation changes.

If jobs are delayed, the API reports the server index as unavailable and the browser implementation falls back automatically.

For testing a queued job manually:

```bash
php maintenance/run.php runJobs --type eqlIconIndexRebuild --maxjobs 1
```
