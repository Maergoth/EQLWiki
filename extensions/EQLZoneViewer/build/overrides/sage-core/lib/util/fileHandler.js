// EQL Zone Viewer in-memory filesystem adapter.
// This fork prevents sage-core from writing generated files into the user's EQ folder.
let rootHandle = null;
const memory = new Map();
let objectMetadata = {};

function key(directory, name, subdir) {
  return [directory, subdir, name].filter(Boolean).join('/').replace(/\\/g, '/').toLowerCase();
}

async function toArrayBuffer(value) {
  if (value instanceof ArrayBuffer) return value.slice(0);
  if (ArrayBuffer.isView(value)) return value.buffer.slice(value.byteOffset, value.byteOffset + value.byteLength);
  if (typeof Blob !== 'undefined' && value instanceof Blob) return await value.arrayBuffer();
  if (typeof value === 'string') return new TextEncoder().encode(value).buffer;
  if (value == null) return new ArrayBuffer(0);
  return new Uint8Array(value).buffer;
}

export function setVirtualRoot(handle) { rootHandle = handle; }
export function clearVirtualFS() { memory.clear(); objectMetadata = {}; }
export function listVirtualFiles(prefix = '') {
  prefix = prefix.toLowerCase();
  return [...memory.keys()].filter((k) => k.startsWith(prefix));
}
export function readVirtualFile(path) { return memory.get(path.toLowerCase()) || null; }
export function getEQRootDir() { return rootHandle; }
export async function getEQSageDir() { return null; }
export async function getEQDir() { return null; }
export async function getRootFiles() { return []; }
export async function getFiles() { return []; }
export async function* getFilesRecursively() {}

export async function writeEQFile(directory, name, value, subdir = undefined) {
  memory.set(key(directory, name, subdir), await toArrayBuffer(value));
  return true;
}
export async function writeFile(_dir, name, value) {
  memory.set(key('files', name), await toArrayBuffer(value));
  return true;
}
export async function writeRootEQFile(folderPath, name, value) {
  memory.set(key('root', name, folderPath), await toArrayBuffer(value));
  return true;
}
export async function getRootEQFile(folderPath, name) {
  return memory.get(key('root', name, folderPath));
}
export async function deleteEqFileOrFolder(directory, name) {
  return memory.delete(key(directory, name));
}
export async function deleteEqFolder(directory) {
  const prefix = `${directory.toLowerCase()}/`;
  for (const k of [...memory.keys()]) if (k.startsWith(prefix)) memory.delete(k);
}
export async function getEQFile(directory, name, type = 'arrayBuffer') {
  const value = memory.get(key(directory, name));
  if (!value) return false;
  if (type === 'text') return new TextDecoder().decode(new Uint8Array(value));
  if (type === 'json') {
    try { return JSON.parse(new TextDecoder().decode(new Uint8Array(value))); }
    catch { return {}; }
  }
  return value.slice(0);
}
export async function getEQFileExists(directory, name) {
  return memory.has(key(directory, name));
}
export async function appendObjectMetadata(objectKey, path) {
  const upper = String(objectKey).toUpperCase();
  if (!objectMetadata[upper]) objectMetadata[upper] = path;
  await writeEQFile('data', 'objectPaths.json', JSON.stringify(objectMetadata));
}
