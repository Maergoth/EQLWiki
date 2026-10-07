# Rebuilding the browser bundles

The production ZIP already contains built files; cPanel installation does not require this directory.

Authored sources are `src/viewer.js`, `src/parser-worker.js`, and
`src/navigation-worker.js`. Edit these sources, not generated/minified bundles.
To rebuild on a development computer with Node and Bash available, start in the
repository root and run:

```bash
cd extensions/EQLZoneViewer/build
npm ci
./rebuild.sh
```

`rebuild.sh` applies the included
`sage-core` overrides and writes three bundles to `../resources/dist/`:
`ZoneViewerApp.js`, `zone-parser.worker.js`, and `navigation.worker.js`.
It installs dependencies itself only if `node_modules` is absent; `npm ci` uses
the committed lockfile. Review both source and generated output in the PR.

When releasing a version change, keep the extension manifest/VERSION and
[`../src/ViewerMarkup.php`](../src/ViewerMarkup.php) versioned viewer/worker URLs
consistent so browser caches do not mix incompatible bundles. See the
[root development guide](../../../docs/EQL_DEVELOPMENT.md)
for environment and verification expectations.

The overrides are necessary because upstream `sage-core` normally writes generated files through the File System Access API and includes optional Draco export behavior. EQL Zone Viewer instead uses an in-memory filesystem and returns the generated GLB buffers to the application.
