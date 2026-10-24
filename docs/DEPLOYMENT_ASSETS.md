# Deployment asset cache identity

Each application archive includes a generated, manifest-managed
`skins/EQLImmersive/deployment-version.txt` containing the full Git commit SHA.
It is not a tracked source file or a cache snapshot. The packager puts it **last**
in the manifest so the existing deployment receiver installs all application
files before activating their new public cache identity.

The EQLImmersive registration callback reads this marker before MediaWiki settings
become read-only. A standard configured `/load.php` endpoint becomes
`/eql-assets/<commit>/load.php`, retaining its existing origin and installation
prefix. Normal page navigation/reload then requests different stylesheet and
startup URLs after a release, even if the previous response is still cached.
The same SHA uses the same URLs when a deployment is retried.

The application `.htaccess` internally routes valid release paths to native
`load.php`, preserving the query. Old valid release paths remain routable to
the currently installed loader; they do not refer to disappearing generated PHP
files. The local router implements the same route. Malformed reserved paths
return 404. No private receiver installation or database-page publication is
required.

The loader base is deliberately query-free: native JavaScript module loading,
`mw.util.wikiScript('load')`, and Parsoid append their own query strings. Native
ResourceLoader `version` hashes, module/language/skin/user parameters, foreign
sources, response lifetimes and authentication remain unchanged. A missing or
invalid marker leaves the normal endpoint intact for development. Custom loader
filenames or endpoints with queries/fragments are preserved; a custom asset host
using the standard filename must route the release alias to the same wiki.

The supported `OutputPageCheckLastModified` hook includes the marker's activation
time when validating an HTML document. This prevents an ordinary reload from
receiving a stale 304 containing the previous stylesheet URLs. On the Linux host,
the later of marker mtime/ctime covers both a new copy and restoration of an older
backup. The existing receiver manages the marker with application backups; normal
multi-file deployment/rollback retains its documented non-atomic limitations.
No global parser-cache flush, article edit or fake revision is needed.

An already open document keeps its loaded resources until navigation/reload.
This mechanism does not force reloads or discard in-progress user work. Separately
edited wiki interface pages retain normal MediaWiki invalidation/expiry behavior.

## Verification

Run `python3 ops/test-release-assets.py` and
`php ops/test-deployment-assets.php`. CI also builds the full release archive.
Check that the marker is last in its manifest, the emitted stylesheet/startup
URLs use the release path, and stylesheet plus dynamic-script requests succeed
through that path. Test a normal reload from a previously cached page, not only a
hard refresh. Existing and older valid aliases should both work; malformed aliases
should return 404. Distinguish synthetic checks from an actual server rewrite test.

The integration uses the standard [LoadScript setting](https://www.mediawiki.org/wiki/Manual:$wgLoadScript),
[extension registration callback](https://www.mediawiki.org/wiki/Manual:Extension.json/Schema#callback),
[OutputPageCheckLastModified hook](https://www.mediawiki.org/wiki/Manual:Hooks/OutputPageCheckLastModified)
and [ResourceLoader](https://www.mediawiki.org/wiki/ResourceLoader).
