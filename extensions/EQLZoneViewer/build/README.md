# Rebuilding the browser bundles

The production ZIP already contains built files; cPanel installation does not require this directory.

To rebuild on a development computer:

```bash
cd build
npm install
./rebuild.sh
```

`rebuild.sh` installs the pinned upstream packages, applies the included `sage-core` overrides, and writes the two browser bundles to `../resources/dist/`.

The overrides are necessary because upstream `sage-core` normally writes generated files through the File System Access API and includes optional Draco export behavior. EQL Zone Viewer instead uses an in-memory filesystem and returns the generated GLB buffers to the application.
