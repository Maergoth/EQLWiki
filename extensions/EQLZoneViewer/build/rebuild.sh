#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"

if [ ! -d node_modules ]; then
  npm install
fi

cp -f overrides/sage-core/lib/util/fileHandler.js node_modules/sage-core/lib/util/fileHandler.js
cp -f overrides/sage-core/lib/s3d/s3d-decoder.js node_modules/sage-core/lib/s3d/s3d-decoder.js
cp -f overrides/sage-core/lib/eqg/gltf-export/v3.js node_modules/sage-core/lib/eqg/gltf-export/v3.js

mkdir -p ../resources/dist
npx esbuild src/viewer.js \
  --bundle --format=esm --platform=browser --target=es2020 --minify \
  --outfile=../resources/dist/ZoneViewerApp.js

npx esbuild src/parser-worker.js \
  --bundle --format=iife --platform=browser --target=es2020 --minify \
  --external:node:fs --external:node:path \
  --define:import.meta.env.DEV=false \
  --define:import.meta.env.VITE_LOCAL_DEV='"false"' \
  --outfile=../resources/dist/zone-parser.worker.js

npx esbuild src/navigation-worker.js \
  --bundle --format=iife --platform=browser --target=es2020 --minify \
  --outfile=../resources/dist/navigation.worker.js

