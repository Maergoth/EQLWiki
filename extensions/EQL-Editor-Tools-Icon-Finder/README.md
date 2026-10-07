# EQL editor tools and Icon Finder

These are reviewed sources for database-backed interface scripts, not a
`wfLoadExtension()` extension. See the [component map](../../docs/EQL_COMPONENTS.md)
and [development guide](../../docs/EQL_DEVELOPMENT.md) for the complete workflow.

| Source | Database execution |
| --- | --- |
| `MediaWiki_BlueprintLoader.js` | `MediaWiki:BlueprintLoader.js` |
| `MediaWiki_IconFinder.js` | `MediaWiki:IconFinder.js` |
| `CommonJS_loader_snippet.js` | Only the editor-loader section of `MediaWiki:Common.js` |

Deploying filesystem files alone does not publish these database pages. Never
replace the complete Common.js page with the loader snippet.

BlueprintLoader runs during source edit/submit. A new truly empty page gets a
blueprint selector sourced from sections/`<pre>` blocks in `Help:Contents`.
Existing source pages retain Icon Finder without the blueprint selector.
The finder is loaded only when opened; catalog requests wait until image paste.

## Matching and results

The current finder uses algorithm 6: a 64-bit difference hash and 16×16 RGB grid,
with centered crop variants. It ranks 100 candidates and renders **12 per batch**.
Source/result previews are 80×80. Click an icon to copy its ID; the corner copy
menu offers ID, template parameter, raw file wikicode, 32×32 wikicode, and scaled
wikicode. Selecting an exact-artwork alias updates both the filename and copied
ID. Legacy spell letter IDs are supported. The finder never edits the article.

The preferred cache is `/static/eql-icon-index/meta.json` followed by immutable
generation index JSON; browser IndexedDB caches the decoded fingerprints. The
host generator and upload-signature refresh live under `ops/host-bin/` and are
installed privately by the owner. This workflow does not require the disabled
older EQLIconIndex extension. Errors retain the browser-build fallback, whose
catalog includes all uploaded item/spell files plus Icon List media.

In the static server index, exact pixel/dimension duplicates share a search
record, but every filename/ID remains an alias. The lightweight Icon List catalog retains every filename and
pages through at most 100 lazy-loaded images independently of the finder index.
The browser-build fallback can retain separate records for repeated artwork.
Do not drop aliases, rename existing icons, or merge solely on perceptual similarity.
Background/UI differences in a screenshot can still affect matching.

## Publishing and checks

Use `ops/sync-icon-finder.php` through the target wiki's maintenance runner with
the reviewed source, expected page revision, and a fresh private backup path.
BlueprintLoader publication needs a separate reviewed database-page update.
Owner approval is required before production publication. Already open browser
pages can retain loaded scripts; use a hard refresh when verifying a new release.

From the repository root:

```sh
node --check extensions/EQL-Editor-Tools-Icon-Finder/MediaWiki_IconFinder.js
node ops/test-icon-finder.cjs
node ops/test-icon-finder-ui.cjs
```

The UI test requires jsdom; the metadata test does not. See the development guide
for dependency setup, cache/library checks, and database-page synchronization.
