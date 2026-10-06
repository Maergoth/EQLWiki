import { inflate } from 'pako';
import { EQFileHandle } from 'sage-core/model/file-handle.js';
import { setGlobals } from 'sage-core/globals.js';
import {
  clearVirtualFS,
  listVirtualFiles,
  readVirtualFile,
  setVirtualRoot
} from 'sage-core/util/fileHandler.js';

globalThis.window = globalThis;
globalThis.imageProcessor = { parseImages: async () => {} };

function send(type, data = {}, transfer = []) {
  globalThis.postMessage({ type, ...data }, transfer);
}

function normalizeName(name) {
  return String(name || '').toLowerCase().replace(/\\/g, '/').split('/').pop();
}
function fileStem(name) { return normalizeName(name).replace(/\.[^.]+$/, ''); }
function readUInt32LE(buffer, offset) {
  return ((buffer[offset]) |
    (buffer[offset + 1] << 8) |
    (buffer[offset + 2] << 16) |
    (buffer[offset + 3] << 24)) >>> 0;
}

class MemoryFileFacade {
  constructor(file) {
    this.source = file;
    this.name = normalizeName(file.name);
    this.size = file.size;
    this.type = file.type;
    this.lastModified = file.lastModified;
  }
  async arrayBuffer() { return await this.source.arrayBuffer(); }
  async text() { return await this.source.text(); }
  slice(...args) { return this.source.slice(...args); }
}

class MemoryFileHandle {
  constructor(file) {
    this.kind = 'file';
    this.name = file.name;
    this.file = file;
  }
  async getFile() { return this.file; }
}

class MemoryDirectoryHandle {
  constructor(files) {
    this.kind = 'directory';
    this.name = 'EverQuest';
    this.files = new Map(files.map((f) => [normalizeName(f.name), f]));
  }
  async getFileHandle(name) {
    const file = this.files.get(normalizeName(name));
    if (!file) throw new DOMException(`File not found: ${name}`, 'NotFoundError');
    return new MemoryFileHandle(file);
  }
  async getDirectoryHandle() { return this; }
  async *entries() {
    for (const file of this.files.values()) yield [file.name, new MemoryFileHandle(file)];
  }
  async *values() {
    for (const file of this.files.values()) yield new MemoryFileHandle(file);
  }
}

setGlobals({
  root: '__eql_zone_viewer_cache__',
  GlobalStore: {
    actions: {
      setLoadingTitle: (text) => send('progress', { message: String(text || '') }),
      setLoadingText: (text) => send('progress', { message: String(text || '') })
    },
    getState: () => ({})
  },
  gameController: { rootFileSystemHandle: null },
  BABYLON: {},
  canvas: typeof OffscreenCanvas !== 'undefined' ? new OffscreenCanvas(1, 1) : null
});

globalThis.gameController = { rootFileSystemHandle: null };
globalThis.sageGlobals = {
  canvas: typeof OffscreenCanvas !== 'undefined' ? new OffscreenCanvas(1, 1) : null
};

function decodeJson(buffer) {
  if (!buffer) return null;
  try { return JSON.parse(new TextDecoder().decode(new Uint8Array(buffer))); }
  catch { return null; }
}

function chooseZoneFile(zone) {
  const expected = `zones/${zone.toLowerCase()}.glb`;
  if (readVirtualFile(expected)) return expected;
  const candidates = listVirtualFiles('zones/').filter((path) => path.endsWith('.glb'));
  const exact = candidates.find((path) => path.endsWith(`/${zone.toLowerCase()}.glb`));
  return exact || candidates[0] || null;
}

function cloneBuffer(buffer) {
  return buffer ? buffer.slice(0) : null;
}

function glbJson(buffer) {
  if (!buffer || buffer.byteLength < 20) return null;
  const view = new DataView(buffer);
  if (view.getUint32(0, true) !== 0x46546c67) return null;
  const chunkLength = view.getUint32(12, true);
  const chunkType = view.getUint32(16, true);
  if (chunkType !== 0x4e4f534a || 20 + chunkLength > buffer.byteLength) return null;
  try {
    const bytes = new Uint8Array(buffer, 20, chunkLength);
    return JSON.parse(new TextDecoder().decode(bytes).replace(/\0+$/g, '').trim());
  } catch {
    return null;
  }
}

function textureReferenceKeys(buffer, output = new Set()) {
  const json = glbJson(buffer);
  if (!json) return output;
  const add = value => {
    if (!value || typeof value !== 'string') return;
    const name = normalizeName(value);
    const stem = name.replace(/\.[^.]+$/, '').replace(/_mdf.*$/i, '');
    if (name) output.add(name);
    if (stem) output.add(stem);
    if (stem) output.add(stem.replace(/_[0-9]+$/, ''));
  };
  for (const image of json.images || []) { add(image.name); add(image.uri); }
  for (const texture of json.textures || []) add(texture.name);
  for (const material of json.materials || []) add(material.name);
  return output;
}

function objectAliases(name) {
  const raw = normalizeName(name).replace(/\.[^.]+$/, '').toLowerCase();
  const stripped = raw
    .replace(/(?:_)?actordef$/i, '')
    .replace(/(?:_)?actor$/i, '')
    .replace(/(?:_)?dmsprite$/i, '')
    .replace(/(?:_)?mesh$/i, '');
  const first = stripped.split('_')[0];
  const values = [
    raw,
    stripped,
    first,
    stripped.replace(/[_-]?\d+$/i, ''),
    first.replace(/[_-]?\d+$/i, ''),
    stripped.replace(/[^a-z0-9]/g, ''),
    stripped.replace(/\d+/g, '')
  ];
  return [...new Set(values.filter(value => value && value.length >= 2))];
}

function glbObjectAliases(path, buffer) {
  const aliases = new Set(objectAliases(path));
  const json = glbJson(buffer);
  if (!json) return aliases;
  const add = value => objectAliases(value).forEach(alias => aliases.add(alias));
  for (const scene of json.scenes || []) add(scene.name);
  for (const node of json.nodes || []) add(node.name);
  for (const mesh of json.meshes || []) add(mesh.name);
  return aliases;
}

function aliasesIntersect(left, right) {
  for (const value of left) if (right.has(value)) return true;
  return false;
}

function aliasesFuzzyMatch(left, right) {
  for (const a of left) {
    if (a.length < 3) continue;
    for (const b of right) {
      if (b.length < 3) continue;
      if (a === b || a.startsWith(`${b}_`) || b.startsWith(`${a}_`) ||
          (Math.min(a.length, b.length) >= 4 && (a.startsWith(b) || b.startsWith(a)))) return true;
    }
  }
  return false;
}

function selectObjectPaths(paths, metadata, objectIndex, maxObjects) {
  const requested = Object.keys(metadata?.objects || {});
  if (!requested.length) return { paths: [], unmatched: [] };

  const candidates = [];
  for (const path of paths) {
    const buffer = readVirtualFile(path);
    const aliases = glbObjectAliases(path, buffer);
    candidates.push({ path, aliases });
  }

  const indexAliases = Object.keys(objectIndex || {}).map(key => ({
    key,
    aliases: new Set(objectAliases(key))
  }));
  const selected = new Set();
  const unmatched = [];

  // Resolve every placed actor independently. This avoids the old behavior
  // where a global top-N cutoff could silently discard trees or shrubs merely
  // because their exported model sorted after hundreds of unrelated assets.
  for (const name of requested) {
    const requestedStem = fileStem(name).toLowerCase();
    const aliases = new Set(objectAliases(name));
    for (const indexEntry of indexAliases) {
      if (aliasesIntersect(indexEntry.aliases, aliases) || aliasesFuzzyMatch(indexEntry.aliases, aliases)) {
        for (const alias of indexEntry.aliases) aliases.add(alias);
      }
    }

    // Preserve distinct numbered foliage and prop variants. The previous
    // fuzzy-only resolver could map JNTREE101 through JNTREE105 to whichever
    // filename happened to be shortest, which made large outdoor zones lose
    // most of their intended tree shapes even though every placement resolved.
    let best = candidates.find(candidate => fileStem(candidate.path).toLowerCase() === requestedStem) || null;
    let bestScore = best ? 1000 : 0;
    if (!best) for (const candidate of candidates) {
      let score = 0;
      if (aliasesIntersect(candidate.aliases, aliases)) score = 100;
      else if (aliasesFuzzyMatch(candidate.aliases, aliases)) score = 50;
      if (!score) continue;
      score -= Math.min(20, normalizeName(candidate.path).length / 20);
      if (!best || score > bestScore || (score === bestScore && candidate.path.localeCompare(best.path) < 0)) {
        best = candidate;
        bestScore = score;
      }
    }
    if (best) selected.add(best.path);
    else unmatched.push(name);
    if (selected.size >= maxObjects) break;
  }

  // A few clients produce no usable actor names in either metadata or GLB
  // nodes. Preserve compatibility only when the complete generated set is
  // already small enough to transfer safely.
  if (!selected.size && paths.length <= maxObjects) {
    for (const path of paths) selected.add(path);
    unmatched.length = 0;
  }

  // If the safety cap was reached, report the remaining placed actor names as
  // unmatched rather than selecting arbitrary models that cannot be used.
  if (selected.size >= maxObjects) {
    for (const name of requested) {
      if (!unmatched.includes(name)) {
        const aliases = new Set(objectAliases(name));
        const represented = candidates.some(candidate => selected.has(candidate.path) &&
          (aliasesIntersect(candidate.aliases, aliases) || aliasesFuzzyMatch(candidate.aliases, aliases)));
        if (!represented) unmatched.push(name);
      }
    }
  }

  return { paths: [...selected].slice(0, maxObjects), unmatched };
}

function selectTexturePaths(paths, references) {
  if (!paths.length || !references.size || paths.length <= 300) return paths;
  const selected = [];
  for (const path of paths) {
    const name = normalizeName(path);
    const stem = name.replace(/\.[^.]+$/, '').toLowerCase();
    const base = stem.replace(/_[0-9]+$/, '');
    if (references.has(name) || references.has(stem) || references.has(base)) {
      selected.push(path);
      continue;
    }
    // Animated texture frames commonly append a numeric suffix to a material
    // name. Include those frames without retaining unrelated client textures.
    for (const ref of references) {
      if (ref.length >= 3 && (stem.startsWith(`${ref}_`) || ref.startsWith(`${base}_`))) {
        selected.push(path);
        break;
      }
    }
  }
  // Avoid a false-negative material-name convention causing a textureless
  // zone. Filtering is only used when it identifies a meaningful subset.
  return selected.length >= Math.min(12, paths.length) ? selected : paths;
}


async function readFileRange(file, start, end) {
  if (start < 0 || end < start || end > file.size) throw new Error('Requested PFS range is outside the archive.');
  return new Uint8Array(await file.slice(start, end).arrayBuffer());
}

async function inflatePfsEntryFromFile(file, offset, size) {
  const output = new Uint8Array(size);
  let sourcePosition = offset;
  let outputPosition = 0;
  while (outputPosition < size) {
    const header = await readFileRange(file, sourcePosition, sourcePosition + 8);
    const deflatedLength = readUInt32LE(header, 0);
    const inflatedLength = readUInt32LE(header, 4);
    if (!deflatedLength || !inflatedLength || outputPosition + inflatedLength > size) {
      throw new Error('Invalid PFS filename block length.');
    }
    const compressed = await readFileRange(file, sourcePosition + 8, sourcePosition + 8 + deflatedLength);
    const inflated = inflate(compressed);
    if (inflated.length !== inflatedLength) throw new Error('PFS filename block decompressed to an unexpected size.');
    output.set(inflated, outputPosition);
    outputPosition += inflatedLength;
    sourcePosition += 8 + deflatedLength;
  }
  return output;
}

async function listPfsFilenames(file) {
  if (file.size < 12) throw new Error('Archive is too small to be PFS/S3D/EQG.');
  const header = await readFileRange(file, 0, 12);
  const magic = String.fromCharCode(header[4], header[5], header[6], header[7]);
  if (magic !== 'PFS ') throw new Error('Archive does not have a PFS header.');
  const directoryOffset = readUInt32LE(header, 0);
  const countBytes = await readFileRange(file, directoryOffset, directoryOffset + 4);
  const directoryCount = readUInt32LE(countBytes, 0);
  const directory = await readFileRange(file, directoryOffset + 4, directoryOffset + 4 + directoryCount * 12);
  let filenameEntry = null;
  for (let i = 0; i < directoryCount; i++) {
    const entryOffset = i * 12;
    const crc = readUInt32LE(directory, entryOffset);
    if (crc === 0x61580ac9) {
      filenameEntry = {
        offset: readUInt32LE(directory, entryOffset + 4),
        size: readUInt32LE(directory, entryOffset + 8)
      };
      break;
    }
  }
  if (!filenameEntry) throw new Error('PFS filename directory is missing.');
  const data = await inflatePfsEntryFromFile(file, filenameEntry.offset, filenameEntry.size);
  let position = 0;
  if (data.length < 4) throw new Error('PFS filename directory is empty.');
  const count = readUInt32LE(data, position); position += 4;
  const names = [];
  for (let i = 0; i < count; i++) {
    if (position + 4 > data.length) throw new Error('PFS filename list is truncated.');
    const length = readUInt32LE(data, position); position += 4;
    if (!length || position + length > data.length) throw new Error('PFS filename entry has an invalid length.');
    const bytes = data.subarray(position, position + length - 1);
    position += length;
    names.push(new TextDecoder('windows-1252').decode(bytes).toLowerCase());
  }
  return names;
}

function archiveContainsZone(id, format, names) {
  const stem = id.toLowerCase();
  const normalized = names.map(normalizeName);
  if (format === 'S3D') {
    return normalized.includes(`${stem}.wld`);
  }
  const hasExactZon = normalized.includes(`${stem}.zon`);
  const hasAnyZon = normalized.some(name => name.endsWith('.zon'));
  const hasTerrain = normalized.some(name => name.endsWith('.ter'));
  const hasZoneData = normalized.some(name => name.endsWith('.dat') && !/^(water|floraexclusion|invw)\.dat$/i.test(name));
  return hasExactZon || hasAnyZon || (hasTerrain && hasZoneData);
}

async function scanArchives(id, candidates) {
  const zones = [];
  for (let index = 0; index < candidates.length; index++) {
    const candidate = candidates[index];
    const format = String(candidate.format || '').toUpperCase();
    try {
      const names = await listPfsFilenames(candidate.file);
      if (archiveContainsZone(candidate.id, format, names)) {
        zones.push({ id: candidate.id.toLowerCase(), format });
      }
    } catch (error) {
      console.warn(`[EQLZoneViewer] Skipping unreadable archive ${candidate.file?.name || candidate.id}`, error);
    }
    if (index % 5 === 0 || index === candidates.length - 1) {
      send('progress', {
        id,
        message: `Identifying zone archives ${index + 1}/${candidates.length}…`,
        value: 0.02 + 0.96 * (index + 1) / Math.max(1, candidates.length)
      });
    }
  }
  zones.sort((a, b) => a.id.localeCompare(b.id, undefined, { numeric: true }) || (a.format === 'S3D' ? -1 : 1));
  send('scan-complete', { id, zones });
}

async function parseZone(id, zone, requestedFormat, sourceFiles, options) {
  send('progress', { id, message: 'Preparing local game files…', value: 0.03 });
  clearVirtualFS();

  const format = String(requestedFormat || '').toUpperCase();
  const mainName = `${zone.toLowerCase()}.${format.toLowerCase()}`;
  const facades = sourceFiles.map(file => new MemoryFileFacade(file));
  const dependencies = facades.filter(file => file.name !== mainName);
  const main = facades.find(file => file.name === mainName);
  if (!main) throw new Error(`The required ${mainName} archive was not supplied to the parser.`);

  // Process dependencies first and the selected archive last. Some asset EQGs
  // contain their own zone records; finishing with the selected archive keeps
  // the requested zone from being replaced by a dependency.
  const files = [...dependencies, main];
  const root = new MemoryDirectoryHandle(files);
  setVirtualRoot(root);
  globalThis.gameController.rootFileSystemHandle = root;

  const settings = { forceReload: true };
  const decoderOptions = {
    rawImageWrite: true,
    embedWebP: false,
    forceWrite: true,
    skipSubload: false,
    ...options
  };

  const handle = new EQFileHandle(zone.toLowerCase(), files, root, settings, decoderOptions);
  await handle.initialize();
  send('progress', { id, message: `Decoding ${zone} (${format})…`, value: 0.12 });
  await handle.process(true);

  const zonePath = chooseZoneFile(zone);
  if (!zonePath) {
    const detail = format === 'EQG'
      ? 'The EQG was identified as a zone archive, but sage-core did not produce zone geometry. This client may use an unsupported EQG revision.'
      : 'The selected archive may not contain a conventional zone WLD.';
    throw new Error(`The parser did not produce a zone GLB. ${detail}`);
  }

  const zoneBuffer = cloneBuffer(readVirtualFile(zonePath));
  const metadataPath = zonePath.replace(/\.glb$/i, '.json');
  const metadata = decodeJson(readVirtualFile(metadataPath)) || {
    objects: {}, regions: [], lights: [], sounds: []
  };

  send('progress', { id, message: 'Collecting referenced zone objects…', value: 0.82 });
  const allObjectPaths = listVirtualFiles('objects/').filter((path) => path.endsWith('.glb'));
  const objects = {};
  const transfer = [zoneBuffer];
  const maxObjects = Number.isFinite(options.maxObjectModels) ? options.maxObjectModels : 1000;
  const objectIndex = decodeJson(readVirtualFile('data/objectpaths.json')) || {};
  const objectSelection = selectObjectPaths(allObjectPaths, metadata, objectIndex, maxObjects);
  const objectPaths = objectSelection.paths;
  const textureReferences = textureReferenceKeys(zoneBuffer);
  for (const path of objectPaths) {
    const data = cloneBuffer(readVirtualFile(path));
    if (!data) continue;
    textureReferenceKeys(data, textureReferences);
    const name = path.split('/').pop();
    objects[name] = data;
    transfer.push(data);
  }

  const textures = {};
  const allTexturePaths = listVirtualFiles('textures/');
  const texturePaths = selectTexturePaths(allTexturePaths, textureReferences);
  for (const path of texturePaths) {
    const data = cloneBuffer(readVirtualFile(path));
    if (!data) continue;
    textures[path] = data;
    transfer.push(data);
  }

  send('complete', {
    id,
    zone,
    format,
    zoneFile: zonePath,
    zoneBuffer,
    metadata,
    objectIndex,
    objects,
    textures,
    stats: {
      sourceFiles: files.length,
      objectModels: Object.keys(objects).length,
      objectModelsGenerated: allObjectPaths.length,
      textures: Object.keys(textures).length,
      texturesGenerated: allTexturePaths.length,
      generatedFiles: listVirtualFiles('').length,
      unmatchedObjectNames: objectSelection.unmatched.slice(0, 100)
    }
  }, transfer);
}

self.onmessage = async (event) => {
  const message = event.data || {};
  const { id, action = 'parse' } = message;
  if (!id) return;
  try {
    if (action === 'scan') {
      await scanArchives(id, message.candidates || []);
      return;
    }
    const { zone, format, files = [], options = {} } = message;
    if (!zone || !format || !files.length) throw new Error('The parser request did not include a zone, format, and source files.');
    await parseZone(id, zone, format, files, options);
  } catch (error) {
    console.error('[EQLZoneViewer worker]', error);
    send('error', {
      id,
      message: error?.message || String(error),
      stack: error?.stack || ''
    });
  }
};
