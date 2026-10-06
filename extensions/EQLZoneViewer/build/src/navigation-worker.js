'use strict';

let mapState = null;
let activeRequestId = 0;

const clamp = (value, min, max) => Math.max(min, Math.min(max, value));
const now = () => (typeof performance !== 'undefined' ? performance.now() : Date.now());

class MinHeap {
  constructor() {
    this.items = [];
    this.priorities = [];
  }
  get size() { return this.items.length; }
  push(item, priority) {
    let index = this.items.length;
    this.items.push(item);
    this.priorities.push(priority);
    while (index > 0) {
      const parent = (index - 1) >> 1;
      if (this.priorities[parent] <= priority) break;
      this.items[index] = this.items[parent];
      this.priorities[index] = this.priorities[parent];
      index = parent;
    }
    this.items[index] = item;
    this.priorities[index] = priority;
  }
  pop() {
    if (!this.items.length) return -1;
    const result = this.items[0];
    const tailItem = this.items.pop();
    const tailPriority = this.priorities.pop();
    if (this.items.length) {
      let index = 0;
      this.items[0] = tailItem;
      this.priorities[0] = tailPriority;
      while (true) {
        const left = index * 2 + 1;
        const right = left + 1;
        if (left >= this.items.length) break;
        let child = left;
        if (right < this.items.length && this.priorities[right] < this.priorities[left]) child = right;
        if (this.priorities[index] <= this.priorities[child]) break;
        [this.items[index], this.items[child]] = [this.items[child], this.items[index]];
        [this.priorities[index], this.priorities[child]] = [this.priorities[child], this.priorities[index]];
        index = child;
      }
    }
    return result;
  }
}

function postProgress(id, value, message) {
  self.postMessage({ type: 'progress', id, value, message });
}

function lineIntersectsBounds(x1, z1, x2, z2, minX, minZ, maxX, maxZ) {
  return !(Math.max(x1, x2) < minX || Math.min(x1, x2) > maxX || Math.max(z1, z2) < minZ || Math.min(z1, z2) > maxZ);
}

function rasterizeLines(lines, grid, minX, minZ, cell, columns, rows, clearanceCells, relaxed, minRouteY, maxRouteY) {
  const mark = (ix, iz, value) => {
    if (ix < 0 || ix >= columns || iz < 0 || iz >= rows) return;
    const index = iz * columns + ix;
    if (value > grid[index]) grid[index] = value;
  };
  const boundsMaxX = minX + (columns - 1) * cell;
  const boundsMaxZ = minZ + (rows - 1) * cell;
  const stride = 6;
  for (let offset = 0; offset + 5 < lines.length; offset += stride) {
    const x1 = lines[offset];
    const z1 = lines[offset + 1];
    const y1 = lines[offset + 2];
    const x2 = lines[offset + 3];
    const z2 = lines[offset + 4];
    const y2 = lines[offset + 5];
    const verticalPadding = relaxed ? 90 : 42;
    if (Math.max(y1, y2) < minRouteY - verticalPadding || Math.min(y1, y2) > maxRouteY + verticalPadding) continue;
    if (!lineIntersectsBounds(x1, z1, x2, z2, minX - cell, minZ - cell, boundsMaxX + cell, boundsMaxZ + cell)) continue;
    const dx = x2 - x1;
    const dz = z2 - z1;
    const length = Math.hypot(dx, dz);
    if (length < Math.max(0.8, cell * 0.08)) continue;
    const steps = Math.max(1, Math.ceil(length / Math.max(2, cell * 0.35)));
    for (let step = 0; step <= steps; step++) {
      const t = step / steps;
      const ix = Math.round((x1 + dx * t - minX) / cell);
      const iz = Math.round((z1 + dz * t - minZ) / cell);
      const radius = relaxed ? 0 : clearanceCells;
      for (let ox = -radius; ox <= radius; ox++) {
        for (let oz = -radius; oz <= radius; oz++) {
          if (ox * ox + oz * oz > radius * radius + 0.25) continue;
          mark(ix + ox, iz + oz, relaxed ? 1 : 255);
        }
      }
    }
  }
}

function hasLineOfSight(a, b, grid, columns, rows, blockMarked = false) {
  let x0 = a % columns;
  let z0 = Math.floor(a / columns);
  const x1 = b % columns;
  const z1 = Math.floor(b / columns);
  const dx = Math.abs(x1 - x0);
  const dz = Math.abs(z1 - z0);
  const sx = x0 < x1 ? 1 : -1;
  const sz = z0 < z1 ? 1 : -1;
  let err = dx - dz;
  while (true) {
    const index = z0 * columns + x0;
    if (grid[index] === 255 || (blockMarked && grid[index] !== 0)) return false;
    if (x0 === x1 && z0 === z1) break;
    const e2 = err * 2;
    if (e2 > -dz) { err -= dz; x0 += sx; }
    if (e2 < dx) { err += dx; z0 += sz; }
  }
  return true;
}

function smoothIndices(indices, grid, columns, rows, blockMarked = false) {
  if (indices.length <= 2) return indices;
  const result = [indices[0]];
  let anchor = 0;
  while (anchor < indices.length - 1) {
    let furthest = anchor + 1;
    for (let probe = indices.length - 1; probe > anchor + 1; probe--) {
      if (hasLineOfSight(indices[anchor], indices[probe], grid, columns, rows, blockMarked)) {
        furthest = probe;
        break;
      }
    }
    result.push(indices[furthest]);
    anchor = furthest;
  }
  return result;
}

async function routePass(message, relaxed, passNumber, passCount) {
  const id = message.id;
  if (!mapState || mapState.key !== message.mapKey) throw new Error('Navigation map is not initialized for this zone.');
  const start = message.start;
  const goal = message.goal;
  const distance = Math.hypot(goal.x - start.x, goal.z - start.z);
  let cell = clamp(distance / 110, 9, 28);
  const margin = Math.max(240, distance * 0.48);
  let minX = Math.max(mapState.bounds.minX, Math.min(start.x, goal.x) - margin);
  let maxX = Math.min(mapState.bounds.maxX, Math.max(start.x, goal.x) + margin);
  let minZ = Math.max(mapState.bounds.minZ, Math.min(start.z, goal.z) - margin);
  let maxZ = Math.min(mapState.bounds.maxZ, Math.max(start.z, goal.z) + margin);
  if (!(maxX > minX && maxZ > minZ)) {
    minX = Math.min(start.x, goal.x) - margin;
    maxX = Math.max(start.x, goal.x) + margin;
    minZ = Math.min(start.z, goal.z) - margin;
    maxZ = Math.max(start.z, goal.z) + margin;
  }
  let columns = Math.max(3, Math.floor((maxX - minX) / cell) + 1);
  let rows = Math.max(3, Math.floor((maxZ - minZ) / cell) + 1);
  const maxCells = 260000;
  if (columns * rows > maxCells) {
    const scale = Math.sqrt((columns * rows) / maxCells);
    cell *= scale;
    columns = Math.max(3, Math.floor((maxX - minX) / cell) + 1);
    rows = Math.max(3, Math.floor((maxZ - minZ) / cell) + 1);
  }
  const totalCells = columns * rows;
  const obstacle = new Uint8Array(totalCells);
  const clearanceCells = clamp(Math.ceil((Number(message.playerRadius) + 1.5) / cell), 0, 2);
  postProgress(id, 0.08 + (passNumber - 1) * 0.08, `Preparing background route graph · pass ${passNumber}/${passCount}…`);
  const minRouteY = Math.min(Number(start.y) || 0, Number(goal.y) || 0);
  const maxRouteY = Math.max(Number(start.y) || 0, Number(goal.y) || 0);
  rasterizeLines(mapState.lines, obstacle, minX, minZ, cell, columns, rows, clearanceCells, relaxed, minRouteY, maxRouteY);
  if (activeRequestId !== id) return null;

  const toIndex = point => {
    const ix = clamp(Math.round((point.x - minX) / cell), 0, columns - 1);
    const iz = clamp(Math.round((point.z - minZ) / cell), 0, rows - 1);
    return iz * columns + ix;
  };
  const startIndex = toIndex(start);
  const goalIndex = toIndex(goal);
  obstacle[startIndex] = 0;
  obstacle[goalIndex] = 0;
  const gScore = new Float64Array(totalCells);
  gScore.fill(Number.POSITIVE_INFINITY);
  const parent = new Int32Array(totalCells);
  parent.fill(-1);
  const closed = new Uint8Array(totalCells);
  const heap = new MinHeap();
  const goalX = goalIndex % columns;
  const goalZ = Math.floor(goalIndex / columns);
  const heuristic = index => {
    const x = index % columns;
    const z = Math.floor(index / columns);
    const dx = Math.abs(goalX - x);
    const dz = Math.abs(goalZ - z);
    return (Math.max(dx, dz) + (Math.SQRT2 - 1) * Math.min(dx, dz)) * cell;
  };
  gScore[startIndex] = 0;
  heap.push(startIndex, heuristic(startIndex));
  const directions = [
    [1, 0, 1], [-1, 0, 1], [0, 1, 1], [0, -1, 1],
    [1, 1, Math.SQRT2], [1, -1, Math.SQRT2], [-1, 1, Math.SQRT2], [-1, -1, Math.SQRT2]
  ];
  const started = now();
  const routeDeadline = Number(message.routeDeadline) || (started + (Number(message.maxMs) || 45000));
  let expanded = 0;
  let lastProgressAt = started;
  while (heap.size) {
    if (activeRequestId !== id) return null;
    if (now() > routeDeadline) return null;
    const current = heap.pop();
    if (closed[current]) continue;
    if (current === goalIndex) {
      const indices = [];
      let cursor = current;
      while (cursor >= 0) {
        indices.push(cursor);
        if (cursor === startIndex) break;
        cursor = parent[cursor];
      }
      indices.reverse();
      // In the relaxed pass, marked map lines are expensive rather than fully
      // impassable. They must still block smoothing, otherwise a valid detour
      // is collapsed back into one straight segment through every wall.
      const smooth = smoothIndices(indices, obstacle, columns, rows, relaxed);
      const output = new Float32Array(smooth.length * 2);
      for (let index = 0; index < smooth.length; index++) {
        const gridIndex = smooth[index];
        output[index * 2] = minX + (gridIndex % columns) * cell;
        output[index * 2 + 1] = minZ + Math.floor(gridIndex / columns) * cell;
      }
      output[0] = start.x;
      output[1] = start.z;
      output[output.length - 2] = goal.x;
      output[output.length - 1] = goal.z;
      return { points: output, expanded, cell, relaxed };
    }
    closed[current] = 1;
    expanded++;
    const cx = current % columns;
    const cz = Math.floor(current / columns);
    for (const [dx, dz, multiplier] of directions) {
      const nx = cx + dx;
      const nz = cz + dz;
      if (nx < 0 || nx >= columns || nz < 0 || nz >= rows) continue;
      const next = nz * columns + nx;
      if (closed[next] || obstacle[next] === 255) continue;
      if (dx && dz) {
        const sideA = cz * columns + nx;
        const sideB = nz * columns + cx;
        if (obstacle[sideA] === 255 || obstacle[sideB] === 255) continue;
      }
      const linePenalty = obstacle[next] === 1 ? cell * 4.5 : 0;
      const tentative = gScore[current] + cell * multiplier + linePenalty;
      if (tentative >= gScore[next]) continue;
      gScore[next] = tentative;
      parent[next] = current;
      heap.push(next, tentative + heuristic(next));
    }
    const currentTime = now();
    if (expanded % 1800 === 0 || currentTime - lastProgressAt > 80) {
      const base = passNumber === 1 ? 0.16 : 0.54;
      const span = passNumber === 1 ? 0.34 : 0.38;
      const fraction = Math.min(0.98, expanded / Math.max(5000, totalCells * 0.65));
      postProgress(id, base + span * fraction, `Calculating in background · pass ${passNumber}/${passCount} · ${expanded.toLocaleString()} nodes…`);
      lastProgressAt = currentTime;
      await new Promise(resolve => setTimeout(resolve, 0));
    }
  }
  return null;
}

async function buildRoute(message) {
  const id = message.id;
  activeRequestId = id;
  message.routeDeadline = now() + (Number(message.maxMs) || 45000);
  try {
    const strict = await routePass(message, false, 1, 2);
    if (activeRequestId !== id) return;
    const result = strict || await routePass(message, true, 2, 2);
    if (activeRequestId !== id) return;
    if (!result) {
      self.postMessage({ type: 'route', id, points: null });
      return;
    }
    self.postMessage({
      type: 'route',
      id,
      points: result.points,
      expanded: result.expanded,
      cell: result.cell,
      relaxed: result.relaxed
    }, [result.points.buffer]);
  } catch (error) {
    if (activeRequestId === id) self.postMessage({ type: 'error', id, message: error?.message || String(error) });
  }
}

self.onmessage = event => {
  const message = event.data || {};
  if (message.action === 'init') {
    mapState = {
      key: message.mapKey,
      lines: new Float32Array(message.lines || new ArrayBuffer(0)),
      bounds: message.bounds || { minX: -100000, minZ: -100000, maxX: 100000, maxZ: 100000 }
    };
    self.postMessage({ type: 'ready', id: message.id, mapKey: mapState.key, lineCount: Math.floor(mapState.lines.length / 6) });
    return;
  }
  if (message.action === 'cancel') {
    if (!message.id || activeRequestId === message.id) activeRequestId = -1;
    return;
  }
  if (message.action === 'route') void buildRoute(message);
};
