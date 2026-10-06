import * as THREE from 'three';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';
import { TrackballControls } from 'three/addons/controls/TrackballControls.js';
import { PointerLockControls } from 'three/addons/controls/PointerLockControls.js';
import { GLTFLoader } from 'three/addons/loaders/GLTFLoader.js';
import { DDSLoader } from 'three/addons/loaders/DDSLoader.js';
import { mergeGeometries } from 'three/addons/utils/BufferGeometryUtils.js';

const PLACEHOLDER_TEXTURE = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';
const ACCEPTED_EXTENSIONS = new Set(['s3d', 'eqg', 'txt', 'eff', 'xmi', 'emt', 'zon']);
const OBVIOUS_NON_ZONE_PREFIXES = /^(?:(?:global|gequip|equip|spells|load(?:ing)?|chequip|dragitem|music)(?:_|$)|it\d)/i;
const OBVIOUS_NON_ZONE_SUFFIXES = /_(obj\d*|chr\d*|lit\d*|sounds?|sndbnk|assets?|items?|mirrors?|banners?|shield|weapons?|armor|models?|textures?)$/i;
const CACHE_FORMAT_VERSION = 'v13';
const MAX_CACHE_BYTES = 128 * 1024 * 1024;
const TARGET_FPS = 60;
const TARGET_FRAME_MS = 1000 / TARGET_FPS;
const MAX_FLOOR_BANDS = 8;

const EQ_TO_THREE = Object.freeze({ swap: true, sx: 1, sz: -1 });
const NAMED_MOB_COLOR = Object.freeze({ r: 127, g: 64, b: 0 });
const ZONE_DISPLAY_NAMES = Object.freeze({
  akanon: "Ak'Anon", airplane: 'Plane of Sky', arena: 'The Arena', befallen: 'Befallen', beholder: 'Gorge of King Xorbb',
  blackburrow: 'Blackburrow', butcher: 'Butcherblock Mountains', cauldron: "Dagnor's Cauldron",
  cazicthule: 'Cazic Thule', commons: 'West Commonlands', crushbone: 'Crushbone',
  ecommons: 'East Commonlands', erudnext: 'Erudin', erudnint: 'Erudin Palace', erudsxing: "Erud's Crossing",
  everfrost: 'Everfrost Peaks', fearplane: 'Plane of Fear', feerrott: 'The Feerrott',
  felwithea: 'Northern Felwithe', felwitheb: 'Southern Felwithe', freporte: 'East Freeport',
  freportn: 'North Freeport', freportw: 'West Freeport', gfaydark: 'Greater Faydark / Kelethin',
  grobb: 'Grobb', gukbottom: 'Lower Guk', guktop: 'Upper Guk', halas: 'Halas', hateplane: 'Plane of Hate',
  highkeep: 'High Keep', highpass: 'Highpass Hold', hole: 'The Hole', innothule: 'Innothule Swamp', eastkarana: 'East Karana',
  kaladima: 'South Kaladim', kaladimb: 'North Kaladim', kedge: 'Kedge Keep', kerra: 'Kerra Island',
  jaggedpine: 'Jaggedpine Forest', kithicor: 'Kithicor Forest', lakerathe: 'Lake Rathetear', lavastorm: 'Lavastorm Mountains',
  lfaydark: 'Lesser Faydark', mistmoore: 'Mistmoore Castle', misty: 'Misty Thicket', najena: 'Najena',
  nektulos: 'Nektulos Forest', neriaka: 'Neriak - Foreign Quarter', neriakb: 'Neriak - Commons',
  neriakc: 'Neriak - Third Gate', northkarana: 'North Karana', nro: 'North Ro', oasis: 'Oasis of Marr',
  oggok: 'Oggok', oot: 'Ocean of Tears', paineel: 'Paineel', paw: 'Splitpaw Lair',
  permafrost: 'Permafrost', qcat: 'Qeynos Aqueducts', qey2hh1: 'West Karana', qeynos: 'South Qeynos',
  qeynos2: 'North Qeynos', qeytoqrg: 'Qeynos Hills', qrg: 'Surefall Glade', rathemtn: 'Rathe Mountains',
  rivervale: 'Rivervale', runnyeye: 'Runnyeye Citadel', sky: 'Plane of Sky', soldunga: "Solusek's Eye (Sol A)",
  soldungb: "Nagafen's Lair (Sol B)", soltemple: 'The Temple of Solusek Ro', southkarana: 'South Karana',
  sro: 'South Ro', southro: 'South Ro', northro: 'North Ro', steamfont: 'Steamfont Mountains', tox: 'Toxxulia Forest', tutorial: 'Tutorial Zone',
  newsebilis: 'New Sebilis Expedition', stonebrunt: 'Stonebrunt Mountains', unrest: 'The Estate of Unrest', warrens: 'The Warrens', westkarana: 'West Karana',
  burningwood: 'Burning Wood', cabeast: 'East Cabilis', cabwest: 'West Cabilis', chardok: 'Chardok',
  citymist: 'City of Mist', dalnir: 'Dalnir', dreadlands: 'Dreadlands', emeraldjungle: 'Emerald Jungle',
  fieldofbone: 'Field of Bone', firiona: 'Firiona Vie', frontiermtns: 'Frontier Mountains',
  charasis: 'Howling Stones (Charasis)', kaesora: 'Kaesora', karnor: "Karnor's Castle", kurn: "Kurn's Tower",
  lakeofillomen: 'Lake of Ill Omen', nurga: 'Mines of Nurga', overthere: 'The Overthere',
  sebilis: 'Old Sebilis', skyfire: 'Skyfire Mountains', swampofnohope: 'Swamp of No Hope',
  timorous: 'Timorous Deep', trakanon: "Trakanon's Teeth", droga: 'Temple of Droga',
  veeshan: "Veeshan's Peak", warslikswood: 'Warsliks Woods', cobaltscar: 'Cobalt Scar',
  crystal: 'Crystal Caverns', eastwastes: 'Eastern Wastes', frozenshadow: 'Tower of Frozen Shadow',
  greatdivide: 'The Great Divide', growthplane: 'Plane of Growth', iceclad: 'Iceclad Ocean',
  kael: 'Kael Drakkal', mischiefplane: 'Plane of Mischief', necropolis: 'Dragon Necropolis',
  sirens: "Siren's Grotto", sleeper: "Sleeper's Tomb", skyshrine: 'Skyshrine',
  templeveeshan: 'Temple of Veeshan', thurgadina: 'Thurgadin', thurgadinb: 'Icewell Keep',
  velketor: "Velketor's Labyrinth", wakening: 'Wakening Land', westwastes: 'Western Wastes'
});
const WORLD_MAP_CACHE_VERSION = 'world-v11';
const WORLD_MAP_MAX_SEGMENTS = 3600;
const WORLD_MAP_MIN_SCALE = 0.08;
const WORLD_MAP_MAX_SCALE = 3.5;

// Some archive IDs and map filenames differ even though they describe the
// same zone. These aliases are used only to locate map families; the archive
// ID remains the canonical load key.
const ZONE_MAP_FILE_ALIASES = Object.freeze({
  kerra: 'kerraridge',
  sky: 'airplane',
  oot: 'oceanoftears',
  newsebilis: 'newsebexp',
  westkarana: 'qey2hh1',
  northro: 'nro',
  southro: 'sro'
});

const WORLD_CANONICAL_ZONE_IDS = Object.freeze({
  sky: 'airplane',
  westkarana: 'qey2hh1',
  northro: 'nro',
  southro: 'sro'
});

const WORLD_MAP_SLOT_X = 200;
const WORLD_MAP_SLOT_Y = 150;
const WORLD_MAP_NODE_WIDTH = 156;
const WORLD_MAP_NODE_HEIGHT = 108;
const WORLD_MAP_NODE_TITLE_HEIGHT = 34;
const WORLD_ROUTE_GRID = 10;
const WORLD_ROUTE_CLEARANCE = 18;
const WORLD_ROUTE_PADDING = 260;
const WORLD_ROUTE_TURN_COST = 2.4;
const WORLD_ROUTE_REUSE_COST = 18;
const WORLD_ROUTE_CROSS_COST = 34;
const WORLD_ROUTE_NEAR_COST = 2.5;
const WORLD_ROUTE_PORT_SPREAD = 0.68;
const WORLD_ROUTE_STRICT_GAP = 0;
const NAVIGATION_BEAM_MIN_HEIGHT = 72;
const NAVIGATION_BEAM_MAX_HEIGHT = 190;
const NAVIGATION_PATH_MIN_CELL = 10;
const NAVIGATION_PATH_MAX_CELL = 32;
const NAVIGATION_PATH_MAX_STATES = 24000;
const NAVIGATION_PATH_MAX_GRID_CELLS = 70000;
const NAVIGATION_PATH_TIME_SLICE_MS = 3;
const NAVIGATION_PATH_MAX_TOTAL_MS = 45000;
const NAVIGATION_PATH_SURFACES_PER_CELL = 4;
const NAVIGATION_SPATIAL_CELL = 256;

// The world atlas is a hand-authored schematic, not a force-directed graph.
// These region origins and per-zone slots preserve the recognizable classic
// geography shown by in-game/community world maps while still allowing local
// map silhouettes to determine each card's dimensions.
const WORLD_GROUP_LAYOUTS = Object.freeze({
  // A deliberately spacious reference-map artboard. The regions follow the
  // official classic-world silhouette: Odus west of Antonica, Faydwer to the
  // northeast, Kunark to the southeast, and Velious to the southwest.
  odus: { x: 120, y: 900, label: 'Odus', fallbackColumns: 3 },
  antonica: { x: 1000, y: 180, label: 'Antonica', fallbackColumns: 12 },
  faydwer: { x: 4200, y: 250, label: 'Faydwer', fallbackColumns: 6 },
  kunark: { x: 4300, y: 1850, label: 'Kunark', fallbackColumns: 9 },
  velious: { x: 1900, y: 2300, label: 'Velious', fallbackColumns: 9 },
  planes: { x: 2700, y: 1320, label: 'Planes', fallbackColumns: 5 },
  other: { x: 5400, y: 1700, label: 'Other', fallbackColumns: 5 }
});

const WORLD_GROUP_ANCHORS = Object.freeze(Object.fromEntries(
  Object.entries(WORLD_GROUP_LAYOUTS).map(([group, layout]) => [group, {
    x: layout.x,
    y: layout.y,
    label: layout.label
  }])
));

// Slots are measured in WORLD_MAP_SLOT_X / WORLD_MAP_SLOT_Y units from the
// corresponding group origin. The ordering follows the familiar world-map
// structure: Odus at left, Antonica in the center, Faydwer upper-right,
// Kunark lower-right, and Velious below Antonica.
const WORLD_ZONE_SCHEMATIC_SLOTS = Object.freeze({
  // Odus
  erudnint: [0, 0], erudnext: [1.2, 0], erudsxing: [2.5, 0],
  kerra: [0, 1.35], tox: [1.2, 1.35],
  paineel: [0.25, 2.75], warrens: [1.45, 2.75], stonebrunt: [2.7, 2.75], hole: [1.0, 4.05],

  // Antonica — Qeynos, the northlands, and the Karana corridor
  halas: [0.8, 0], permafrost: [2.0, 0], everfrost: [1.4, 1.15],
  jaggedpine: [-0.45, 1.9], qrg: [0.15, 3.1], blackburrow: [1.4, 2.35],
  qeytoqrg: [1.4, 3.55], qeynos2: [0.55, 4.75], qeynos: [0.55, 5.95], qcat: [1.75, 5.95],
  beholder: [3.2, 2.55], runnyeye: [4.25, 1.45], misty: [5.45, 1.45], rivervale: [5.45, 2.65],
  qey2hh1: [2.3, 3.95], northkarana: [3.5, 3.95], eastkarana: [4.7, 3.95],
  highpass: [5.9, 3.95], kithicor: [6.85, 3.95],
  southkarana: [3.5, 5.2], paw: [4.7, 5.2], highkeep: [5.9, 5.2],
  arena: [2.75, 7.0], lakerathe: [3.95, 7.0], rathemtn: [5.15, 7.0],

  // Antonica — Freeport, Neriak, the deserts, and the southern swamps
  soldungb: [8.0, 0], soldunga: [8.0, 1.1], soltemple: [9.2, 0],
  lavastorm: [9.2, 1.1], najena: [10.4, 1.1], neriakc: [11.6, 1.1],
  nektulos: [8.15, 2.45], neriaka: [9.35, 2.45], neriakb: [10.55, 2.45],
  commons: [7.9, 3.95], ecommons: [9.0, 3.95], freportw: [10.1, 3.95],
  freporte: [11.2, 3.95], freportn: [11.35, 2.87],
  newsebilis: [7.9, 5.2], befallen: [9.0, 5.2],
  nro: [11.6, 5.2], oasis: [11.6, 6.4], sro: [11.6, 7.33],
  oggok: [6.35, 6.15], feerrott: [6.55, 7.35], cazicthule: [6.55, 8.55],
  innothule: [7.8, 7.35], guktop: [9.0, 7.35], gukbottom: [10.2, 7.35], grobb: [7.8, 8.55],

  // Faydwer
  kaladimb: [0, 0], kaladima: [0, 1.15], butcher: [1.3, 1.15],
  cauldron: [1.3, 2.45], kedge: [0.05, 3.45], unrest: [1.3, 3.75],
  crushbone: [2.7, 0.35], gfaydark: [3.85, 1.15],
  felwithea: [5.1, 0.35], felwitheb: [5.1, 1.55],
  lfaydark: [3.85, 2.55], mistmoore: [3.85, 3.85],
  steamfont: [5.25, 2.55], akanon: [5.25, 3.85],

  // Kunark — arranged to follow the continent rather than a rectangular grid
  timorous: [-1.5, -1.25],
  veeshan: [0, -0.15], skyfire: [0, 1.15],
  overthere: [2.45, 1.15], charasis: [2.75, -0.15],
  dalnir: [3.65, 0.9], warslikswood: [3.85, 2.05],
  fieldofbone: [6.0, 0.75], kurn: [6.0, -0.45], kaesora: [7.15, 0.75],
  cabwest: [5.0, 2.05], cabeast: [6.1, 2.05],
  citymist: [8.7, 1.25], emeraldjungle: [7.75, 2.25],
  droga: [1.45, 2.35], frontiermtns: [2.45, 4.85], nurga: [1.35, 3.75],
  chardok: [-0.8, 3.75], burningwood: [0.25, 4.55], dreadlands: [1.45, 5.75], karnor: [1.45, 6.9],
  lakeofillomen: [4.0, 3.95], swampofnohope: [6.15, 4.0],
  firiona: [4.35, 5.65], trakanon: [7.75, 4.75], sebilis: [7.75, 6.05],

  // Velious — the Great Divide / Eastern Wastes spine is central and clear
  necropolis: [0, 0.65], westwastes: [1.3, 0.65], templeveeshan: [2.6, -0.1],
  sirens: [0.45, 1.95], cobaltscar: [0, 3.25], skyshrine: [1.4, 3.25],
  wakening: [2.8, 3.25], kael: [4.2, 3.25], sleeper: [5.6, 3.55],
  velketor: [2.9, 1.95], greatdivide: [4.2, 1.95], eastwastes: [5.6, 2.35],
  thurgadina: [4.2, 0.75], thurgadinb: [5.45, -0.1], crystal: [5.6, 1.05],
  iceclad: [7.0, 2.35], frozenshadow: [8.3, 2.35]
});

// Transport hubs and planes deliberately sit between or outside continent
// frames, matching their role on the canonical map rather than being packed
// into an artificial "Planes" cluster.
const WORLD_ZONE_ABSOLUTE_POSITIONS = Object.freeze({
  // Ocean and planar hubs occupy the open water/gaps between continents.
  oot: { x: 3950, y: 790 },
  airplane: { x: 3780, y: 500 },
  hateplane: { x: 3550, y: 1140 },
  fearplane: { x: 3000, y: 1700 },
  timorous: { x: 3850, y: 1450 },
  mischiefplane: { x: 1880, y: 2110 },
  growthplane: { x: 2460, y: 2980 }
});

const WORLD_FRAME_GROUPS = Object.freeze(['odus', 'antonica', 'faydwer', 'kunark', 'velious']);
const WORLD_FRAME_EXCLUDED_ZONE_IDS = new Set(['oot']);
const WORLD_MAP_EXCLUDED_ZONE_IDS = new Set(['tutorial']);

const ZONE_WORLD_GROUPS = Object.freeze({
  // Odus
  erudnext: 'odus', erudnint: 'odus', erudsxing: 'odus', kerra: 'odus', paineel: 'odus',
  hole: 'odus', tox: 'odus', stonebrunt: 'odus', warrens: 'odus',

  // Antonica
  arena: 'antonica', befallen: 'antonica', beholder: 'antonica', blackburrow: 'antonica',
  cazicthule: 'antonica', commons: 'antonica', ecommons: 'antonica', everfrost: 'antonica',
  feerrott: 'antonica', freporte: 'antonica', freportn: 'antonica', freportw: 'antonica',
  grobb: 'antonica', gukbottom: 'antonica', guktop: 'antonica', halas: 'antonica', highkeep: 'antonica',
  highpass: 'antonica', innothule: 'antonica', eastkarana: 'antonica', northkarana: 'antonica',
  southkarana: 'antonica', qey2hh1: 'antonica', jaggedpine: 'antonica', kithicor: 'antonica',
  lakerathe: 'antonica', lavastorm: 'antonica', misty: 'antonica', najena: 'antonica',
  nektulos: 'antonica', neriaka: 'antonica', neriakb: 'antonica', neriakc: 'antonica',
  nro: 'antonica', oasis: 'antonica', oggok: 'antonica', paw: 'antonica', permafrost: 'antonica',
  qcat: 'antonica', qeynos: 'antonica', qeynos2: 'antonica', qeytoqrg: 'antonica', qrg: 'antonica',
  rathemtn: 'antonica', rivervale: 'antonica', runnyeye: 'antonica', soldunga: 'antonica',
  soldungb: 'antonica', soltemple: 'antonica', sro: 'antonica',
  newsebilis: 'antonica',

  // Faydwer and the Ocean of Tears connector
  akanon: 'faydwer', butcher: 'faydwer', cauldron: 'faydwer', crushbone: 'faydwer',
  felwithea: 'faydwer', felwitheb: 'faydwer', gfaydark: 'faydwer', kaladima: 'faydwer',
  kaladimb: 'faydwer', kedge: 'faydwer', lfaydark: 'faydwer', mistmoore: 'faydwer',
  oot: 'faydwer', steamfont: 'faydwer', unrest: 'faydwer',

  // Kunark
  burningwood: 'kunark', cabeast: 'kunark', cabwest: 'kunark', chardok: 'kunark',
  citymist: 'kunark', dalnir: 'kunark', dreadlands: 'kunark', emeraldjungle: 'kunark',
  fieldofbone: 'kunark', firiona: 'kunark', frontiermtns: 'kunark', charasis: 'kunark',
  kaesora: 'kunark', karnor: 'kunark', kurn: 'kunark', lakeofillomen: 'kunark',
  nurga: 'kunark', overthere: 'kunark', sebilis: 'kunark', skyfire: 'kunark',
  swampofnohope: 'kunark', timorous: 'kunark', trakanon: 'kunark', droga: 'kunark',
  veeshan: 'kunark', warslikswood: 'kunark',

  // Velious
  cobaltscar: 'velious', crystal: 'velious', eastwastes: 'velious', frozenshadow: 'velious',
  greatdivide: 'velious', iceclad: 'velious', kael: 'velious', necropolis: 'velious',
  sirens: 'velious', sleeper: 'velious', skyshrine: 'velious', templeveeshan: 'velious',
  thurgadina: 'velious', thurgadinb: 'velious', velketor: 'velious', wakening: 'velious',
  westwastes: 'velious',

  // Planes
  airplane: 'planes', fearplane: 'planes', hateplane: 'planes', growthplane: 'planes',
  mischiefplane: 'planes'
});

// Aliases that cannot be inferred reliably from display names alone. Values
// are archive IDs. Source-specific cases are handled below.
const WORLD_CONNECTION_ALIASES = Object.freeze({
  'ak anon': 'akanon',
  'butcher block': 'butcher',
  'butcherblock': 'butcher',
  'castle mistmoore': 'mistmoore',
  'cabilis east': 'cabeast',
  'east cabilis': 'cabeast',
  'cabilis west': 'cabwest',
  'cablis west': 'cabwest',
  'western cabilis': 'cabwest',
  'city of guk': 'guktop',
  'city of mist': 'citymist',
  'city of thurgadin': 'thurgadina',
  'clan crushbone': 'crushbone',
  'crypt of dalnir': 'dalnir',
  'dagnors cauldron': 'cauldron',
  'east commons': 'ecommons',
  'eastern plains of karana': 'eastkarana',
  'emerald jungle': 'emeraldjungle',
  'erudin city': 'erudnext',
  'erudin docks': 'erudnext',
  'erudin palace': 'erudnint',
  'feerott': 'feerrott',
  'felwithe': 'felwithea',
  'new sebilis': 'newsebilis',
  'north felwithe': 'felwithea',
  'northern felwithe': 'felwithea',
  'southern felwithe': 'felwitheb',
  'freeport': 'freportw',
  'freeport sewers': 'qcat',
  'gorge of king xorbb': 'beholder',
  'greater faydark': 'gfaydark',
  'high keep': 'highkeep',
  'howling stones': 'charasis',
  'iceclad': 'iceclad',
  'kael drakkel': 'kael',
  'karnors castle': 'karnor',
  'kurns tower': 'kurn',
  'lair of the splitpaw': 'paw',
  'lesser faydark': 'lfaydark',
  'misty thicket': 'misty',
  'nagafens lair': 'soldungb',
  'nektulos': 'nektulos',
  'neriak': 'neriaka',
  'neriak commons': 'neriakb',
  'neriak foreign quarter': 'neriaka',
  'neriak third gate': 'neriakc',
  'north desert of ro': 'nro',
  'northern ro': 'nro',
  'northern desert of ro': 'nro',
  'northern plains of karana': 'northkarana',
  'northern qeynos': 'qeynos2',
  'old sebilis': 'sebilis',
  'oasis': 'oasis',
  'oasis of marr': 'oasis',
  'permafrost caverns': 'permafrost',
  'permafrost keep': 'permafrost',
  'qeynos aquaduct system': 'qcat',
  'qeynos aqueduct system': 'qcat',
  'qeynos catacombs': 'qcat',
  'ruins of old guk': 'gukbottom',
  'ruins of sebilis': 'sebilis',
  'runnyeye citadel': 'runnyeye',
  'liberated citadel of runnyeye': 'runnyeye',
  'sirens grotto': 'sirens',
  'soluseks eye': 'soldunga',
  'south desert of ro': 'sro',
  'southern ro': 'sro',
  'southern plains of karana': 'southkarana',
  'southern qeynos': 'qeynos',
  'steamfont': 'steamfont',
  'steamfont mountains': 'steamfont',
  'temple of cazic thule': 'cazicthule',
  'temple of droga': 'droga',
  'temple of solusek ro': 'soltemple',
  'the hole': 'hole',
  'the overthere': 'overthere',
  'the warrens': 'warrens',
  'tower of frozen shadow': 'frozenshadow',
  'toxullia forest': 'tox',
  'toxulia forest': 'tox',
  'trakanons teeth': 'trakanon',
  'upper guk': 'guktop',
  'valley of king xorbb': 'beholder',
  'veeshans peak': 'veeshan',
  'wakening land': 'wakening',
  'wakening lands': 'wakening',
  'warsliks woods': 'warslikswood',
  'western plains of karana': 'qey2hh1',
  'west karana': 'qey2hh1'
});

const WORLD_SOURCE_TARGET_OVERRIDES = Object.freeze({
  'befallen|commonlands': 'commons',
  'kithicor|commonlands': 'commons',
  'nektulos|commonlands': 'ecommons',
  'northro|commonlands': 'ecommons',
  'gukbottom|city of guk': 'guktop',
  'guktop|ruins of old guk': 'gukbottom',
  'qeynos|north qeynos': 'qeynos2',
  'qeynos2|south qeynos': 'qeynos',
  'qcat|north qeynos': 'qeynos2',
  'qcat|south qeynos': 'qeynos',
  'freporte|west freeport': 'freportw',
  'freportn|west freeport': 'freportw',
  'freportw|east freeport': 'freporte',
  'freportw|north freeport': 'freportn',
  'kaladima|north kaladim': 'kaladimb',
  'kaladimb|south kaladim': 'kaladima',
  'erudnext|erudin palace': 'erudnint',
  'erudnint|erudin': 'erudnext',
  'felwithea|southern felwithe': 'felwitheb',
  'felwitheb|northern felwithe': 'felwithea',
  'neriaka|neriak commons': 'neriakb',
  'neriakb|neriak foreign quarter': 'neriaka',
  'neriakb|neriak third gate': 'neriakc',
  'neriakc|neriak commons': 'neriakb',
  'cabeast|western cabilis': 'cabwest',
  'cabwest|east cabilis': 'cabeast',
  'thurgadina|icewell keep': 'thurgadinb',
  'thurgadinb|city of thurgadin': 'thurgadina'
});

const WORLD_MANUAL_CONNECTIONS = Object.freeze([
  { from: 'oot', to: 'butcher', type: 'water', label: 'Ocean of Tears boat / translocator route', bidirectional: true },
  { from: 'oot', to: 'freporte', type: 'water', label: 'Ocean of Tears boat / translocator route', bidirectional: true },
  { from: 'erudsxing', to: 'erudnext', type: 'water', label: "Erud's Crossing boat / translocator route", bidirectional: true },
  { from: 'erudsxing', to: 'qeynos', type: 'water', label: "Erud's Crossing boat / translocator route", bidirectional: true },
  { from: 'timorous', to: 'butcher', type: 'water', label: 'Timorous Deep translocator route', bidirectional: true },
  { from: 'timorous', to: 'firiona', type: 'water', label: 'Timorous Deep translocator route', bidirectional: true },
  { from: 'timorous', to: 'overthere', type: 'water', label: 'Timorous Deep translocator route', bidirectional: true },
  { from: 'timorous', to: 'sro', type: 'water', label: 'Timorous Deep translocator route', bidirectional: true },
  { from: 'iceclad', to: 'nro', type: 'water', label: 'North Ro / Iceclad translocator route', bidirectional: true },

  // The source map files do not identify these wizard-port origins, so these
  // two routes are explicit validated access rules rather than parser guesses.
  { from: 'oasis', to: 'hateplane', type: 'teleport', label: 'Plane of Hate access from Oasis', bidirectional: false, oneWay: true },
  { from: 'freporte', to: 'airplane', type: 'teleport', label: 'Plane of Sky access from East Freeport', bidirectional: false, oneWay: true },

  { from: 'commons', to: 'newsebilis', type: 'land', label: 'New Sebilis entrance from West Commonlands', bidirectional: true }
]);

// A one-sided label proves that a pair is connected, but it is not by itself
// proof of a one-way game mechanic: many map packs annotate an exit on only
// one of the two zone files. Arrowheads are therefore restricted to routes
// whose direction is explicit in the map or in a validated access rule.
const WORLD_FORCED_ONE_WAY_CONNECTIONS = new Set([
  'timorous>akanon',
  'timorous>cabwest',
  'timorous>erudnext',
  'timorous>felwithea',
  'timorous>freporte',
  'timorous>gfaydark',
  'timorous>halas',
  'timorous>kaladima',
  'timorous>neriakb',
  'timorous>oggok',
  'timorous>rivervale',
  'hole>erudnint',
  'hole>neriakc',
  'mischiefplane>cobaltscar',
  'greatdivide>mischiefplane',
  'templeveeshan>mischiefplane',
  'wakening>growthplane',
  'oasis>hateplane',
  'freporte>airplane'
]);

const WORLD_FIREPOT_DESTINATIONS = Object.freeze([
  'akanon',
  'cabwest',
  'erudnext',
  'felwithea',
  'freporte',
  'gfaydark',
  'halas',
  'kaladima',
  'neriakb',
  'oggok',
  'rivervale'
]);

// The reference map places the Timorous Deep firepot network in a compact
// inset instead of drawing eleven continent-crossing portal lines. Connections
// remain present in edge metadata and node tooltips.
const WORLD_FIREPOT_INSET = Object.freeze({
  // Dedicated open-water panel immediately below Timorous Deep. The previous
  // Odus placement sat underneath Paineel, The Warrens, Stonebrunt, and The
  // Hole, which made both the portal labels and unrelated zone cards appear
  // stacked. Keeping the panel beside its source also makes the one-way route
  // visually self-explanatory.
  minX: 3340,
  minY: 1605,
  maxX: 4165,
  maxY: 2205
});


function h(tag, className, text) {
  const el = document.createElement(tag);
  if (className) el.className = className;
  if (text !== undefined) el.textContent = text;
  return el;
}

function clamp(value, min, max) { return Math.max(min, Math.min(max, value)); }
function normalizeName(name) {
  return String(name || '').toLowerCase().replace(/\\/g, '/').split('/').pop();
}
function fileStem(name) { return normalizeName(name).replace(/\.[^.]+$/, ''); }
function objectAliases(name) {
  const raw = fileStem(name).toLowerCase();
  const stripped = raw
    .replace(/(?:_)?actordef$/i, '')
    .replace(/(?:_)?actor$/i, '')
    .replace(/(?:_)?dmsprite$/i, '')
    .replace(/(?:_)?mesh$/i, '');
  const first = stripped.split('_')[0];
  return [...new Set([
    raw,
    stripped,
    first,
    stripped.replace(/[_-]?\d+$/i, ''),
    first.replace(/[_-]?\d+$/i, ''),
    stripped.replace(/[^a-z0-9]/g, ''),
    stripped.replace(/\d+/g, '')
  ].filter(value => value && value.length >= 2))];
}
function escapeRegExp(s) { return String(s).replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }
function escapeHtml(s) {
  return String(s).replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c]));
}
function isFormControlFocused() {
  const tag = document.activeElement?.tagName;
  return tag === 'INPUT' || tag === 'SELECT' || tag === 'TEXTAREA' || tag === 'BUTTON';
}
function safeNumber(value, fallback = 0) {
  const number = Number(value);
  return Number.isFinite(number) ? number : fallback;
}

function titleCaseZoneId(id) {
  return String(id || '')
    .replace(/[_-]+/g, ' ')
    .replace(/\b\w/g, letter => letter.toUpperCase());
}
function zoneDisplayName(id) {
  const key = String(id || '').toLowerCase();
  return ZONE_DISPLAY_NAMES[key] || titleCaseZoneId(key);
}

let WORLD_ALIAS_INDEX_CACHE = null;

function hashString(value) {
  let hash = 2166136261;
  for (let index = 0; index < String(value).length; index++) {
    hash ^= String(value).charCodeAt(index);
    hash = Math.imul(hash, 16777619);
  }
  return (hash >>> 0).toString(36);
}

function deterministicUnit(value, salt = '') {
  const number = parseInt(hashString(`${salt}:${value}`), 36) >>> 0;
  return (number % 1000000) / 1000000;
}

function nextBrowserFrame() {
  return new Promise(resolve => requestAnimationFrame(() => resolve()));
}

function yieldToBrowser() {
  const schedulerApi = globalThis.scheduler;
  if (schedulerApi && typeof schedulerApi.yield === 'function') {
    return schedulerApi.yield();
  }
  return new Promise(resolve => setTimeout(resolve, 0));
}

function canonicalWorldZoneId(id) {
  const key = String(id || '').toLowerCase();
  return WORLD_CANONICAL_ZONE_IDS[key] || key;
}

function normalizeWorldConnectionName(value) {
  return String(value || '')
    .replace(/[_`’]/g, "'")
    .replace(/'s\b/gi, 's')
    .replace(/^\s*to\s+/i, '')
    .replace(/\s*\([^)]*\)\s*$/g, '')
    .replace(/\bthe\b/gi, ' ')
    .replace(/cazic[- ]thule/gi, 'cazic thule')
    .replace(/[^a-z0-9]+/gi, ' ')
    .trim()
    .toLowerCase()
    .replace(/^the\s+/, '')
    .replace(/\s+/g, ' ');
}

function worldAliasIndex() {
  if (WORLD_ALIAS_INDEX_CACHE) return WORLD_ALIAS_INDEX_CACHE;
  const map = new Map();
  const add = (alias, id) => {
    const key = normalizeWorldConnectionName(alias);
    if (!key) return;
    const canonical = canonicalWorldZoneId(id);
    if (!map.has(key)) map.set(key, canonical);
  };
  for (const [id, display] of Object.entries(ZONE_DISPLAY_NAMES)) {
    add(id, id);
    add(display, id);
    add(display.replace(/\s*\([^)]*\)\s*/g, ' '), id);
    for (const part of display.split('/')) add(part, id);
  }
  for (const [alias, id] of Object.entries(WORLD_CONNECTION_ALIASES)) add(alias, id);
  return (WORLD_ALIAS_INDEX_CACHE = map);
}

function resolveWorldConnectionTargets(label, sourceId, availableWorldIds) {
  const sourceWorldId = canonicalWorldZoneId(sourceId);
  const resolveExact = (candidate) => {
    const targetName = normalizeWorldConnectionName(candidate);
    if (!targetName) return null;
    const sourceOverride = WORLD_SOURCE_TARGET_OVERRIDES[`${sourceWorldId}|${targetName}`] ||
      WORLD_SOURCE_TARGET_OVERRIDES[`${String(sourceId || '').toLowerCase()}|${targetName}`];
    const target = canonicalWorldZoneId(sourceOverride || worldAliasIndex().get(targetName) || '');
    if (!target || target === sourceWorldId || !availableWorldIds.has(target)) return null;
    return target;
  };

  const direct = resolveExact(label);
  if (direct) return [direct];

  // Some map labels describe two transport destinations in one point, for
  // example “to Erudin & South Qeynos (Translocator Jempar)”. Split only on
  // explicit conjunctions, then require every destination to resolve through
  // the exact alias table. Deliberately do not use substring matching: that
  // previously turned expansion labels such as “East Wastes:
  // Zeixshi-Kar's Awakening” into a false Wakening Land connection.
  const stripped = String(label || '')
    .replace(/^\s*to\s+/i, '')
    .replace(/\s*\([^)]*\)\s*$/g, '');
  const targets = [];
  for (const part of stripped.split(/\s*(?:&|\band\b)\s*/i)) {
    const target = resolveExact(`to ${part}`);
    if (target && !targets.includes(target)) targets.push(target);
  }
  return targets;
}

function classifyWorldConnection(label, sourceId, targetId) {
  const text = String(label || '').toLowerCase();
  const sourceWorldId = canonicalWorldZoneId(sourceId);
  const targetWorldId = canonicalWorldZoneId(targetId);
  const sourceGroup = ZONE_WORLD_GROUPS[sourceWorldId] || 'other';
  const targetGroup = ZONE_WORLD_GROUPS[targetWorldId] || 'other';
  if (/boat|dock|ship|translocator|ocean|sea route/.test(text)) return 'water';
  if (sourceWorldId === 'timorous' && WORLD_FORCED_ONE_WAY_CONNECTIONS.has(`${sourceWorldId}>${targetWorldId}`)) return 'portal';
  if (/teleport|portal|click|touch|pedestal|book|gem|orb|chair|painting|vase|torch/.test(text)) return 'portal';
  if (sourceGroup === 'planes' || targetGroup === 'planes') return 'teleport';
  if (sourceGroup !== targetGroup && [sourceWorldId, targetWorldId].some(id => ['oot', 'iceclad', 'erudsxing'].includes(id))) return 'water';
  return 'land';
}

function worldConnectionKey(a, b) {
  return [canonicalWorldZoneId(a), canonicalWorldZoneId(b)].sort().join('|');
}

function isWorldFirepotConnection(edge) {
  return Boolean(
    edge?.oneWay &&
    edge.from === 'timorous' &&
    WORLD_FIREPOT_DESTINATIONS.includes(edge.to)
  );
}

function quantile(values, fraction) {
  if (!values.length) return 0;
  const sorted = [...values].sort((a, b) => a - b);
  const position = clamp(fraction, 0, 1) * (sorted.length - 1);
  const lower = Math.floor(position);
  const upper = Math.ceil(position);
  if (lower === upper) return sorted[lower];
  const t = position - lower;
  return sorted[lower] * (1 - t) + sorted[upper] * t;
}

function buildWorldMapSnapshot(lines) {
  const source = (lines || []).filter(line => line.layer !== 2);
  if (!source.length) return null;
  const xs = [];
  const ys = [];
  for (const line of source) {
    // Match the exact top-down transform used by the 3D map: horizontal is EQ
    // Y and screen-down is -EQ X.
    xs.push(line.y1, line.y2);
    ys.push(-line.x1, -line.x2);
  }
  let minX = quantile(xs, xs.length > 200 ? 0.008 : 0);
  let maxX = quantile(xs, xs.length > 200 ? 0.992 : 1);
  let minY = quantile(ys, ys.length > 200 ? 0.008 : 0);
  let maxY = quantile(ys, ys.length > 200 ? 0.992 : 1);
  if (!(maxX > minX) || !(maxY > minY)) return null;
  const padX = Math.max(5, (maxX - minX) * 0.035);
  const padY = Math.max(5, (maxY - minY) * 0.035);
  minX -= padX; maxX += padX; minY -= padY; maxY += padY;

  const retained = source.filter(line => {
    const x1 = line.y1, y1 = -line.x1, x2 = line.y2, y2 = -line.x2;
    const midX = (x1 + x2) * 0.5;
    const midY = (y1 + y2) * 0.5;
    return midX >= minX && midX <= maxX && midY >= minY && midY <= maxY;
  });
  const stride = Math.max(1, Math.ceil(retained.length / WORLD_MAP_MAX_SEGMENTS));
  const segments = [];
  const centerX = (minX + maxX) * 0.5;
  const centerY = (minY + maxY) * 0.5;
  for (let index = 0; index < retained.length; index += stride) {
    const line = retained[index];
    const luminance = 0.2126 * line.r + 0.7152 * line.g + 0.0722 * line.b;
    const color = luminance < 45
      ? 0xc8d7e4
      : ((clamp(line.r, 0, 255) << 16) | (clamp(line.g, 0, 255) << 8) | clamp(line.b, 0, 255));
    segments.push(
      line.y1 - centerX, -line.x1 - centerY,
      line.y2 - centerX, -line.x2 - centerY,
      color
    );
  }
  const width = Math.max(1, maxX - minX);
  const height = Math.max(1, maxY - minY);
  return { segments, width, height, sourceSegments: source.length, retainedSegments: Math.ceil(retained.length / stride) };
}

function makeWorldNodeSize(snapshot) {
  // Every zone occupies the same compact card footprint. The map silhouette is
  // contained inside that card by prepareWorldMapPreviews(); its native aspect
  // ratio no longer changes the atlas geometry or pushes neighboring zones out
  // of their canonical positions.
  return { width: WORLD_MAP_NODE_WIDTH, height: WORLD_MAP_NODE_HEIGHT };
}

function worldNodeBasePosition(node, fallbackCounts) {
  const absolute = WORLD_ZONE_ABSOLUTE_POSITIONS[node.worldId];
  if (absolute) return { x: absolute.x, y: absolute.y, fixed: true };

  const groupLayout = WORLD_GROUP_LAYOUTS[node.group] || WORLD_GROUP_LAYOUTS.other;
  const slot = WORLD_ZONE_SCHEMATIC_SLOTS[node.worldId];
  if (slot) {
    return {
      x: groupLayout.x + slot[0] * WORLD_MAP_SLOT_X,
      y: groupLayout.y + slot[1] * WORLD_MAP_SLOT_Y,
      fixed: true
    };
  }

  const count = fallbackCounts.get(node.group) || 0;
  fallbackCounts.set(node.group, count + 1);
  const columns = groupLayout.fallbackColumns || 5;
  const column = count % columns;
  const row = Math.floor(count / columns);
  return {
    x: groupLayout.x + column * WORLD_MAP_SLOT_X,
    y: groupLayout.y + (8.4 + row) * WORLD_MAP_SLOT_Y,
    fixed: false
  };
}

function separateWorldNodes(nodes, passes, pullToBase) {
  for (let pass = 0; pass < passes; pass++) {
    let moved = false;
    for (let i = 0; i < nodes.length; i++) {
      const a = nodes[i];
      for (let j = i + 1; j < nodes.length; j++) {
        const b = nodes[j];
        const overlapX = (a.width + b.width) * 0.5 + 20 - Math.abs(b.x - a.x);
        const overlapY = (a.height + b.height) * 0.5 + 38 - Math.abs(b.y - a.y);
        if (overlapX <= 0 || overlapY <= 0) continue;
        moved = true;

        const aMobility = a.layoutFixed && !b.layoutFixed ? 0.18 : 0.5;
        const bMobility = b.layoutFixed && !a.layoutFixed ? 0.18 : 0.5;
        const mobilityTotal = Math.max(0.01, aMobility + bMobility);
        const aShare = aMobility / mobilityTotal;
        const bShare = bMobility / mobilityTotal;

        if (overlapX < overlapY) {
          const direction = b.x >= a.x ? 1 : -1;
          a.x -= overlapX * aShare * direction;
          b.x += overlapX * bShare * direction;
        } else {
          const direction = b.y >= a.y ? 1 : -1;
          a.y -= overlapY * aShare * direction;
          b.y += overlapY * bShare * direction;
        }
      }
    }

    if (pullToBase > 0) {
      for (const node of nodes) {
        const pull = node.layoutFixed ? pullToBase : pullToBase * 0.35;
        node.x += (node.baseX - node.x) * pull;
        node.y += (node.baseY - node.y) * pull;
      }
    }
    if (!moved) break;
  }
}

function layoutWorldGraph(nodes, edges) {
  const fallbackCounts = new Map();
  for (const node of nodes) {
    const base = worldNodeBasePosition(node, fallbackCounts);
    node.x = Math.round(base.x * 10) / 10;
    node.y = Math.round(base.y * 10) / 10;
  }

  // Do not run force, spring, or collision relaxation on canonical nodes. Even
  // a small all-node relaxation accumulates across a dense graph and was able
  // to lift Velious above Antonica, collapse region gaps, and turn the atlas
  // back into a single horizontal mass. Uniform card footprints and explicit
  // slots make the authored positions safe to use verbatim.
  return nodes;
}

function worldRegionBounds(nodes) {
  const regions = [];
  for (const group of WORLD_FRAME_GROUPS) {
    const groupNodes = nodes.filter(node => node.group === group && !WORLD_FRAME_EXCLUDED_ZONE_IDS.has(node.worldId));
    if (!groupNodes.length) continue;
    let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
    for (const node of groupNodes) {
      minX = Math.min(minX, node.x - node.width * 0.5);
      maxX = Math.max(maxX, node.x + node.width * 0.5);
      minY = Math.min(minY, node.y - node.height * 0.5);
      maxY = Math.max(maxY, node.y + node.height * 0.5 + 30);
    }
    const layout = WORLD_GROUP_LAYOUTS[group];
    regions.push({
      group,
      label: layout?.label || titleCaseZoneId(group),
      minX: minX - 62,
      minY: minY - 105,
      maxX: maxX + 62,
      maxY: maxY + 58
    });
  }
  return regions;
}

function worldNodeBoundaryPoint(node, towardX, towardY) {
  const dx = towardX - node.x;
  const dy = towardY - node.y;
  if (Math.abs(dx) < 0.001 && Math.abs(dy) < 0.001) return { x: node.x, y: node.y };
  const tx = Math.abs(dx) > 0.001 ? (node.width * 0.5) / Math.abs(dx) : Infinity;
  const ty = Math.abs(dy) > 0.001 ? (node.height * 0.5) / Math.abs(dy) : Infinity;
  const t = Math.min(tx, ty);
  return { x: node.x + dx * t, y: node.y + dy * t };
}


class WorldRouteHeap {
  constructor() { this.items = []; }
  push(value, priority) {
    const item = { value, priority };
    const items = this.items;
    items.push(item);
    let index = items.length - 1;
    while (index > 0) {
      const parent = (index - 1) >> 1;
      if (items[parent].priority <= priority) break;
      items[index] = items[parent];
      index = parent;
    }
    items[index] = item;
  }
  pop() {
    const items = this.items;
    if (!items.length) return null;
    const first = items[0];
    const last = items.pop();
    if (items.length && last) {
      let index = 0;
      while (true) {
        const left = index * 2 + 1;
        const right = left + 1;
        if (left >= items.length) break;
        let child = left;
        if (right < items.length && items[right].priority < items[left].priority) child = right;
        if (items[child].priority >= last.priority) break;
        items[index] = items[child];
        index = child;
      }
      items[index] = last;
    }
    return first.value;
  }
  get size() { return this.items.length; }
}

function buildWorldRouteContext(nodes) {
  let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
  for (const node of nodes) {
    minX = Math.min(minX, node.x - node.width * 0.5);
    maxX = Math.max(maxX, node.x + node.width * 0.5);
    minY = Math.min(minY, node.y - node.height * 0.5);
    maxY = Math.max(maxY, node.y + node.height * 0.5);
  }
  const minIX = Math.floor((minX - WORLD_ROUTE_PADDING) / WORLD_ROUTE_GRID);
  const minIY = Math.floor((minY - WORLD_ROUTE_PADDING) / WORLD_ROUTE_GRID);
  const maxIX = Math.ceil((maxX + WORLD_ROUTE_PADDING) / WORLD_ROUTE_GRID);
  const maxIY = Math.ceil((maxY + WORLD_ROUTE_PADDING) / WORLD_ROUTE_GRID);
  const width = maxIX - minIX + 1;
  const height = maxIY - minIY + 1;
  const blocked = new Uint8Array(width * height);
  const horizontalUsage = new Uint16Array(width * height);
  const verticalUsage = new Uint16Array(width * height);
  const cellIndex = (ix, iy) => {
    if (ix < minIX || ix > maxIX || iy < minIY || iy > maxIY) return -1;
    return (iy - minIY) * width + (ix - minIX);
  };

  for (const node of nodes) {
    const left = node.x - node.width * 0.5 - WORLD_ROUTE_CLEARANCE;
    const right = node.x + node.width * 0.5 + WORLD_ROUTE_CLEARANCE;
    const top = node.y - node.height * 0.5 - WORLD_ROUTE_CLEARANCE;
    const bottom = node.y + node.height * 0.5 + WORLD_ROUTE_CLEARANCE;
    const fromIX = Math.floor(left / WORLD_ROUTE_GRID);
    const toIX = Math.ceil(right / WORLD_ROUTE_GRID);
    const fromIY = Math.floor(top / WORLD_ROUTE_GRID);
    const toIY = Math.ceil(bottom / WORLD_ROUTE_GRID);
    for (let iy = fromIY; iy <= toIY; iy++) {
      const y = iy * WORLD_ROUTE_GRID;
      if (y < top || y > bottom) continue;
      for (let ix = fromIX; ix <= toIX; ix++) {
        const x = ix * WORLD_ROUTE_GRID;
        if (x < left || x > right) continue;
        const index = cellIndex(ix, iy);
        if (index >= 0) blocked[index] = 1;
      }
    }
  }

  return {
    minIX, minIY, maxIX, maxIY, width, height,
    blocked, horizontalUsage, verticalUsage, cellIndex
  };
}

function worldRoutePort(node, side, offset = 0) {
  const normalizedOffset = clamp(offset, -WORLD_ROUTE_PORT_SPREAD, WORLD_ROUTE_PORT_SPREAD);
  let anchorX = node.x;
  let anchorY = node.y;
  let routeX = node.x;
  let routeY = node.y;
  if (side === 'left' || side === 'right') {
    anchorY += normalizedOffset * Math.max(8, node.height * 0.5 - 15);
    if (side === 'left') {
      anchorX -= node.width * 0.5;
      routeX = anchorX - WORLD_ROUTE_CLEARANCE - WORLD_ROUTE_GRID * 0.55;
    } else {
      anchorX += node.width * 0.5;
      routeX = anchorX + WORLD_ROUTE_CLEARANCE + WORLD_ROUTE_GRID * 0.55;
    }
    routeY = anchorY;
  } else {
    anchorX += normalizedOffset * Math.max(8, node.width * 0.5 - 17);
    if (side === 'top') {
      anchorY -= node.height * 0.5;
      routeY = anchorY - WORLD_ROUTE_CLEARANCE - WORLD_ROUTE_GRID * 0.55;
    } else {
      anchorY += node.height * 0.5;
      routeY = anchorY + WORLD_ROUTE_CLEARANCE + WORLD_ROUTE_GRID * 0.55;
    }
    routeX = anchorX;
  }
  let ix;
  let iy;
  if (side === 'left') ix = Math.floor(routeX / WORLD_ROUTE_GRID);
  else if (side === 'right') ix = Math.ceil(routeX / WORLD_ROUTE_GRID);
  else ix = Math.round(routeX / WORLD_ROUTE_GRID);
  if (side === 'top') iy = Math.floor(routeY / WORLD_ROUTE_GRID);
  else if (side === 'bottom') iy = Math.ceil(routeY / WORLD_ROUTE_GRID);
  else iy = Math.round(routeY / WORLD_ROUTE_GRID);
  return {
    side,
    offset: normalizedOffset,
    anchor: { x: anchorX, y: anchorY },
    route: { x: ix * WORLD_ROUTE_GRID, y: iy * WORLD_ROUTE_GRID },
    ix,
    iy
  };
}

function primaryWorldPortSide(node, other) {
  const dx = other.x - node.x;
  const dy = other.y - node.y;
  if (Math.abs(dx) >= Math.abs(dy)) return dx >= 0 ? 'right' : 'left';
  return dy >= 0 ? 'bottom' : 'top';
}

function worldPortAssignmentKey(edge, worldId) {
  return `${worldConnectionKey(edge.a, edge.b)}@${worldId}`;
}

function assignWorldEdgePorts(nodes, edges) {
  const nodeById = new Map(nodes.map(node => [node.worldId, node]));
  const requests = new Map();
  const assignments = new Map();
  for (const edge of edges) {
    if (isWorldFirepotConnection(edge)) continue;
    const a = nodeById.get(edge.oneWay ? edge.from : edge.a);
    const b = nodeById.get(edge.oneWay ? edge.to : edge.b);
    if (!a || !b) continue;
    const endpoints = [[a, b], [b, a]];
    for (const [node, other] of endpoints) {
      const side = primaryWorldPortSide(node, other);
      const key = `${node.worldId}:${side}`;
      if (!requests.has(key)) requests.set(key, []);
      const horizontalSide = side === 'left' || side === 'right';
      const delta = horizontalSide ? other.y - node.y : other.x - node.x;
      const span = horizontalSide ? node.height : node.width;
      const desired = clamp(delta / Math.max(1, span * 2.1), -WORLD_ROUTE_PORT_SPREAD, WORLD_ROUTE_PORT_SPREAD);
      requests.get(key).push({ edge, node, other, side, desired });
    }
  }
  for (const list of requests.values()) {
    list.sort((left, right) => left.desired - right.desired || worldConnectionKey(left.edge.a, left.edge.b).localeCompare(worldConnectionKey(right.edge.a, right.edge.b)));
    const minimumGap = Math.min(0.24, WORLD_ROUTE_PORT_SPREAD * 1.75 / Math.max(1, list.length - 1));
    const offsets = list.map(item => item.desired);
    for (let index = 1; index < offsets.length; index++) offsets[index] = Math.max(offsets[index], offsets[index - 1] + minimumGap);
    if (offsets.length && offsets[offsets.length - 1] > WORLD_ROUTE_PORT_SPREAD) {
      const shift = offsets[offsets.length - 1] - WORLD_ROUTE_PORT_SPREAD;
      for (let index = 0; index < offsets.length; index++) offsets[index] -= shift;
    }
    if (offsets.length && offsets[0] < -WORLD_ROUTE_PORT_SPREAD) {
      const shift = -WORLD_ROUTE_PORT_SPREAD - offsets[0];
      for (let index = 0; index < offsets.length; index++) offsets[index] += shift;
    }
    for (let index = 0; index < list.length; index++) {
      const item = list[index];
      assignments.set(worldPortAssignmentKey(item.edge, item.node.worldId), { side: item.side, offset: clamp(offsets[index], -WORLD_ROUTE_PORT_SPREAD, WORLD_ROUTE_PORT_SPREAD) });
    }
  }
  return assignments;
}

function preferredWorldPortPairs(a, b) {
  const dx = b.x - a.x;
  const dy = b.y - a.y;
  const horizontal = dx >= 0 ? ['right', 'left'] : ['left', 'right'];
  const vertical = dy >= 0 ? ['bottom', 'top'] : ['top', 'bottom'];
  const oppositeVertical = dy >= 0 ? ['top', 'bottom'] : ['bottom', 'top'];
  const oppositeHorizontal = dx >= 0 ? ['left', 'right'] : ['right', 'left'];
  const pairs = Math.abs(dx) >= Math.abs(dy)
    ? [horizontal, vertical, [horizontal[0], vertical[1]], [vertical[0], horizontal[1]], oppositeVertical, oppositeHorizontal]
    : [vertical, horizontal, [vertical[0], horizontal[1]], [horizontal[0], vertical[1]], oppositeHorizontal, oppositeVertical];
  const seen = new Set();
  return pairs.filter(pair => {
    const key = pair.join('|');
    if (seen.has(key)) return false;
    seen.add(key);
    return true;
  });
}

function worldRouteCellPenalty(context, nextCell, horizontal) {
  const same = horizontal ? context.horizontalUsage[nextCell] : context.verticalUsage[nextCell];
  const crossing = horizontal ? context.verticalUsage[nextCell] : context.horizontalUsage[nextCell];
  let nearby = 0;
  const localX = nextCell % context.width;
  const localY = Math.floor(nextCell / context.width);
  for (const [dx, dy] of [[1, 0], [-1, 0], [0, 1], [0, -1]]) {
    const x = localX + dx;
    const y = localY + dy;
    if (x < 0 || x >= context.width || y < 0 || y >= context.height) continue;
    const neighbor = y * context.width + x;
    nearby += context.horizontalUsage[neighbor] + context.verticalUsage[neighbor];
  }
  return same * WORLD_ROUTE_REUSE_COST + crossing * WORLD_ROUTE_CROSS_COST + nearby * WORLD_ROUTE_NEAR_COST;
}

function worldRouteCellHasLine(context, cell) {
  return cell >= 0 && (context.horizontalUsage[cell] > 0 || context.verticalUsage[cell] > 0);
}

function worldRouteNearEndpoint(ix, iy, port, radius = 0) {
  return Math.abs(ix - port.ix) + Math.abs(iy - port.iy) <= radius;
}

function worldRouteLaneBlocked(context, nextIX, nextIY, horizontal, strict) {
  const nextCell = context.cellIndex(nextIX, nextIY);
  if (nextCell < 0) return true;
  // Even the soft router may cross another line, but it may never run along
  // an already occupied lane. That distinction keeps necessary cartographic
  // crossings possible while preventing two zone connections from visually
  // merging into one line near a shared card.
  if (!strict) {
    const sameDirection = horizontal ? context.horizontalUsage : context.verticalUsage;
    return sameDirection[nextCell] > 0;
  }
  if (worldRouteCellHasLine(context, nextCell)) return true;
  for (let dy = -WORLD_ROUTE_STRICT_GAP; dy <= WORLD_ROUTE_STRICT_GAP; dy++) {
    for (let dx = -WORLD_ROUTE_STRICT_GAP; dx <= WORLD_ROUTE_STRICT_GAP; dx++) {
      if (!dx && !dy) continue;
      const neighbor = context.cellIndex(nextIX + dx, nextIY + dy);
      if (worldRouteCellHasLine(context, neighbor)) return true;
    }
  }
  return false;
}

function findWorldGridRoute(context, startPort, endPort, strict = true) {
  const startCell = context.cellIndex(startPort.ix, startPort.iy);
  const endCell = context.cellIndex(endPort.ix, endPort.iy);
  if (startCell < 0 || endCell < 0 || context.blocked[startCell] || context.blocked[endCell]) return null;
  const directions = [
    { dx: 1, dy: 0 },
    { dx: 0, dy: 1 },
    { dx: -1, dy: 0 },
    { dx: 0, dy: -1 }
  ];
  const sideDirection = { right: 0, bottom: 1, left: 2, top: 3 };
  const startDirection = sideDirection[startPort.side];
  const heap = new WorldRouteHeap();
  const scores = new Map();
  const parents = new Map();
  const startState = startCell * 4 + startDirection;
  scores.set(startState, 0);
  heap.push({ ix: startPort.ix, iy: startPort.iy, direction: startDirection, state: startState, score: 0 }, 0);
  let bestState = null;
  let iterations = 0;
  const maxIterations = Math.max(30000, context.width * context.height * 4);

  while (heap.size && iterations++ < maxIterations) {
    const current = heap.pop();
    const currentScore = scores.get(current.state);
    if (currentScore === undefined || Math.abs(currentScore - current.score) > 0.0001) continue;
    const currentCell = context.cellIndex(current.ix, current.iy);
    if (currentCell === endCell) {
      bestState = current.state;
      break;
    }
    for (let direction = 0; direction < directions.length; direction++) {
      const nextIX = current.ix + directions[direction].dx;
      const nextIY = current.iy + directions[direction].dy;
      const nextCell = context.cellIndex(nextIX, nextIY);
      if (nextCell < 0) continue;
      if (context.blocked[nextCell] && nextCell !== endCell && nextCell !== startCell) continue;
      const horizontal = direction === 0 || direction === 2;
      if (worldRouteLaneBlocked(context, nextIX, nextIY, horizontal, strict)) continue;
      const nextState = nextCell * 4 + direction;
      const turnCost = direction === current.direction ? 0 : WORLD_ROUTE_TURN_COST;
      const occupancyCost = (nextCell === endCell || nextCell === startCell) ? 0 : worldRouteCellPenalty(context, nextCell, horizontal);
      const nextScore = currentScore + 1 + turnCost + occupancyCost;
      const known = scores.get(nextState);
      if (known !== undefined && known <= nextScore) continue;
      scores.set(nextState, nextScore);
      parents.set(nextState, current.state);
      const heuristic = Math.abs(endPort.ix - nextIX) + Math.abs(endPort.iy - nextIY);
      heap.push({ ix: nextIX, iy: nextIY, direction, state: nextState, score: nextScore }, nextScore + heuristic);
    }
  }
  if (bestState === null) return null;

  const cells = [];
  let state = bestState;
  while (state !== undefined) {
    const cell = Math.floor(state / 4);
    const localX = cell % context.width;
    const localY = Math.floor(cell / context.width);
    const ix = context.minIX + localX;
    const iy = context.minIY + localY;
    cells.push({ ix, iy, x: ix * WORLD_ROUTE_GRID, y: iy * WORLD_ROUTE_GRID, cell });
    if (state === startState) break;
    state = parents.get(state);
  }
  cells.reverse();
  if (!cells.length) return null;
  return { cells, score: scores.get(bestState) || 0 };
}

function commitWorldGridRoute(context, cells) {
  for (let index = 1; index < cells.length; index++) {
    const before = cells[index - 1];
    const current = cells[index];
    const horizontal = before.iy === current.iy;
    const usage = horizontal ? context.horizontalUsage : context.verticalUsage;
    if (before.cell >= 0 && usage[before.cell] < 65535) usage[before.cell]++;
    if (current.cell >= 0 && usage[current.cell] < 65535) usage[current.cell]++;
  }
}

function commitWorldRoutePoints(context, points) {
  for (let index = 1; index < points.length; index++) {
    const from = points[index - 1];
    const to = points[index];
    const dx = to.x - from.x;
    const dy = to.y - from.y;
    const distance = Math.max(Math.abs(dx), Math.abs(dy));
    const steps = Math.max(1, Math.ceil(distance / (WORLD_ROUTE_GRID * 0.45)));
    const horizontal = Math.abs(dx) >= Math.abs(dy);
    for (let step = 0; step <= steps; step++) {
      const t = step / steps;
      const ix = Math.round((from.x + dx * t) / WORLD_ROUTE_GRID);
      const iy = Math.round((from.y + dy * t) / WORLD_ROUTE_GRID);
      const cell = context.cellIndex(ix, iy);
      if (cell < 0) continue;
      const primary = horizontal ? context.horizontalUsage : context.verticalUsage;
      if (primary[cell] < 65535) primary[cell]++;
      if (Math.abs(dx) > 0.1 && Math.abs(dy) > 0.1) {
        const secondary = horizontal ? context.verticalUsage : context.horizontalUsage;
        if (secondary[cell] < 65535) secondary[cell]++;
      }
    }
  }
}

function simplifyWorldRoute(points) {
  const clean = [];
  for (const point of points) {
    const previous = clean[clean.length - 1];
    if (previous && Math.abs(previous.x - point.x) < 0.01 && Math.abs(previous.y - point.y) < 0.01) continue;
    clean.push({ x: point.x, y: point.y });
  }
  if (clean.length <= 2) return clean;
  const simplified = [clean[0]];
  for (let index = 1; index < clean.length - 1; index++) {
    const before = simplified[simplified.length - 1];
    const current = clean[index];
    const after = clean[index + 1];
    const sameX = Math.abs(before.x - current.x) < 0.01 && Math.abs(current.x - after.x) < 0.01;
    const sameY = Math.abs(before.y - current.y) < 0.01 && Math.abs(current.y - after.y) < 0.01;
    if (!sameX && !sameY) simplified.push(current);
  }
  simplified.push(clean[clean.length - 1]);
  return simplified;
}


function worldRoutePolylineLength(points) {
  let length = 0;
  for (let index = 1; index < points.length; index++) {
    length += Math.abs(points[index].x - points[index - 1].x) + Math.abs(points[index].y - points[index - 1].y);
  }
  return length;
}

function worldRouteSegmentHitsNode(from, to, node, clearance = 8) {
  const left = node.x - node.width * 0.5 - clearance;
  const right = node.x + node.width * 0.5 + clearance;
  const top = node.y - node.height * 0.5 - clearance;
  const bottom = node.y + node.height * 0.5 + clearance;
  if (Math.abs(from.y - to.y) < 0.01) {
    const y = from.y;
    if (y <= top || y >= bottom) return false;
    return Math.max(Math.min(from.x, to.x), left) < Math.min(Math.max(from.x, to.x), right);
  }
  if (Math.abs(from.x - to.x) < 0.01) {
    const x = from.x;
    if (x <= left || x >= right) return false;
    return Math.max(Math.min(from.y, to.y), top) < Math.min(Math.max(from.y, to.y), bottom);
  }
  return false;
}

function worldRoutePolylineHitsNodes(points, nodes, endpointIds) {
  for (let index = 1; index < points.length; index++) {
    const from = points[index - 1];
    const to = points[index];
    for (const node of nodes) {
      if (endpointIds.has(node.worldId)) continue;
      if (worldRouteSegmentHitsNode(from, to, node)) return true;
    }
  }
  return false;
}

function worldRoutePolylineOccupancy(context, points) {
  let reuse = 0;
  let crossings = 0;
  let near = 0;
  for (let index = 1; index < points.length; index++) {
    const from = points[index - 1];
    const to = points[index];
    const dx = to.x - from.x;
    const dy = to.y - from.y;
    const distance = Math.max(Math.abs(dx), Math.abs(dy));
    const steps = Math.max(1, Math.ceil(distance / (WORLD_ROUTE_GRID * 0.65)));
    const horizontal = Math.abs(dx) >= Math.abs(dy);
    for (let step = 0; step <= steps; step++) {
      const t = step / steps;
      const ix = Math.round((from.x + dx * t) / WORLD_ROUTE_GRID);
      const iy = Math.round((from.y + dy * t) / WORLD_ROUTE_GRID);
      const cell = context.cellIndex(ix, iy);
      if (cell < 0) continue;
      const same = horizontal ? context.horizontalUsage[cell] : context.verticalUsage[cell];
      const cross = horizontal ? context.verticalUsage[cell] : context.horizontalUsage[cell];
      reuse += same;
      crossings += cross;
      const localX = cell % context.width;
      const localY = Math.floor(cell / context.width);
      for (const [ox, oy] of [[1, 0], [-1, 0], [0, 1], [0, -1]]) {
        const x = localX + ox;
        const y = localY + oy;
        if (x < 0 || x >= context.width || y < 0 || y >= context.height) continue;
        const neighbor = y * context.width + x;
        near += context.horizontalUsage[neighbor] + context.verticalUsage[neighbor];
      }
    }
  }
  return { reuse, crossings, near };
}

function directWorldRouteCandidates(startPort, endPort) {
  const sx = startPort.route.x;
  const sy = startPort.route.y;
  const ex = endPort.route.x;
  const ey = endPort.route.y;
  const candidates = [];
  const add = points => candidates.push(simplifyWorldRoute([
    startPort.anchor,
    startPort.route,
    ...points,
    endPort.route,
    endPort.anchor
  ]));

  // The two ordinary elbows are the visual language used by classic EQ
  // connection maps. Additional midpoint channels let parallel edges separate
  // without forcing a route around an entire continent.
  add([{ x: ex, y: sy }]);
  add([{ x: sx, y: ey }]);
  for (const ratio of [0.35, 0.5, 0.65]) {
    const midX = Math.round((sx + (ex - sx) * ratio) / WORLD_ROUTE_GRID) * WORLD_ROUTE_GRID;
    const midY = Math.round((sy + (ey - sy) * ratio) / WORLD_ROUTE_GRID) * WORLD_ROUTE_GRID;
    add([{ x: midX, y: sy }, { x: midX, y: ey }]);
    add([{ x: sx, y: midY }, { x: ex, y: midY }]);
  }
  return candidates;
}

function findDirectWorldRoute(nodes, context, a, b, portTuples) {
  const endpointIds = new Set([a.worldId, b.worldId]);
  const directLength = Math.max(WORLD_ROUTE_GRID, Math.abs(b.x - a.x) + Math.abs(b.y - a.y));
  const sameGroup = a.group === b.group;
  const maximumDetour = sameGroup ? 1.72 : 2.05;
  let best = null;
  let bestClear = null;
  let assignedClear = null;
  for (let tupleIndex = 0; tupleIndex < portTuples.length; tupleIndex++) {
    const [startSide, endSide, startOffset, endOffset] = portTuples[tupleIndex];
    const startPort = worldRoutePort(a, startSide, startOffset);
    const endPort = worldRoutePort(b, endSide, endOffset);
    for (const points of directWorldRouteCandidates(startPort, endPort)) {
      if (worldRoutePolylineHitsNodes(points, nodes, endpointIds)) continue;
      const length = worldRoutePolylineLength(points);
      if (length > directLength * maximumDetour + 80) continue;
      const occupancy = worldRoutePolylineOccupancy(context, points);
      const bends = Math.max(0, points.length - 2);
      // Crossing an existing route is preferable to an enormous detour. The
      // later bridge pass makes that crossing explicit. Reusing the same lane
      // is penalized more heavily because it visually merges two connections.
      const metric = length + bends * 8 + occupancy.reuse * 22 + occupancy.crossings * 7 + occupancy.near * 0.8;
      const candidate = { points, metric, length, occupancy };
      if (!best || metric < best.metric) best = candidate;
      if (occupancy.reuse === 0 && (!bestClear || metric < bestClear.metric)) bestClear = candidate;
      // The first tuple is the per-node port assignment. Prefer it whenever it
      // yields a clear route so multiple connections cannot collapse onto the
      // same card anchor merely because a zero-offset fallback is a few pixels
      // shorter.
      if (tupleIndex === 0 && occupancy.reuse === 0 && (!assignedClear || metric < assignedClear.metric)) assignedClear = candidate;
    }
  }
  // Never knowingly merge two visual routes into the same exact lane. When
  // every short candidate would reuse a lane, defer to the strict grid router,
  // which can spend the extra whitespace to keep the lines independent.
  return assignedClear || bestClear || null;
}

function routeWorldGraphEdges(nodes, edges) {
  const nodeById = new Map(nodes.map(node => [node.worldId, node]));
  const context = buildWorldRouteContext(nodes);
  const portAssignments = assignWorldEdgePorts(nodes, edges);
  const degree = new Map();
  for (const edge of edges) {
    degree.set(edge.a, (degree.get(edge.a) || 0) + 1);
    degree.set(edge.b, (degree.get(edge.b) || 0) + 1);
  }
  const ordered = [...edges].filter(edge => !isWorldFirepotConnection(edge)).sort((left, right) => {
    const la = nodeById.get(left.oneWay ? left.from : left.a);
    const lb = nodeById.get(left.oneWay ? left.to : left.b);
    const ra = nodeById.get(right.oneWay ? right.from : right.a);
    const rb = nodeById.get(right.oneWay ? right.to : right.b);
    const leftSameGroup = Boolean(la && lb && la.group === lb.group);
    const rightSameGroup = Boolean(ra && rb && ra.group === rb.group);
    const leftTransport = left.type === 'water' || left.type === 'teleport';
    const rightTransport = right.type === 'water' || right.type === 'teleport';
    const leftClass = leftSameGroup ? (leftTransport ? 1 : 0) : 2;
    const rightClass = rightSameGroup ? (rightTransport ? 1 : 0) : 2;
    if (leftClass !== rightClass) return leftClass - rightClass;
    const leftDistance = la && lb ? Math.abs(lb.x - la.x) + Math.abs(lb.y - la.y) : Infinity;
    const rightDistance = ra && rb ? Math.abs(rb.x - ra.x) + Math.abs(rb.y - ra.y) : Infinity;
    if (leftDistance !== rightDistance) return leftDistance - rightDistance;
    const leftDegree = (degree.get(left.a) || 0) + (degree.get(left.b) || 0);
    const rightDegree = (degree.get(right.a) || 0) + (degree.get(right.b) || 0);
    return rightDegree - leftDegree || worldConnectionKey(left.a, left.b).localeCompare(worldConnectionKey(right.a, right.b));
  });

  for (const edge of ordered) {
    const a = nodeById.get(edge.oneWay ? edge.from : edge.a);
    const b = nodeById.get(edge.oneWay ? edge.to : edge.b);
    if (!a || !b) continue;
    const assignedA = portAssignments.get(worldPortAssignmentKey(edge, a.worldId));
    const assignedB = portAssignments.get(worldPortAssignmentKey(edge, b.worldId));
    const candidates = [];
    if (assignedA && assignedB) candidates.push([assignedA.side, assignedB.side, assignedA.offset, assignedB.offset]);
    for (const [startSide, endSide] of preferredWorldPortPairs(a, b)) {
      if (assignedA && assignedB && startSide === assignedA.side && endSide === assignedB.side) continue;
      candidates.push([startSide, endSide, 0, 0]);
    }

    let routed = null;
    let usedStrictRoute = false;
    const directLength = Math.max(WORLD_ROUTE_GRID, Math.abs(b.x - a.x) + Math.abs(b.y - a.y));
    const directRoute = findDirectWorldRoute(nodes, context, a, b, candidates);
    if (directRoute) {
      routed = directRoute.points;
      commitWorldRoutePoints(context, routed);
      edge.routeStyle = 'direct-orthogonal';
    }
    const evaluate = (tuple, strict) => {
      const [startSide, endSide, startOffset, endOffset] = tuple;
      const startPort = worldRoutePort(a, startSide, startOffset);
      const endPort = worldRoutePort(b, endSide, endOffset);
      const result = findWorldGridRoute(context, startPort, endPort, strict);
      if (!result) return null;
      const points = simplifyWorldRoute([
        startPort.anchor,
        startPort.route,
        ...result.cells.map(cell => ({ x: cell.x, y: cell.y })),
        endPort.route,
        endPort.anchor
      ]);
      // The grid search operates on route cells, but each route also has a
      // short anchor leg between the card edge and its first grid cell. Check
      // the complete polyline before accepting a strict route so that two
      // edges cannot silently share that anchor leg.
      const occupancy = worldRoutePolylineOccupancy(context, points);
      if (strict && occupancy.reuse > 0) return null;
      let geometricLength = 0;
      for (let index = 1; index < points.length; index++) {
        geometricLength += Math.abs(points[index].x - points[index - 1].x) + Math.abs(points[index].y - points[index - 1].y);
      }
      const occupancyCost = occupancy.reuse * 95 + occupancy.crossings * 9 + occupancy.near * 0.9;
      return {
        points,
        result,
        occupancy,
        geometricLength,
        metric: geometricLength + occupancyCost + (strict ? 0 : result.score * 0.035),
        strict
      };
    };

    if (!routed) {
      let bestStrict = null;
      for (const tuple of candidates) {
        const candidate = evaluate(tuple, true);
        if (!candidate) continue;
        if (!bestStrict || candidate.metric < bestStrict.metric) bestStrict = candidate;
        if (candidate.geometricLength <= directLength * 1.42 + 70) break;
      }
      const detourLimit = a.group === b.group ? 1.78 : 2.35;
      let chosen = bestStrict;
      if (!bestStrict || bestStrict.geometricLength > directLength * detourLimit) {
        let bestSoft = null;
        for (const tuple of candidates) {
          const candidate = evaluate(tuple, false);
          if (!candidate) continue;
          if (!bestSoft || candidate.metric < bestSoft.metric) bestSoft = candidate;
          if (candidate.geometricLength <= directLength * 1.28 + 55) break;
        }
        if (bestSoft && (!bestStrict || bestSoft.geometricLength < bestStrict.geometricLength * 0.9)) chosen = bestSoft;
      }
      if (chosen) {
        routed = chosen.points;
        commitWorldRoutePoints(context, routed);
        usedStrictRoute = chosen.strict;
        edge.routeStyle = chosen.strict ? 'grid-strict' : 'grid-soft';
      }
    }
    if (!routed) {
      const start = worldNodeBoundaryPoint(a, b.x, b.y);
      const end = worldNodeBoundaryPoint(b, a.x, a.y);
      routed = [start, end];
    }
    edge.route = routed;
    edge.routeStrict = usedStrictRoute;
  }
  return edges;
}

function worldRouteSegmentIntersection(a, b, c, d) {
  const rX = b.x - a.x;
  const rY = b.y - a.y;
  const sX = d.x - c.x;
  const sY = d.y - c.y;
  const denominator = rX * sY - rY * sX;
  if (Math.abs(denominator) < 0.0001) return null;
  const qX = c.x - a.x;
  const qY = c.y - a.y;
  const t = (qX * sY - qY * sX) / denominator;
  const u = (qX * rY - qY * rX) / denominator;
  if (t < -0.0001 || t > 1.0001 || u < -0.0001 || u > 1.0001) return null;
  return {
    x: a.x + rX * t,
    y: a.y + rY * t,
    horizontal: Math.abs(rX) >= Math.abs(rY)
  };
}

function worldRouteBridgePriority(edge) {
  if (edge.type === 'portal' || edge.type === 'teleport') return 3;
  if (edge.type === 'water') return 2;
  return 1;
}

function annotateWorldRouteCrossings(nodes, edges) {
  const nodeById = new Map(nodes.map(node => [node.worldId, node]));
  const routed = edges.filter(edge => !isWorldFirepotConnection(edge) && Array.isArray(edge.route) && edge.route.length >= 2);
  for (const edge of routed) edge.routeBridges = [];

  const nearSharedEndpoint = (point, left, right) => {
    const shared = [left.a, left.b].filter(id => id === right.a || id === right.b);
    return shared.some(id => {
      const node = nodeById.get(id);
      if (!node) return false;
      return Math.abs(point.x - node.x) <= node.width * 0.5 + 36 &&
        Math.abs(point.y - node.y) <= node.height * 0.5 + 36;
    });
  };

  for (let leftIndex = 0; leftIndex < routed.length; leftIndex++) {
    const left = routed[leftIndex];
    for (let rightIndex = leftIndex + 1; rightIndex < routed.length; rightIndex++) {
      const right = routed[rightIndex];
      for (let li = 1; li < left.route.length; li++) {
        const la = left.route[li - 1];
        const lb = left.route[li];
        for (let ri = 1; ri < right.route.length; ri++) {
          const ra = right.route[ri - 1];
          const rb = right.route[ri];
          const crossing = worldRouteSegmentIntersection(la, lb, ra, rb);
          if (!crossing || nearSharedEndpoint(crossing, left, right)) continue;

          const leftPriority = worldRouteBridgePriority(left);
          const rightPriority = worldRouteBridgePriority(right);
          const owner = rightPriority > leftPriority || (rightPriority === leftPriority && rightIndex > leftIndex) ? right : left;
          const ownerA = owner === left ? la : ra;
          const ownerB = owner === left ? lb : rb;
          const bridge = {
            x: crossing.x,
            y: crossing.y,
            horizontal: Math.abs(ownerB.x - ownerA.x) >= Math.abs(ownerB.y - ownerA.y)
          };
          if (!owner.routeBridges.some(existing => Math.hypot(existing.x - bridge.x, existing.y - bridge.y) < 8)) {
            owner.routeBridges.push(bridge);
          }
        }
      }
    }
  }
  return edges;
}

function worldFirepotInsetNode() {
  return {
    worldId: '__firepot_inset',
    id: '__firepot_inset',
    group: 'other',
    x: (WORLD_FIREPOT_INSET.minX + WORLD_FIREPOT_INSET.maxX) * 0.5,
    y: (WORLD_FIREPOT_INSET.minY + WORLD_FIREPOT_INSET.maxY) * 0.5,
    width: WORLD_FIREPOT_INSET.maxX - WORLD_FIREPOT_INSET.minX,
    height: WORLD_FIREPOT_INSET.maxY - WORLD_FIREPOT_INSET.minY
  };
}

function routeWorldFirepotInset(nodes) {
  const insetNode = worldFirepotInsetNode();
  const edge = {
    a: '__firepot_inset',
    b: 'timorous',
    from: 'timorous',
    to: '__firepot_inset',
    oneWay: true,
    type: 'portal'
  };
  routeWorldGraphEdges([...nodes, insetNode], [edge]);
  return edge.route || null;
}

function colorNear(point, color, tolerance = 5) {
  return Math.abs(point.r - color.r) <= tolerance &&
    Math.abs(point.g - color.g) <= tolerance &&
    Math.abs(point.b - color.b) <= tolerance;
}
function isSuppressedMapLabel(label) {
  // Map-pack credit labels are useful in the source files but are not useful
  // as in-view annotations, wiki links, or Path-to-location destinations. Suppress
  // any point label that explicitly names Brewall, including possessive forms.
  return /\bbrewall(?:['’]s)?\b/i.test(String(label || '').trim());
}
function isNamedMobPoint(point) {
  const label = String(point?.label || '').trim();
  if (!label) return false;
  if (/\((?:named|hunter)(?:[, )]|$)/i.test(label)) return true;
  if (colorNear(point, NAMED_MOB_COLOR, 8)) return true;

  // Brewall-style maps use black for ordinary NPC information. In outdoor
  // zones many classic named spawns are black rather than explicitly tagged,
  // so retain plausible NPC names while excluding navigation and annotation
  // records that would create misleading wiki links.
  const black = colorNear(point, { r: 0, g: 0, b: 0 }, 8);
  if (!black) return false;
  return !/^(?:to\s|succor\b|tip:|gs:|trap:|locked\b|zone\b|may\b|warning\b|boat\b|path\b|bridge\b|dock\b|water\b|lava\b)/i.test(label);
}
function namedMobPriority(point) {
  const label = String(point?.label || '').trim();
  if (/\((?:named|hunter)(?:[, )]|$)/i.test(label)) return 3;
  if (colorNear(point, NAMED_MOB_COLOR, 8)) return 2;
  return 1;
}
function cleanMobLabel(label) {
  return String(label || '')
    .replace(/\s*\((?=[^)]*(?:named|hunter|roam|\bhs\b|mission|on\s|gm\b|merchant|parcel|raid|bank|cultural))[^)]*\)\s*$/i, '')
    .replace(/^\s+|\s+$/g, '');
}
function wikiPageUrl(title) {
  const pageTitle = cleanMobLabel(title);
  if (globalThis.mw?.util?.getUrl) return globalThis.mw.util.getUrl(pageTitle);
  return `/wiki/${encodeURIComponent(pageTitle.replace(/ /g, '_'))}`;
}
function eqToThree(x, y, z) {
  const eqX = safeNumber(x);
  const eqY = safeNumber(y);
  const eqZ = safeNumber(z);
  if (EQ_TO_THREE.swap) return new THREE.Vector3(EQ_TO_THREE.sx * eqY, eqZ, EQ_TO_THREE.sz * eqX);
  return new THREE.Vector3(EQ_TO_THREE.sx * eqX, eqZ, EQ_TO_THREE.sz * eqY);
}
function threeToEqCoordinates(point) {
  if (EQ_TO_THREE.swap) {
    return {
      x: point.z / EQ_TO_THREE.sz,
      y: point.x / EQ_TO_THREE.sx,
      z: point.y
    };
  }
  return {
    x: point.x / EQ_TO_THREE.sx,
    y: point.z / EQ_TO_THREE.sz,
    z: point.y
  };
}

function parseEqMapText(text, layer = 0, sourceName = '') {
  const lines = [];
  const points = [];
  for (const rawLine of String(text || '').split(/\r?\n/)) {
    const raw = rawLine.trim();
    if (!raw || raw.startsWith('#')) continue;
    const type = raw.charAt(0).toUpperCase();
    const fields = raw.split(',').map(field => field.trim());
    if (type === 'L' && fields.length >= 9) {
      const values = fields.slice(0, 9).map((field, index) => index === 0
        ? safeNumber(field.replace(/^[^\-\d.]*/, ''), NaN)
        : safeNumber(field, NaN));
      if (values.every(Number.isFinite)) {
        lines.push({
          x1: values[0], y1: values[1], z1: values[2],
          x2: values[3], y2: values[4], z2: values[5],
          r: clamp(Math.round(values[6]), 0, 255),
          g: clamp(Math.round(values[7]), 0, 255),
          b: clamp(Math.round(values[8]), 0, 255),
          layer,
          sourceName
        });
      }
    } else if (type === 'P' && fields.length >= 8) {
      const x = safeNumber(fields[0].replace(/^[^\-\d.]*/, ''), NaN);
      const y = safeNumber(fields[1], NaN);
      const z = safeNumber(fields[2], NaN);
      const r = safeNumber(fields[3], NaN);
      const g = safeNumber(fields[4], NaN);
      const b = safeNumber(fields[5], NaN);
      const size = safeNumber(fields[6], 2);
      if ([x, y, z, r, g, b].every(Number.isFinite)) {
        const label = fields.slice(7).join(',').trim().replace(/_/g, ' ');
        if (isSuppressedMapLabel(label)) continue;
        points.push({
          x, y, z,
          r: clamp(Math.round(r), 0, 255),
          g: clamp(Math.round(g), 0, 255),
          b: clamp(Math.round(b), 0, 255),
          size: clamp(Math.round(size), 1, 3),
          label,
          layer,
          sourceName
        });
      }
    }
  }
  return { lines, points };
}


class ZoneCache {
  constructor() { this.dbPromise = null; }
  open() {
    if (!('indexedDB' in globalThis)) return Promise.resolve(null);
    if (this.dbPromise) return this.dbPromise;
    this.dbPromise = new Promise((resolve) => {
      const req = indexedDB.open('EQLZoneViewer', 1);
      req.onupgradeneeded = () => {
        if (!req.result.objectStoreNames.contains('zones')) req.result.createObjectStore('zones');
      };
      req.onsuccess = () => resolve(req.result);
      req.onerror = () => resolve(null);
    });
    return this.dbPromise;
  }
  async get(key) {
    const db = await this.open();
    if (!db) return null;
    return new Promise(resolve => {
      const tx = db.transaction('zones', 'readonly');
      const req = tx.objectStore('zones').get(key);
      req.onsuccess = () => resolve(req.result || null);
      req.onerror = () => resolve(null);
    });
  }
  async set(key, value) {
    const db = await this.open();
    if (!db) return;
    return new Promise(resolve => {
      const tx = db.transaction('zones', 'readwrite');
      tx.objectStore('zones').put(value, key);
      tx.oncomplete = () => resolve();
      tx.onerror = () => resolve();
    });
  }
  async clear() {
    const db = await this.open();
    if (!db) return;
    return new Promise(resolve => {
      const tx = db.transaction('zones', 'readwrite');
      tx.objectStore('zones').clear();
      tx.oncomplete = () => resolve();
      tx.onerror = () => resolve();
    });
  }
  async clearPrefix(prefix) {
    const db = await this.open();
    if (!db) return;
    return new Promise(resolve => {
      const tx = db.transaction('zones', 'readwrite');
      const store = tx.objectStore('zones');
      const request = store.openCursor();
      request.onsuccess = () => {
        const cursor = request.result;
        if (!cursor) return;
        if (String(cursor.key).startsWith(prefix)) cursor.delete();
        cursor.continue();
      };
      tx.oncomplete = () => resolve();
      tx.onerror = () => resolve();
    });
  }
}

class LocalGameSource {
  constructor(input) {
    this.input = input;
    this.files = new Map();
    this.fileEntries = [];
    this.filesByName = new Map();
    this.fileDepths = new Map();
    this.directoryHandle = null;
    this.label = '';
  }
  resetIndex() {
    this.files.clear();
    this.fileEntries = [];
    this.filesByName.clear();
    this.fileDepths.clear();
  }
  addIndexedFile(file, relativePath = '') {
    const basename = normalizeName(file.name);
    const path = String(relativePath || file.webkitRelativePath || file.name)
      .replace(/\\/g, '/')
      .replace(/^\/+/, '');
    const entry = { file, path, basename };
    this.fileEntries.push(entry);
    if (!this.filesByName.has(basename)) this.filesByName.set(basename, []);
    this.filesByName.get(basename).push(entry);

    // Preserve the shallowest copy as the ordinary game-file lookup. This
    // prevents a custom maps subdirectory from replacing a root archive or
    // root-side dependency with the same basename.
    const current = this.files.get(basename);
    const depth = path.split('/').length;
    const currentDepth = this.fileDepths.get(basename) ?? Infinity;
    if (!current || depth < currentDepth) {
      this.files.set(basename, file);
      this.fileDepths.set(basename, depth);
    }
  }
  async select() {
    if ('showDirectoryPicker' in globalThis) {
      try {
        const handle = await globalThis.showDirectoryPicker({ mode: 'read' });
        this.directoryHandle = handle;
        this.label = handle.name || 'EverQuest directory';
        await this.indexHandle(handle);
        return true;
      } catch (error) {
        if (error?.name === 'AbortError') return false;
        console.warn('[EQLZoneViewer] Directory picker failed; using file-input fallback.', error);
      }
    }
    return await this.selectWithInput();
  }
  selectWithInput() {
    return new Promise(resolve => {
      const done = () => {
        this.input.removeEventListener('change', done);
        const list = [...(this.input.files || [])];
        if (!list.length) { resolve(false); return; }
        this.resetIndex();
        for (const file of list) this.addIndexedFile(file, file.webkitRelativePath || file.name);
        const rel = list[0].webkitRelativePath || '';
        this.label = rel.split('/')[0] || 'Selected EverQuest directory';
        resolve(true);
      };
      this.input.addEventListener('change', done, { once: true });
      this.input.value = '';
      this.input.click();
    });
  }
  async indexHandle(root) {
    this.resetIndex();
    let seen = 0;
    const walk = async (dir, path = '', depth = 0, insideMaps = false) => {
      for await (const [, entry] of dir.entries()) {
        if (++seen > 50000) throw new Error('The selected directory contains more than 50,000 entries. Select the EverQuest installation root rather than a parent drive.');
        const childPath = path ? `${path}/${entry.name}` : entry.name;
        if (entry.kind === 'file') {
          const ext = entry.name.split('.').pop().toLowerCase();
          if (ACCEPTED_EXTENSIONS.has(ext)) {
            const file = await entry.getFile();
            this.addIndexedFile(file, childPath);
          }
          continue;
        }
        if (entry.kind !== 'directory') continue;
        const childInsideMaps = insideMaps || /^maps$/i.test(entry.name);
        const rootAssetDirectory = depth === 0 && /^(resources|assets|maps)$/i.test(entry.name);
        if ((childInsideMaps && depth < 7) || rootAssetDirectory) {
          await walk(entry, childPath, depth + 1, childInsideMaps);
        }
      }
    };
    await walk(root);
  }
  archiveCandidates() {
    const candidates = [];
    for (const file of this.files.values()) {
      const name = normalizeName(file.name);
      const match = name.match(/^(.+)\.(s3d|eqg)$/i);
      if (!match) continue;
      const stem = match[1];
      if (OBVIOUS_NON_ZONE_PREFIXES.test(stem) || OBVIOUS_NON_ZONE_SUFFIXES.test(stem)) continue;
      candidates.push({ id: stem, format: match[2].toUpperCase(), file });
    }
    return candidates.sort((a, b) => a.id.localeCompare(b.id, undefined, { numeric: true }) || a.format.localeCompare(b.format));
  }
  async filesForZone(zoneRecord) {
    const zone = zoneRecord.id;
    const format = zoneRecord.format.toLowerCase();
    const selected = new Map();
    const add = (file) => file && selected.set(normalizeName(file.name), file);
    const main = this.files.get(`${zone}.${format}`);
    if (!main) throw new Error(`The selected ${zone}.${format} archive is no longer available. Select the game directory again.`);
    add(main);

    if (format === 's3d') {
      for (const file of this.files.values()) {
        const name = normalizeName(file.name);
        if (name === `${zone}.s3d` || new RegExp(`^${escapeRegExp(zone)}_obj\d*\.s3d$`, 'i').test(name)) add(file);
      }
      // Character and lighting archives are not needed for static zone viewing.
      // Omitting them avoids exporting hundreds of unused animated models.
      for (const common of ['gequip.s3d', 'global_obj.s3d']) add(this.files.get(common));
    } else {
      const assets = this.files.get(`${zone}_assets.txt`);
      if (assets) {
        add(assets);
        const lines = (await assets.text())
          .split(/\r?\n/)
          .map(line => normalizeName(line.replace(/["']/g, '').trim()))
          .filter(line => line && !line.startsWith('#') && !line.startsWith('//'));
        for (const line of lines) add(this.files.get(line));
      }
      for (const file of this.files.values()) {
        const name = normalizeName(file.name);
        if (name.startsWith(`${zone}_`) && /\.(eqg|zon|txt)$/i.test(name)) add(file);
      }
    }

    add(this.files.get('mp3index.txt'));
    return [...selected.values()];
  }
  mapFamilyForZone(zone) {
    const zoneStem = String(zone || '').toLowerCase();
    const preferred = ZONE_MAP_FILE_ALIASES[zoneStem];
    const mapStems = [...new Set([
      ...(Array.isArray(preferred) ? preferred : [preferred]),
      zoneStem
    ].filter(Boolean))];
    const patterns = mapStems.map(stem => ({
      stem,
      pattern: new RegExp(`^${escapeRegExp(stem)}(?:_([123]))?\\.txt$`, 'i')
    }));
    const families = new Map();
    for (const entry of this.fileEntries) {
      let matched = null;
      let priority = -1;
      for (let index = 0; index < patterns.length; index++) {
        const match = entry.basename.match(patterns[index].pattern);
        if (!match) continue;
        matched = match;
        priority = index;
        break;
      }
      if (!matched) continue;
      const lowerPath = `/${entry.path.toLowerCase()}`;
      if (!lowerPath.includes('/maps/') && !lowerPath.startsWith('/maps/')) continue;
      const directory = entry.path.includes('/') ? entry.path.slice(0, entry.path.lastIndexOf('/')) : '';
      const stem = patterns[priority].stem;
      const familyKey = `${directory}|${stem}`;
      if (!families.has(familyKey)) {
        families.set(familyKey, { directory, stem, priority, layers: new Map(), bytes: 0 });
      }
      const family = families.get(familyKey);
      const layer = matched[1] ? Number(matched[1]) : 0;
      family.layers.set(layer, entry.file);
      family.bytes += entry.file.size || 0;
    }
    if (!families.size) return null;
    const ranked = [...families.values()].sort((a, b) => {
      const aBase = a.layers.has(0) ? 1 : 0;
      const bBase = b.layers.has(0) ? 1 : 0;
      return bBase - aBase || a.priority - b.priority || b.bytes - a.bytes ||
        a.directory.localeCompare(b.directory);
    });
    return ranked[0];
  }
  worldMapSignature(zoneRecords) {
    const parts = [];
    const seen = new Set();
    for (const record of zoneRecords || []) {
      const worldId = canonicalWorldZoneId(record.id);
      if (seen.has(worldId)) continue;
      seen.add(worldId);
      parts.push(`zone:${worldId}:${record.id}:${record.format}:${record.file?.size || 0}:${record.file?.lastModified || 0}`);
      const family = this.mapFamilyForZone(record.id);
      if (!family) continue;
      for (const layer of [0, 1]) {
        const file = family.layers.get(layer);
        if (file) parts.push(`map:${worldId}:${layer}:${normalizeName(file.name)}:${file.size}:${file.lastModified}`);
      }
    }
    return `${WORLD_MAP_CACHE_VERSION}:${hashString(parts.sort().join('|'))}`;
  }
  signature(zoneRecord, files) {
    const parts = files.map(f => `${normalizeName(f.name)}:${f.size}:${f.lastModified}`).sort();
    return `${CACHE_FORMAT_VERSION}:${zoneRecord.format}:${zoneRecord.id}:${parts.join('|')}`;
  }
}

class FirstPersonController {
  constructor(camera, domElement) {
    this.camera = camera;
    this.domElement = domElement;
    this.controls = new PointerLockControls(camera, domElement);
    this.keys = new Set();
    this.enabled = false;
    this.collision = true;
    this.fly = false;
    this.speed = 45;
    this.sprintMultiplier = 2;
    this.eyeHeight = 6;
    this.maxStepUp = 8;
    this.maxDrop = 60;
    this.gravity = 55;
    this.jumpHeight = 10;
    this.jumpVelocity = Math.sqrt(2 * this.gravity * this.jumpHeight);
    this.playerRadius = 2.25;
    this.collisionSkin = 0.18;
    this.verticalVelocity = 0;
    this.grounded = false;
    this.jumpRequested = false;
    this.colliders = [];
    this.colliderRecords = [];
    this.wallColliders = [];
    this.wallColliderRecords = [];
    this.spatialCellSize = NAVIGATION_SPATIAL_CELL;
    this.colliderSpatialIndex = new Map();
    this.colliderSpatialFallback = [];
    this.wallSpatialIndex = new Map();
    this.wallSpatialFallback = [];
    this.wallCollisionMaterial = new THREE.MeshBasicMaterial({ side: THREE.DoubleSide });
    this.raycaster = new THREE.Raycaster();
    this.collisionInterval = 1 / 30;
    this.collisionElapsed = 0;
    this.lastCollisionPosition = new THREE.Vector3();
    this.before = new THREE.Vector3();
    this.delta = new THREE.Vector3();
    this.direction = new THREE.Vector3();
    this.right = new THREE.Vector3();
    this.down = new THREE.Vector3(0, -1, 0);
    this.up = new THREE.Vector3(0, 1, 0);
    this.groundOrigin = new THREE.Vector3();
    this.wallSide = new THREE.Vector3();
    this.wallNormal = new THREE.Vector3();
    this.wallStart = new THREE.Vector3();
    this.wallEnd = new THREE.Vector3();
    this.wallContact = new THREE.Vector3();
    this.wallSlide = new THREE.Vector3();
    this.wallRemaining = new THREE.Vector3();
    this.wallSweepBox = new THREE.Box3();
    this.wallNormalMatrix = new THREE.Matrix3();
    this.onKeyDown = e => {
      if (!this.enabled || (isFormControlFocused() && !this.controls.isLocked)) return;
      const movementKey = ['KeyW','KeyA','KeyS','KeyD','KeyQ','KeyC','KeyE','Space','ShiftLeft','ShiftRight','ArrowUp','ArrowDown','ArrowLeft','ArrowRight'].includes(e.code);
      if (!movementKey) return;
      // Ctrl, Alt, and Command are never movement modifiers. While pointer lock
      // is active, suppress combinations such as Ctrl+W/Ctrl+S before they can
      // close or replace the viewer tab, but do not treat the letter as motion.
      if (e.ctrlKey || e.altKey || e.metaKey) {
        if (this.controls.isLocked) e.preventDefault();
        return;
      }
      if (e.code === 'Space' && !this.fly && !e.repeat) this.jumpRequested = true;
      this.keys.add(e.code);
      if (this.controls.isLocked) e.preventDefault();
    };
    this.onKeyUp = e => this.keys.delete(e.code);
    document.addEventListener('keydown', this.onKeyDown);
    document.addEventListener('keyup', this.onKeyUp);
  }
  setScene(scene) {
    this.colliders = [];
    this.colliderRecords = [];
    this.wallColliders = [];
    this.wallColliderRecords = [];
    this.colliderSpatialIndex.clear();
    this.colliderSpatialFallback = [];
    this.wallSpatialIndex.clear();
    this.wallSpatialFallback = [];
    scene?.updateMatrixWorld(true);
    scene?.traverse(obj => {
      if (!obj.isMesh || !obj.visible || obj.userData.eqlProp || obj.userData.eqlCollision === false) return;
      this.colliders.push(obj);
      const colliderRecord = { mesh: obj, box: new THREE.Box3().setFromObject(obj) };
      this.colliderRecords.push(colliderRecord);
      this.indexSpatialRecord(colliderRecord, this.colliderSpatialIndex, this.colliderSpatialFallback);

      // Visual materials are frequently single-sided. That is correct for
      // rendering, but it makes ray-based collision fail whenever a legacy EQ
      // wall's triangle winding faces away from the player. Use lightweight
      // double-sided proxy meshes that share the original geometry. They are
      // never added to the render scene and therefore add no draw calls.
      const proxy = new THREE.Mesh(obj.geometry, this.wallCollisionMaterial);
      proxy.name = `wall-collider-${obj.name || this.wallColliders.length}`;
      proxy.matrixAutoUpdate = false;
      proxy.matrix.copy(obj.matrixWorld);
      proxy.updateMatrixWorld(true);
      const box = new THREE.Box3().setFromObject(proxy);
      this.wallColliders.push(proxy);
      const wallRecord = { mesh: proxy, box };
      this.wallColliderRecords.push(wallRecord);
      this.indexSpatialRecord(wallRecord, this.wallSpatialIndex, this.wallSpatialFallback);
    });
    this.lastCollisionPosition.copy(this.camera.position);
  }
  spatialKey(ix, iz) { return `${ix}:${iz}`; }
  indexSpatialRecord(record, index, fallback) {
    const cell = this.spatialCellSize;
    const minX = Math.floor(record.box.min.x / cell);
    const maxX = Math.floor(record.box.max.x / cell);
    const minZ = Math.floor(record.box.min.z / cell);
    const maxZ = Math.floor(record.box.max.z / cell);
    const bucketCount = (maxX - minX + 1) * (maxZ - minZ + 1);
    // Huge terrain meshes remain in a small fallback list instead of being
    // copied into thousands of buckets. Smaller meshes are indexed normally.
    if (!Number.isFinite(bucketCount) || bucketCount > 400) {
      fallback.push(record);
      return;
    }
    for (let ix = minX; ix <= maxX; ix++) {
      for (let iz = minZ; iz <= maxZ; iz++) {
        const key = this.spatialKey(ix, iz);
        let bucket = index.get(key);
        if (!bucket) {
          bucket = [];
          index.set(key, bucket);
        }
        bucket.push(record);
      }
    }
  }
  spatialRecordsForBox(index, fallback, box) {
    const cell = this.spatialCellSize;
    const minX = Math.floor(box.min.x / cell);
    const maxX = Math.floor(box.max.x / cell);
    const minZ = Math.floor(box.min.z / cell);
    const maxZ = Math.floor(box.max.z / cell);
    const records = [];
    const seen = new Set();
    const add = record => {
      if (!seen.has(record)) {
        seen.add(record);
        records.push(record);
      }
    };
    for (const record of fallback) add(record);
    const bucketCount = (maxX - minX + 1) * (maxZ - minZ + 1);
    if (bucketCount > 900) return records;
    for (let ix = minX; ix <= maxX; ix++) {
      for (let iz = minZ; iz <= maxZ; iz++) {
        const bucket = index.get(this.spatialKey(ix, iz));
        if (bucket) for (const record of bucket) add(record);
      }
    }
    return records;
  }
  surfaceCandidatesAt(x, z) {
    const cell = this.spatialCellSize;
    const ix = Math.floor(x / cell);
    const iz = Math.floor(z / cell);
    const records = [];
    const seen = new Set();
    const add = record => {
      if (!seen.has(record) && x >= record.box.min.x - 0.5 && x <= record.box.max.x + 0.5 && z >= record.box.min.z - 0.5 && z <= record.box.max.z + 0.5) {
        seen.add(record);
        records.push(record.mesh);
      }
    };
    for (const record of this.colliderSpatialFallback) add(record);
    const bucket = this.colliderSpatialIndex.get(this.spatialKey(ix, iz));
    if (bucket) for (const record of bucket) add(record);
    return records;
  }
  setFly(enabled) {
    this.fly = Boolean(enabled);
    this.jumpRequested = false;
    this.verticalVelocity = 0;
    this.collisionElapsed = this.collisionInterval;
    if (!this.fly && this.enabled) this.grounded = this.snapToGround(true);
    else this.grounded = false;
  }
  activate(position) {
    this.enabled = true;
    if (position) this.camera.position.copy(position);
    this.lastCollisionPosition.copy(this.camera.position);
    this.collisionElapsed = this.collisionInterval;
    this.verticalVelocity = 0;
    this.jumpRequested = false;
    this.grounded = !this.fly && this.snapToGround(true);
  }
  deactivate() {
    this.enabled = false;
    if (this.controls.isLocked) this.controls.unlock();
    this.keys.clear();
  }
  lock() { if (this.enabled) this.controls.lock(); }
  update(dt) {
    if (!this.enabled || !this.controls.isLocked) return;
    const sprinting = this.keys.has('ShiftLeft') || this.keys.has('ShiftRight');
    const move = this.speed * (sprinting ? this.sprintMultiplier : 1) * dt;
    let forward = 0, right = 0, vertical = 0;
    if (this.keys.has('KeyW') || this.keys.has('ArrowUp')) forward += move;
    if (this.keys.has('KeyS') || this.keys.has('ArrowDown')) forward -= move;
    if (this.keys.has('KeyD') || this.keys.has('ArrowRight')) right += move;
    if (this.keys.has('KeyA') || this.keys.has('ArrowLeft')) right -= move;
    if (this.fly && (this.keys.has('Space') || this.keys.has('KeyE'))) vertical += move;
    if (this.keys.has('KeyC') || this.keys.has('KeyQ')) vertical -= move;

    this.before.copy(this.camera.position);
    if (this.fly) {
      // Flight follows the complete view vector, including pitch. Looking down
      // and pressing W therefore moves toward the ground, while looking up
      // climbs. Q/C and Space/E remain world-vertical adjustments.
      this.camera.updateMatrixWorld();
      this.camera.getWorldDirection(this.direction).normalize();
      this.right.setFromMatrixColumn(this.camera.matrixWorld, 0).normalize();
      if (forward) this.camera.position.addScaledVector(this.direction, forward);
      if (right) this.camera.position.addScaledVector(this.right, right);
      if (vertical) this.camera.position.y += vertical;
    } else {
      if (forward) this.controls.moveForward(forward);
      if (right) this.controls.moveRight(right);

      if (this.jumpRequested && this.grounded) {
        this.verticalVelocity = this.jumpVelocity;
        this.grounded = false;
      }
      this.jumpRequested = false;
      if (!this.grounded) {
        this.verticalVelocity -= this.gravity * dt;
        this.camera.position.y += this.verticalVelocity * dt;
      }
    }

    this.collisionElapsed += dt;
    if (this.collisionElapsed < this.collisionInterval) return;
    // Collision runs at a fixed rate, but movement occurs every animation
    // frame. Sweep from the last position that was actually collision-tested;
    // using only this frame's `before` position allowed every skipped frame to
    // pass through walls unchecked.
    if (this.collision && this.wallColliders.length) this.resolveMovementCollision(this.lastCollisionPosition);
    if (!this.fly) {
      if (this.grounded) {
        this.grounded = this.snapToGround(false, this.before);
      } else if (this.verticalVelocity <= 0) {
        const landed = this.landIfReachedGround();
        if (landed) {
          this.grounded = true;
          this.verticalVelocity = 0;
        }
      }
    }
    this.lastCollisionPosition.copy(this.camera.position);
    this.collisionElapsed = 0;
  }
  resolveMovementCollision(previous) {
    const currentY = this.camera.position.y;
    this.delta.copy(this.camera.position).sub(previous);
    this.delta.y = 0;
    const distance = this.delta.length();
    if (distance <= 0.0001) return;

    this.direction.copy(this.delta).multiplyScalar(1 / distance);
    this.wallStart.set(previous.x, currentY, previous.z);
    this.wallEnd.set(this.camera.position.x, currentY, this.camera.position.z);
    const candidates = this.wallCandidatesForSweep(this.wallStart, this.wallEnd);
    const hit = this.findSweptWallHit(this.wallStart, this.direction, distance, candidates);
    if (!hit) return;

    const allowed = clamp(hit.distance - this.playerRadius - this.collisionSkin, 0, distance);
    this.wallContact.copy(this.wallStart).addScaledVector(this.direction, allowed);

    this.wallNormalMatrix.getNormalMatrix(hit.object.matrixWorld);
    this.wallNormal.copy(hit.face.normal).applyMatrix3(this.wallNormalMatrix);
    this.wallNormal.y = 0;
    if (this.wallNormal.lengthSq() < 0.000001) {
      this.camera.position.set(this.wallContact.x, currentY, this.wallContact.z);
      return;
    }
    this.wallNormal.normalize();
    if (this.wallNormal.dot(this.direction) > 0) this.wallNormal.negate();

    // Preserve the tangent component instead of snapping all the way back.
    // This makes diagonal movement naturally slide along a wall and avoids the
    // sticky-corner behavior of the old all-or-nothing response.
    const remainingDistance = Math.max(0, distance - allowed);
    this.wallRemaining.copy(this.direction).multiplyScalar(remainingDistance);
    this.wallSlide.copy(this.wallRemaining)
      .addScaledVector(this.wallNormal, -this.wallRemaining.dot(this.wallNormal));

    let finalX = this.wallContact.x;
    let finalZ = this.wallContact.z;
    const slideDistance = this.wallSlide.length();
    if (slideDistance > 0.0001) {
      this.wallSlide.multiplyScalar(1 / slideDistance);
      this.wallEnd.copy(this.wallContact).addScaledVector(this.wallSlide, slideDistance);
      const slideCandidates = this.wallCandidatesForSweep(this.wallContact, this.wallEnd);
      const slideHit = this.findSweptWallHit(this.wallContact, this.wallSlide, slideDistance, slideCandidates);
      const allowedSlide = slideHit
        ? clamp(slideHit.distance - this.playerRadius - this.collisionSkin, 0, slideDistance)
        : slideDistance;
      finalX += this.wallSlide.x * allowedSlide;
      finalZ += this.wallSlide.z * allowedSlide;
    }

    this.camera.position.set(finalX, currentY, finalZ);
  }
  wallCandidatesForSweep(start, end) {
    const radius = this.playerRadius + 0.5;
    const lowY = Math.min(start.y, end.y) - this.eyeHeight - radius;
    const highY = Math.max(start.y, end.y) + radius;
    this.wallSweepBox.min.set(
      Math.min(start.x, end.x) - radius,
      lowY,
      Math.min(start.z, end.z) - radius
    );
    this.wallSweepBox.max.set(
      Math.max(start.x, end.x) + radius,
      highY,
      Math.max(start.z, end.z) + radius
    );
    const candidates = [];
    const records = this.spatialRecordsForBox(this.wallSpatialIndex, this.wallSpatialFallback, this.wallSweepBox);
    for (const record of records) {
      if (record.box.intersectsBox(this.wallSweepBox)) candidates.push(record.mesh);
    }
    return candidates;
  }
  findSweptWallHit(start, direction, distance, candidates) {
    if (!candidates.length) return null;
    this.wallSide.set(-direction.z, 0, direction.x).normalize();
    const radius = this.playerRadius;
    const origins = [
      [0, -this.eyeHeight + Math.max(1.25, radius * 0.65)],
      [0, -this.eyeHeight * 0.48],
      [-radius, -this.eyeHeight * 0.48],
      [radius, -this.eyeHeight * 0.48],
      [0, -0.65]
    ];
    let nearest = null;
    const far = distance + radius + this.collisionSkin;
    for (const [sideOffset, heightOffset] of origins) {
      this.groundOrigin.copy(start)
        .addScaledVector(this.wallSide, sideOffset);
      this.groundOrigin.y += heightOffset;
      this.raycaster.set(this.groundOrigin, direction);
      this.raycaster.near = 0;
      this.raycaster.far = far;
      const hits = this.raycaster.intersectObjects(candidates, false);
      for (const candidate of hits) {
        if (!candidate.face) continue;
        this.wallNormalMatrix.getNormalMatrix(candidate.object.matrixWorld);
        this.wallNormal.copy(candidate.face.normal).applyMatrix3(this.wallNormalMatrix).normalize();
        // Floors, ceilings, and ordinary walkable slopes must not stop
        // horizontal movement. Only near-vertical surfaces are walls.
        if (Math.abs(this.wallNormal.y) > 0.72) continue;
        if (!nearest || candidate.distance < nearest.distance) nearest = candidate;
        break;
      }
    }
    return nearest;
  }
  landIfReachedGround() {
    if (!this.colliders.length) return false;
    this.groundOrigin.copy(this.camera.position).addScaledVector(this.up, this.maxStepUp);
    this.raycaster.set(this.groundOrigin, this.down);
    this.raycaster.far = this.maxStepUp + this.eyeHeight + this.maxDrop + 40;
    const floor = this.raycaster.intersectObjects(this.colliders, false)
      .find(hit => hit.point.y <= this.camera.position.y);
    if (!floor) return false;
    const desiredY = floor.point.y + this.eyeHeight;
    if (this.camera.position.y <= desiredY + Math.max(1, -this.verticalVelocity * this.collisionInterval * 2)) {
      this.camera.position.y = desiredY;
      return true;
    }
    return false;
  }
  snapToGround(force = false, fallbackPosition = null) {
    if (!this.colliders.length) return false;
    this.groundOrigin.copy(this.camera.position).addScaledVector(this.up, this.maxStepUp);
    this.raycaster.set(this.groundOrigin, this.down);
    // Entering Grounded mode from an arbitrary camera height must still find
    // the nearest surface below. Normal movement keeps a smaller drop limit so
    // the player remains glued to the current traversable level.
    this.raycaster.far = force ? 200000 : this.maxStepUp + this.eyeHeight + this.maxDrop;
    const floor = this.raycaster.intersectObjects(this.colliders, false)
      .find(hit => hit.point.y <= this.camera.position.y - 0.25);
    if (!floor) {
      if (!force && fallbackPosition) this.camera.position.copy(fallbackPosition);
      return false;
    }
    const desiredY = floor.point.y + this.eyeHeight;
    const rise = desiredY - this.camera.position.y;
    if (force || (rise <= this.maxStepUp + 0.5 && rise >= -this.maxDrop)) {
      this.camera.position.y = desiredY;
      this.grounded = true;
      return true;
    }
    if (fallbackPosition) this.camera.position.copy(fallbackPosition);
    return false;
  }
  dispose() {
    document.removeEventListener('keydown', this.onKeyDown);
    document.removeEventListener('keyup', this.onKeyUp);
    this.wallCollisionMaterial.dispose();
    this.deactivate();
  }
}

export class ZoneViewer {
  constructor(root, config) {
    this.root = root;
    this.config = config;
    this.cache = new ZoneCache();
    this.source = null;
    this.worker = null;
    this.requestId = 0;
    this.mode = 'top';
    this.modeBeforeMap = 'top';
    this.currentZoneKey = null;
    this.currentZoneRecord = null;
    this.zoneGroup = new THREE.Group();
    this.propsGroup = new THREE.Group();
    this.mapGroup = new THREE.Group();
    this.navigationGroup = new THREE.Group();
    this.navigationGroup.name = 'navigation-guide';
    this.navigationGroup.visible = true;
    this.locationMarkerGroup = new THREE.Group();
    this.locationMarkerGroup.name = 'location-pillars';
    this.navigationGuideGroup = new THREE.Group();
    this.navigationGuideGroup.name = 'selected-navigation-guide';
    this.navigationGroup.add(this.locationMarkerGroup, this.navigationGuideGroup);
    this.mapGroup.visible = false;
    this.mapData = null;
    this.mapFileVisible = false;
    this.miniMapVisible = false;
    this.worldMapVisible = false;
    this.worldMapData = null;
    this.worldMapSignature = null;
    this.worldMapContext = null;
    this.worldMapView = { scale: 1, offsetX: 0, offsetY: 0 };
    this.worldMapPointer = null;
    this.worldMapHoverNode = null;
    this.worldMapHoverFirepot = null;
    this.worldMapFirepotHitRegions = [];
    this.worldMapBuildPromise = null;
    this.worldMapControlState = null;
    this.preMapCameraState = null;
    this.savedTopCameraState = null;
    this.savedFullMapCameraState = null;
    this.mapTransform = { ...EQ_TO_THREE };
    this.mapBounds = new THREE.Box3();
    this.mapContentBounds = new THREE.Box3();
    this.terrainBounds = new THREE.Box3();
    this.topFitBounds = new THREE.Box3();
    this.mapMaterials = [];
    this.loadedMaterials = [];
    this.materialRegistry = new Map();
    this.materialKeys = new WeakMap();
    this.nextMaterialKey = 1;
    this.rawTextures = new Map();
    this.texturePromises = new Map();
    this.floorLevels = [];
    this.floorLevelSource = 'geometry';
    this.selectedFloorIndices = new Set();
    this.floorUniformMaterials = new Set();
    this.namedMobLabels = [];
    this.targetedMobLabel = null;
    this.lastFirstPersonPose = null;
    this.gotoNpcEntries = [];
    this.locationPillarEntries = [];
    this.locationPillarMesh = null;
    this.locationPointerClient = null;
    this.locationHoverEntry = null;
    this.locationPinnedEntry = null;
    this.locationPinnedUntil = 0;
    this.locationPillarHeight = 0;
    this.navigationTarget = null;
    this.navigationPath = [];
    this.navigationBuildToken = 0;
    this.navigationBuildActive = false;
    this.navigationBuildStartedAt = 0;
    this.navigationWorker = null;
    this.navigationWorkerMapKey = null;
    this.navigationWorkerReady = false;
    this.navigationWorkerInitPromise = null;
    this.navigationWorkerInitResolve = null;
    this.navigationWorkerInitReject = null;
    this.navigationWorkerRequestId = 0;
    this.navigationWorkerPending = new Map();
    this.gotoPreviewTimer = 0;
    this.gotoPreviewQuery = '';
    this.navigationRaycaster = new THREE.Raycaster();
    this.navigationNormalMatrix = new THREE.Matrix3();
    this.navigationNormal = new THREE.Vector3();
    this.distanceCullTargets = [];
    this.visibleBatchCount = 0;
    this.culledBatchCount = 0;
    this.firstPersonViewDistance = 1800;
    this.firstPersonViewDistanceMin = 650;
    this.firstPersonViewDistanceMax = 3000;
    this.lastViewDistanceAdjustment = 0;
    this.lastCoordinateUpdate = 0;
    this.currentBounds = new THREE.Box3(new THREE.Vector3(-100,-10,-100), new THREE.Vector3(100,100,100));
    this.lastTime = performance.now();
    this.lastRenderedAt = 0;
    this.targetFrameInterval = TARGET_FRAME_MS;
    this.renderRequested = true;
    this.frameTimes = [];
    this.renderDurations = [];
    this.lastDiagnosticUpdate = 0;
    this.benchmarkUntil = 0;
    this.benchmarkStartedAt = 0;
    this.benchmarkFrames = 0;
    this.actualFps = 0;
    this.actualDrawCalls = 0;
    this.actualTriangles = 0;
    this.actualLines = 0;
    this.actualPoints = 0;
    this.actualFrameMs = 0;
    this.actualRenderMs = 0;
    this.lastQualityAdjustment = 0;
    this.dynamicPixelRatio = 1;
    this.parserStats = {};
    this.displayStats = {};
    this.diagnosticReason = 'Load a zone to begin profiling.';
    this.pointerStart = null;
    this.pickTargets = [];
    this.cacheEpoch = 0;
    this.sceneStats = { triangles: 0, drawCalls: 0, sourceMeshes: 0, meshes: 0, instances: 0, placedProps: 0, zoneDrawCalls: 0, propDrawCalls: 0, zoneMeshes: 0, propMeshes: 0 };
    this.buildUI();
    this.initThree();
    this.animate();
  }

  buildUI() {
    this.root.classList.add('eqlzv-mounted');
    this.root.style.setProperty('--eqlzv-height', this.config.height || '760px');
    this.root.innerHTML = '';

    const app = h('div', 'eqlzv-app');
    const toolbar = h('div', 'eqlzv-toolbar');
    const picker = h('button', 'eqlzv-button eqlzv-primary', 'Select EverQuest Folder');
    picker.type = 'button';
    const input = h('input', 'eqlzv-file-input');
    input.type = 'file';
    input.multiple = true;
    input.setAttribute('webkitdirectory', '');
    input.setAttribute('directory', '');
    const sourceLabel = h('span', 'eqlzv-source-label', 'Files stay local — no folder upload occurs');
    sourceLabel.title = 'Firefox may say “Upload Folder,” but the viewer only receives local read permission. Game files are not sent to EQL Wiki.';
    const zoneSelect = h('select', 'eqlzv-select eqlzv-zone-select');
    zoneSelect.disabled = true;
    zoneSelect.setAttribute('aria-label', 'Choose an EverQuest zone');
    zoneSelect.innerHTML = '<option value="">Select a directory first</option>';
    const load = h('button', 'eqlzv-button', 'Load Zone');
    load.disabled = true;
    const worldMap = h('button', 'eqlzv-button eqlzv-world-button', 'World Map');
    worldMap.type = 'button';
    worldMap.disabled = true;
    worldMap.title = 'Build or open a connected world atlas from local EverQuest maps';
    toolbar.append(picker, input, sourceLabel, zoneSelect, load, worldMap);

    const modes = h('div', 'eqlzv-toolbar eqlzv-toolbar-secondary');
    const viewLabel = h('span', 'eqlzv-toolbar-label', 'View');
    const modeGroup = h('div', 'eqlzv-segmented');
    const top = h('button', 'eqlzv-button is-active', 'Top Down');
    top.title = 'Top-down orbit view (1)';
    top.disabled = true;
    const first = h('button', 'eqlzv-button', 'First Person');
    first.title = 'First-person walk/fly view (2)';
    first.disabled = true;
    modeGroup.append(top, first);
    const fly = h('button', 'eqlzv-button', 'Grounded');
    fly.disabled = true;
    fly.title = 'Toggle grounded walking and free flight (G)';
    const reset = h('button', 'eqlzv-button', 'Reset');
    reset.disabled = true;
    reset.title = 'Reset the current view (R)';

    const floorLabel = h('div', 'eqlzv-field-label');
    floorLabel.append(document.createTextNode('Floors'));
    const floorPicker = h('details', 'eqlzv-floor-picker');
    const floorSummary = h('summary', 'eqlzv-button eqlzv-floor-summary', 'All floors');
    const floorPanel = h('div', 'eqlzv-floor-panel');
    floorPanel.innerHTML = '<span class="eqlzv-floor-empty">No overlapping floors detected</span>';
    floorPicker.append(floorSummary, floorPanel);
    floorPicker.dataset.disabled = '1';
    floorLabel.append(floorPicker);

    const gotoLabel = h('label', 'eqlzv-field-label eqlzv-goto-label');
    gotoLabel.append(document.createTextNode('Path to location'));
    const gotoNpc = h('input', 'eqlzv-search eqlzv-goto-input');
    gotoNpc.type = 'search';
    gotoNpc.placeholder = 'NPC or map label';
    gotoNpc.autocomplete = 'off';
    gotoNpc.disabled = true;
    const gotoList = h('datalist');
    gotoList.id = `eqlzv-npc-list-${Math.random().toString(36).slice(2)}`;
    gotoNpc.setAttribute('list', gotoList.id);
    const gotoButton = h('button', 'eqlzv-button eqlzv-goto-button', 'Go to');
    gotoButton.type = 'button';
    gotoButton.disabled = true;
    gotoLabel.append(gotoNpc, gotoList, gotoButton);

    const clipLabel = h('label', 'eqlzv-slider-label');
    const clipText = h('span', '', 'Cut above');
    const clip = h('input', 'eqlzv-slider');
    clip.type = 'range';
    clip.min = '0';
    clip.max = '1000';
    clip.value = '1000';
    clip.disabled = true;
    const clipValue = h('span', 'eqlzv-slider-value', 'Off');
    clipLabel.append(clipText, clip, clipValue);

    const displayDetails = h('details', 'eqlzv-options');
    const displaySummary = h('summary', 'eqlzv-button', 'Display');
    const displayPanel = h('div', 'eqlzv-options-panel');
    const props = this.checkbox('Props', true);
    const wire = this.checkbox('Wireframe', false);
    const textures = this.checkbox('Textures', true);
    const collision = this.checkbox('Wall collision', true);
    const namedMobs = this.checkbox('Named mob labels', true);
    const locationBeams = this.checkbox('Location beams', true);
    const distanceFog = this.checkbox('First-person fog/culling', true);
    const qualityLabel = h('div', 'eqlzv-field-label eqlzv-quality-label');
    qualityLabel.append(document.createTextNode('Quality'));
    const qualityFixed = h('span', 'eqlzv-quality-fixed', 'High · target 60 FPS');
    qualityLabel.append(qualityFixed);
    const clearCache = h('button', 'eqlzv-button eqlzv-subtle', 'Clear Cache');
    displayPanel.append(
      textures.label,
      props.label,
      namedMobs.label,
      locationBeams.label,
      distanceFog.label,
      wire.label,
      collision.label,
      qualityLabel,
      clearCache
    );
    displayDetails.append(displaySummary, displayPanel);

    const help = h('button', 'eqlzv-button', 'Controls');
    help.title = 'Show controls (H)';
    help.setAttribute('aria-expanded', 'false');
    const fullscreen = h('button', 'eqlzv-button', 'Full Screen');
    modes.append(viewLabel, modeGroup, fly, reset, floorLabel, gotoLabel, clipLabel, displayDetails, help, fullscreen);

    const viewport = h('div', 'eqlzv-viewport');
    const canvas = h('canvas', 'eqlzv-canvas');
    canvas.tabIndex = 0;
    const worldCanvas = h('canvas', 'eqlzv-world-canvas');
    worldCanvas.hidden = true;
    worldCanvas.tabIndex = 0;
    worldCanvas.setAttribute('aria-label', 'Connected EverQuest world map');
    const worldTooltip = h('div', 'eqlzv-world-tooltip');
    worldTooltip.hidden = true;
    const mapLabels = h('canvas', 'eqlzv-map-labels');
    mapLabels.setAttribute('aria-hidden', 'true');
    const miniMap = h('aside', 'eqlzv-minimap');
    miniMap.hidden = true;
    miniMap.setAttribute('aria-label', 'Local EverQuest minimap');
    const miniMapTitle = h('div', 'eqlzv-minimap-title', 'Local Map');
    const miniMapCanvas = h('canvas', 'eqlzv-minimap-canvas');
    miniMapCanvas.setAttribute('aria-hidden', 'true');
    miniMap.append(miniMapTitle, miniMapCanvas);
    const mobLabels = h('div', 'eqlzv-mob-labels');
    const navigationLabel = h('div', 'eqlzv-navigation-label');
    navigationLabel.hidden = true;
    navigationLabel.setAttribute('role', 'status');
    const locationTooltip = h('div', 'eqlzv-location-tooltip');
    locationTooltip.hidden = true;
    locationTooltip.setAttribute('role', 'tooltip');
    mobLabels.append(navigationLabel, locationTooltip);
    const zoneBadge = h('div', 'eqlzv-zone-badge', 'No zone loaded');
    const overlay = h('div', 'eqlzv-overlay');
    overlay.innerHTML = `<div class="eqlzv-welcome">
      <strong>Local EverQuest Zone Viewer</strong>
      <span>Select the folder containing your EverQuest installation. Game files are read locally in your browser and are not sent to EQL Wiki.</span>
      <small><b>Firefox note:</b> Firefox may call this “Upload Folder.” That wording only grants the page permission to read files from the folder locally; it does not upload the folder.</small>
    </div>`;

    const helpPanel = h('section', 'eqlzv-help-panel');
    helpPanel.hidden = true;
    helpPanel.setAttribute('aria-label', 'Zone viewer controls');
    const helpHeader = h('div', 'eqlzv-help-header');
    const helpTitle = h('strong', '', 'Controls');
    const helpClose = h('button', 'eqlzv-help-close', '×');
    helpClose.type = 'button';
    helpClose.setAttribute('aria-label', 'Close controls');
    const helpContent = h('div', 'eqlzv-help-content');
    helpHeader.append(helpTitle, helpClose);
    helpPanel.append(helpHeader, helpContent);

    const firstPersonPrompt = h('button', 'eqlzv-fps-enter');
    firstPersonPrompt.type = 'button';
    firstPersonPrompt.hidden = true;
    firstPersonPrompt.innerHTML = '<strong>Click to enter First Person</strong><span>Grounded: WASD move · Shift sprint · Space jumps · G toggles flight · Esc releases</span>';

    const performancePanel = h('section', 'eqlzv-performance-panel');
    performancePanel.hidden = true;
    performancePanel.setAttribute('aria-label', 'Zone viewer performance diagnostics');
    const performanceHeader = h('div', 'eqlzv-performance-header');
    const performanceTitle = h('strong', '', 'Performance diagnostics');
    const performanceClose = h('button', 'eqlzv-help-close', '×');
    performanceClose.type = 'button';
    performanceClose.setAttribute('aria-label', 'Close performance diagnostics');
    const performanceContent = h('div', 'eqlzv-performance-content');
    const benchmark = h('button', 'eqlzv-button eqlzv-primary', 'Run 5-second benchmark');
    benchmark.type = 'button';
    benchmark.disabled = true;
    const performanceActions = h('div', 'eqlzv-performance-actions');
    performanceActions.append(benchmark);
    performanceHeader.append(performanceTitle, performanceClose);
    performancePanel.append(performanceHeader, performanceContent, performanceActions);

    const hud = h('div', 'eqlzv-hud');
    const coord = h('span', 'eqlzv-coordinates', 'X —  Y —  Z —');
    const performance = h('button', 'eqlzv-performance');
    performance.type = 'button';
    performance.title = 'Open performance diagnostics';
    performance.setAttribute('aria-expanded', 'false');
    performance.hidden = true;
    const controlHint = h('div', 'eqlzv-control-hint');
    hud.append(coord, performance, controlHint);
    const status = h('div', 'eqlzv-status');
    status.innerHTML = '<span class="eqlzv-status-text">Ready.</span><progress max="1" value="0"></progress>';
    const pathCancel = h('button', 'eqlzv-status-cancel', 'Cancel path');
    pathCancel.type = 'button';
    pathCancel.hidden = true;
    status.append(pathCancel);
    viewport.append(canvas, worldCanvas, worldTooltip, mapLabels, miniMap, mobLabels, zoneBadge, overlay, helpPanel, performancePanel, firstPersonPrompt, hud);
    app.append(toolbar, modes, viewport, status);
    this.root.append(app);

    this.els = {
      app, picker, input, sourceLabel, zoneSelect, load, worldMap,
      top, first, fly, reset, props: props.input, wire: wire.input,
      textures: textures.input, collision: collision.input,
      namedMobs: namedMobs.input, locationBeams: locationBeams.input, distanceFog: distanceFog.input,
      clip, clipValue, floorPicker, floorSummary, floorPanel,
      gotoNpc, gotoList, gotoButton, fullscreen, clearCache,
      help, helpPanel, helpContent, helpClose,
      performancePanel, performanceContent, performanceClose,
      benchmark, firstPersonPrompt, viewport, canvas, worldCanvas, worldTooltip, mapLabels, miniMap, miniMapCanvas, mobLabels, navigationLabel, locationTooltip,
      zoneBadge, overlay, coord, performance, controlHint,
      statusText: status.querySelector('.eqlzv-status-text'),
      progress: status.querySelector('progress'),
      pathCancel
    };

    picker.addEventListener('click', () => this.selectDirectory());
    load.addEventListener('click', () => this.loadSelectedZone());
    worldMap.addEventListener('click', () => this.toggleWorldMap());
    zoneSelect.addEventListener('change', () => { load.disabled = !zoneSelect.value; });
    top.addEventListener('click', () => this.setMode('top'));
    first.addEventListener('click', () => this.setMode('first'));
    fly.addEventListener('click', () => this.toggleFly());
    reset.addEventListener('click', () => this.resetView());
    props.input.addEventListener('change', () => {
      this.propsGroup.visible = props.input.checked && !this.mapFileVisible;
      this.sceneStats = this.measureScene(this.sceneStats.placedProps || 0);
      this.applyRenderQuality(false);
      this.updatePerformanceHud();
      this.fp?.setScene(this.scene);
      this.refreshDistanceCullTargets();
      this.requestRender();
    });
    namedMobs.input.addEventListener('change', () => {
      this.updateNamedMobLabels();
      this.requestRender();
    });
    locationBeams.input.addEventListener('change', () => this.setLocationBeamsVisible(locationBeams.input.checked));
    distanceFog.input.addEventListener('change', () => {
      this.updateFirstPersonEnvironment(true);
      this.requestRender();
    });
    wire.input.addEventListener('change', () => this.updateMaterials());
    textures.input.addEventListener('change', () => this.updateMaterials());
    collision.input.addEventListener('change', () => { if (this.fp) this.fp.collision = collision.input.checked; });
    clip.addEventListener('input', () => this.updateClipping());
    floorSummary.addEventListener('click', event => {
      if (floorPicker.dataset.disabled === '1') event.preventDefault();
    });
    floorPanel.addEventListener('change', event => {
      if (event.target instanceof HTMLInputElement && event.target.matches('[data-eql-floor-index]')) {
        this.applyFloorSelection();
      }
    });
    const previewGoto = () => {
      const query = gotoNpc.value.trim();
      if (!query || !this.hasExactGotoMatch(query)) return;
      if (this.gotoPreviewTimer) clearTimeout(this.gotoPreviewTimer);
      this.gotoPreviewQuery = query;
      this.gotoPreviewTimer = setTimeout(() => {
        this.gotoPreviewTimer = 0;
        if (this.els.gotoNpc.value.trim() !== this.gotoPreviewQuery) return;
        void this.gotoNpcLabel(this.gotoPreviewQuery, { exactOnly: true, teleport: false });
      }, 280);
    };
    const performGoto = () => {
      if (this.gotoPreviewTimer) clearTimeout(this.gotoPreviewTimer);
      this.gotoPreviewTimer = 0;
      void this.gotoNpcLabel(gotoNpc.value, { teleport: true });
    };
    gotoButton.addEventListener('click', performGoto);
    pathCancel.addEventListener('click', () => this.cancelNavigationPathBuild());
    gotoNpc.addEventListener('input', previewGoto);
    gotoNpc.addEventListener('change', previewGoto);
    gotoNpc.addEventListener('keydown', event => {
      if (event.key === 'Enter') {
        event.preventDefault();
        performGoto();
      }
    });
    fullscreen.addEventListener('click', async () => {
      try {
        if (document.fullscreenElement) await document.exitFullscreen();
        else await this.els.app.requestFullscreen();
      } catch (error) {
        this.setStatus(`Full-screen mode was blocked: ${error.message}`);
      }
    });
    document.addEventListener('fullscreenchange', () => {
      fullscreen.textContent = document.fullscreenElement ? 'Exit Full Screen' : 'Full Screen';
      this.resize();
    });
    clearCache.addEventListener('click', async () => {
      this.cacheEpoch++;
      await this.cache.clear();
      this.currentZoneKey = null;
      this.worldMapData = null;
      this.worldMapSignature = null;
      this.worldMapBuildPromise = null;
      this.worldMapView = { scale: 1, offsetX: 0, offsetY: 0, initialized: false };
      this.setWorldMapVisible(false, { silent: true });
      this.setStatus('Local zone and World Map caches cleared.');
    });
    help.addEventListener('click', () => this.toggleHelp());
    helpClose.addEventListener('click', () => this.toggleHelp(false));
    performance.addEventListener('click', () => this.togglePerformancePanel());
    performanceClose.addEventListener('click', () => this.togglePerformancePanel(false));
    benchmark.addEventListener('click', () => this.runBenchmark());
    firstPersonPrompt.addEventListener('click', () => this.fp.lock());
    document.addEventListener('keydown', event => this.handleShortcut(event));
    this.updateControlHint();
    this.updateHelpContent();
  }
  checkbox(text, checked) {
    const label = h('label', 'eqlzv-check');
    const input = h('input'); input.type = 'checkbox'; input.checked = checked;
    label.append(input, document.createTextNode(text));
    return { label, input };
  }

  handleShortcut(event) {
    if (isFormControlFocused() || event.ctrlKey || event.altKey || event.metaKey) return;
    if (event.code === 'KeyH') { event.preventDefault(); this.toggleHelp(); }
    else if (this.worldMapVisible && event.code === 'Escape') { event.preventDefault(); this.setWorldMapVisible(false); }
    else if (event.code === 'KeyR') { event.preventDefault(); this.resetView(); }
    else if (!this.worldMapVisible && event.code === 'Digit1') { event.preventDefault(); this.setMode('top'); }
    else if (!this.worldMapVisible && (event.code === 'Digit2' || event.code === 'Digit3')) { event.preventDefault(); this.setMode('first'); }
    else if (!this.worldMapVisible && event.code === 'KeyM') { event.preventDefault(); this.toggleGameMap(); }
    else if (!this.worldMapVisible && event.code === 'KeyG') { event.preventDefault(); this.toggleFly(); }
  }

  toggleHelp(force) {
    const open = force === undefined ? this.els.helpPanel.hidden : Boolean(force);
    this.els.helpPanel.hidden = !open;
    this.els.help.setAttribute('aria-expanded', String(open));
    if (open) this.updateHelpContent();
  }

  togglePerformancePanel(force) {
    const open = force === undefined ? this.els.performancePanel.hidden : Boolean(force);
    this.els.performancePanel.hidden = !open;
    this.els.performance.setAttribute('aria-expanded', String(open));
    if (open) {
      this.toggleHelp(false);
      this.updatePerformancePanel();
    }
  }

  runBenchmark() {
    if (!this.zoneGroup.children.length) return;
    const now = performance.now();
    this.benchmarkStartedAt = now;
    this.benchmarkUntil = now + 5000;
    this.benchmarkFrames = 0;
    this.frameTimes = [];
    this.renderDurations = [];
    this.lastRenderedAt = 0;
    this.els.benchmark.disabled = true;
    this.els.benchmark.textContent = 'Benchmarking…';
    this.setStatus('Running a 5-second continuous-render benchmark…');
    this.requestRender();
  }

  updateHelpContent() {
    const modeName = this.worldMapVisible
      ? 'Connected World Map'
      : this.mapFileVisible
      ? 'Local Game Map'
      : this.miniMapVisible
        ? `${this.mode === 'first' ? (this.fp.fly ? 'First Person — Fly' : 'First Person — Grounded') : 'Top Down'} + Minimap`
        : this.mode === 'first'
        ? (this.fp.fly ? 'First Person — Fly' : 'First Person — Grounded')
        : 'Top Down';
    const mapStatus = this.mapData
      ? `<kbd>M</kbd> cycles <b>Full map → Minimap → Closed</b> using the local map loaded from <b>${escapeHtml(this.mapData.sourceLabel)}</b>.`
      : 'No matching map file was found under the selected game folder’s <b>maps</b> directory.';
    this.els.helpContent.innerHTML = `
      <p class="eqlzv-help-current">Current view: <strong>${modeName}</strong></p>
      <div class="eqlzv-help-grid">
        <section class="${this.worldMapVisible ? 'is-current' : ''}">
          <h3>World Map</h3>
          <p>Built locally from each recognized zone’s map geometry and exact <b>to …</b> labels. <b>Drag</b> to pan, use the <b>wheel</b> to zoom, and click any zone card or firepot destination name to load that zone. Connections use separate card ports and high-penalty crossing lanes so routes remain individually traceable instead of stacking on top of one another. Solid links are ordinary zone lines, blue dashes are boat/water routes, and purple dashes are portals or teleports.</p>
        </section>
        <section class="${this.mode === 'top' && !this.mapFileVisible && !this.worldMapVisible ? 'is-current' : ''}">
          <h3>Top Down <kbd>1</kbd></h3>
          <p><b>Left-drag</b> freely rotates and rolls the 3D view without an underside limit, <b>right-drag</b> pans, and the <b>wheel</b> zooms. <kbd>R</kbd> returns to the perfectly top-down view. Click geometry to read native EQ coordinates.</p>
        </section>
        <section class="${this.mode === 'first' ? 'is-current' : ''}">
          <h3>First Person <kbd>2</kbd></h3>
          <p>Click the scene to capture the mouse. <b>WASD</b> walks, <b>Shift</b> sprints, <b>Space</b> jumps roughly 10 EQ feet, and <kbd>G</kbd> toggles Grounded/Fly. Grounded mode follows the terrain. In Fly mode, forward movement follows mouse pitch; <b>Space/E</b> rises and <b>Q/C</b> descends. <b>Esc</b> releases the mouse.</p>
        </section>
      </div>
      <p class="eqlzv-help-global">The <b>World Map</b> button is an alternative to the zone dropdown and is cached locally until a relevant archive or map file changes. <kbd>M</kbd> cycles the current zone’s full local map, a bottom-right minimap, and the closed state. The full map preserves the current Top Down framing rather than resetting its zoom. <kbd>R</kbd> resets the active view. <kbd>H</kbd> toggles this panel. ${mapStatus} The glowing red arrow marks the most recent First Person location and facing direction. The <b>Path to location</b> list previews a collision-aware golden walking path as soon as a destination is selected. Every searchable location has a slim gold pillar; hovering or clicking a pillar reveals its complete point-of-interest label, while the selected destination receives the brighter beam and ground ring. All pillars and the selected beam can be hidden with <b>Location beams</b> under <b>Display</b>. <b>Go to</b> teleports the First Person camera to that exact destination while preserving the route. Pathfinding uses the same 10-unit jump limit as Grounded mode. Its map-graph search runs in a dedicated background worker, so you can keep walking and looking around while it calculates. The route remains anchored to the position where you requested it; movement never restarts or rebases the calculation. Only the short final ground-projection pass runs cooperatively on the viewer thread. It can be cancelled from the status bar. Named-mob links open in a new tab and can be disabled under <b>Display</b>. <b>Ctrl is not used by the viewer.</b></p>`;
  }

  hotkey(key, label, disabled = false) {
    return `<span class="eqlzv-hotkey${disabled ? ' is-disabled' : ''}"><kbd>${escapeHtml(key)}</kbd>${escapeHtml(label)}</span>`;
  }

  updateControlHint() {
    const mapAvailable = Boolean(this.mapData?.lines?.length);
    let items;
    if (this.worldMapVisible) {
      items = [
        this.hotkey('Drag', 'Pan world'),
        this.hotkey('Wheel', 'Zoom'),
        this.hotkey('Click', 'Load zone'),
        this.hotkey('R', 'Fit world'),
        this.hotkey('Esc', 'Close')
      ];
    } else if (this.mapFileVisible) {
      items = [
        this.hotkey('Drag', 'Pan'),
        this.hotkey('Wheel', 'Zoom'),
        this.hotkey('M', 'Minimap'),
        this.hotkey('R', 'Reset')
      ];
    } else if (this.mode === 'top') {
      items = [
        this.hotkey('Left drag', 'Rotate'),
        this.hotkey('Right drag', 'Pan'),
        this.hotkey('Wheel', 'Zoom'),
        this.hotkey('M', this.miniMapVisible ? 'Close minimap' : 'Full map', !mapAvailable),
        this.hotkey('R', 'Reset')
      ];
    } else {
      items = [
        this.hotkey('WASD', this.fp.fly ? 'Fly' : 'Walk'),
        this.hotkey('Shift', 'Sprint'),
        this.hotkey('Space', this.fp.fly ? 'Rise' : 'Jump'),
        this.hotkey('G', this.fp.fly ? 'Ground lock' : 'Fly'),
        this.hotkey('M', this.miniMapVisible ? 'Close minimap' : 'Full map', !mapAvailable),
        this.hotkey('Esc', 'Cursor')
      ];
    }
    this.els.controlHint.innerHTML = items.join('');
    this.updateHelpContent();
  }

  updateFirstPersonPrompt() {
    if (!this.els.firstPersonPrompt) return;
    this.els.firstPersonPrompt.innerHTML = this.fp?.fly
      ? '<strong>Click to enter Fly mode</strong><span>WASD follows mouselook · Shift sprint · Space/E rise · Q/C descend · G returns to ground</span>'
      : '<strong>Click to enter Walk mode</strong><span>Ground-locked WASD · Shift sprint · Space jumps · G toggles flight · Esc releases</span>';
  }

  initThree() {
    const canvas = this.els.canvas;
    this.renderer = new THREE.WebGLRenderer({ canvas, antialias: true, alpha: false, powerPreference: 'high-performance' });
    // High quality is fixed. Continuous rendering targets 60 FPS.
    this.renderer.setPixelRatio(Math.min(Math.max(1, devicePixelRatio || 1), 1.5));
    this.renderer.localClippingEnabled = true;
    this.renderer.outputColorSpace = THREE.SRGBColorSpace;
    this.renderer.setClearColor(0x08101b, 1);

    this.scene = new THREE.Scene();
    this.scene.background = new THREE.Color(0x08101b);
    this.scene.add(this.zoneGroup, this.propsGroup, this.mapGroup, this.navigationGroup);
    this.scene.add(new THREE.HemisphereLight(0xcddfff, 0x19212b, 2.2));
    const sun = new THREE.DirectionalLight(0xffffff, 2.1); sun.position.set(200, 400, 160); this.scene.add(sun);

    this.perspective = new THREE.PerspectiveCamera(68, 1, 0.1, 200000);
    this.orthographic = new THREE.OrthographicCamera(-100, 100, 100, -100, -100000, 100000);
    this.camera = this.perspective;
    this.orbit = this.createOrbitControls(this.perspective, false);
    this.fp = new FirstPersonController(this.perspective, canvas);
    this.fp.controls.addEventListener('lock', () => {
      this.els.viewport.classList.add('is-pointer-locked');
      this.els.firstPersonPrompt.hidden = true;
      this.updateControlHint();
    });
    this.fp.controls.addEventListener('unlock', () => {
      this.els.viewport.classList.remove('is-pointer-locked');
      this.els.firstPersonPrompt.hidden = this.mode !== 'first';
      this.updateControlHint();
    });

    this.loadingManager = new THREE.LoadingManager();
    this.loadingManager.setURLModifier((url) => url.startsWith('data:') || url.startsWith('blob:') ? url : PLACEHOLDER_TEXTURE);
    this.gltfLoader = new GLTFLoader(this.loadingManager);
    this.raycaster = new THREE.Raycaster();
    this.raycaster.params.Line.threshold = 4;
    this.pointer = new THREE.Vector2();
    this.mapLabelContext = this.els.mapLabels.getContext('2d');
    this.miniMapContext = this.els.miniMapCanvas.getContext('2d');
    this.worldMapContext = this.els.worldCanvas.getContext('2d');

    this.els.worldCanvas.addEventListener('pointerdown', event => this.onWorldMapPointerDown(event));
    this.els.worldCanvas.addEventListener('pointermove', event => this.onWorldMapPointerMove(event));
    this.els.worldCanvas.addEventListener('pointerup', event => this.onWorldMapPointerUp(event));
    this.els.worldCanvas.addEventListener('pointercancel', () => this.cancelWorldMapPointer());
    this.els.worldCanvas.addEventListener('pointerleave', event => this.onWorldMapPointerLeave(event));
    this.els.worldCanvas.addEventListener('wheel', event => this.onWorldMapWheel(event), { passive: false });

    canvas.addEventListener('pointerdown', (event) => {
      if (this.mode === 'first') {
        if (this.fp.controls.isLocked && event.button === 0 && this.targetedMobLabel?.href) {
          this.fp.controls.unlock();
          globalThis.open(this.targetedMobLabel.href, '_blank', 'noopener,noreferrer');
          return;
        }
        if (event.button === 0 && this.pinLocationPillar(event)) return;
        if (!this.fp.controls.isLocked) this.fp.lock();
        return;
      }
      if (event.button === 0) this.pointerStart = { x: event.clientX, y: event.clientY, pointerId: event.pointerId };
    });
    canvas.addEventListener('pointerup', (event) => {
      if (!this.pointerStart || this.pointerStart.pointerId !== event.pointerId || event.button !== 0 || this.mode === 'first') return;
      const distance = Math.hypot(event.clientX - this.pointerStart.x, event.clientY - this.pointerStart.y);
      this.pointerStart = null;
      if (distance <= 4 && !this.pinLocationPillar(event)) this.pickCoordinate(event);
    });
    canvas.addEventListener('pointermove', event => {
      const rect = this.els.viewport.getBoundingClientRect();
      this.locationPointerClient = { x: event.clientX - rect.left, y: event.clientY - rect.top };
      this.updateLocationPillarHover();
      this.requestRender();
    });
    canvas.addEventListener('pointerleave', () => {
      if (!this.fp?.controls?.isLocked) {
        this.locationPointerClient = null;
        this.locationHoverEntry = null;
        if (!this.locationPinnedEntry || performance.now() >= this.locationPinnedUntil) {
          if (this.els?.locationTooltip) this.els.locationTooltip.hidden = true;
        } else {
          this.updateLocationPillarHover();
        }
      }
    });
    canvas.addEventListener('pointercancel', () => { this.pointerStart = null; });
    window.addEventListener('resize', () => this.resize());
    new ResizeObserver(() => this.resize()).observe(this.els.viewport);
    this.resize();
  }

  createOrbitControls(camera, mapMode = false) {
    if (!mapMode) {
      const controls = new TrackballControls(camera, this.els.canvas);
      controls.rotateSpeed = 3.0;
      controls.zoomSpeed = 1.2;
      controls.panSpeed = 0.8;
      controls.staticMoving = false;
      controls.dynamicDampingFactor = 0.12;
      controls.noRotate = false;
      controls.noZoom = false;
      controls.noPan = false;
      controls.addEventListener('change', () => {
        this.updateCoordinateHud();
        this.updateNamedMobLabels();
        if (this.mode === 'top' && !this.mapFileVisible) this.savedTopCameraState = this.captureCameraState(controls.target);
        this.drawMiniMap();
        this.requestRender();
      });
      return controls;
    }
    const controls = new OrbitControls(camera, this.els.canvas);
    controls.enableDamping = true;
    controls.dampingFactor = 0.12;
    controls.screenSpacePanning = true;
    controls.enableRotate = !mapMode;
    controls.minPolarAngle = 0;
    controls.maxPolarAngle = Math.PI;
    controls.minAzimuthAngle = -Infinity;
    controls.maxAzimuthAngle = Infinity;
    controls.mouseButtons.LEFT = mapMode ? THREE.MOUSE.PAN : THREE.MOUSE.ROTATE;
    controls.mouseButtons.MIDDLE = THREE.MOUSE.DOLLY;
    controls.mouseButtons.RIGHT = THREE.MOUSE.PAN;
    controls.touches.ONE = mapMode ? THREE.TOUCH.PAN : THREE.TOUCH.ROTATE;
    controls.touches.TWO = THREE.TOUCH.DOLLY_PAN;
    controls.addEventListener('change', () => {
      this.updateCoordinateHud();
      this.updateNamedMobLabels();
      if (this.mapFileVisible) this.savedFullMapCameraState = this.captureCameraState(controls.target);
      this.requestRender();
    });
    return controls;
  }

  async selectDirectory() {
    this.source = new LocalGameSource(this.els.input);
    this.setStatus('Waiting for directory permission…');
    try {
      const selected = await this.source.select();
      if (!selected) { this.setStatus('Directory selection cancelled.'); return; }
      this.cacheEpoch++;
      await this.cache.clearPrefix('zone:');
      this.currentZoneKey = null;
      this.worldMapData = null;
      this.worldMapSignature = null;
      this.worldMapBuildPromise = null;
      this.worldMapView = { scale: 1, offsetX: 0, offsetY: 0, initialized: false };
      this.setWorldMapVisible(false, { silent: true });
      const candidates = this.source.archiveCandidates();
      if (!candidates.length) throw new Error('No .s3d or .eqg archives were found in that directory.');
      this.els.sourceLabel.textContent = `${this.source.label} · ${this.source.fileEntries.length.toLocaleString()} relevant files`;
      this.els.zoneSelect.disabled = true;
      this.els.load.disabled = true;
      this.els.worldMap.disabled = true;
      this.els.overlay.hidden = false;
      this.els.overlay.innerHTML = '<div class="eqlzv-spinner"></div><div>Identifying actual zone archives…</div>';
      const scannedZones = await this.scanZoneArchives(candidates);
      const scannedKeys = new Set(scannedZones.map(zone => `${zone.format}:${zone.id}`));
      const knownArchiveFallbacks = candidates
        .filter(candidate => Object.prototype.hasOwnProperty.call(ZONE_DISPLAY_NAMES, candidate.id))
        .filter(candidate => this.source.mapFamilyForZone(candidate.id))
        .filter(candidate => !scannedKeys.has(`${candidate.format}:${candidate.id}`))
        .map(candidate => ({ ...candidate, scanFallback: true }));
      this.zones = [...scannedZones, ...knownArchiveFallbacks].sort((a, b) =>
        a.id.localeCompare(b.id, undefined, { numeric: true }) || (a.format === 'S3D' ? -1 : 1));
      if (!this.zones.length) throw new Error('No playable zone archives were identified. Make sure the selected folder is the EverQuest installation root.');
      this.els.sourceLabel.textContent = `${this.source.label} · ${this.source.fileEntries.length.toLocaleString()} relevant files · ${this.zones.length.toLocaleString()} zones`;
      this.els.zoneSelect.disabled = false;
      this.populateZones();
      this.els.worldMap.disabled = !this.worldZoneRecords().length;
      this.setStatus('Directory indexed. Choose a zone or open the World Map. Firefox’s “Upload Folder” wording is only local read permission.', 0);
      this.els.overlay.innerHTML = '<div class="eqlzv-welcome"><strong>Directory connected</strong><span>Only archives containing zone geometry are listed. Choose a zone above or open the connected World Map.</span><small>Files remain local to this browser and are not uploaded to EQL Wiki.</small></div>';
    } catch (error) {
      this.showError(error);
    }
  }

  scanZoneArchives(candidates) {
    if (this.worker) this.worker.terminate();
    this.worker = new Worker(this.config.workerUrl);
    const id = ++this.requestId;
    return new Promise((resolve, reject) => {
      this.worker.onmessage = (event) => {
        const msg = event.data || {};
        if (msg.id && msg.id !== id) return;
        if (msg.type === 'progress') this.setStatus(msg.message || 'Scanning archives…', msg.value ?? null);
        else if (msg.type === 'scan-complete') {
          this.worker.terminate(); this.worker = null;
          const byKey = new Map(candidates.map(candidate => [`${candidate.format}:${candidate.id}`, candidate]));
          const zones = (msg.zones || []).map(item => {
            const original = byKey.get(`${item.format}:${item.id}`);
            return original ? { ...item, file: original.file } : null;
          }).filter(Boolean);
          resolve(zones);
        } else if (msg.type === 'error') {
          this.worker.terminate(); this.worker = null;
          reject(new Error(msg.message || 'Archive scan failed.'));
        }
      };
      this.worker.onerror = (event) => {
        this.worker?.terminate(); this.worker = null;
        reject(new Error(event.message || 'The archive scanner crashed.'));
      };
      this.worker.postMessage({ id, action: 'scan', candidates: candidates.map(c => ({ id: c.id, format: c.format, file: c.file })) });
    });
  }

  populateZones() {
    const select = this.els.zoneSelect;
    const previous = select.value;
    const zones = [...(this.zones || [])].sort((a, b) =>
      zoneDisplayName(a.id).localeCompare(zoneDisplayName(b.id), undefined, { numeric: true }) ||
      a.id.localeCompare(b.id, undefined, { numeric: true }) ||
      a.format.localeCompare(b.format)
    );
    const known = zones.filter(zone => Object.prototype.hasOwnProperty.call(ZONE_DISPLAY_NAMES, zone.id));
    const other = zones.filter(zone => !Object.prototype.hasOwnProperty.call(ZONE_DISPLAY_NAMES, zone.id));
    const options = records => records.map(zone => {
      const key = `${zone.format}:${zone.id}`;
      const display = Object.prototype.hasOwnProperty.call(ZONE_DISPLAY_NAMES, zone.id)
        ? zoneDisplayName(zone.id)
        : titleCaseZoneId(zone.id);
      return `<option value="${escapeHtml(key)}">${escapeHtml(display)} — ${escapeHtml(zone.id)} (${zone.format})</option>`;
    }).join('');
    let html = '<option value="">Choose zone…</option>';
    if (known.length) html += `<optgroup label="Known EverQuest zones">${options(known)}</optgroup>`;
    if (other.length) html += `<optgroup label="Other playable zone archives">${options(other)}</optgroup>`;
    select.innerHTML = html;
    if (zones.some(zone => `${zone.format}:${zone.id}` === previous)) select.value = previous;
    this.els.load.disabled = !select.value;
  }

  selectedZoneRecord() {
    const key = this.els.zoneSelect.value;
    return (this.zones || []).find(z => `${z.format}:${z.id}` === key) || null;
  }


  worldZoneRecords() {
    const chosen = new Map();
    for (const record of this.zones || []) {
      if (!Object.prototype.hasOwnProperty.call(ZONE_DISPLAY_NAMES, record.id)) continue;
      const worldId = canonicalWorldZoneId(record.id);
      if (WORLD_MAP_EXCLUDED_ZONE_IDS.has(worldId)) continue;
      const existing = chosen.get(worldId);
      const score = (record.id === worldId ? 100 : 0) + (record.format === 'S3D' ? 10 : 0) + (this.source?.mapFamilyForZone(record.id) ? 5 : 0);
      if (!existing || score > existing.score) chosen.set(worldId, { record, score });
    }
    return [...chosen.values()].map(item => item.record).sort((a, b) => zoneDisplayName(a.id).localeCompare(zoneDisplayName(b.id)));
  }

  async readWorldZoneMap(record) {
    const family = this.source?.mapFamilyForZone(record.id);
    if (!family) return { lines: [], points: [], sourceLabel: null };
    const lines = [];
    const points = [];
    for (const layer of [0, 1]) {
      const file = family.layers.get(layer);
      if (!file) continue;
      try {
        const parsed = parseEqMapText(await file.text(), layer, file.name);
        lines.push(...parsed.lines);
        points.push(...parsed.points);
      } catch (error) {
        console.warn(`[EQLZoneViewer] Could not parse ${file.name} for the world map.`, error);
      }
    }
    return { lines, points, sourceLabel: family.directory || 'maps' };
  }

  async buildWorldMapData(force = false) {
    if (!this.source) throw new Error('Select the EverQuest installation folder before opening the World Map.');
    if (this.worldMapBuildPromise && !force) return this.worldMapBuildPromise;
    this.worldMapBuildPromise = (async () => {
      const records = this.worldZoneRecords();
      if (!records.length) throw new Error('No recognized EverQuest zones are available for the World Map.');
      const signature = this.source.worldMapSignature(records);
      if (this.worldMapSignature && this.worldMapSignature !== signature) {
        this.worldMapView = { scale: 1, offsetX: 0, offsetY: 0, initialized: false };
      }
      const cacheKey = `world:${signature}`;
      if (!force && this.worldMapData && this.worldMapSignature === signature) return this.worldMapData;

      if (!force) {
        this.setStatus('Checking the cached connected World Map…', 0.03);
        const cached = await this.cache.get(cacheKey);
        if (cached?.cacheVersion === WORLD_MAP_CACHE_VERSION && Array.isArray(cached.nodes) && Array.isArray(cached.edges)) {
          this.worldMapData = cached;
          this.worldMapSignature = signature;
          this.prepareWorldMapPreviews();
          this.setStatus(`Connected World Map loaded from cache · ${cached.nodes.length} zones · ${cached.edges.length} connections.`, 0);
          return cached;
        }
      }

      const nodes = [];
      const availableWorldIds = new Set(records.map(record => canonicalWorldZoneId(record.id)));
      const recordByWorldId = new Map(records.map(record => [canonicalWorldZoneId(record.id), record]));
      const connectionCandidates = [];
      const unresolved = [];

      for (let index = 0; index < records.length; index++) {
        const record = records[index];
        const worldId = canonicalWorldZoneId(record.id);
        this.setStatus(`Building World Map · reading ${zoneDisplayName(record.id)} (${index + 1}/${records.length})…`, 0.06 + 0.54 * ((index + 1) / records.length));
        const map = await this.readWorldZoneMap(record);
        const snapshot = buildWorldMapSnapshot(map.lines);
        const size = makeWorldNodeSize(snapshot);
        nodes.push({
          id: record.id,
          worldId,
          format: record.format,
          name: zoneDisplayName(record.id),
          group: ZONE_WORLD_GROUPS[worldId] || 'other',
          width: size.width,
          height: size.height,
          snapshot,
          mapSource: map.sourceLabel
        });

        for (const point of map.points) {
          if (!/^\s*to\b/i.test(point.label)) continue;
          const targetWorldIds = resolveWorldConnectionTargets(point.label, worldId, availableWorldIds);
          if (!targetWorldIds.length) {
            unresolved.push({ source: worldId, label: point.label });
            continue;
          }
          for (const targetWorldId of targetWorldIds) {
            connectionCandidates.push({
              from: worldId,
              to: targetWorldId,
              type: classifyWorldConnection(point.label, worldId, targetWorldId),
              label: point.label.replace(/^\s*to\s+/i, '').trim(),
              source: 'map',
              bidirectional: false,
              oneWay: WORLD_FORCED_ONE_WAY_CONNECTIONS.has(`${worldId}>${targetWorldId}`)
            });
          }
        }
        if (index % 4 === 3) await nextBrowserFrame();
      }

      for (const route of WORLD_MANUAL_CONNECTIONS) {
        const from = canonicalWorldZoneId(route.from);
        const to = canonicalWorldZoneId(route.to);
        if (!recordByWorldId.has(from) || !recordByWorldId.has(to)) continue;
        connectionCandidates.push({
          from,
          to,
          type: route.type,
          label: route.label,
          source: 'manual',
          bidirectional: Boolean(route.bidirectional),
          oneWay: Boolean(route.oneWay)
        });
      }

      const edgeByPair = new Map();
      const typeWeight = { land: 1, water: 2, portal: 3, teleport: 4 };
      for (const route of connectionCandidates) {
        if (route.from === route.to) continue;
        const key = worldConnectionKey(route.from, route.to);
        const [a, b] = key.split('|');
        let edge = edgeByPair.get(key);
        if (!edge) {
          edge = {
            a,
            b,
            type: route.type,
            labels: [],
            sources: new Set(),
            directions: new Set(),
            forcedOneWay: null
          };
          edgeByPair.set(key, edge);
        }
        if (typeWeight[route.type] > typeWeight[edge.type]) edge.type = route.type;
        if (route.label && !edge.labels.includes(route.label)) edge.labels.push(route.label);
        edge.sources.add(route.source);
        edge.directions.add(`${route.from}>${route.to}`);
        if (route.bidirectional) edge.directions.add(`${route.to}>${route.from}`);
        if (route.oneWay) edge.forcedOneWay = `${route.from}>${route.to}`;
        const forcedKey = `${route.from}>${route.to}`;
        if (WORLD_FORCED_ONE_WAY_CONNECTIONS.has(forcedKey)) edge.forcedOneWay = forcedKey;
      }

      const edges = [...edgeByPair.values()].map(edge => {
        const oneWay = Boolean(edge.forcedOneWay);
        const [from, to] = oneWay ? edge.forcedOneWay.split('>') : [edge.a, edge.b];
        const source = edge.sources.has('map') && edge.sources.has('manual')
          ? 'map+manual'
          : (edge.sources.has('map') ? 'map' : 'manual');
        return {
          a: edge.a,
          b: edge.b,
          from,
          to,
          oneWay,
          type: edge.type,
          label: edge.labels.slice(0, 4).join(' / '),
          source,
          evidenceDirections: [...edge.directions]
        };
      });

      this.setStatus('Building World Map · arranging zones and routing validated connections…', 0.68);
      layoutWorldGraph(nodes, edges);
      // Treat the firepot panel as a real routing obstacle even though it is
      // not a loadable zone card. Long boat/portal lines must go around it,
      // never through the clickable destination list.
      routeWorldGraphEdges([...nodes, worldFirepotInsetNode()], edges);
      annotateWorldRouteCrossings(nodes, edges);
      const firepotInsetRoute = routeWorldFirepotInset(nodes);
      const data = {
        cacheVersion: WORLD_MAP_CACHE_VERSION,
        signature,
        generatedAt: Date.now(),
        nodes,
        edges,
        firepotInsetRoute,
        unresolvedCount: unresolved.length
      };
      this.worldMapData = data;
      this.worldMapSignature = signature;
      this.setStatus(`Connected World Map generated · ${nodes.length} zones · ${edges.length} connections.`, 0.92);
      await this.cache.set(cacheKey, data);
      this.prepareWorldMapPreviews();
      this.setStatus(`Connected World Map ready · ${nodes.length} zones · ${edges.length} connections · cached locally.`, 0);
      return data;
    })();

    try {
      return await this.worldMapBuildPromise;
    } finally {
      this.worldMapBuildPromise = null;
    }
  }

  prepareWorldMapPreviews() {
    if (!this.worldMapData?.nodes) return;
    for (const node of this.worldMapData.nodes) {
      if (node.previewCanvas) continue;
      const width = Math.max(96, Math.round(node.width * 1.35));
      const height = Math.max(76, Math.round(node.height * 1.35));
      const canvas = document.createElement('canvas');
      canvas.width = width;
      canvas.height = height;
      const ctx = canvas.getContext('2d');
      ctx.clearRect(0, 0, width, height);
      const snapshot = node.snapshot;
      if (snapshot?.segments?.length) {
        const pad = 7;
        const scale = Math.min((width - pad * 2) / Math.max(1, snapshot.width), (height - pad * 2) / Math.max(1, snapshot.height));
        ctx.translate(width / 2, height / 2);
        ctx.lineCap = 'round';
        const grouped = new Map();
        for (let index = 0; index < snapshot.segments.length; index += 5) {
          const color = snapshot.segments[index + 4] >>> 0;
          if (!grouped.has(color)) grouped.set(color, []);
          grouped.get(color).push(
            snapshot.segments[index], snapshot.segments[index + 1],
            snapshot.segments[index + 2], snapshot.segments[index + 3]
          );
        }
        for (const [color, segments] of grouped) {
          ctx.beginPath();
          for (let index = 0; index < segments.length; index += 4) {
            ctx.moveTo(segments[index] * scale, segments[index + 1] * scale);
            ctx.lineTo(segments[index + 2] * scale, segments[index + 3] * scale);
          }
          ctx.strokeStyle = `#${color.toString(16).padStart(6, '0')}`;
          ctx.globalAlpha = color === 0xc8d7e4 ? 0.78 : 0.88;
          ctx.lineWidth = 1;
          ctx.stroke();
        }
        ctx.globalAlpha = 1;
      } else {
        ctx.strokeStyle = 'rgba(150, 180, 207, .42)';
        ctx.setLineDash([5, 4]);
        ctx.strokeRect(8.5, 8.5, width - 17, height - 17);
        ctx.setLineDash([]);
        ctx.fillStyle = 'rgba(165, 190, 212, .68)';
        ctx.font = '600 11px system-ui, sans-serif';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText('No local map preview', width / 2, height / 2);
      }
      node.previewCanvas = canvas;
    }
  }

  async toggleWorldMap(force) {
    const visible = force === undefined ? !this.worldMapVisible : Boolean(force);
    if (!visible) {
      this.setWorldMapVisible(false);
      return;
    }
    try {
      this.fp?.deactivate();
      this.els.firstPersonPrompt.hidden = true;
      this.els.worldMap.disabled = true;
      this.els.overlay.hidden = false;
      this.els.overlay.innerHTML = '<div class="eqlzv-spinner"></div><div>Building the connected World Map from local map files…</div>';
      await this.buildWorldMapData(false);
      this.setWorldMapVisible(true);
    } catch (error) {
      this.showError(error);
    } finally {
      this.els.worldMap.disabled = !this.source || !this.worldZoneRecords().length;
    }
  }

  setWorldMapVisible(visible, options = {}) {
    const nextVisible = Boolean(visible && this.worldMapData?.nodes?.length);
    if (nextVisible === this.worldMapVisible && !nextVisible) return;
    if (nextVisible && this.mapFileVisible) this.setGameMapVisible(false);
    this.worldMapVisible = nextVisible;
    this.els.worldMap.classList.toggle('is-active', nextVisible);
    this.els.worldMap.setAttribute('aria-pressed', String(nextVisible));
    this.els.worldCanvas.hidden = !nextVisible;
    this.els.canvas.hidden = nextVisible;
    this.els.mapLabels.hidden = nextVisible;
    this.els.mobLabels.hidden = nextVisible;
    this.els.miniMap.hidden = nextVisible || !this.miniMapVisible;
    this.els.worldTooltip.hidden = true;

    if (nextVisible) {
      this.fp?.deactivate();
      this.els.firstPersonPrompt.hidden = true;
      this.els.overlay.hidden = true;
      this.els.zoneBadge.textContent = `World Map · ${this.worldMapData.nodes.length} zones`;
      this.worldMapControlState = {
        floorDisabled: this.els.floorPicker.dataset.disabled,
        gotoDisabled: this.els.gotoNpc.disabled,
        gotoButtonDisabled: this.els.gotoButton.disabled,
        clipDisabled: this.els.clip.disabled
      };
      for (const el of [this.els.top, this.els.first, this.els.fly]) el.disabled = true;
      this.els.floorPicker.dataset.disabled = '1';
      this.els.floorPicker.removeAttribute('open');
      this.els.gotoNpc.disabled = true;
      this.els.gotoButton.disabled = true;
      this.els.clip.disabled = true;
      this.els.reset.disabled = false;
      if (!this.worldMapView.initialized) this.resetWorldMapView();
      else this.drawWorldMap();
      this.setStatus(`World Map open · drag to pan · wheel to zoom · click a zone to load it.`, 0);
    } else {
      this.els.canvas.hidden = false;
      this.els.mapLabels.hidden = false;
      this.els.mobLabels.hidden = false;
      this.els.miniMap.hidden = !this.miniMapVisible;
      const hasZone = Boolean(this.zoneGroup.children.length);
      this.setViewerControlsEnabled(hasZone);
      if (this.worldMapControlState) {
        this.els.floorPicker.dataset.disabled = this.worldMapControlState.floorDisabled;
        this.els.gotoNpc.disabled = this.worldMapControlState.gotoDisabled;
        this.els.gotoButton.disabled = this.worldMapControlState.gotoButtonDisabled;
        this.els.clip.disabled = this.worldMapControlState.clipDisabled;
        this.worldMapControlState = null;
      }
      if (hasZone) {
        this.els.zoneBadge.textContent = `${zoneDisplayName(this.currentZoneRecord?.id)} · ${this.currentZoneRecord?.id || ''}`.replace(/ · $/, '');
        this.els.overlay.hidden = true;
        this.requestRender();
      } else if (this.source) {
        this.els.zoneBadge.textContent = 'No zone loaded';
        this.els.overlay.hidden = false;
        this.els.overlay.innerHTML = '<div class="eqlzv-welcome"><strong>Directory connected</strong><span>Choose a zone above or reopen the World Map.</span><small>Files remain local to this browser and are not uploaded to EQL Wiki.</small></div>';
      }
      if (!options.silent) this.setStatus('World Map closed.');
    }
    this.updateControlHint();
    this.updateHelpContent();
  }

  resetWorldMapView() {
    if (!this.worldMapData?.nodes?.length) return;
    const width = Math.max(1, this.els.viewport.clientWidth);
    const height = Math.max(1, this.els.viewport.clientHeight);
    let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
    for (const node of this.worldMapData.nodes) {
      minX = Math.min(minX, node.x - node.width * 0.5 - 40);
      maxX = Math.max(maxX, node.x + node.width * 0.5 + 40);
      minY = Math.min(minY, node.y - node.height * 0.5 - 60);
      maxY = Math.max(maxY, node.y + node.height * 0.5 + 40);
    }
    for (const region of worldRegionBounds(this.worldMapData.nodes)) {
      minX = Math.min(minX, region.minX - 20);
      maxX = Math.max(maxX, region.maxX + 20);
      minY = Math.min(minY, region.minY - 20);
      maxY = Math.max(maxY, region.maxY + 20);
    }
    minX = Math.min(minX, WORLD_FIREPOT_INSET.minX - 20);
    maxX = Math.max(maxX, WORLD_FIREPOT_INSET.maxX + 20);
    minY = Math.min(minY, WORLD_FIREPOT_INSET.minY - 20);
    maxY = Math.max(maxY, WORLD_FIREPOT_INSET.maxY + 20);
    const worldWidth = Math.max(1, maxX - minX);
    const worldHeight = Math.max(1, maxY - minY);
    const scale = clamp(Math.min((width - 70) / worldWidth, (height - 70) / worldHeight), WORLD_MAP_MIN_SCALE, WORLD_MAP_MAX_SCALE);
    this.worldMapView.scale = scale;
    this.worldMapView.offsetX = width * 0.5 - (minX + maxX) * 0.5 * scale;
    this.worldMapView.offsetY = height * 0.5 - (minY + maxY) * 0.5 * scale;
    this.worldMapView.initialized = true;
    this.drawWorldMap();
  }

  worldToScreen(x, y) {
    return {
      x: x * this.worldMapView.scale + this.worldMapView.offsetX,
      y: y * this.worldMapView.scale + this.worldMapView.offsetY
    };
  }

  screenToWorld(x, y) {
    return {
      x: (x - this.worldMapView.offsetX) / this.worldMapView.scale,
      y: (y - this.worldMapView.offsetY) / this.worldMapView.scale
    };
  }

  worldNodeLabelMetrics(node, scale = this.worldMapView.scale) {
    const ctx = this.worldMapContext;
    const fontSize = clamp(19 * Math.sqrt(scale), 10, 21);
    ctx.font = `700 ${fontSize}px Georgia, serif`;
    const words = String(node.name || '').replace(/\s*\/\s*/g, ' / ').split(/\s+/).filter(Boolean);
    const preferredLineWidth = clamp(Math.max(node.width * scale * 0.82, 112), 112, 292);
    const lines = [];
    let current = '';
    for (const word of words) {
      const candidate = current ? `${current} ${word}` : word;
      if (!current || ctx.measureText(candidate).width <= preferredLineWidth || lines.length >= 1) current = candidate;
      else { lines.push(current); current = word; }
    }
    if (current) lines.push(current);
    if (lines.length > 2) {
      const first = lines.shift();
      lines.splice(0, lines.length, first, lines.join(' '));
    }
    const shownLines = lines.slice(0, 2);
    const textWidth = Math.max(...shownLines.map(line => ctx.measureText(line).width), 0);
    const lineHeight = fontSize * 1.04;
    const plaqueWidth = clamp(Math.max(node.width * scale * 0.76, textWidth + 22), 94, 340);
    const plaqueHeight = Math.max(27, shownLines.length * lineHeight + 10);
    return { fontSize, shownLines, lineHeight, plaqueWidth, plaqueHeight };
  }

  worldNodeAt(clientX, clientY) {
    if (!this.worldMapData?.nodes?.length) return null;
    const rect = this.els.worldCanvas.getBoundingClientRect();
    const px = clientX - rect.left;
    const py = clientY - rect.top;
    const scale = this.worldMapView.scale;
    for (let index = this.worldMapData.nodes.length - 1; index >= 0; index--) {
      const node = this.worldMapData.nodes[index];
      const center = this.worldToScreen(node.x, node.y);
      const w = node.width * scale;
      const h = node.height * scale;
      const cardHit = px >= center.x - w * 0.5 && px <= center.x + w * 0.5 && py >= center.y - h * 0.5 && py <= center.y + h * 0.5;
      const metrics = this.worldNodeLabelMetrics(node, scale);
      const plaqueCenterY = center.y + h * 0.5 - Math.min(8, metrics.plaqueHeight * 0.2);
      const labelHit = px >= center.x - metrics.plaqueWidth * 0.5 && px <= center.x + metrics.plaqueWidth * 0.5 &&
        py >= plaqueCenterY - metrics.plaqueHeight * 0.5 && py <= plaqueCenterY + metrics.plaqueHeight * 0.5;
      if (cardHit || labelHit) return node;
    }
    return null;
  }


  worldFirepotAt(clientX, clientY) {
    const rect = this.els.worldCanvas.getBoundingClientRect();
    const x = clientX - rect.left;
    const y = clientY - rect.top;
    for (let index = this.worldMapFirepotHitRegions.length - 1; index >= 0; index--) {
      const region = this.worldMapFirepotHitRegions[index];
      if (x >= region.left && x <= region.right && y >= region.top && y <= region.bottom) return region;
    }
    return null;
  }

  worldMapTargetAt(clientX, clientY) {
    const firepot = this.worldFirepotAt(clientX, clientY);
    if (firepot) return { type: 'firepot', worldId: firepot.worldId, name: firepot.name, region: firepot };
    const node = this.worldNodeAt(clientX, clientY);
    if (node) return { type: 'node', worldId: node.worldId, name: node.name, node };
    return null;
  }

  worldMapRecordForWorldId(worldId) {
    return (this.zones || []).find(zone => canonicalWorldZoneId(zone.id) === worldId) || null;
  }

  async loadWorldMapZone(worldId) {
    const record = this.worldMapRecordForWorldId(worldId);
    if (!record) {
      this.setStatus(`${zoneDisplayName(worldId)} is represented by the local map files, but no matching zone archive was found in the selected game folder.`);
      return;
    }
    this.els.zoneSelect.value = `${record.format}:${record.id}`;
    this.els.load.disabled = false;
    await this.loadSelectedZone();
  }

  drawWorldMap() {
    if (!this.worldMapVisible || !this.worldMapData?.nodes?.length || !this.worldMapContext) return;
    const canvas = this.els.worldCanvas;
    const width = Math.max(1, this.els.viewport.clientWidth);
    const height = Math.max(1, this.els.viewport.clientHeight);
    const ratio = Math.min(2, Math.max(1, devicePixelRatio || 1));
    const targetWidth = Math.round(width * ratio);
    const targetHeight = Math.round(height * ratio);
    if (canvas.width !== targetWidth || canvas.height !== targetHeight) {
      canvas.width = targetWidth;
      canvas.height = targetHeight;
    }
    const ctx = this.worldMapContext;
    this.worldMapFirepotHitRegions = [];
    ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
    ctx.clearRect(0, 0, width, height);
    const gradient = ctx.createRadialGradient(width * 0.48, height * 0.4, 35, width * 0.5, height * 0.5, Math.max(width, height));
    gradient.addColorStop(0, '#202522');
    gradient.addColorStop(0.5, '#111715');
    gradient.addColorStop(1, '#070b0b');
    ctx.fillStyle = gradient;
    ctx.fillRect(0, 0, width, height);
    ctx.save();
    ctx.globalCompositeOperation = 'screen';
    const smokeA = ctx.createRadialGradient(width * 0.2, height * 0.72, 0, width * 0.2, height * 0.72, Math.max(width, height) * 0.42);
    smokeA.addColorStop(0, 'rgba(114, 126, 118, .08)');
    smokeA.addColorStop(1, 'rgba(20, 24, 22, 0)');
    ctx.fillStyle = smokeA;
    ctx.fillRect(0, 0, width, height);
    const smokeB = ctx.createRadialGradient(width * 0.8, height * 0.2, 0, width * 0.8, height * 0.2, Math.max(width, height) * 0.36);
    smokeB.addColorStop(0, 'rgba(126, 112, 76, .055)');
    smokeB.addColorStop(1, 'rgba(20, 24, 22, 0)');
    ctx.fillStyle = smokeB;
    ctx.fillRect(0, 0, width, height);
    ctx.restore();

    const scale = this.worldMapView.scale;
    const nodeById = new Map(this.worldMapData.nodes.map(node => [node.worldId, node]));

    // Draw continent frames first so the canonical structure is immediately
    // legible even at the fit-to-screen zoom level.
    ctx.save();
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    for (const region of worldRegionBounds(this.worldMapData.nodes)) {
      const topLeft = this.worldToScreen(region.minX, region.minY);
      const bottomRight = this.worldToScreen(region.maxX, region.maxY);
      const frameWidth = bottomRight.x - topLeft.x;
      const frameHeight = bottomRight.y - topLeft.y;
      const radius = clamp(18 * scale, 6, 22);
      ctx.beginPath();
      ctx.roundRect(topLeft.x, topLeft.y, frameWidth, frameHeight, radius);
      ctx.fillStyle = 'rgba(8, 12, 12, .46)';
      ctx.fill();
      ctx.strokeStyle = 'rgba(171, 137, 69, .64)';
      ctx.lineWidth = clamp(1.4 * scale, 0.8, 1.6);
      ctx.stroke();

      ctx.font = `700 ${clamp(27 * Math.sqrt(scale), 14, 29)}px system-ui, sans-serif`;
      ctx.fillStyle = 'rgba(132, 176, 164, .9)';
      ctx.fillText(region.label.toUpperCase(), (topLeft.x + bottomRight.x) * 0.5, topLeft.y + clamp(38 * scale, 20, 40));
    }
    ctx.restore();

    const firepotEdges = this.worldMapData.edges.filter(isWorldFirepotConnection);
    if (firepotEdges.length) {
      const topLeft = this.worldToScreen(WORLD_FIREPOT_INSET.minX, WORLD_FIREPOT_INSET.minY);
      const bottomRight = this.worldToScreen(WORLD_FIREPOT_INSET.maxX, WORLD_FIREPOT_INSET.maxY);
      const insetWidth = bottomRight.x - topLeft.x;
      const insetHeight = bottomRight.y - topLeft.y;
      const timorous = nodeById.get('timorous');

      // A single portal line links Timorous Deep to the compact firepot key.
      if (timorous) {
        const fallbackAnchor = {
          x: WORLD_FIREPOT_INSET.maxX,
          y: (WORLD_FIREPOT_INSET.minY + WORLD_FIREPOT_INSET.maxY) * 0.5
        };
        const fallbackStart = worldNodeBoundaryPoint(timorous, fallbackAnchor.x, fallbackAnchor.y);
        const connectorWorld = Array.isArray(this.worldMapData.firepotInsetRoute) && this.worldMapData.firepotInsetRoute.length >= 2
          ? this.worldMapData.firepotInsetRoute
          : [fallbackStart, fallbackAnchor];
        const connector = connectorWorld.map(point => this.worldToScreen(point.x, point.y));
        const start = connector[0];
        const end = connector[connector.length - 1];
        const tail = connector[connector.length - 2] || start;
        ctx.save();
        ctx.beginPath();
        ctx.moveTo(start.x, start.y);
        for (let index = 1; index < connector.length; index++) ctx.lineTo(connector[index].x, connector[index].y);
        ctx.strokeStyle = 'rgba(183, 104, 218, .82)';
        ctx.lineWidth = 1.8;
        ctx.setLineDash([3, 5]);
        ctx.stroke();
        ctx.setLineDash([]);
        const angle = Math.atan2(end.y - tail.y, end.x - tail.x);
        const arrowLength = clamp(8 * Math.sqrt(scale), 5, 10);
        const arrowHalfWidth = arrowLength * 0.55;
        const baseX = end.x - Math.cos(angle) * arrowLength;
        const baseY = end.y - Math.sin(angle) * arrowLength;
        ctx.beginPath();
        ctx.moveTo(end.x, end.y);
        ctx.lineTo(
          baseX + Math.cos(angle + Math.PI / 2) * arrowHalfWidth,
          baseY + Math.sin(angle + Math.PI / 2) * arrowHalfWidth
        );
        ctx.lineTo(
          baseX + Math.cos(angle - Math.PI / 2) * arrowHalfWidth,
          baseY + Math.sin(angle - Math.PI / 2) * arrowHalfWidth
        );
        ctx.closePath();
        ctx.fillStyle = 'rgba(183, 104, 218, .9)';
        ctx.fill();
        ctx.restore();
      }

      ctx.save();
      ctx.beginPath();
      ctx.roundRect(topLeft.x, topLeft.y, insetWidth, insetHeight, clamp(15 * scale, 6, 16));
      ctx.fillStyle = 'rgba(8, 12, 12, .94)';
      ctx.fill();
      ctx.strokeStyle = 'rgba(185, 148, 73, .78)';
      ctx.lineWidth = clamp(1.5 * scale, 0.9, 1.8);
      ctx.stroke();

      const titleSize = clamp(18 * Math.sqrt(scale), 9, 18);
      const labelSize = clamp(12 * Math.sqrt(scale), 7, 12);
      ctx.textAlign = 'center';
      ctx.textBaseline = 'middle';
      ctx.font = `700 ${titleSize}px Georgia, serif`;
      ctx.fillStyle = 'rgba(222, 190, 116, .96)';
      ctx.fillText('TIMOROUS DEEP FIREPOTS', (topLeft.x + bottomRight.x) * 0.5, topLeft.y + titleSize * 1.25);
      ctx.font = `600 ${clamp(labelSize - 1, 6, 11)}px system-ui, sans-serif`;
      ctx.fillStyle = 'rgba(171, 181, 169, .84)';
      ctx.fillText('one-way portal destinations · click a zone name', (topLeft.x + bottomRight.x) * 0.5, topLeft.y + titleSize * 2.35);

      const roomWidth = insetWidth * 0.24;
      const roomHeight = insetHeight * 0.23;
      const roomX = (topLeft.x + bottomRight.x) * 0.5;
      const roomY = topLeft.y + insetHeight * 0.58;
      ctx.beginPath();
      ctx.roundRect(roomX - roomWidth * 0.5, roomY - roomHeight * 0.5, roomWidth, roomHeight, clamp(8 * scale, 3, 8));
      ctx.fillStyle = 'rgba(24, 20, 29, .96)';
      ctx.fill();
      ctx.strokeStyle = 'rgba(183, 111, 214, .82)';
      ctx.stroke();
      ctx.font = `700 ${labelSize}px Georgia, serif`;
      ctx.fillStyle = '#eadcf0';
      ctx.fillText('Firepot Room', roomX, roomY);

      const destinations = firepotEdges
        .map(edge => ({ id: edge.to, name: nodeById.get(edge.to)?.name || zoneDisplayName(edge.to) }))
        .sort((a, b) => a.name.localeCompare(b.name));
      const leftDestinations = destinations.slice(0, Math.ceil(destinations.length / 2));
      const rightDestinations = destinations.slice(Math.ceil(destinations.length / 2));
      const drawDestinationColumn = (items, side) => {
        const centerX = side < 0 ? topLeft.x + insetWidth * 0.2 : topLeft.x + insetWidth * 0.8;
        const roomEdgeX = roomX + side * roomWidth * 0.5;
        const startY = topLeft.y + insetHeight * 0.37;
        const endY = topLeft.y + insetHeight * 0.88;
        const step = items.length > 1 ? (endY - startY) / (items.length - 1) : 0;
        const pillHeight = clamp(21 * Math.sqrt(scale), 14, 23);
        ctx.font = `700 ${labelSize}px system-ui, sans-serif`;
        for (let index = 0; index < items.length; index++) {
          const item = items[index];
          const y = startY + step * index;
          const textWidth = ctx.measureText(item.name).width;
          const pillWidth = Math.min(insetWidth * 0.35, Math.max(54, textWidth + 16));
          const left = centerX - pillWidth * 0.5;
          const top = y - pillHeight * 0.5;
          const hovered = this.worldMapHoverFirepot?.worldId === item.id;
          const connectorEndX = centerX - side * pillWidth * 0.5;
          ctx.beginPath();
          ctx.moveTo(roomEdgeX, roomY);
          ctx.lineTo(connectorEndX, y);
          ctx.strokeStyle = hovered ? 'rgba(206, 139, 230, .8)' : 'rgba(177, 103, 210, .36)';
          ctx.lineWidth = hovered ? 1.5 : 1;
          ctx.stroke();

          ctx.beginPath();
          ctx.roundRect(left, top, pillWidth, pillHeight, clamp(6 * scale, 3, 7));
          ctx.fillStyle = hovered ? 'rgba(54, 42, 31, .98)' : 'rgba(16, 20, 19, .94)';
          ctx.fill();
          ctx.strokeStyle = hovered ? 'rgba(233, 199, 119, .98)' : 'rgba(169, 137, 71, .7)';
          ctx.lineWidth = hovered ? 1.7 : 1;
          ctx.stroke();
          ctx.textAlign = 'center';
          ctx.fillStyle = hovered ? '#ffe5a6' : 'rgba(226, 224, 208, .94)';
          ctx.fillText(item.name, centerX, y + 0.5);
          if (hovered) {
            ctx.beginPath();
            ctx.moveTo(centerX - Math.min(textWidth, pillWidth - 14) * 0.5, y + labelSize * 0.62);
            ctx.lineTo(centerX + Math.min(textWidth, pillWidth - 14) * 0.5, y + labelSize * 0.62);
            ctx.strokeStyle = 'rgba(233, 199, 119, .86)';
            ctx.lineWidth = 1;
            ctx.stroke();
          }
          this.worldMapFirepotHitRegions.push({
            worldId: item.id,
            name: item.name,
            left,
            top,
            right: left + pillWidth,
            bottom: top + pillHeight
          });
        }
      };
      drawDestinationColumn(leftDestinations, -1);
      drawDestinationColumn(rightDestinations, 1);
      ctx.restore();
    }

    ctx.save();
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    for (const edge of this.worldMapData.edges) {
      if (isWorldFirepotConnection(edge)) continue;
      const a = nodeById.get(edge.oneWay ? edge.from : edge.a);
      const b = nodeById.get(edge.oneWay ? edge.to : edge.b);
      if (!a || !b) continue;
      const fallbackStart = worldNodeBoundaryPoint(a, b.x, b.y);
      const fallbackEnd = worldNodeBoundaryPoint(b, a.x, a.y);
      const routePoints = Array.isArray(edge.route) && edge.route.length >= 2
        ? edge.route.map(point => this.worldToScreen(point.x, point.y))
        : [
            this.worldToScreen(fallbackStart.x, fallbackStart.y),
            this.worldToScreen(fallbackEnd.x, fallbackEnd.y)
          ];
      let arrowTail = null;
      let arrowTip = null;
      ctx.beginPath();
      ctx.moveTo(routePoints[0].x, routePoints[0].y);
      for (let index = 1; index < routePoints.length; index++) {
        ctx.lineTo(routePoints[index].x, routePoints[index].y);
      }
      arrowTip = routePoints[routePoints.length - 1];
      for (let index = routePoints.length - 2; index >= 0; index--) {
        if (Math.hypot(arrowTip.x - routePoints[index].x, arrowTip.y - routePoints[index].y) > 1) {
          arrowTail = routePoints[index];
          break;
        }
      }

      let routeColor;
      if (edge.type === 'water') {
        routeColor = 'rgba(84, 154, 211, .82)';
        ctx.setLineDash([8, 5]);
      } else if (edge.type === 'portal' || edge.type === 'teleport') {
        routeColor = 'rgba(183, 104, 218, .82)';
        ctx.setLineDash([3, 5]);
      } else {
        routeColor = 'rgba(190, 184, 157, .58)';
        ctx.setLineDash([]);
      }
      const routeWidth = edge.type === 'land' ? 1.5 : 2.0;
      // A dark casing keeps the route legible at the few unavoidable junctions
      // and makes two nearby lines read as distinct paths rather than one bar.
      ctx.strokeStyle = 'rgba(2, 5, 6, .92)';
      ctx.lineWidth = routeWidth + 3.2;
      ctx.stroke();
      ctx.strokeStyle = routeColor;
      ctx.lineWidth = routeWidth;
      ctx.stroke();

      if (edge.oneWay && arrowTail && arrowTip) {
        const angle = Math.atan2(arrowTip.y - arrowTail.y, arrowTip.x - arrowTail.x);
        const arrowLength = clamp(8 * Math.sqrt(scale), 5, 10);
        const arrowHalfWidth = arrowLength * 0.55;
        const baseX = arrowTip.x - Math.cos(angle) * arrowLength;
        const baseY = arrowTip.y - Math.sin(angle) * arrowLength;
        ctx.setLineDash([]);
        ctx.beginPath();
        ctx.moveTo(arrowTip.x, arrowTip.y);
        ctx.lineTo(
          baseX + Math.cos(angle + Math.PI / 2) * arrowHalfWidth,
          baseY + Math.sin(angle + Math.PI / 2) * arrowHalfWidth
        );
        ctx.lineTo(
          baseX + Math.cos(angle - Math.PI / 2) * arrowHalfWidth,
          baseY + Math.sin(angle - Math.PI / 2) * arrowHalfWidth
        );
        ctx.closePath();
        ctx.fillStyle = routeColor;
        ctx.fill();
      }
    }
    ctx.restore();

    // At the rare topological crossing that cannot be removed without
    // misplacing a continent, paint a proper cartographic line bridge. The
    // dark break and raised arc make it unambiguous that the routes cross
    // without connecting or sharing a lane.
    ctx.save();
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    ctx.setLineDash([]);
    for (const edge of this.worldMapData.edges) {
      if (!Array.isArray(edge.routeBridges) || !edge.routeBridges.length) continue;
      const bridgeColor = edge.type === 'water'
        ? 'rgba(84, 154, 211, .96)'
        : (edge.type === 'portal' || edge.type === 'teleport')
          ? 'rgba(183, 104, 218, .96)'
          : 'rgba(213, 205, 172, .9)';
      const routeWidth = edge.type === 'land' ? 1.5 : 2.0;
      const radius = clamp(10 * Math.sqrt(scale), 6, 13);
      for (const bridge of edge.routeBridges) {
        const point = this.worldToScreen(bridge.x, bridge.y);
        const drawBridge = () => {
          ctx.beginPath();
          if (bridge.horizontal) {
            ctx.moveTo(point.x - radius, point.y);
            ctx.bezierCurveTo(
              point.x - radius * 0.45, point.y - radius * 0.8,
              point.x + radius * 0.45, point.y - radius * 0.8,
              point.x + radius, point.y
            );
          } else {
            ctx.moveTo(point.x, point.y - radius);
            ctx.bezierCurveTo(
              point.x + radius * 0.8, point.y - radius * 0.45,
              point.x + radius * 0.8, point.y + radius * 0.45,
              point.x, point.y + radius
            );
          }
        };
        drawBridge();
        ctx.strokeStyle = 'rgba(2, 5, 6, .98)';
        ctx.lineWidth = routeWidth + 5.5;
        ctx.stroke();
        drawBridge();
        ctx.strokeStyle = bridgeColor;
        ctx.lineWidth = routeWidth + 0.35;
        ctx.stroke();
      }
    }
    ctx.restore();

    for (const node of this.worldMapData.nodes) {
      const center = this.worldToScreen(node.x, node.y);
      const w = node.width * scale;
      const h = node.height * scale;
      const metrics = this.worldNodeLabelMetrics(node, scale);
      const extendedHalfWidth = Math.max(w * 0.6, metrics.plaqueWidth * 0.55);
      if (center.x + extendedHalfWidth < 0 || center.x - extendedHalfWidth > width || center.y + h * 0.7 + metrics.plaqueHeight < 0 || center.y - h * 0.7 > height) continue;
      const hovered = this.worldMapHoverNode?.worldId === node.worldId;
      const left = center.x - w * 0.5;
      const top = center.y - h * 0.5;
      const radius = clamp(8 * scale, 3, 9);

      ctx.save();
      ctx.beginPath();
      ctx.roundRect(left, top, w, h, radius);
      ctx.fillStyle = hovered ? 'rgba(48, 43, 31, .97)' : 'rgba(13, 18, 17, .92)';
      ctx.shadowColor = hovered ? 'rgba(225, 184, 92, .72)' : 'rgba(0, 0, 0, .58)';
      ctx.shadowBlur = hovered ? 14 : 7;
      ctx.fill();
      ctx.shadowBlur = 0;
      ctx.strokeStyle = hovered ? '#e1b85c' : 'rgba(158, 127, 65, .72)';
      ctx.lineWidth = hovered ? 2 : 1;
      ctx.stroke();

      // Clip only the map preview. The name plaque is intentionally drawn
      // after restoring the canvas so long zone names can escape the card.
      ctx.save();
      ctx.beginPath();
      ctx.roundRect(left + 1, top + 1, Math.max(1, w - 2), Math.max(1, h - 2), Math.max(1, radius - 1));
      ctx.clip();
      if (node.previewCanvas) {
        ctx.globalAlpha = hovered ? 1 : 0.86;
        ctx.drawImage(node.previewCanvas, left + 4, top + 4, Math.max(1, w - 8), Math.max(1, h - 8));
        ctx.globalAlpha = 1;
      }
      ctx.restore();
      ctx.restore();

      const plaqueCenterY = top + h - Math.min(8, metrics.plaqueHeight * 0.2);
      const plaqueLeft = center.x - metrics.plaqueWidth * 0.5;
      const plaqueTop = plaqueCenterY - metrics.plaqueHeight * 0.5;
      ctx.save();
      ctx.beginPath();
      ctx.roundRect(plaqueLeft, plaqueTop, metrics.plaqueWidth, metrics.plaqueHeight, clamp(8 * scale, 4, 8));
      ctx.fillStyle = hovered ? 'rgba(51, 41, 27, .98)' : 'rgba(7, 11, 10, .96)';
      ctx.shadowColor = hovered ? 'rgba(226, 184, 92, .5)' : 'rgba(0, 0, 0, .72)';
      ctx.shadowBlur = hovered ? 12 : 7;
      ctx.fill();
      ctx.shadowBlur = 0;
      ctx.strokeStyle = hovered ? 'rgba(226, 184, 92, .94)' : 'rgba(158, 127, 65, .78)';
      ctx.lineWidth = hovered ? 1.8 : 1;
      ctx.stroke();
      ctx.font = `700 ${metrics.fontSize}px Georgia, serif`;
      ctx.textAlign = 'center';
      ctx.textBaseline = 'middle';
      ctx.fillStyle = hovered ? '#ffe6a6' : '#eee5cf';
      const firstY = plaqueCenterY - ((metrics.shownLines.length - 1) * metrics.lineHeight) * 0.5;
      metrics.shownLines.forEach((line, index) => ctx.fillText(line, center.x, firstY + index * metrics.lineHeight));
      ctx.restore();
    }

    // Small legend in screen space.
    ctx.save();
    ctx.font = '600 11px system-ui, sans-serif';
    ctx.textAlign = 'left';
    ctx.textBaseline = 'middle';
    const legend = [
      ['#beb89d', [], 'Land / zone line', false],
      ['#549ad3', [8, 5], 'Boat / water route', false],
      ['#b768da', [3, 5], 'Portal / teleport', false],
      ['#e2b85c', [], 'Validated one-way route', true]
    ];
    let legendY = 18;
    for (const [color, dash, label, arrow] of legend) {
      ctx.beginPath();
      ctx.setLineDash(dash);
      ctx.moveTo(16, legendY);
      ctx.lineTo(48, legendY);
      ctx.strokeStyle = color;
      ctx.lineWidth = 2;
      ctx.stroke();
      ctx.setLineDash([]);
      if (arrow) {
        ctx.beginPath();
        ctx.moveTo(48, legendY);
        ctx.lineTo(41, legendY - 4);
        ctx.lineTo(41, legendY + 4);
        ctx.closePath();
        ctx.fillStyle = color;
        ctx.fill();
      }
      ctx.fillStyle = 'rgba(229, 222, 202, .9)';
      ctx.fillText(label, 57, legendY);
      legendY += 18;
    }
    ctx.restore();
  }

  onWorldMapPointerDown(event) {
    if (!this.worldMapVisible || event.button !== 0) return;
    this.els.worldCanvas.setPointerCapture?.(event.pointerId);
    this.worldMapPointer = {
      id: event.pointerId,
      startX: event.clientX,
      startY: event.clientY,
      lastX: event.clientX,
      lastY: event.clientY,
      dragged: false,
      target: this.worldMapTargetAt(event.clientX, event.clientY)
    };
  }

  onWorldMapPointerMove(event) {
    if (!this.worldMapVisible) return;
    if (this.worldMapPointer?.id === event.pointerId) {
      const dx = event.clientX - this.worldMapPointer.lastX;
      const dy = event.clientY - this.worldMapPointer.lastY;
      if (Math.hypot(event.clientX - this.worldMapPointer.startX, event.clientY - this.worldMapPointer.startY) > 4) this.worldMapPointer.dragged = true;
      if (this.worldMapPointer.dragged) {
        this.worldMapView.offsetX += dx;
        this.worldMapView.offsetY += dy;
      }
      this.worldMapPointer.lastX = event.clientX;
      this.worldMapPointer.lastY = event.clientY;
    }

    const target = this.worldMapTargetAt(event.clientX, event.clientY);
    const node = target?.type === 'node' ? target.node : null;
    const firepot = target?.type === 'firepot' ? target.region : null;
    const nodeChanged = node !== this.worldMapHoverNode;
    const firepotChanged = (firepot?.worldId || null) !== (this.worldMapHoverFirepot?.worldId || null);
    if (nodeChanged || firepotChanged) {
      this.worldMapHoverNode = node;
      this.worldMapHoverFirepot = firepot;
      this.drawWorldMap();
    } else if (this.worldMapPointer?.dragged) {
      this.drawWorldMap();
    }

    if (target?.type === 'node') {
      const connectionEdges = this.worldMapData.edges.filter(edge => edge.a === node.worldId || edge.b === node.worldId);
      const nodeNames = new Map(this.worldMapData.nodes.map(item => [item.worldId, item.name]));
      const connectionLabels = connectionEdges
        .map(edge => {
          const otherId = edge.a === node.worldId ? edge.b : edge.a;
          const otherName = nodeNames.get(otherId) || zoneDisplayName(otherId);
          if (!edge.oneWay) return `↔ ${otherName}`;
          return edge.from === node.worldId ? `→ ${otherName}` : `← ${otherName}`;
        })
        .sort((a, b) => a.localeCompare(b))
        .slice(0, 6);
      const omitted = Math.max(0, connectionEdges.length - connectionLabels.length);
      const routeSummary = connectionLabels.length
        ? `${connectionLabels.join(' · ')}${omitted ? ` · +${omitted} more` : ''}`
        : 'No validated routes';
      this.els.worldTooltip.innerHTML =
        `<strong>${escapeHtml(node.name)}</strong>` +
        `<span>${connectionEdges.length} validated connection${connectionEdges.length === 1 ? '' : 's'} · click the zone name or card to load ${escapeHtml(node.format)}</span>` +
        `<span>${escapeHtml(routeSummary)}</span>`;
    } else if (target?.type === 'firepot') {
      this.els.worldTooltip.innerHTML =
        `<strong>${escapeHtml(target.name)}</strong>` +
        `<span>Timorous Deep firepot destination · validated one-way portal</span>` +
        `<span>Click this zone name to load it.</span>`;
    }

    if (target) {
      const rect = this.els.viewport.getBoundingClientRect();
      this.els.worldTooltip.style.left = `${clamp(event.clientX - rect.left + 14, 8, rect.width - 250)}px`;
      this.els.worldTooltip.style.top = `${clamp(event.clientY - rect.top + 14, 8, rect.height - 118)}px`;
      this.els.worldTooltip.hidden = false;
      this.els.worldCanvas.style.cursor = this.worldMapPointer?.dragged ? 'grabbing' : 'pointer';
    } else {
      this.els.worldTooltip.hidden = true;
      this.els.worldCanvas.style.cursor = this.worldMapPointer ? 'grabbing' : 'grab';
    }
  }

  async onWorldMapPointerUp(event) {
    const pointer = this.worldMapPointer;
    if (!pointer || pointer.id !== event.pointerId) return;
    this.worldMapPointer = null;
    this.els.worldCanvas.releasePointerCapture?.(event.pointerId);
    const target = this.worldMapTargetAt(event.clientX, event.clientY) || pointer.target;
    this.els.worldCanvas.style.cursor = target ? 'pointer' : 'grab';
    if (pointer.dragged || !target) return;
    await this.loadWorldMapZone(target.worldId);
  }

  cancelWorldMapPointer() {
    this.worldMapPointer = null;
    if (this.els?.worldCanvas) this.els.worldCanvas.style.cursor = (this.worldMapHoverNode || this.worldMapHoverFirepot) ? 'pointer' : 'grab';
  }

  onWorldMapPointerLeave(event) {
    if (!this.worldMapPointer) {
      this.worldMapHoverNode = null;
      this.worldMapHoverFirepot = null;
      this.els.worldTooltip.hidden = true;
      this.els.worldCanvas.style.cursor = 'grab';
      this.drawWorldMap();
    }
  }

  onWorldMapWheel(event) {
    if (!this.worldMapVisible) return;
    event.preventDefault();
    const rect = this.els.worldCanvas.getBoundingClientRect();
    const sx = event.clientX - rect.left;
    const sy = event.clientY - rect.top;
    const before = this.screenToWorld(sx, sy);
    const factor = Math.exp(-event.deltaY * 0.0012);
    this.worldMapView.scale = clamp(this.worldMapView.scale * factor, WORLD_MAP_MIN_SCALE, WORLD_MAP_MAX_SCALE);
    this.worldMapView.offsetX = sx - before.x * this.worldMapView.scale;
    this.worldMapView.offsetY = sy - before.y * this.worldMapView.scale;
    this.drawWorldMap();
  }

  async loadSelectedZone() {
    const zoneRecord = this.selectedZoneRecord();
    if (!zoneRecord || !this.source) return;
    this.setWorldMapVisible(false, { silent: true });
    const loadKey = `${zoneRecord.format}:${zoneRecord.id}`;
    const displayName = zoneDisplayName(zoneRecord.id);
    this.els.load.disabled = true;
    try {
      if (this.currentZoneKey && this.currentZoneKey !== loadKey) {
        this.cacheEpoch++;
        await this.cache.clearPrefix('zone:');
      }
      this.resetLoadedZone();
      this.mapData = await this.loadLocalMapData(zoneRecord.id);
      const files = await this.source.filesForZone(zoneRecord);
      if (!files.length) throw new Error(`No files could be resolved for ${zoneRecord.id}.`);
      const signature = this.source.signature(zoneRecord, files);
      this.setStatus(`Checking local cache for ${displayName}…`, 0.02);
      const cached = await this.cache.get(`zone:${signature}`);
      if (cached?.zoneBuffer && cached.cacheVersion === CACHE_FORMAT_VERSION) {
        this.setStatus(`Loading cached ${displayName} geometry…`, 0.78);
        await this.displayParsedZone(cached);
        this.currentZoneKey = loadKey;
        this.currentZoneRecord = zoneRecord;
        this.els.zoneBadge.textContent = `${displayName} · ${zoneRecord.id}`;
        this.setStatus(`${displayName} (${zoneRecord.format}) loaded from local cache.`, 0);
        return;
      }
      this.setStatus(`Sending ${files.length} ${files.length === 1 ? 'file' : 'files'} to the local parser…`, 0.05);
      const parsed = await this.parse(zoneRecord, files);
      parsed.cacheVersion = CACHE_FORMAT_VERSION;
      const parsedBytes = this.estimateParsedBytes(parsed);
      await this.displayParsedZone(parsed);
      this.currentZoneKey = loadKey;
      this.currentZoneRecord = zoneRecord;
      const placementSummary = parsed.displayStats ? ` ${parsed.displayStats.placedProps} props placed; ${parsed.displayStats.missingPropModels} model references unresolved.` : '';
      const cacheSummary = parsedBytes > MAX_CACHE_BYTES ? ' Large-zone cache skipped to avoid duplicating its memory footprint.' : '';
      this.els.zoneBadge.textContent = `${displayName} · ${zoneRecord.id}`;
      this.setStatus(`${displayName} (${zoneRecord.format}) loaded.${placementSummary}${cacheSummary}`, 0);

      // Cache only moderate payloads, after the scene is already usable. Large
      // IndexedDB writes duplicate ArrayBuffers and can make a successful load
      // appear frozen for several seconds.
      if (parsedBytes <= MAX_CACHE_BYTES) {
        const epoch = this.cacheEpoch;
        setTimeout(() => {
          if (epoch === this.cacheEpoch && this.currentZoneKey === loadKey) {
            this.cache.set(`zone:${signature}`, parsed).catch(error => console.warn('[EQLZoneViewer] Cache write failed', error));
          }
        }, 0);
      }
    } catch (error) {
      this.showError(error);
    } finally {
      this.els.load.disabled = !this.els.zoneSelect.value;
    }
  }

  resetLoadedZone() {
    this.fp?.deactivate();
    this.terminateNavigationWorker();
    this.clearNavigationGuide();
    this.clearLocationPillars();
    this.clearSceneGroup(this.zoneGroup);
    this.clearSceneGroup(this.propsGroup);
    this.clearSceneGroup(this.mapGroup);
    this.mapMaterials = [];
    this.mapData = null;
    this.mapFileVisible = false;
    this.miniMapVisible = false;
    this.modeBeforeMap = 'top';
    this.preMapCameraState = null;
    this.savedTopCameraState = null;
    this.savedFullMapCameraState = null;
    this.zoneGroup.visible = true;
    this.propsGroup.visible = this.els.props.checked;
    this.mapGroup.visible = false;
    this.renderer?.setClearColor(0x08101b, 1);
    if (this.scene?.background?.set) this.scene.background.set(0x08101b);
    this.mapBounds.makeEmpty();
    this.mapContentBounds.makeEmpty();
    this.terrainBounds.makeEmpty();
    this.topFitBounds.makeEmpty();
    this.els.miniMap.hidden = true;
    this.clearMapLabels();
    this.clearMiniMap();
    this.clearNamedMobLabels();
    this.distanceCullTargets = [];
    this.visibleBatchCount = 0;
    this.culledBatchCount = 0;
    if (this.scene) this.scene.fog = null;
    if (this.perspective) { this.perspective.far = 200000; this.perspective.updateProjectionMatrix(); }
    if (this.els.zoneBadge) this.els.zoneBadge.textContent = 'Loading zone…';
    for (const texture of this.texturePromises.values()) {
      Promise.resolve(texture).then(t => { t?.image?.close?.(); t?.dispose?.(); }).catch(() => {});
    }
    this.loadedMaterials = [];
    this.materialRegistry = new Map();
    this.materialKeys = new WeakMap();
    this.nextMaterialKey = 1;
    this.rawTextures = new Map();
    this.texturePromises = new Map();
    this.pickTargets = [];
    this.sceneStats = { triangles: 0, drawCalls: 0, sourceMeshes: 0, meshes: 0, instances: 0, placedProps: 0, zoneDrawCalls: 0, propDrawCalls: 0, zoneMeshes: 0, propMeshes: 0 };
    this.parserStats = {};
    this.displayStats = {};
    this.frameTimes = [];
    this.renderDurations = [];
    this.actualFps = 0;
    this.actualDrawCalls = 0;
    this.actualTriangles = 0;
    this.actualFrameMs = 0;
    this.actualRenderMs = 0;
    this.benchmarkUntil = 0;
    this.benchmarkFrames = 0;
    if (this.els.performance) this.els.performance.hidden = true;
    if (this.els.performancePanel) this.togglePerformancePanel(false);
    if (this.els.benchmark) {
      this.els.benchmark.disabled = true;
      this.els.benchmark.textContent = 'Run 5-second benchmark';
    }
    this.floorLevels = [];
    this.floorLevelSource = 'geometry';
    this.selectedFloorIndices.clear();
    this.floorUniformMaterials.clear();
    this.gotoNpcEntries = [];
    this.lastPick = null;
    this.els.coord.textContent = 'X —  Y —  Z —';
    this.els.floorSummary.textContent = 'All floors';
    this.els.floorPanel.innerHTML = '<span class="eqlzv-floor-empty">No overlapping floors detected</span>';
    this.els.floorPicker.dataset.disabled = '1';
    this.els.floorPicker.removeAttribute('open');
    this.els.gotoNpc.value = '';
    this.els.gotoList.replaceChildren();
    this.els.gotoNpc.disabled = true;
    this.els.gotoButton.disabled = true;
    this.els.clip.disabled = true;
    this.els.clipValue.textContent = 'Off';
    this.setViewerControlsEnabled(false);
    this.els.overlay.hidden = false;
    this.els.overlay.innerHTML = '<div class="eqlzv-spinner"></div><div>Preparing zone…</div>';
    this.requestRender();
  }

  setViewerControlsEnabled(enabled) {
    for (const el of [this.els.top, this.els.first, this.els.reset, this.els.fly]) el.disabled = !enabled;
    this.updateControlHint();
  }

  async loadLocalMapData(zone) {
    const family = this.source?.mapFamilyForZone(zone);
    if (!family) return null;
    const result = { lines: [], points: [], sourceLabel: family.directory || 'maps', layers: [] };
    for (const [layer, file] of [...family.layers.entries()].sort((a, b) => a[0] - b[0])) {
      try {
        const parsed = parseEqMapText(await file.text(), layer, file.name);
        result.lines.push(...parsed.lines);
        result.points.push(...parsed.points);
        result.layers.push({ layer, name: file.name, lines: parsed.lines.length, points: parsed.points.length });
      } catch (error) {
        console.warn(`[EQLZoneViewer] Could not read map layer ${file.name}`, error);
      }
    }
    if (!result.lines.length && !result.points.length) return null;
    return result;
  }

  parse(zoneRecord, files) {
    if (this.worker) this.worker.terminate();
    this.worker = new Worker(this.config.workerUrl);
    const id = ++this.requestId;
    return new Promise((resolve, reject) => {
      this.worker.onmessage = (event) => {
        const msg = event.data || {};
        if (msg.id && msg.id !== id) return;
        if (msg.type === 'progress') this.setStatus(msg.message || 'Parsing…', msg.value ?? null);
        else if (msg.type === 'complete') { this.worker.terminate(); this.worker = null; resolve(msg); }
        else if (msg.type === 'error') { this.worker.terminate(); this.worker = null; reject(new Error(msg.message || 'Zone parser failed.')); }
      };
      this.worker.onerror = (event) => { this.worker?.terminate(); this.worker = null; reject(new Error(event.message || 'The local parser worker crashed.')); };
      this.worker.postMessage({
        id,
        action: 'parse',
        zone: zoneRecord.id,
        format: zoneRecord.format,
        files,
        options: { maxObjectModels: 1000 }
      });
    });
  }

  parseGltf(buffer) {
    return new Promise((resolve, reject) => this.gltfLoader.parse(buffer, '', resolve, reject));
  }

  async displayParsedZone(parsed) {
    this.loadedMaterials = [];
    this.materialRegistry = new Map();
    this.materialKeys = new WeakMap();
    this.nextMaterialKey = 1;
    this.rawTextures = this.indexRawTextures(parsed.textures || {});
    this.texturePromises = new Map();
    this.parserStats = parsed.stats || {};
    this.els.overlay.innerHTML = '<div class="eqlzv-spinner"></div><div>Building and batching scene…</div>';
    this.els.overlay.hidden = false;

    const gltf = await this.parseGltf(parsed.zoneBuffer);
    gltf.scene.updateMatrixWorld(true);
    this.prepareObject(gltf.scene, false);
    const zoneRecords = this.collectZoneBatchRecords(gltf.scene);
    this.buildStaticBatches(zoneRecords.batchable, this.zoneGroup, false);
    for (const fallback of zoneRecords.fallback) this.zoneGroup.add(fallback);

    const objectEntries = Object.entries(parsed.objects || {});
    const objectScenes = new Map();
    let count = 0;
    for (const [filename, buffer] of objectEntries) {
      try {
        const objGltf = await this.parseGltf(buffer);
        let bindPoseMixer = null;
        if (objGltf.animations?.length) {
          // Legacy S3D props often store the model's actual bind transforms in
          // a one-frame/"pos" animation instead of on the glTF nodes. Baking a
          // skinned tree before applying that frame leaves the canopy bones at
          // the origin, which visibly detaches tree tops from their trunks.
          const bindClip = objGltf.animations.find(clip => /^pos$/i.test(clip.name || '')) || objGltf.animations[0];
          bindPoseMixer = new THREE.AnimationMixer(objGltf.scene);
          bindPoseMixer.clipAction(bindClip).play();
          bindPoseMixer.setTime(0);
          bindPoseMixer.update(0);
        }
        objGltf.scene.updateMatrixWorld(true);
        this.prepareObject(objGltf.scene, true);
        const key = fileStem(filename).toLowerCase();
        const record = {
          scene: objGltf.scene,
          mirrored: this.sceneContainsReflection(objGltf.scene),
          instancable: this.isInstancableTemplate(objGltf.scene),
          bindPoseMixer
        };
        const aliases = new Set(objectAliases(key));
        objGltf.scene.traverse(object => {
          if (object.name) objectAliases(object.name).forEach(alias => aliases.add(alias));
        });
        for (const alias of aliases) if (!objectScenes.has(alias)) objectScenes.set(alias, record);
      } catch (error) {
        console.warn(`[EQLZoneViewer] Could not parse object ${filename}`, error);
      }
      if (++count % 20 === 0) this.setStatus(`Building objects ${count}/${objectEntries.length}…`, 0.84 + 0.10 * count / Math.max(1, objectEntries.length));
    }

    const metadataObjects = parsed.metadata?.objects || {};
    this.zoneGroup.updateMatrixWorld(true);
    const preliminaryTerrainBounds = new THREE.Box3().setFromObject(this.zoneGroup);
    const propBatchRecords = [];
    const propPlacementCenters = [];
    let placedProps = 0;
    let missingPropModels = 0;
    for (const [name, placements] of Object.entries(metadataObjects)) {
      const template = this.resolveObjectTemplate(name, objectScenes);
      if (!template) {
        missingPropModels += Array.isArray(placements) ? placements.length : 0;
        continue;
      }
      const validPlacements = Array.isArray(placements) ? placements : [];
      for (const placement of validPlacements) {
        propPlacementCenters.push(new THREE.Vector3().setFromMatrixPosition(this.createPlacementMatrix(placement, true)));
      }
      if (template.instancable) {
        this.collectPropBatchRecords(name, template, validPlacements, propBatchRecords);
        placedProps += validPlacements.length;
      } else {
        for (const placement of validPlacements) {
          const wrapper = new THREE.Group();
          wrapper.name = `placement-${name}`;
          wrapper.matrixAutoUpdate = false;
          wrapper.matrix.copy(this.createPlacementMatrix(placement, template.mirrored));
          const clone = template.scene.clone(true);
          clone.traverse(obj => {
            if (!obj.isMesh) return;
            obj.userData.eqlProp = true;
            obj.userData.eqlCollision = false;
          });
          wrapper.add(clone);
          this.propsGroup.add(wrapper);
          placedProps++;
        }
      }
    }
    this.buildStaticBatches(propBatchRecords, this.propsGroup, true);
    this.maybeCorrectMirroredPropPlacement(propPlacementCenters, preliminaryTerrainBounds);

    this.zoneGroup.updateMatrixWorld(true);
    this.propsGroup.updateMatrixWorld(true);
    this.terrainBounds = new THREE.Box3().setFromObject(this.zoneGroup);
    if (this.terrainBounds.isEmpty()) this.terrainBounds.set(new THREE.Vector3(-100, -10, -100), new THREE.Vector3(100, 100, 100));
    this.currentBounds = this.terrainBounds.clone();
    if (this.propsGroup.children.length) this.currentBounds.union(new THREE.Box3().setFromObject(this.propsGroup));
    this.buildLocalMapScene();
    this.topFitBounds = this.computeTopFitBounds();
    this.buildNamedMobLabels();
    this.detectFloorLevels();
    this.configureClipSlider();
    this.pickTargets = [];
    this.zoneGroup.traverse(obj => { if (obj.isMesh) this.pickTargets.push(obj); });
    this.propsGroup.traverse(obj => { if (obj.isMesh) this.pickTargets.push(obj); });
    this.sceneStats = this.measureScene(placedProps);
    this.refreshDistanceCullTargets();
    this.configureFirstPersonViewDistance();
    this.applyRenderQuality(false);
    this.updatePerformanceHud();
    this.els.benchmark.disabled = false;
    this.fp.setScene(this.scene);
    this.fp.setFly(false);
    this.buildLocationPillars();
    this.initializeFirstPersonPose();
    this.els.fly.textContent = 'Grounded';
    this.els.fly.classList.remove('is-active');
    this.updateFirstPersonPrompt();
    this.mode = 'top';
    this.configureModeControls('top');
    this.resetView();
    this.setViewerControlsEnabled(true);
    this.els.overlay.hidden = true;
    parsed.displayStats = { placedProps, missingPropModels, ...this.sceneStats };
    this.displayStats = parsed.displayStats;
    this.requestRender();
  }

  isInstancableTemplate(scene) {
    let meshCount = 0;
    let supported = true;
    scene.traverse(obj => {
      if (!obj.isMesh) return;
      meshCount++;
      const materials = Array.isArray(obj.material) ? obj.material : [obj.material];
      if (!obj.geometry?.attributes?.position || materials.length !== 1) supported = false;
    });
    return supported && meshCount > 0;
  }

  collectZoneBatchRecords(scene) {
    const batchable = [];
    const fallback = [];
    scene.updateMatrixWorld(true);
    scene.traverse(mesh => {
      if (!mesh.isMesh || !mesh.geometry) return;
      const materials = Array.isArray(mesh.material) ? mesh.material : [mesh.material];
      if (materials.length !== 1) {
        const clone = new THREE.Mesh(mesh.geometry, mesh.material);
        clone.name = mesh.name;
        clone.matrixAutoUpdate = false;
        clone.matrix.copy(mesh.matrixWorld);
        clone.userData = { ...mesh.userData, eqlProp: false, eqlCollision: true };
        clone.frustumCulled = true;
        fallback.push(clone);
        return;
      }
      const geometry = this.cloneAndBakeMeshGeometry(mesh, mesh.matrixWorld);
      if (!geometry) return;
      const center = geometry.boundingBox.getCenter(new THREE.Vector3());
      batchable.push({
        geometry,
        material: materials[0],
        matrix: new THREE.Matrix4(),
        center,
        prop: false,
        sourceMeshes: 1
      });
    });
    return { batchable, fallback };
  }

  collectPropBatchRecords(name, template, placements, output) {
    template.scene.updateMatrixWorld(true);
    const rootInverse = new THREE.Matrix4().copy(template.scene.matrixWorld).invert();
    const reflectX = new THREE.Matrix4().makeScale(-1, 1, 1);
    const preparedMeshes = [];

    template.scene.traverse(mesh => {
      if (!mesh.isMesh || !mesh.geometry) return;
      const materials = Array.isArray(mesh.material) ? mesh.material : [mesh.material];
      if (materials.length !== 1) return;
      const local = new THREE.Matrix4().multiplyMatrices(rootInverse, mesh.matrixWorld);
      const bakedLocal = template.mirrored
        ? local
        : new THREE.Matrix4().multiplyMatrices(reflectX, local);
      const geometry = this.cloneAndBakeMeshGeometry(mesh, bakedLocal);
      if (!geometry) return;
      preparedMeshes.push({ geometry, material: materials[0] });
    });

    for (const placement of placements) {
      // R * source * R has a positive determinant and therefore works with
      // BatchedMesh. For non-mirrored S3D templates, the missing reflection is
      // baked into each prepared geometry above.
      const matrix = this.createPlacementMatrix(placement, true);
      const center = new THREE.Vector3().setFromMatrixPosition(matrix);
      for (const prepared of preparedMeshes) {
        output.push({
          geometry: prepared.geometry,
          material: prepared.material,
          matrix: matrix.clone(),
          center: center.clone(),
          prop: true,
          sourceMeshes: 1,
          sourceName: name
        });
      }
    }
  }

  cloneAndBakeMeshGeometry(mesh, matrix) {
    const source = mesh?.geometry;
    if (!source?.attributes?.position) return null;
    const geometry = source.clone();
    const position = geometry.getAttribute('position');
    const requiresPoseBake = Boolean(mesh.isSkinnedMesh || mesh.morphTargetInfluences?.length);

    if (requiresPoseBake && typeof mesh.getVertexPosition === 'function') {
      mesh.updateWorldMatrix(true, false);
      mesh.skeleton?.update?.();
      const vertex = new THREE.Vector3();
      for (let index = 0; index < position.count; index++) {
        mesh.getVertexPosition(index, vertex);
        position.setXYZ(index, vertex.x, vertex.y, vertex.z);
      }
      position.needsUpdate = true;
      geometry.deleteAttribute('skinIndex');
      geometry.deleteAttribute('skinWeight');
      geometry.morphAttributes = {};
      geometry.morphTargetsRelative = false;
      // Static zone props do not need their character-style skeleton or wind
      // morphs at runtime. Baking the bind pose makes thousands of trees,
      // grasses, and plants eligible for real InstancedMesh rendering.
      geometry.computeVertexNormals();
    }

    geometry.applyMatrix4(matrix);
    if (matrix.determinant() < 0) this.flipGeometryWinding(geometry);
    geometry.clearGroups();
    geometry.computeBoundingBox();
    geometry.computeBoundingSphere();
    return geometry;
  }

  cloneAndBakeGeometry(source, matrix) {
    if (!source?.attributes?.position) return null;
    const geometry = source.clone();
    geometry.applyMatrix4(matrix);
    if (matrix.determinant() < 0) this.flipGeometryWinding(geometry);
    geometry.clearGroups();
    geometry.computeBoundingBox();
    geometry.computeBoundingSphere();
    return geometry;
  }

  flipGeometryWinding(geometry) {
    const index = geometry.getIndex();
    if (index) {
      const array = index.array.slice();
      for (let i = 0; i + 2 < array.length; i += 3) {
        const value = array[i + 1];
        array[i + 1] = array[i + 2];
        array[i + 2] = value;
      }
      geometry.setIndex(new THREE.BufferAttribute(array, 1, index.normalized));
    } else {
      for (const attribute of Object.values(geometry.attributes)) {
        if (!attribute || attribute.isInterleavedBufferAttribute) continue;
        const itemSize = attribute.itemSize;
        for (let vertex = 0; vertex + 2 < attribute.count; vertex += 3) {
          for (let component = 0; component < itemSize; component++) {
            const a = attribute.getComponent(vertex + 1, component);
            const b = attribute.getComponent(vertex + 2, component);
            attribute.setComponent(vertex + 1, component, b);
            attribute.setComponent(vertex + 2, component, a);
          }
        }
        attribute.needsUpdate = true;
      }
    }
    const tangent = geometry.getAttribute('tangent');
    if (tangent?.itemSize === 4) {
      for (let i = 0; i < tangent.count; i++) tangent.setW(i, -tangent.getW(i));
      tangent.needsUpdate = true;
    }
  }

  geometryBatchSignature(geometry) {
    const attributes = Object.keys(geometry.attributes).sort().map(name => {
      const attribute = geometry.getAttribute(name);
      return `${name}:${attribute.itemSize}:${attribute.normalized ? 1 : 0}:${attribute.array?.constructor?.name || 'array'}`;
    }).join(',');
    return `${geometry.getIndex() ? 'indexed' : 'plain'}|${attributes}`;
  }

  materialBatchId(material) {
    if (!this.materialKeys.has(material)) this.materialKeys.set(material, this.nextMaterialKey++);
    return this.materialKeys.get(material);
  }

  buildStaticBatches(records, targetGroup, isProp) {
    if (!records.length) return;
    const buckets = new Map();
    const cellSize = isProp
      ? (records.length > 12000 ? 5120 : records.length > 5000 ? 3072 : 1600)
      : 2048;
    for (const record of records) {
      const cellX = Math.floor(record.center.x / cellSize);
      const cellZ = Math.floor(record.center.z / cellSize);
      const key = `${this.materialBatchId(record.material)}|${this.geometryBatchSignature(record.geometry)}|${cellX}:${cellZ}`;
      if (!buckets.has(key)) buckets.set(key, []);
      buckets.get(key).push(record);
    }

    let batchNumber = 0;
    for (const bucketRecords of buckets.values()) {
      const mergeRecords = [];

      // Repeated prop geometry is best represented by InstancedMesh. It is a
      // single ordinary WebGL draw call in Firefox as well as Chromium and it
      // avoids duplicating every tree or shrub's vertex data.
      if (isProp) {
        const byGeometry = new Map();
        for (const record of bucketRecords) {
          if (!byGeometry.has(record.geometry)) byGeometry.set(record.geometry, []);
          byGeometry.get(record.geometry).push(record);
        }
        for (const group of byGeometry.values()) {
          if (group.length > 1) {
            for (const chunk of this.splitInstanceRecords(group)) {
              targetGroup.add(this.createInstancedBatch(chunk, batchNumber++));
            }
          } else {
            mergeRecords.push(group[0]);
          }
        }
      } else {
        mergeRecords.push(...bucketRecords);
      }

      // Physically merge unique static geometry. THREE.BatchedMesh falls back
      // to one draw per sub-geometry when WEBGL_multi_draw is unavailable,
      // which is common in Firefox. A merged BufferGeometry guarantees one
      // real draw call per material/spatial chunk on every supported browser.
      for (const chunk of this.splitMergeRecords(mergeRecords)) {
        try {
          targetGroup.add(this.createMergedBatch(chunk, isProp, batchNumber++));
        } catch (error) {
          console.warn('[EQLZoneViewer] Static merge failed; retaining individual meshes.', error);
          for (const record of chunk) targetGroup.add(this.createFallbackMesh(record, isProp));
        }
      }
    }
  }

  splitInstanceRecords(records) {
    const chunks = [];
    const maxInstances = 4096;
    for (let start = 0; start < records.length; start += maxInstances) {
      chunks.push(records.slice(start, start + maxInstances));
    }
    return chunks;
  }

  splitMergeRecords(records) {
    const chunks = [];
    let chunk = [];
    let vertices = 0;
    let indices = 0;
    const maxMeshes = 2048;
    const maxVertices = 1_250_000;
    const maxIndices = 3_750_000;

    const flush = () => {
      if (chunk.length) chunks.push(chunk);
      chunk = [];
      vertices = 0;
      indices = 0;
    };

    for (const record of records) {
      const addVertices = record.geometry.attributes.position.count;
      const addIndices = record.geometry.index?.count || 0;
      if (chunk.length && (chunk.length + 1 > maxMeshes || vertices + addVertices > maxVertices || indices + addIndices > maxIndices)) flush();
      chunk.push(record);
      vertices += addVertices;
      indices += addIndices;
    }
    flush();
    return chunks;
  }

  createInstancedBatch(records, batchNumber) {
    const first = records[0];
    const mesh = new THREE.InstancedMesh(first.geometry, first.material, records.length);
    mesh.name = `prop-instances-${batchNumber}`;
    mesh.userData.eqlProp = true;
    mesh.userData.eqlCollision = false;
    mesh.userData.eqlSourceMeshes = records.reduce((sum, record) => sum + (record.sourceMeshes || 1), 0);
    mesh.userData.eqlInstances = records.length;
    const triangleCount = Math.floor((first.geometry.index?.count || first.geometry.attributes.position.count) / 3);
    mesh.userData.eqlTriangles = triangleCount * records.length;
    mesh.frustumCulled = true;
    mesh.castShadow = false;
    mesh.receiveShadow = false;
    for (let index = 0; index < records.length; index++) mesh.setMatrixAt(index, records[index].matrix);
    mesh.instanceMatrix.setUsage(THREE.StaticDrawUsage);
    mesh.instanceMatrix.needsUpdate = true;
    mesh.computeBoundingBox();
    mesh.computeBoundingSphere();
    return mesh;
  }

  createMergedBatch(records, isProp, batchNumber) {
    if (!records.length) throw new Error('Cannot merge an empty record set.');
    const transformed = [];
    for (const record of records) {
      const geometry = record.geometry.clone();
      geometry.applyMatrix4(record.matrix);
      geometry.clearGroups();
      transformed.push(geometry);
    }
    const merged = mergeGeometries(transformed, false);
    for (const geometry of transformed) geometry.dispose();
    if (!merged) throw new Error('BufferGeometryUtils.mergeGeometries rejected incompatible attributes.');
    merged.computeBoundingBox();
    merged.computeBoundingSphere();

    const material = records[0].material;
    const mesh = new THREE.Mesh(merged, material);
    mesh.name = `${isProp ? 'prop' : 'zone'}-merged-${batchNumber}`;
    mesh.userData.eqlProp = isProp;
    mesh.userData.eqlCollision = !isProp;
    mesh.userData.eqlSourceMeshes = records.reduce((sum, record) => sum + (record.sourceMeshes || 1), 0);
    mesh.userData.eqlInstances = records.length;
    mesh.userData.eqlTriangles = records.reduce((sum, record) => {
      const count = record.geometry.index?.count || record.geometry.attributes.position.count;
      return sum + Math.floor(count / 3);
    }, 0);
    mesh.frustumCulled = true;
    mesh.castShadow = false;
    mesh.receiveShadow = false;
    return mesh;
  }

  createFallbackMesh(record, isProp) {
    const mesh = new THREE.Mesh(record.geometry, record.material);
    mesh.matrixAutoUpdate = false;
    mesh.matrix.copy(record.matrix);
    mesh.userData.eqlProp = isProp;
    mesh.userData.eqlCollision = !isProp;
    mesh.userData.eqlSourceMeshes = record.sourceMeshes || 1;
    mesh.userData.eqlInstances = 1;
    mesh.userData.eqlTriangles = Math.floor((record.geometry.index?.count || record.geometry.attributes.position.count) / 3);
    return mesh;
  }

  estimateParsedBytes(parsed) {
    let bytes = parsed?.zoneBuffer?.byteLength || 0;
    for (const buffer of Object.values(parsed?.objects || {})) bytes += buffer?.byteLength || 0;
    for (const buffer of Object.values(parsed?.textures || {})) bytes += buffer?.byteLength || 0;
    return bytes;
  }

  measureScene(placedProps = 0) {
    let triangles = 0;
    let drawCalls = 0;
    let sourceMeshes = 0;
    let meshes = 0;
    let instances = 0;
    let zoneDrawCalls = 0;
    let propDrawCalls = 0;
    let zoneMeshes = 0;
    let propMeshes = 0;
    const visit = (group, isProp) => group.traverse(obj => {
      if (!obj.isMesh || !obj.geometry) return;
      meshes++;
      if (isProp) propMeshes++;
      else zoneMeshes++;
      if (obj.isBatchedMesh) {
        sourceMeshes += obj.userData.eqlSourceMeshes || obj.userData.eqlInstances || 1;
        instances += obj.userData.eqlInstances || 1;
        triangles += obj.userData.eqlTriangles || 0;
      } else {
        const count = obj.isInstancedMesh ? Math.max(1, obj.count) : 1;
        sourceMeshes += obj.userData.eqlSourceMeshes || count;
        instances += obj.userData.eqlInstances || count;
        const indexCount = obj.geometry.index?.count;
        const vertexCount = obj.geometry.attributes?.position?.count || 0;
        triangles += obj.userData.eqlTriangles || Math.floor((indexCount || vertexCount) / 3) * count;
      }
      const calls = Math.max(1, Array.isArray(obj.material) ? obj.material.length : 1);
      drawCalls += calls;
      if (isProp) propDrawCalls += calls;
      else zoneDrawCalls += calls;
    });
    visit(this.zoneGroup, false);
    if (this.propsGroup.visible) visit(this.propsGroup, true);
    return {
      triangles, drawCalls, sourceMeshes, meshes, instances, placedProps,
      zoneDrawCalls, propDrawCalls, zoneMeshes, propMeshes
    };
  }

  configureFirstPersonViewDistance() {
    const size = this.currentBounds.getSize(new THREE.Vector3());
    const horizontal = Math.max(size.x, size.z, 1000);
    this.firstPersonViewDistanceMin = clamp(horizontal * 0.08, 550, 900);
    this.firstPersonViewDistanceMax = clamp(horizontal * 0.45, 1600, 5000);
    this.firstPersonViewDistance = clamp(horizontal * 0.24, 1100, 2600);
    this.lastViewDistanceAdjustment = 0;
  }

  refreshDistanceCullTargets() {
    this.distanceCullTargets = [];
    const collect = (group, isProp) => group.traverse(object => {
      if (!object.isMesh || !object.geometry) return;
      object.geometry.computeBoundingSphere?.();
      const sphere = object.geometry.boundingSphere?.clone();
      if (!sphere) return;
      sphere.applyMatrix4(object.matrixWorld);
      object.userData.eqlCullSphere = sphere;
      object.userData.eqlCullProp = isProp;
      object.visible = true;
      this.distanceCullTargets.push(object);
    });
    this.zoneGroup.updateMatrixWorld(true);
    this.propsGroup.updateMatrixWorld(true);
    collect(this.zoneGroup, false);
    collect(this.propsGroup, true);
    this.visibleBatchCount = this.distanceCullTargets.length;
    this.culledBatchCount = 0;
  }

  updateFirstPersonEnvironment(force = false, now = performance.now()) {
    if (!this.scene || !this.perspective) return;
    const enabled = this.mode === 'first' && !this.mapFileVisible && this.els.distanceFog.checked;
    if (!enabled) {
      this.scene.fog = null;
      this.perspective.far = 200000;
      this.perspective.updateProjectionMatrix();
      let visible = 0;
      let hidden = 0;
      for (const object of this.distanceCullTargets) {
        object.visible = !object.userData.eqlCullProp || this.els.props.checked;
        if (object.visible) visible++;
        else hidden++;
      }
      this.visibleBatchCount = visible;
      this.culledBatchCount = hidden;
      return;
    }

    if (!force && this.fp?.controls?.isLocked && this.actualFps > 0 && now - this.lastViewDistanceAdjustment > 1500) {
      const previous = this.firstPersonViewDistance;
      if (this.actualFps < 30) this.firstPersonViewDistance *= 0.72;
      else if (this.actualFps < 50) this.firstPersonViewDistance *= 0.86;
      else if (this.actualFps > 58.5) this.firstPersonViewDistance *= 1.06;
      this.firstPersonViewDistance = clamp(
        this.firstPersonViewDistance,
        this.firstPersonViewDistanceMin,
        this.firstPersonViewDistanceMax
      );
      if (Math.abs(this.firstPersonViewDistance - previous) > 1) this.lastViewDistanceAdjustment = now;
    }

    const far = this.firstPersonViewDistance;
    const near = far * 0.52;
    const background = this.scene.background?.isColor ? this.scene.background : new THREE.Color(0x08101b);
    if (!this.scene.fog) this.scene.fog = new THREE.Fog(background.clone(), near, far);
    else {
      this.scene.fog.color.copy(background);
      this.scene.fog.near = near;
      this.scene.fog.far = far;
    }
    this.perspective.far = far * 1.08;
    this.perspective.updateProjectionMatrix();

    let visible = 0;
    let culled = 0;
    for (const object of this.distanceCullTargets) {
      const allowed = !object.userData.eqlCullProp || this.els.props.checked;
      const sphere = object.userData.eqlCullSphere;
      const inRange = !sphere || this.perspective.position.distanceTo(sphere.center) <= far + sphere.radius;
      object.visible = Boolean(allowed && inRange);
      if (object.visible) visible++;
      else culled++;
    }
    this.visibleBatchCount = visible;
    this.culledBatchCount = culled;
  }

  qualityProfile() {
    const deviceRatio = Math.max(1, devicePixelRatio || 1);
    return {
      name: 'High',
      pixelRatio: Math.min(deviceRatio, 1.5),
      adaptive: false,
      fps: TARGET_FPS
    };
  }

  applyRenderQuality(announce = false) {
    if (!this.renderer) return;
    const profile = this.qualityProfile();
    this.activeQualityProfile = profile;
    this.targetFrameInterval = TARGET_FRAME_MS;
    this.dynamicPixelRatio = profile.pixelRatio;
    this.renderer.setPixelRatio(this.dynamicPixelRatio);
    this.resize();
    this.updatePerformanceHud();
    this.updatePerformancePanel();
    if (announce && this.zoneGroup.children.length) {
      this.setStatus(`High-quality rendering at ${this.dynamicPixelRatio.toFixed(2)}× pixel ratio, capped at ${TARGET_FPS} FPS.`, 0);
    }
  }

  updatePerformanceHud() {
    if (!this.els.performance || !this.zoneGroup.children.length) return;
    const format = value => this.formatMetric(value);
    const profile = this.activeQualityProfile || this.qualityProfile();
    const draws = this.actualDrawCalls || this.sceneStats.drawCalls;
    const triangles = this.actualTriangles || this.sceneStats.triangles;
    const fps = this.isContinuousRendering() && this.actualFps > 0 ? `${Math.round(this.actualFps)} FPS · ` : '';
    this.els.performance.textContent = `${fps}${format(triangles)} tris · ${draws.toLocaleString()} draws · ${profile.name} ${this.dynamicPixelRatio.toFixed(2)}×`;
    this.els.performance.hidden = false;
  }

  formatMetric(value) {
    const number = Number(value) || 0;
    if (number >= 1_000_000) return `${(number / 1_000_000).toFixed(1)}M`;
    if (number >= 1000) return `${(number / 1000).toFixed(number >= 100_000 ? 0 : 1)}K`;
    return String(Math.round(number));
  }

  isContinuousRendering() {
    return this.mode === 'first' || this.benchmarkUntil > performance.now();
  }

  performanceDiagnosis() {
    const draws = this.actualDrawCalls || this.sceneStats.drawCalls;
    const triangles = this.actualTriangles || this.sceneStats.triangles;
    const fps = this.actualFps;
    const frameMs = this.actualFrameMs;
    const renderMs = this.actualRenderMs;
    const canvasPixels = (this.els.canvas.width || 0) * (this.els.canvas.height || 0);

    if (draws > 1500) {
      return {
        level: 'bad',
        title: 'Draw-call bound',
        text: `${draws.toLocaleString()} rendered calls is far too high for this scene. Texture tiling does not create those calls; separate meshes/material passes do. Static batching should bring this below roughly 500, and ideally below 250.`
      };
    }
    if (draws > 600) {
      return {
        level: 'warn',
        title: 'Likely draw-call bound',
        text: `${draws.toLocaleString()} calls can bottleneck the browser even with only ${this.formatMetric(triangles)} triangles. Remaining animated or multi-material objects are the likely source.`
      };
    }
    if (triangles > 8_000_000) {
      return {
        level: 'warn',
        title: 'Geometry bound',
        text: `${this.formatMetric(triangles)} rendered triangles is the dominant cost. Lowering render resolution will help less than distance culling or geometry simplification.`
      };
    }
    if (fps > 0 && fps < 55 && frameMs > TARGET_FRAME_MS && renderMs < 8) {
      return {
        level: 'warn',
        title: 'CPU/update bound',
        text: `Rendering itself averages ${renderMs.toFixed(1)} ms, but frames average ${frameMs.toFixed(1)} ms. Browser work, collision checks, or scene updates are consuming the remaining time.`
      };
    }
    if (fps > 0 && fps < 55 && renderMs >= 12) {
      return {
        level: 'warn',
        title: 'Render/driver bound',
        text: `The browser's render call averages ${renderMs.toFixed(1)} ms at ${(canvasPixels / 1_000_000).toFixed(1)} megapixels. This usually points to GPU fill rate, shader/material cost, or driver synchronization. The viewer is intentionally locked to High quality, so reducing draw calls or scene complexity is the effective remedy.`
      };
    }
    if (fps >= 57) {
      return {
        level: 'good',
        title: '60 FPS target met',
        text: `The current rolling average is ${fps.toFixed(1)} FPS with ${draws.toLocaleString()} rendered calls.`
      };
    }
    return {
      level: 'neutral',
      title: 'Benchmark needed',
      text: 'Top Down and local-map views render only when the camera changes, so an idle FPS number would be meaningless. Run the 5-second benchmark for a continuous measurement.'
    };
  }

  updatePerformancePanel() {
    if (!this.els?.performanceContent || !this.zoneGroup.children.length) return;
    const info = this.renderer?.info;
    const memory = info?.memory || {};
    const profile = this.activeQualityProfile || this.qualityProfile();
    const diagnosis = this.performanceDiagnosis();
    const fpsText = this.actualFps > 0 ? `${this.actualFps.toFixed(1)} FPS` : 'Not sampled';
    const frameText = this.actualFrameMs > 0 ? `${this.actualFrameMs.toFixed(2)} ms` : '—';
    const renderText = this.actualRenderMs > 0 ? `${this.actualRenderMs.toFixed(2)} ms` : '—';
    const unmatched = Array.isArray(this.parserStats.unmatchedObjectNames) ? this.parserStats.unmatchedObjectNames.length : 0;
    const resolution = `${this.els.canvas.width || 0} × ${this.els.canvas.height || 0}`;
    this.els.performanceContent.innerHTML = `
      <div class="eqlzv-diagnosis is-${diagnosis.level}">
        <strong>${escapeHtml(diagnosis.title)}</strong>
        <span>${escapeHtml(diagnosis.text)}</span>
      </div>
      <div class="eqlzv-performance-grid">
        <div><span>Target</span><strong>${TARGET_FPS} FPS</strong></div>
        <div><span>Quality</span><strong>High (fixed)</strong></div>
        <div><span>Measured</span><strong>${fpsText}</strong></div>
        <div><span>Frame interval</span><strong>${frameText}</strong></div>
        <div><span>Render call time</span><strong>${renderText}</strong></div>
        <div><span>Rendered draws</span><strong>${(this.actualDrawCalls || this.sceneStats.drawCalls).toLocaleString()}</strong></div>
        <div><span>Rendered triangles</span><strong>${this.formatMetric(this.actualTriangles || this.sceneStats.triangles)}</strong></div>
        <div><span>Render meshes</span><strong>${this.sceneStats.meshes.toLocaleString()}</strong></div>
        <div><span>Terrain batches</span><strong>${Number(this.sceneStats.zoneMeshes || 0).toLocaleString()} · ~${Number(this.sceneStats.zoneDrawCalls || 0).toLocaleString()} draws</strong></div>
        <div><span>Prop batches</span><strong>${Number(this.sceneStats.propMeshes || 0).toLocaleString()} · ~${Number(this.sceneStats.propDrawCalls || 0).toLocaleString()} draws</strong></div>
        <div><span>Source mesh instances</span><strong>${this.sceneStats.sourceMeshes.toLocaleString()}</strong></div>
        <div><span>Placed props</span><strong>${this.sceneStats.placedProps.toLocaleString()}</strong></div>
        <div><span>Unresolved prop placements</span><strong>${Number(this.displayStats.missingPropModels || 0).toLocaleString()}</strong></div>
        <div><span>GPU geometries</span><strong>${Number(memory.geometries || 0).toLocaleString()}</strong></div>
        <div><span>GPU textures</span><strong>${Number(memory.textures || 0).toLocaleString()}</strong></div>
        <div><span>Resolution</span><strong>${resolution} · ${this.dynamicPixelRatio.toFixed(2)}×</strong></div>
        <div><span>Object models loaded</span><strong>${Number(this.parserStats.objectModels || 0).toLocaleString()}</strong></div>
        <div><span>Unmatched model names</span><strong>${unmatched.toLocaleString()}</strong></div>
        <div><span>Visible render batches</span><strong>${this.visibleBatchCount.toLocaleString()}</strong></div>
        <div><span>Distance-culled batches</span><strong>${this.culledBatchCount.toLocaleString()}</strong></div>
        <div><span>First-person view distance</span><strong>${Math.round(this.firstPersonViewDistance).toLocaleString()} units</strong></div>
      </div>
      <p class="eqlzv-performance-note">Repeated UV coordinates and tiled textures are normal for EverQuest and do not multiply draw calls. The live <b>Rendered draws</b> value is taken from the renderer after an actual frame; it is more useful than a static mesh estimate.</p>`;
  }

  recordRenderedFrame(now, renderStartedAt, previousRenderedAt) {
    const renderDuration = Math.max(0, performance.now() - renderStartedAt);
    if (previousRenderedAt > 0) {
      const interval = now - previousRenderedAt;
      if (interval > 0 && interval < 250) this.frameTimes.push(interval);
    }
    this.renderDurations.push(renderDuration);
    if (this.frameTimes.length > 120) this.frameTimes.splice(0, this.frameTimes.length - 120);
    if (this.renderDurations.length > 120) this.renderDurations.splice(0, this.renderDurations.length - 120);

    const average = values => values.length ? values.reduce((sum, value) => sum + value, 0) / values.length : 0;
    this.actualFrameMs = average(this.frameTimes);
    this.actualRenderMs = average(this.renderDurations);
    this.actualFps = this.actualFrameMs > 0 ? 1000 / this.actualFrameMs : 0;
    this.actualDrawCalls = this.renderer.info.render.calls || 0;
    this.actualTriangles = this.renderer.info.render.triangles || 0;
    this.actualLines = this.renderer.info.render.lines || 0;
    this.actualPoints = this.renderer.info.render.points || 0;

    if (this.benchmarkUntil > 0) this.benchmarkFrames++;
    if (now - this.lastDiagnosticUpdate > 500) {
      this.lastDiagnosticUpdate = now;
      this.updatePerformanceHud();
      if (!this.els.performancePanel.hidden) this.updatePerformancePanel();
    }
  }

  finishBenchmark() {
    if (!this.benchmarkUntil) return;
    this.benchmarkUntil = 0;
    this.els.benchmark.disabled = false;
    this.els.benchmark.textContent = 'Run 5-second benchmark';
    const diagnosis = this.performanceDiagnosis();
    this.setStatus(`Benchmark complete: ${this.actualFps.toFixed(1)} FPS, ${this.actualDrawCalls.toLocaleString()} draws. ${diagnosis.title}.`, 0);
    this.updatePerformanceHud();
    this.updatePerformancePanel();
  }

  sceneContainsReflection(scene) {
    let reflected = false;
    scene.updateMatrixWorld(true);
    scene.traverse(obj => {
      if (reflected || !obj.isMesh) return;
      const determinant = obj.matrixWorld.determinant();
      if (determinant < 0) reflected = true;
    });
    return reflected;
  }

  resolveObjectTemplate(name, objectScenes) {
    const candidates = objectAliases(name);
    for (const candidate of candidates) {
      if (objectScenes.has(candidate)) return objectScenes.get(candidate);
    }
    for (const candidate of candidates) {
      const exactPrefix = [...objectScenes.entries()].find(([key]) =>
        key === candidate || key.startsWith(`${candidate}_`) || candidate.startsWith(`${key}_`) ||
        (Math.min(key.length, candidate.length) >= 4 && (key.startsWith(candidate) || candidate.startsWith(key)))
      );
      if (exactPrefix) return exactPrefix[1];
    }
    return null;
  }

  maybeCorrectMirroredPropPlacement(centers, terrainBounds) {
    if (!centers?.length || !terrainBounds || terrainBounds.isEmpty() || !this.propsGroup) return false;
    const expanded = terrainBounds.clone();
    const size = expanded.getSize(new THREE.Vector3());
    expanded.expandByVector(new THREE.Vector3(Math.max(12, size.x * 0.08), Math.max(12, size.y * 0.08), Math.max(12, size.z * 0.08)));
    const score = mirrored => {
      let inside = 0;
      let distance = 0;
      const point = new THREE.Vector3();
      const closest = new THREE.Vector3();
      for (const center of centers) {
        point.set(mirrored ? -center.x : center.x, center.y, center.z);
        if (expanded.containsPoint(point)) inside++;
        else {
          expanded.clampPoint(point, closest);
          distance += point.distanceTo(closest);
        }
      }
      return { inside, distance, ratio: inside / centers.length };
    };
    const current = score(false);
    const alternate = score(true);

    // Bounds alone can be inconclusive for zones whose exported terrain contains
    // a large empty apron. Compare a representative sample of placement centers
    // with the local map-line footprint as a second, independent signal.
    const mapLines = (this.mapData?.lines || []).filter(line => line.layer !== 2);
    const mapScore = mirrored => {
      if (!mapLines.length) return null;
      const gridCell = 160;
      const buckets = new Map();
      const fallback = [];
      const key = (ix, iz) => `${ix}:${iz}`;
      const segments = mapLines.map(line => {
        const a = this.eqMapToThree(line.x1, line.y1, line.z1);
        const b = this.eqMapToThree(line.x2, line.y2, line.z2);
        return { ax: a.x, az: a.z, bx: b.x, bz: b.z };
      });
      for (const segment of segments) {
        const minX = Math.floor(Math.min(segment.ax, segment.bx) / gridCell);
        const maxX = Math.floor(Math.max(segment.ax, segment.bx) / gridCell);
        const minZ = Math.floor(Math.min(segment.az, segment.bz) / gridCell);
        const maxZ = Math.floor(Math.max(segment.az, segment.bz) / gridCell);
        const count = (maxX - minX + 1) * (maxZ - minZ + 1);
        if (count > 64) { fallback.push(segment); continue; }
        for (let ix = minX; ix <= maxX; ix++) {
          for (let iz = minZ; iz <= maxZ; iz++) {
            const bucketKey = key(ix, iz);
            let bucket = buckets.get(bucketKey);
            if (!bucket) { bucket = []; buckets.set(bucketKey, bucket); }
            bucket.push(segment);
          }
        }
      }
      const pointSegmentDistanceSquared = (px, pz, segment) => {
        const dx = segment.bx - segment.ax;
        const dz = segment.bz - segment.az;
        const lengthSquared = dx * dx + dz * dz;
        const t = lengthSquared > 0.0001 ? clamp(((px - segment.ax) * dx + (pz - segment.az) * dz) / lengthSquared, 0, 1) : 0;
        const x = segment.ax + dx * t;
        const z = segment.az + dz * t;
        return (px - x) ** 2 + (pz - z) ** 2;
      };
      const stride = Math.max(1, Math.floor(centers.length / 180));
      let samples = 0;
      let near = 0;
      let total = 0;
      for (let index = 0; index < centers.length; index += stride) {
        const center = centers[index];
        const x = mirrored ? -center.x : center.x;
        const z = center.z;
        const ix = Math.floor(x / gridCell);
        const iz = Math.floor(z / gridCell);
        const candidates = [...fallback];
        const seen = new Set(candidates);
        for (let ox = -2; ox <= 2; ox++) {
          for (let oz = -2; oz <= 2; oz++) {
            for (const segment of buckets.get(key(ix + ox, iz + oz)) || []) {
              if (!seen.has(segment)) { seen.add(segment); candidates.push(segment); }
            }
          }
        }
        let best = 500 * 500;
        for (const segment of candidates) best = Math.min(best, pointSegmentDistanceSquared(x, z, segment));
        const distance = Math.sqrt(best);
        if (distance <= 55) near++;
        total += Math.min(500, distance);
        samples++;
      }
      return { near, average: samples ? total / samples : Infinity, samples };
    };
    const currentMap = mapScore(false);
    const alternateMap = mapScore(true);
    const decisive = current.ratio < 0.55 && alternate.ratio >= current.ratio + 0.28 && alternate.inside >= Math.min(8, centers.length);
    const distanceWin = current.ratio < 0.4 && alternate.distance < current.distance * 0.42 && alternate.inside > current.inside;
    const mapWin = Boolean(currentMap && alternateMap && alternateMap.samples >= 5 &&
      alternateMap.average < currentMap.average * 0.58 &&
      alternateMap.near >= currentMap.near + Math.max(3, Math.round(alternateMap.samples * 0.16)));
    if (!decisive && !distanceWin && !mapWin) return false;
    this.propsGroup.scale.x = -1;
    this.propsGroup.updateMatrixWorld(true);
    console.info(`[EQLZoneViewer] Corrected mirrored prop placement for ${this.currentZoneRecord?.id || 'zone'} (${current.inside}/${centers.length} inside -> ${alternate.inside}/${centers.length}; map distance ${currentMap?.average?.toFixed?.(1) || 'n/a'} -> ${alternateMap?.average?.toFixed?.(1) || 'n/a'}).`);
    return true;
  }

  createPlacementMatrix(placement, templateAlreadyMirrored) {
    const position = new THREE.Vector3(
      safeNumber(placement.x),
      safeNumber(placement.y),
      safeNumber(placement.z)
    );
    const rotation = new THREE.Euler(
      THREE.MathUtils.degToRad(safeNumber(placement.rotateX)),
      THREE.MathUtils.degToRad(safeNumber(placement.rotateY)),
      THREE.MathUtils.degToRad(safeNumber(placement.rotateZ)),
      'XYZ'
    );
    const quaternion = new THREE.Quaternion().setFromEuler(rotation);
    const scaleValue = safeNumber(placement.scale, 1) || 1;
    const sourceTransform = new THREE.Matrix4().compose(
      position,
      quaternion,
      new THREE.Vector3(scaleValue, scaleValue, scaleValue)
    );

    // sage-core mirrors the finished zone across X. EQG object GLBs are also
    // mirrored internally; S3D object GLBs are not. Conjugating the placement
    // by the same reflection keeps translations and rotations in the same
    // coordinate space as the zone and avoids props appearing across the map.
    const reflectX = new THREE.Matrix4().makeScale(-1, 1, 1);
    const result = new THREE.Matrix4().multiplyMatrices(reflectX, sourceTransform);
    if (templateAlreadyMirrored) result.multiply(reflectX);
    return result;
  }

  prepareObject(root, isProp) {
    const materialClones = new Map();
    const cloneOnce = material => {
      if (!materialClones.has(material)) materialClones.set(material, this.cloneMaterial(material));
      return materialClones.get(material);
    };
    root.traverse(obj => {
      if (!obj.isMesh) return;
      obj.userData.eqlProp = isProp;
      obj.userData.eqlCollision = !isProp;
      obj.frustumCulled = true;
      const mats = Array.isArray(obj.material) ? obj.material : [obj.material];
      obj.material = Array.isArray(obj.material) ? mats.map(cloneOnce) : cloneOnce(mats[0]);
    });
  }
  materialRegistryKey(material) {
    const map = material?.map;
    const textureKey = this.textureCandidates(material)[0] || material?.name || 'untextured';
    const color = material?.color?.getHexString?.() || 'ffffff';
    const emissive = material?.emissive?.getHexString?.() || '000000';
    const mapTransform = map ? [
      map.wrapS, map.wrapT, map.offset?.x, map.offset?.y,
      map.repeat?.x, map.repeat?.y, map.center?.x, map.center?.y,
      map.rotation, map.flipY, map.colorSpace
    ] : [];
    return JSON.stringify([
      material?.type || 'Material', textureKey, color, emissive,
      material?.transparent ? 1 : 0, Number(material?.opacity ?? 1).toFixed(4),
      Number(material?.alphaTest ?? 0).toFixed(4), material?.side,
      material?.vertexColors ? 1 : 0, material?.blending,
      material?.depthWrite ? 1 : 0, material?.depthTest ? 1 : 0,
      Number(material?.roughness ?? 1).toFixed(3), Number(material?.metalness ?? 0).toFixed(3),
      material?.userData?.eqShader ?? material?.userData?.gltfExtensions?.eqShader ?? '',
      ...mapTransform
    ]);
  }

  cloneMaterial(mat) {
    const source = mat || new THREE.MeshStandardMaterial();
    const key = this.materialRegistryKey(source);
    const existing = this.materialRegistry.get(key);
    if (existing) return existing;

    const clone = source.clone();
    // Three.js normally renders transparent double-sided materials twice.
    // Old EQ foliage and masked surfaces do not benefit enough from that
    // second pass to justify doubling their draw cost.
    if (clone.transparent && clone.side === THREE.DoubleSide) clone.forceSinglePass = true;
    clone.userData.eqlOriginalMap = clone.map || null;
    clone.userData.eqlOriginalColor = clone.color?.clone?.() || new THREE.Color(0xb8bec6);
    this.materialRegistry.set(key, clone);
    this.loadedMaterials.push(clone);
    this.attachLocalTexture(clone);
    return clone;
  }

  installFloorBandShader(material) {
    if (!material || material.userData.eqlFloorShaderInstalled) return;
    material.userData.eqlFloorShaderInstalled = true;
    material.userData.eqlFloorBandCount = 0;
    material.userData.eqlFloorBands = Array.from({ length: MAX_FLOOR_BANDS }, () => new THREE.Vector2(-1e9, 1e9));
    const originalCompile = material.onBeforeCompile?.bind(material);
    const originalCacheKey = material.customProgramCacheKey?.bind(material);
    material.onBeforeCompile = (shader, renderer) => {
      originalCompile?.(shader, renderer);
      if (!shader.vertexShader.includes('#include <begin_vertex>') || !shader.fragmentShader.includes('#include <clipping_planes_fragment>')) return;
      shader.uniforms.eqlFloorBandCount = { value: material.userData.eqlFloorBandCount };
      shader.uniforms.eqlFloorBands = { value: material.userData.eqlFloorBands };
      shader.vertexShader = shader.vertexShader
        .replace('void main() {', 'varying float vEqlWorldY;\nvoid main() {')
        .replace('#include <begin_vertex>', `#include <begin_vertex>
\tvec4 eqlWorldPosition = vec4( transformed, 1.0 );
#ifdef USE_BATCHING
\teqlWorldPosition = batchingMatrix * eqlWorldPosition;
#endif
#ifdef USE_INSTANCING
\teqlWorldPosition = instanceMatrix * eqlWorldPosition;
#endif
\teqlWorldPosition = modelMatrix * eqlWorldPosition;
\tvEqlWorldY = eqlWorldPosition.y;`);
      shader.fragmentShader = shader.fragmentShader
        .replace('void main() {', `uniform float eqlFloorBandCount;\nuniform vec2 eqlFloorBands[${MAX_FLOOR_BANDS}];\nvarying float vEqlWorldY;\nvoid main() {`)
        .replace('#include <clipping_planes_fragment>', `#include <clipping_planes_fragment>
\tif ( eqlFloorBandCount > 0.5 ) {
\t\tbool eqlFloorVisible = false;
\t\tfor ( int eqlFloorIndex = 0; eqlFloorIndex < ${MAX_FLOOR_BANDS}; eqlFloorIndex ++ ) {
\t\t\tif ( float( eqlFloorIndex ) < eqlFloorBandCount && vEqlWorldY >= eqlFloorBands[ eqlFloorIndex ].x && vEqlWorldY <= eqlFloorBands[ eqlFloorIndex ].y ) eqlFloorVisible = true;
\t\t}
\t\tif ( ! eqlFloorVisible ) discard;
\t}`);
      material.userData.eqlFloorShader = shader;
    };
    material.customProgramCacheKey = () => `${originalCacheKey?.() || material.type}|eql-floor-v2`;
    this.floorUniformMaterials.add(material);
    material.needsUpdate = true;
  }

  selectedFloorBands() {
    if (!this.selectedFloorIndices.size) return [];
    return [...this.selectedFloorIndices]
      .sort((a, b) => a - b)
      .map(index => this.floorLevels[index]?.band)
      .filter(Boolean)
      .slice(0, MAX_FLOOR_BANDS);
  }

  updateFloorBandUniforms() {
    const bands = this.selectedFloorBands();
    // Do not alter the material shader at all until a floor filter is actually
    // selected. This keeps ordinary zone rendering on Three.js' stock shader
    // path and prevents an optional floor feature from blanking the terrain.
    if (bands.length) {
      for (const material of this.loadedMaterials) this.installFloorBandShader(material);
    }
    for (const material of this.floorUniformMaterials) {
      material.userData.eqlFloorBandCount = bands.length;
      for (let index = 0; index < MAX_FLOOR_BANDS; index++) {
        const band = bands[index] || [-1e9, 1e9];
        material.userData.eqlFloorBands[index].set(band[0], band[1]);
      }
      const shader = material.userData.eqlFloorShader;
      if (shader) {
        shader.uniforms.eqlFloorBandCount.value = bands.length;
        shader.uniforms.eqlFloorBands.value = material.userData.eqlFloorBands;
      }
    }
  }

  indexRawTextures(textures) {
    const map = new Map();
    for (const [path, buffer] of Object.entries(textures || {})) {
      const filename = normalizeName(path);
      const stem = fileStem(filename);
      const entry = { path, buffer };
      map.set(filename, entry);
      map.set(stem, entry);
      map.set(stem.replace(/_[0-9]+$/, ''), entry);
    }
    return map;
  }

  textureCandidates(material) {
    const values = [material?.map?.name, material?.name, material?.userData?.name, material?.map?.userData?.name];
    const result = [];
    for (const value of values) {
      if (!value) continue;
      const name = normalizeName(value).replace(/^\/eq\/textures\//, '');
      result.push(name, fileStem(name), fileStem(name).replace(/_mdf.*$/i, ''));
    }
    return [...new Set(result.filter(Boolean))];
  }

  attachLocalTexture(material) {
    const entry = this.textureCandidates(material).map(k => this.rawTextures.get(k)).find(Boolean);
    if (!entry) return;
    const gltfTexture = material.userData.eqlOriginalMap || material.map || null;
    this.decodeLocalTexture(entry).then(texture => {
      if (!texture) return;
      this.copyTextureSampling(gltfTexture, texture);
      material.userData.eqlOriginalMap = texture;
      material.map = this.els.textures.checked ? texture : null;
      material.needsUpdate = true;
      this.requestRender();
    }).catch(error => console.warn('[EQLZoneViewer] Texture decode failed', entry.path, error));
  }

  copyTextureSampling(source, target) {
    if (!source || !target) return target;
    target.mapping = source.mapping;
    target.channel = source.channel;
    target.wrapS = source.wrapS;
    target.wrapT = source.wrapT;
    target.magFilter = source.magFilter;
    if (!target.isCompressedTexture || target.mipmaps?.length > 1) target.minFilter = source.minFilter;
    target.anisotropy = source.anisotropy;
    target.flipY = source.flipY;
    target.premultiplyAlpha = source.premultiplyAlpha;
    target.unpackAlignment = source.unpackAlignment;
    target.colorSpace = source.colorSpace || THREE.SRGBColorSpace;
    target.offset.copy(source.offset);
    target.repeat.copy(source.repeat);
    target.center.copy(source.center);
    target.rotation = source.rotation;
    target.matrixAutoUpdate = source.matrixAutoUpdate;
    if (!source.matrixAutoUpdate) target.matrix.copy(source.matrix);
    target.needsUpdate = true;
    return target;
  }

  decodeLocalTexture(entry) {
    if (this.texturePromises.has(entry.path)) return this.texturePromises.get(entry.path);
    const promise = (async () => {
      const bytes = new Uint8Array(entry.buffer);
      const magic = String.fromCharCode(...bytes.slice(0, 4));
      if (magic === 'DDS ') {
        const dds = new DDSLoader().parse(entry.buffer, true);
        if (!dds?.mipmaps?.length) return null;
        const texture = new THREE.CompressedTexture(dds.mipmaps, dds.width, dds.height, dds.format);
        texture.minFilter = dds.mipmapCount === 1 ? THREE.LinearFilter : THREE.LinearMipmapLinearFilter;
        texture.magFilter = THREE.LinearFilter;
        texture.flipY = false;
        texture.colorSpace = THREE.SRGBColorSpace;
        texture.needsUpdate = true;
        texture.name = fileStem(entry.path);
        return texture;
      }
      let type = 'application/octet-stream';
      if (bytes[0] === 0x42 && bytes[1] === 0x4d) type = 'image/bmp';
      else if (bytes[0] === 0x89 && bytes[1] === 0x50) type = 'image/png';
      else if (bytes[0] === 0xff && bytes[1] === 0xd8) type = 'image/jpeg';
      const bitmap = await createImageBitmap(new Blob([entry.buffer], { type }));
      const texture = new THREE.Texture(bitmap);
      texture.flipY = false;
      texture.colorSpace = THREE.SRGBColorSpace;
      texture.needsUpdate = true;
      texture.name = fileStem(entry.path);
      return texture;
    })();
    this.texturePromises.set(entry.path, promise);
    return promise;
  }

  updateMaterials() {
    const wire = this.els.wire.checked;
    const textures = this.els.textures.checked;
    for (const mat of this.loadedMaterials) {
      mat.wireframe = wire;
      mat.map = textures ? mat.userData.eqlOriginalMap : null;
      if (mat.color) mat.color.copy(mat.userData.eqlOriginalColor || new THREE.Color(0xb8bec6));
      mat.needsUpdate = true;
    }
    this.requestRender();
  }

  clearSceneGroup(group) {
    const disposedTextures = new Set();
    const disposedMaterials = new Set();
    const disposedGeometries = new Set();
    for (const child of [...group.children]) {
      group.remove(child);
      child.traverse(obj => {
        if (obj.geometry && !disposedGeometries.has(obj.geometry)) {
          disposedGeometries.add(obj.geometry);
          obj.geometry.disposeBoundsTree?.();
          obj.geometry.dispose();
        }
        const materials = Array.isArray(obj.material) ? obj.material : [obj.material];
        for (const mat of materials) {
          if (!mat || disposedMaterials.has(mat)) continue;
          disposedMaterials.add(mat);
          for (const key of ['map', 'normalMap', 'roughnessMap', 'metalnessMap', 'emissiveMap', 'alphaMap']) {
            const texture = mat[key];
            if (texture && !disposedTextures.has(texture)) {
              disposedTextures.add(texture);
              texture.image?.close?.();
              texture.dispose?.();
            }
          }
          mat.dispose?.();
        }
      });
    }
    this.renderer?.renderLists?.dispose?.();
    this.renderer?.info?.reset?.();
  }

  chooseMapTransform() {
    // sage-core's legacy S3D export is [EQ Y, EQ Z, -EQ X]. EverQuest map
    // files retain native EQ X/Y/Z. This fixed transform was validated against
    // the Trakanon's Teeth zone mesh and map geometry, and keeps maps, labels,
    // coordinate readouts, and first-person movement in one non-mirrored space.
    return { ...EQ_TO_THREE };
  }

  eqMapToThree(x, y, z) {
    return eqToThree(x, y, z);
  }

  threeToEq(point) {
    return threeToEqCoordinates(point);
  }

  buildLocalMapScene() {
    this.clearSceneGroup(this.mapGroup);
    this.mapMaterials = [];
    this.mapBounds.makeEmpty();
    this.mapGroup.visible = false;
    if (!this.mapData?.lines?.length) {
      return;
    }

    this.mapTransform = this.chooseMapTransform();
    const positions = new Float32Array(this.mapData.lines.length * 6);
    const colors = new Float32Array(this.mapData.lines.length * 6);
    const color = new THREE.Color();
    let offset = 0;
    for (const line of this.mapData.lines) {
      const a = this.eqMapToThree(line.x1, line.y1, line.z1);
      const b = this.eqMapToThree(line.x2, line.y2, line.z2);
      positions.set([a.x, a.y, a.z, b.x, b.y, b.z], offset);
      color.setRGB(line.r / 255, line.g / 255, line.b / 255, THREE.SRGBColorSpace);
      colors.set([color.r, color.g, color.b, color.r, color.g, color.b], offset);
      offset += 6;
      this.mapBounds.expandByPoint(a);
      this.mapBounds.expandByPoint(b);
      if (line.layer !== 2) {
        this.mapContentBounds.expandByPoint(a);
        this.mapContentBounds.expandByPoint(b);
      }
    }
    const geometry = new THREE.BufferGeometry();
    geometry.setAttribute('position', new THREE.BufferAttribute(positions, 3));
    geometry.setAttribute('color', new THREE.BufferAttribute(colors, 3));
    geometry.computeBoundingSphere();
    const material = new THREE.LineBasicMaterial({
      vertexColors: true,
      transparent: true,
      opacity: 1,
      depthTest: false,
      depthWrite: false,
      toneMapped: false
    });
    material.clippingPlanes = [];
    this.mapMaterials.push(material);
    const segments = new THREE.LineSegments(geometry, material);
    segments.name = 'local-eq-map-lines';
    segments.frustumCulled = true;
    segments.renderOrder = 1000;
    segments.userData.eqlMap = true;
    this.mapGroup.add(segments);

    if (this.mapData.points.length) {
      const pointPositions = new Float32Array(this.mapData.points.length * 3);
      const pointColors = new Float32Array(this.mapData.points.length * 3);
      let index = 0;
      for (const point of this.mapData?.points || []) {
        const p = this.eqMapToThree(point.x, point.y, point.z);
        pointPositions.set([p.x, p.y, p.z], index);
        color.setRGB(point.r / 255, point.g / 255, point.b / 255, THREE.SRGBColorSpace);
        pointColors.set([color.r, color.g, color.b], index);
        index += 3;
        this.mapBounds.expandByPoint(p);
        if (point.layer !== 2) this.mapContentBounds.expandByPoint(p);
      }
      const pointGeometry = new THREE.BufferGeometry();
      pointGeometry.setAttribute('position', new THREE.BufferAttribute(pointPositions, 3));
      pointGeometry.setAttribute('color', new THREE.BufferAttribute(pointColors, 3));
      const pointMaterial = new THREE.PointsMaterial({
        size: 4,
        sizeAttenuation: false,
        vertexColors: true,
        depthTest: false,
        depthWrite: false,
        toneMapped: false
      });
      pointMaterial.clippingPlanes = [];
      this.mapMaterials.push(pointMaterial);
      const points = new THREE.Points(pointGeometry, pointMaterial);
      points.renderOrder = 1001;
      points.userData.eqlMap = true;
      this.mapGroup.add(points);
    }
    if (this.mapBounds.isEmpty()) this.mapBounds.copy(this.currentBounds);
    if (this.mapContentBounds.isEmpty()) this.mapContentBounds.copy(this.mapBounds);
  }

  computeTopFitBounds() {
    const terrain = this.terrainBounds?.isEmpty?.() === false
      ? this.terrainBounds.clone()
      : this.currentBounds.clone();
    const map = this.mapContentBounds?.isEmpty?.() === false
      ? this.mapContentBounds.clone()
      : null;
    if (!map) return terrain;

    const terrainSize = terrain.getSize(new THREE.Vector3());
    const mapSize = map.getSize(new THREE.Vector3());
    const terrainCenter = terrain.getCenter(new THREE.Vector3());
    const mapCenter = map.getCenter(new THREE.Vector3());
    const mapHorizontal = Math.max(mapSize.x, mapSize.z, 1);
    const centerOffset = Math.hypot(terrainCenter.x - mapCenter.x, terrainCenter.z - mapCenter.z);
    const terrainLooksInflated = terrainSize.x > mapSize.x * 1.75 || terrainSize.z > mapSize.z * 1.75;
    const centersAgree = centerOffset <= mapHorizontal * 0.45;

    // Terrain geometry is the default fit source. When collision or stray
    // geometry creates a clearly inflated terrain box, a real layer-0/1 map
    // is a better horizontal framing guide. Vertical bounds still come from
    // terrain so the camera remains above the rendered zone.
    if (terrainLooksInflated && centersAgree && mapSize.x > 20 && mapSize.z > 20) {
      map.min.y = terrain.min.y;
      map.max.y = terrain.max.y;
      return map;
    }
    return terrain;
  }

  occlusionDerivedFloorLevels() {
    const size = this.currentBounds.getSize(new THREE.Vector3());
    const horizontalRange = Math.max(size.x, size.z, 1);
    const verticalRange = Math.max(size.y, 1);
    if (verticalRange < 24) return [];

    // Build continuous walkable-surface components in X/Z/Y space. A floor is
    // offered only when two substantial components occupy the same horizontal
    // cells at meaningfully different heights — literally where one floor can
    // hide another. Cartographer height bands and ordinary outdoor slopes do
    // not satisfy that test.
    const cellSize = clamp(horizontalRange / 650, 8, 22);
    const heightBin = 4;
    const adjacentStepTolerance = 13;
    const nodesBySign = [new Map(), new Map()];
    const signAreas = [0, 0];
    const meshes = [];
    let totalTriangles = 0;
    this.zoneGroup.updateMatrixWorld(true);
    this.zoneGroup.traverse(object => {
      if (!object.isMesh || !object.geometry?.attributes?.position) return;
      const count = Math.floor((object.geometry.index?.count || object.geometry.attributes.position.count) / 3);
      if (!count) return;
      meshes.push({ object, count });
      totalTriangles += count;
    });
    if (!totalTriangles) return [];

    const globalStep = Math.max(1, Math.ceil(totalTriangles / 220000));
    const a = new THREE.Vector3();
    const b = new THREE.Vector3();
    const c = new THREE.Vector3();
    const ab = new THREE.Vector3();
    const ac = new THREE.Vector3();
    const normal = new THREE.Vector3();
    for (const { object, count } of meshes) {
      const geometry = object.geometry;
      const position = geometry.attributes.position;
      const index = geometry.index;
      for (let triangle = 0; triangle < count; triangle += globalStep) {
        const ia = index ? index.getX(triangle * 3) : triangle * 3;
        const ib = index ? index.getX(triangle * 3 + 1) : triangle * 3 + 1;
        const ic = index ? index.getX(triangle * 3 + 2) : triangle * 3 + 2;
        a.fromBufferAttribute(position, ia).applyMatrix4(object.matrixWorld);
        b.fromBufferAttribute(position, ib).applyMatrix4(object.matrixWorld);
        c.fromBufferAttribute(position, ic).applyMatrix4(object.matrixWorld);
        ab.subVectors(b, a);
        ac.subVectors(c, a);
        normal.crossVectors(ab, ac);
        const twiceArea = normal.length();
        if (twiceArea < 0.2 || Math.abs(normal.y) / twiceArea < 0.72) continue;
        const x = (a.x + b.x + c.x) / 3;
        const y = (a.y + b.y + c.y) / 3;
        const z = (a.z + b.z + c.z) / 3;
        const sign = normal.y >= 0 ? 1 : 0;
        const cx = Math.floor(x / cellSize);
        const cz = Math.floor(z / cellSize);
        const hk = Math.round(y / heightBin);
        const key = `${cx}:${cz}:${hk}`;
        const area = Math.min(twiceArea * 0.5 * globalStep, cellSize * cellSize * 4);
        signAreas[sign] += area;
        const nodes = nodesBySign[sign];
        let node = nodes.get(key);
        if (!node) {
          node = { key, cx, cz, hk, yWeight: 0, area: 0, y: 0 };
          nodes.set(key, node);
        }
        node.yWeight += y * area;
        node.area += area;
      }
    }

    // Mirrored legacy zones frequently reverse all triangle winding. Retain
    // the dominant horizontal face direction so ceilings do not become floors.
    const nodes = nodesBySign[signAreas[1] > signAreas[0] ? 1 : 0];
    if (nodes.size < 2) return [];
    const cells = new Map();
    for (const node of nodes.values()) {
      node.y = node.area ? node.yWeight / node.area : node.hk * heightBin;
      const cellKey = `${node.cx}:${node.cz}`;
      if (!cells.has(cellKey)) cells.set(cellKey, []);
      cells.get(cellKey).push(node);
    }

    const visited = new Set();
    const components = [];
    for (const startNode of nodes.values()) {
      if (visited.has(startNode.key)) continue;
      const stack = [startNode];
      visited.add(startNode.key);
      const component = { area: 0, yWeight: 0, cells: new Set(), minimum: Infinity, maximum: -Infinity, value: 0 };
      while (stack.length) {
        const node = stack.pop();
        component.area += node.area;
        component.yWeight += node.y * node.area;
        component.cells.add(`${node.cx}:${node.cz}`);
        component.minimum = Math.min(component.minimum, node.y);
        component.maximum = Math.max(component.maximum, node.y);
        for (let dx = -1; dx <= 1; dx++) {
          for (let dz = -1; dz <= 1; dz++) {
            for (const candidate of cells.get(`${node.cx + dx}:${node.cz + dz}`) || []) {
              if (visited.has(candidate.key)) continue;
              const tolerance = dx === 0 && dz === 0 ? 6 : adjacentStepTolerance;
              if (Math.abs(candidate.y - node.y) <= tolerance) {
                visited.add(candidate.key);
                stack.push(candidate);
              }
            }
          }
        }
      }
      component.value = component.area ? component.yWeight / component.area : 0;
      components.push(component);
    }
    if (components.length < 2) return [];

    const maximumArea = Math.max(...components.map(component => component.area));
    const substantial = components.filter(component =>
      component.cells.size >= 8 && component.area >= maximumArea * 0.002
    );
    if (substantial.length < 2) return [];

    const sharedCells = (left, right) => {
      const smaller = left.cells.size <= right.cells.size ? left : right;
      const larger = smaller === left ? right : left;
      let count = 0;
      for (const key of smaller.cells) if (larger.cells.has(key)) count++;
      return { count, ratio: count / Math.max(1, smaller.cells.size) };
    };
    const participating = new Set();
    for (let leftIndex = 0; leftIndex < substantial.length; leftIndex++) {
      for (let rightIndex = leftIndex + 1; rightIndex < substantial.length; rightIndex++) {
        const left = substantial[leftIndex];
        const right = substantial[rightIndex];
        if (Math.abs(right.value - left.value) < 15) continue;
        const overlap = sharedCells(left, right);
        if (overlap.count >= 8 && overlap.ratio >= 0.08) {
          participating.add(left);
          participating.add(right);
        }
      }
    }
    const participatingLevels = substantial.filter(component => participating.has(component));
    if (participatingLevels.length < 2) return [];

    // Components at effectively the same architectural elevation become one
    // checkbox even when disconnected rooms or platforms were exported as
    // separate meshes.
    const grouped = [];
    for (const component of participatingLevels.sort((left, right) => left.value - right.value)) {
      const existing = grouped.find(level => Math.abs(level.value - component.value) < 12);
      if (existing) {
        const total = existing.weight + component.area;
        existing.value = (existing.value * existing.weight + component.value * component.area) / total;
        existing.weight = total;
      } else grouped.push({ value: component.value, weight: component.area });
    }
    let levels = grouped;
    if (levels.length < 2) return [];
    if (levels.length > MAX_FLOOR_BANDS) {
      levels = levels.sort((a, b) => b.weight - a.weight).slice(0, MAX_FLOOR_BANDS).sort((a, b) => a.value - b.value);
    }
    return levels.map((level, index) => ({
      value: level.value,
      weight: level.weight,
      label: `Floor ${index + 1}`,
      band: [
        index ? (levels[index - 1].value + level.value) / 2 : -1e9,
        index < levels.length - 1 ? (level.value + levels[index + 1].value) / 2 : 1e9
      ]
    }));
  }

  captureFirstPersonPose() {
    if (!this.perspective || !this.fp) return;
    const position = this.perspective.position.clone();
    position.y -= this.fp.eyeHeight;
    const forward = new THREE.Vector3(0, 0, -1).applyQuaternion(this.perspective.quaternion);
    forward.y = 0;
    if (forward.lengthSq() < 0.0001) forward.set(0, 0, -1);
    forward.normalize();
    this.lastFirstPersonPose = { position, forward };
  }

  initializeFirstPersonPose() {
    const spawn = this.findFirstPersonSpawn();
    if (!spawn) return;
    const position = spawn.clone();
    position.y -= this.fp.eyeHeight;
    const center = this.currentBounds.getCenter(new THREE.Vector3());
    const forward = center.sub(position);
    forward.y = 0;
    if (forward.lengthSq() < 0.0001) forward.set(0, 0, -1);
    forward.normalize();
    this.lastFirstPersonPose = { position, forward };
  }

  drawMapViewerArrow(ctx, width, height) {
    const pose = this.lastFirstPersonPose;
    if (!pose || !this.mapFileVisible) return;
    const screenPosition = pose.position.clone().project(this.camera);
    const screenAhead = pose.position.clone().addScaledVector(pose.forward, 100).project(this.camera);
    if (screenPosition.z < -1 || screenPosition.z > 1 ||
        screenPosition.x < -1.15 || screenPosition.x > 1.15 ||
        screenPosition.y < -1.15 || screenPosition.y > 1.15) return;

    const x = (screenPosition.x * 0.5 + 0.5) * width;
    const y = (-screenPosition.y * 0.5 + 0.5) * height;
    const aheadX = (screenAhead.x * 0.5 + 0.5) * width;
    const aheadY = (-screenAhead.y * 0.5 + 0.5) * height;
    const angle = Math.atan2(aheadY - y, aheadX - x) + Math.PI / 2;

    ctx.save();
    ctx.translate(x, y);
    ctx.rotate(angle);
    ctx.shadowColor = 'rgba(255, 24, 24, .98)';
    ctx.shadowBlur = 14;
    ctx.lineJoin = 'round';
    ctx.beginPath();
    ctx.moveTo(0, -21);
    ctx.lineTo(12, 13);
    ctx.lineTo(0, 8);
    ctx.lineTo(-12, 13);
    ctx.closePath();
    ctx.fillStyle = 'rgba(255, 26, 26, .96)';
    ctx.fill();
    ctx.shadowBlur = 4;
    ctx.lineWidth = 2;
    ctx.strokeStyle = 'rgba(255, 235, 235, .98)';
    ctx.stroke();
    ctx.restore();
  }

  clearMiniMap() {
    const canvas = this.els?.miniMapCanvas;
    const ctx = this.miniMapContext;
    if (canvas && ctx) ctx.clearRect(0, 0, canvas.width, canvas.height);
  }

  drawMiniMap() {
    const container = this.els?.miniMap;
    const canvas = this.els?.miniMapCanvas;
    const ctx = this.miniMapContext;
    if (!container || !canvas || !ctx || !this.miniMapVisible || !this.mapData?.lines?.length) {
      this.clearMiniMap();
      return;
    }

    const width = Math.max(160, container.clientWidth || 260);
    const height = Math.max(130, (container.clientHeight || 220) - 24);
    const ratio = Math.min(2, Math.max(1, devicePixelRatio || 1));
    const targetWidth = Math.round(width * ratio);
    const targetHeight = Math.round(height * ratio);
    if (canvas.width !== targetWidth || canvas.height !== targetHeight) {
      canvas.width = targetWidth;
      canvas.height = targetHeight;
    }
    canvas.style.height = `${height}px`;
    ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
    ctx.clearRect(0, 0, width, height);
    ctx.fillStyle = 'rgba(7, 14, 22, .94)';
    ctx.fillRect(0, 0, width, height);

    const bounds = this.mapContentBounds?.isEmpty?.() === false ? this.mapContentBounds : this.mapBounds;
    const boundsSize = bounds.getSize(new THREE.Vector3());
    const boundsCenter = bounds.getCenter(new THREE.Vector3());
    const pose = this.lastFirstPersonPose;
    const center = pose?.position?.clone?.() || boundsCenter;
    const fullRadius = Math.max(boundsSize.x, boundsSize.z, 1) * 0.5;
    const localRadius = clamp(fullRadius * 0.22, 350, 1500);
    const radius = Math.min(Math.max(250, localRadius), Math.max(250, fullRadius));
    const padding = 10;
    const scale = Math.min((width - padding * 2) / (radius * 2), (height - padding * 2) / (radius * 2));
    const toScreen = (point) => ({
      x: width * 0.5 + (point.x - center.x) * scale,
      y: height * 0.5 + (point.z - center.z) * scale
    });

    ctx.save();
    ctx.beginPath();
    ctx.rect(padding, padding, width - padding * 2, height - padding * 2);
    ctx.clip();
    ctx.lineCap = 'round';
    for (const line of this.mapData.lines) {
      if (line.layer === 2) continue;
      const a = toScreen(this.eqMapToThree(line.x1, line.y1, line.z1));
      const b = toScreen(this.eqMapToThree(line.x2, line.y2, line.z2));
      if ((a.x < -20 && b.x < -20) || (a.x > width + 20 && b.x > width + 20) ||
          (a.y < -20 && b.y < -20) || (a.y > height + 20 && b.y > height + 20)) continue;
      const luminance = 0.2126 * line.r + 0.7152 * line.g + 0.0722 * line.b;
      ctx.strokeStyle = luminance < 40
        ? 'rgba(218, 230, 240, .78)'
        : `rgba(${line.r}, ${line.g}, ${line.b}, .88)`;
      ctx.lineWidth = line.layer === 0 ? 1.15 : 1;
      ctx.beginPath();
      ctx.moveTo(a.x, a.y);
      ctx.lineTo(b.x, b.y);
      ctx.stroke();
    }
    ctx.restore();

    if (pose) {
      const arrow = toScreen(pose.position);
      const ahead = toScreen(pose.position.clone().addScaledVector(pose.forward, 100));
      const angle = Math.atan2(ahead.y - arrow.y, ahead.x - arrow.x) + Math.PI / 2;
      ctx.save();
      ctx.translate(arrow.x, arrow.y);
      ctx.rotate(angle);
      ctx.shadowColor = 'rgba(255, 24, 24, .98)';
      ctx.shadowBlur = 11;
      ctx.lineJoin = 'round';
      ctx.beginPath();
      ctx.moveTo(0, -13);
      ctx.lineTo(8, 8);
      ctx.lineTo(0, 5);
      ctx.lineTo(-8, 8);
      ctx.closePath();
      ctx.fillStyle = 'rgba(255, 30, 30, .98)';
      ctx.fill();
      ctx.shadowBlur = 3;
      ctx.lineWidth = 1.5;
      ctx.strokeStyle = '#fff';
      ctx.stroke();
      ctx.restore();
    }

    ctx.strokeStyle = 'rgba(143, 190, 226, .7)';
    ctx.lineWidth = 1;
    ctx.strokeRect(.5, .5, width - 1, height - 1);
  }

  clearMapLabels() {
    const canvas = this.els?.mapLabels;
    const ctx = this.mapLabelContext;
    if (canvas && ctx) ctx.clearRect(0, 0, canvas.width, canvas.height);
  }

  drawMapLabels() {
    const canvas = this.els.mapLabels;
    const ctx = this.mapLabelContext;
    if (!ctx || !this.mapFileVisible || (!this.mapData?.points?.length && !this.lastFirstPersonPose)) {
      this.clearMapLabels();
      return;
    }
    const width = this.els.viewport.clientWidth;
    const height = this.els.viewport.clientHeight;
    const ratio = Math.min(2, Math.max(1, devicePixelRatio || 1));
    const targetWidth = Math.max(1, Math.round(width * ratio));
    const targetHeight = Math.max(1, Math.round(height * ratio));
    if (canvas.width !== targetWidth || canvas.height !== targetHeight) {
      canvas.width = targetWidth;
      canvas.height = targetHeight;
    }
    ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
    ctx.clearRect(0, 0, width, height);
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    const position = new THREE.Vector3();
    const clipValue = Number(this.els.clip.value);
    const clipOff = clipValue >= Number(this.els.clip.max) - Number(this.els.clip.step || 1);
    for (const point of this.mapData.points) {
      if (!clipOff && point.z > clipValue) continue;
      position.copy(this.eqMapToThree(point.x, point.y, point.z)).project(this.camera);
      if (position.z < -1 || position.z > 1 || position.x < -1.1 || position.x > 1.1 || position.y < -1.1 || position.y > 1.1) continue;
      const x = (position.x * 0.5 + 0.5) * width;
      const y = (-position.y * 0.5 + 0.5) * height;
      const fontSize = point.size === 3 ? 16 : point.size === 1 ? 10 : 13;
      ctx.font = `${point.size === 3 ? 700 : 600} ${fontSize}px system-ui, sans-serif`;
      const luminance = 0.2126 * point.r + 0.7152 * point.g + 0.0722 * point.b;
      ctx.lineWidth = 3;
      ctx.strokeStyle = luminance < 120 ? 'rgba(255,255,255,.9)' : 'rgba(0,0,0,.8)';
      ctx.fillStyle = `rgb(${point.r},${point.g},${point.b})`;
      ctx.strokeText(point.label, x, y);
      ctx.fillText(point.label, x, y);
    }
    this.drawMapViewerArrow(ctx, width, height);
  }

  clearNamedMobLabels() {
    this.targetedMobLabel = null;
    this.namedMobLabels = [];
    if (this.els?.mobLabels) {
      if (this.els.navigationLabel) this.els.mobLabels.replaceChildren(this.els.navigationLabel);
      else this.els.mobLabels.replaceChildren();
    }
  }

  buildNamedMobLabels() {
    this.clearNamedMobLabels();
    this.buildGotoNpcEntries();
    if (!this.mapData?.points?.length || !this.els?.mobLabels) return;
    const named = this.mapData.points.filter(isNamedMobPoint);
    for (const point of named) {
      const title = cleanMobLabel(point.label);
      if (!title) continue;
      const anchor = h('a', 'eqlzv-mob-label', title);
      anchor.href = wikiPageUrl(title);
      anchor.target = '_blank';
      anchor.rel = 'noopener noreferrer';
      anchor.title = `Open ${title} on EQL Wiki`;
      anchor.dataset.eqlMob = title;
      anchor.addEventListener('click', event => {
        event.stopPropagation();
        if (this.fp?.controls?.isLocked) this.fp.controls.unlock();
      });
      this.els.mobLabels.append(anchor);
      this.namedMobLabels.push({
        element: anchor,
        href: anchor.href,
        title,
        priority: namedMobPriority(point),
        eqZ: point.z,
        position: this.eqMapToThree(point.x, point.y, point.z).add(new THREE.Vector3(0, 8 + point.size * 2, 0))
      });
    }
    this.updateNamedMobLabels();
  }

  buildGotoNpcEntries() {
    this.gotoNpcEntries = [];
    this.els.gotoList.replaceChildren();
    if (!this.mapData?.points?.length) {
      this.els.gotoNpc.disabled = true;
      this.els.gotoButton.disabled = true;
      return;
    }
    const options = new Set();
    for (const point of this.mapData.points) {
      const raw = String(point.label || '').trim();
      if (!raw) continue;
      const cleaned = cleanMobLabel(raw) || raw;
      this.gotoNpcEntries.push({
        label: cleaned,
        rawLabel: raw,
        search: `${cleaned} ${raw}`.toLowerCase(),
        point
      });
      options.add(cleaned);
    }
    for (const label of [...options].sort((a, b) => a.localeCompare(b, undefined, { numeric: true }))) {
      const option = document.createElement('option');
      option.value = label;
      this.els.gotoList.append(option);
    }
    const enabled = this.gotoNpcEntries.length > 0;
    this.els.gotoNpc.disabled = !enabled;
    this.els.gotoButton.disabled = !enabled;
  }

  hasExactGotoMatch(query) {
    const needle = String(query || '').trim().toLowerCase();
    return Boolean(needle && this.gotoNpcEntries.some(entry =>
      entry.label.toLowerCase() === needle || entry.rawLabel.toLowerCase() === needle
    ));
  }

  clearLocationPillars() {
    this.locationPillarEntries = [];
    this.locationPillarMesh = null;
    this.locationHoverEntry = null;
    this.locationPinnedEntry = null;
    this.locationPinnedUntil = 0;
    if (this.els?.locationTooltip) this.els.locationTooltip.hidden = true;
    if (this.locationMarkerGroup) this.clearSceneGroup(this.locationMarkerGroup);
  }

  buildLocationPillars() {
    this.clearLocationPillars();
    if (!this.gotoNpcEntries.length || !this.locationMarkerGroup) return;
    const byPosition = new Map();
    for (const entry of this.gotoNpcEntries) {
      const point = entry.point;
      if (!point || isSuppressedMapLabel(point.label)) continue;
      const key = `${safeNumber(point.x).toFixed(2)}:${safeNumber(point.y).toFixed(2)}:${safeNumber(point.z).toFixed(2)}`;
      let marker = byPosition.get(key);
      if (!marker) {
        const target = this.eqMapToThree(point.x, point.y, point.z);
        const ground = this.findGroundPointAt(target.x, target.z, target.y + 24) || target;
        marker = { ground, labels: [], rawLabels: [] };
        byPosition.set(key, marker);
      }
      if (entry.label && !marker.labels.includes(entry.label)) marker.labels.push(entry.label);
      if (entry.rawLabel && !marker.rawLabels.includes(entry.rawLabel)) marker.rawLabels.push(entry.rawLabel);
    }
    const unique = [...byPosition.values()];
    if (!unique.length) return;

    const zoneHeight = Math.max(1, this.currentBounds.max.y - this.currentBounds.min.y);
    const height = clamp(zoneHeight * 0.055, 22, 58);
    this.locationPillarHeight = height;
    const geometry = new THREE.CylinderGeometry(0.34, 0.34, height, 7, 1, true);
    const material = new THREE.MeshBasicMaterial({
      color: 0xe4b84d,
      transparent: true,
      opacity: 0.34,
      depthWrite: false,
      side: THREE.DoubleSide,
      blending: THREE.AdditiveBlending,
      toneMapped: false
    });
    const pillars = new THREE.InstancedMesh(geometry, material, unique.length);
    pillars.name = 'all-location-pillars';
    pillars.renderOrder = 900;
    pillars.userData.eqlCollision = false;
    pillars.frustumCulled = true;
    const matrix = new THREE.Matrix4();
    this.locationPillarEntries = unique.map((marker, index) => {
      const position = marker.ground.clone();
      position.y += height * 0.5;
      matrix.makeTranslation(position.x, position.y, position.z);
      pillars.setMatrixAt(index, matrix);
      const raw = marker.rawLabels.length ? marker.rawLabels : marker.labels;
      return {
        instanceId: index,
        ground: marker.ground.clone(),
        labelPosition: marker.ground.clone().add(new THREE.Vector3(0, height, 0)),
        label: raw.join(' · '),
        shortLabel: marker.labels.join(' · ')
      };
    });
    pillars.instanceMatrix.needsUpdate = true;
    pillars.computeBoundingBox?.();
    pillars.computeBoundingSphere?.();
    this.locationPillarMesh = pillars;
    this.locationMarkerGroup.add(pillars);
    this.setLocationBeamsVisible(this.els?.locationBeams?.checked !== false, false);
  }

  setLocationBeamsVisible(visible, render = true) {
    const enabled = Boolean(visible);
    if (this.locationMarkerGroup) this.locationMarkerGroup.visible = enabled;
    if (this.navigationGuideGroup) {
      for (const child of this.navigationGuideGroup.children) {
        if (child.name === 'navigation-destination-beam') child.visible = enabled;
      }
    }
    if (!enabled) {
      this.locationHoverEntry = null;
      this.locationPinnedEntry = null;
      this.locationPinnedUntil = 0;
      if (this.els?.locationTooltip) this.els.locationTooltip.hidden = true;
      if (this.els?.navigationLabel) this.els.navigationLabel.hidden = true;
    } else {
      this.updateLocationPillarHover();
      this.updateNavigationOverlay();
    }
    if (render) this.requestRender();
  }

  locationPillarScreenHit(pointer, threshold = 26) {
    if (!pointer || !this.locationPillarEntries.length || this.els?.locationBeams?.checked === false) return null;
    const width = Math.max(1, this.els.viewport.clientWidth);
    const height = Math.max(1, this.els.viewport.clientHeight);
    let best = null;
    const projectedTop = new THREE.Vector3();
    const projectedBottom = new THREE.Vector3();
    for (const entry of this.locationPillarEntries) {
      projectedTop.copy(entry.labelPosition).project(this.camera);
      projectedBottom.copy(entry.ground).project(this.camera);
      if (projectedTop.z < -1 || projectedTop.z > 1 || projectedBottom.z < -1 || projectedBottom.z > 1) continue;
      const topX = (projectedTop.x * 0.5 + 0.5) * width;
      const topY = (-projectedTop.y * 0.5 + 0.5) * height;
      const bottomX = (projectedBottom.x * 0.5 + 0.5) * width;
      const bottomY = (-projectedBottom.y * 0.5 + 0.5) * height;
      const dx = bottomX - topX;
      const dy = bottomY - topY;
      const lengthSquared = dx * dx + dy * dy;
      const t = lengthSquared > 0.001
        ? clamp(((pointer.x - topX) * dx + (pointer.y - topY) * dy) / lengthSquared, 0, 1)
        : 0;
      const x = topX + dx * t;
      const y = topY + dy * t;
      const distance = Math.hypot(pointer.x - x, pointer.y - y);
      if (distance > threshold) continue;
      const depth = Math.min(projectedTop.z, projectedBottom.z);
      if (!best || distance < best.distance - 1.5 || (Math.abs(distance - best.distance) <= 1.5 && depth < best.depth)) {
        best = { entry, x, y, distance, depth };
      }
    }
    return best;
  }

  showLocationTooltip(hit, pinned = false) {
    const element = this.els?.locationTooltip;
    if (!element || !hit?.entry) return false;
    const width = Math.max(1, this.els.viewport.clientWidth);
    const height = Math.max(1, this.els.viewport.clientHeight);
    element.textContent = hit.entry.label || hit.entry.shortLabel || 'Point of interest';
    element.hidden = false;
    element.classList.toggle('is-pinned', pinned);
    const clampedX = clamp(hit.x, 14, width - 14);
    const clampedY = clamp(hit.y - 8, 22, height - 12);
    element.style.transform = `translate(-50%, -100%) translate(${clampedX.toFixed(1)}px, ${clampedY.toFixed(1)}px)`;
    return true;
  }

  pinLocationPillar(event = null) {
    if (this.els?.locationBeams?.checked === false || this.worldMapVisible || this.mapFileVisible) return false;
    const width = Math.max(1, this.els.viewport.clientWidth);
    const height = Math.max(1, this.els.viewport.clientHeight);
    let pointer;
    if (this.fp?.controls?.isLocked) pointer = { x: width * 0.5, y: height * 0.5 };
    else if (event) {
      const rect = this.els.viewport.getBoundingClientRect();
      pointer = { x: event.clientX - rect.left, y: event.clientY - rect.top };
    } else pointer = this.locationPointerClient;
    const hit = this.locationPillarScreenHit(pointer, 30);
    if (!hit) return false;
    this.locationPinnedEntry = hit.entry;
    this.locationPinnedUntil = performance.now() + 7000;
    this.locationHoverEntry = hit.entry;
    this.showLocationTooltip(hit, true);
    this.requestRender();
    return true;
  }

  updateLocationPillarHover() {
    const element = this.els?.locationTooltip;
    if (!element || !this.locationPillarEntries.length || this.worldMapVisible || this.mapFileVisible || !this.navigationGroup.visible || this.els?.locationBeams?.checked === false) {
      if (element) element.hidden = true;
      return;
    }
    const width = Math.max(1, this.els.viewport.clientWidth);
    const height = Math.max(1, this.els.viewport.clientHeight);
    const pointer = this.fp?.controls?.isLocked
      ? { x: width * 0.5, y: height * 0.5 }
      : this.locationPointerClient;
    const hit = this.locationPillarScreenHit(pointer, 24);
    if (hit) {
      this.locationHoverEntry = hit.entry;
      this.showLocationTooltip(hit, false);
      return;
    }
    if (this.locationPinnedEntry && performance.now() < this.locationPinnedUntil) {
      const projected = this.locationPinnedEntry.labelPosition.clone().project(this.camera);
      if (projected.z >= -1 && projected.z <= 1) {
        const pinnedHit = {
          entry: this.locationPinnedEntry,
          x: (projected.x * 0.5 + 0.5) * width,
          y: (-projected.y * 0.5 + 0.5) * height
        };
        this.showLocationTooltip(pinnedHit, true);
        return;
      }
    }
    this.locationPinnedEntry = null;
    this.locationPinnedUntil = 0;
    this.locationHoverEntry = null;
    element.classList.remove('is-pinned');
    element.hidden = true;
  }

  terminateNavigationWorker(error = null) {
    if (this.navigationWorker) {
      try { this.navigationWorker.terminate(); } catch (_) {}
    }
    this.navigationWorker = null;
    this.navigationWorkerMapKey = null;
    this.navigationWorkerReady = false;
    this.navigationWorkerInitPromise = null;
    if (this.navigationWorkerInitReject && error) this.navigationWorkerInitReject(error);
    this.navigationWorkerInitResolve = null;
    this.navigationWorkerInitReject = null;
    for (const pending of this.navigationWorkerPending.values()) pending.reject(error || new Error('Navigation worker stopped.'));
    this.navigationWorkerPending.clear();
  }

  navigationWorkerUrl() {
    if (this.config.navigationWorkerUrl) return this.config.navigationWorkerUrl;
    const moduleUrl = String(this.config.moduleUrl || '');
    return moduleUrl ? moduleUrl.replace(/ZoneViewerApp\.js(?:\?.*)?$/, 'navigation.worker.js') : '';
  }

  ensureNavigationWorker() {
    if (this.navigationWorker) return this.navigationWorker;
    const url = this.navigationWorkerUrl();
    if (!url) return null;
    const worker = new Worker(url);
    worker.onmessage = event => {
      const message = event.data || {};
      if (message.type === 'ready') {
        this.navigationWorkerMapKey = message.mapKey;
        this.navigationWorkerReady = true;
        this.navigationWorkerInitResolve?.(true);
        this.navigationWorkerInitResolve = null;
        this.navigationWorkerInitReject = null;
        return;
      }
      const pending = this.navigationWorkerPending.get(message.id);
      if (message.type === 'progress') {
        if (pending && pending.token === this.navigationBuildToken) {
          this.setStatus(`${message.message || 'Calculating path in the background…'} You can keep moving.`, clamp(message.value ?? 0.1, 0.01, 0.97));
        }
        return;
      }
      if (!pending) return;
      if (message.type === 'route') {
        this.navigationWorkerPending.delete(message.id);
        pending.resolve(message.points ? { points: new Float32Array(message.points), relaxed: Boolean(message.relaxed), cell: message.cell || 20 } : null);
      } else if (message.type === 'error') {
        this.navigationWorkerPending.delete(message.id);
        pending.reject(new Error(message.message || 'Background path worker failed.'));
      }
    };
    worker.onerror = event => {
      const error = new Error(event.message || 'Background path worker crashed.');
      this.terminateNavigationWorker(error);
    };
    this.navigationWorker = worker;
    return worker;
  }

  async prepareNavigationWorkerMap(token) {
    const mapKey = `${this.currentZoneKey || 'zone'}:${this.mapData?.sourceLabel || 'map'}:${this.mapData?.lines?.length || 0}`;
    if (this.navigationWorkerReady && this.navigationWorkerMapKey === mapKey) return true;
    if (this.navigationWorkerInitPromise && this.navigationWorkerMapKey === mapKey) return this.navigationWorkerInitPromise;
    const worker = this.ensureNavigationWorker();
    if (!worker || !this.mapData?.lines?.length) return false;
    this.navigationWorkerMapKey = mapKey;
    this.navigationWorkerReady = false;
    this.navigationWorkerInitPromise = new Promise((resolve, reject) => {
      this.navigationWorkerInitResolve = resolve;
      this.navigationWorkerInitReject = reject;
    });
    const source = this.mapData.lines.filter(line => line.layer !== 2);
    const packed = new Float32Array(source.length * 6);
    let write = 0;
    let sliceStartedAt = performance.now();
    for (let index = 0; index < source.length; index++) {
      if (token !== this.navigationBuildToken) {
        this.navigationWorkerInitResolve?.(false);
        this.navigationWorkerInitResolve = null;
        this.navigationWorkerInitReject = null;
        this.navigationWorkerInitPromise = null;
        return false;
      }
      const line = source[index];
      // Pack the fixed EQ -> Three coordinate transform directly to avoid
      // allocating two Vector3 objects per map line on the render thread.
      packed[write++] = EQ_TO_THREE.swap ? EQ_TO_THREE.sx * line.y1 : EQ_TO_THREE.sx * line.x1;
      packed[write++] = EQ_TO_THREE.swap ? EQ_TO_THREE.sz * line.x1 : EQ_TO_THREE.sz * line.y1;
      packed[write++] = line.z1;
      packed[write++] = EQ_TO_THREE.swap ? EQ_TO_THREE.sx * line.y2 : EQ_TO_THREE.sx * line.x2;
      packed[write++] = EQ_TO_THREE.swap ? EQ_TO_THREE.sz * line.x2 : EQ_TO_THREE.sz * line.y2;
      packed[write++] = line.z2;
      if (performance.now() - sliceStartedAt >= 2.5) {
        this.setStatus(`Preparing the background route map · ${Math.round((index + 1) / source.length * 100)}%… You can keep moving.`, 0.02 + 0.08 * ((index + 1) / source.length));
        await yieldToBrowser();
        sliceStartedAt = performance.now();
      }
    }
    if (token !== this.navigationBuildToken) {
      this.navigationWorkerInitResolve?.(false);
      this.navigationWorkerInitResolve = null;
      this.navigationWorkerInitReject = null;
      this.navigationWorkerInitPromise = null;
      return false;
    }
    const id = ++this.navigationWorkerRequestId;
    worker.postMessage({
      action: 'init',
      id,
      mapKey,
      lines: packed.buffer,
      bounds: {
        minX: this.currentBounds.min.x - 50,
        minZ: this.currentBounds.min.z - 50,
        maxX: this.currentBounds.max.x + 50,
        maxZ: this.currentBounds.max.z + 50
      }
    }, [packed.buffer]);
    try {
      await this.navigationWorkerInitPromise;
      return token === this.navigationBuildToken;
    } finally {
      this.navigationWorkerInitPromise = null;
    }
  }

  requestNavigationWorkerRoute(start, goal, token) {
    const worker = this.navigationWorker;
    if (!worker || !this.navigationWorkerReady) return Promise.resolve(null);
    const id = ++this.navigationWorkerRequestId;
    return new Promise((resolve, reject) => {
      this.navigationWorkerPending.set(id, { resolve, reject, token });
      worker.postMessage({
        action: 'route',
        id,
        mapKey: this.navigationWorkerMapKey,
        start: { x: start.x, y: start.y, z: start.z },
        goal: { x: goal.x, y: goal.y, z: goal.z },
        playerRadius: this.fp?.playerRadius || 2.25,
        jumpHeight: this.fp?.jumpHeight || 10,
        maxMs: NAVIGATION_PATH_MAX_TOTAL_MS
      });
    });
  }

  navigationProjectedSurface(x, z, expectedY, previous, cache, direction = null) {
    const key = `${Math.round(x * 4) / 4}:${Math.round(z * 4) / 4}`;
    const surfaces = this.navigationSurfacesAt(x, z, key, cache);
    if (!surfaces.length) return null;
    let best = null;
    for (const surface of surfaces) {
      const candidate = new THREE.Vector3(surface.x, surface.y, surface.z);
      if (previous) {
        const rise = candidate.y - previous.y;
        if (rise > this.fp.jumpHeight + 1.5 || rise < -this.fp.maxDrop - 2) continue;
        if (this.navigationSegmentBlocked(previous, candidate)) continue;
      }
      const heightCost = Math.abs(candidate.y - expectedY) * 2.4;
      const slopeCost = (1 - surface.normalY) * 18;
      const forwardCost = direction && previous
        ? Math.max(0, -candidate.clone().sub(previous).setY(0).normalize().dot(direction)) * 20
        : 0;
      const cost = heightCost + slopeCost + forwardCost;
      if (!best || cost < best.cost) best = { point: candidate, cost };
    }
    return best?.point || null;
  }

  navigationRepairStep(previous, intended, expectedY, cache, direction) {
    const direct = this.navigationProjectedSurface(intended.x, intended.z, expectedY, previous, cache, direction);
    if (direct) return direct;
    const forward = direction?.clone().setY(0) || intended.clone().sub(previous).setY(0);
    if (forward.lengthSq() < 0.0001) forward.set(0, 0, -1);
    forward.normalize();
    const side = new THREE.Vector3(-forward.z, 0, forward.x);
    const radii = [2.5, 5, 8, 12, 18, 26];
    let best = null;
    for (const radius of radii) {
      const offsets = [
        side.clone().multiplyScalar(radius),
        side.clone().multiplyScalar(-radius),
        side.clone().multiplyScalar(radius).addScaledVector(forward, radius * 0.45),
        side.clone().multiplyScalar(-radius).addScaledVector(forward, radius * 0.45),
        side.clone().multiplyScalar(radius).addScaledVector(forward, -radius * 0.35),
        side.clone().multiplyScalar(-radius).addScaledVector(forward, -radius * 0.35)
      ];
      for (const offset of offsets) {
        const probe = intended.clone().add(offset);
        const candidate = this.navigationProjectedSurface(probe.x, probe.z, expectedY, previous, cache, direction);
        if (!candidate) continue;
        const cost = candidate.distanceToSquared(intended) + Math.abs(candidate.y - expectedY) * 5;
        if (!best || cost < best.cost) best = { point: candidate, cost };
      }
      if (best) return best.point;
    }
    return null;
  }

  async projectWorkerNavigationPath(workerResult, originalStart, goal, token) {
    const flat = workerResult?.points;
    if (!flat || flat.length < 4) return null;
    const requestStart = originalStart.clone();
    const coarse = [];
    for (let offset = 0; offset + 1 < flat.length; offset += 2) {
      coarse.push(new THREE.Vector3(flat[offset], 0, flat[offset + 1]));
    }
    coarse[0].copy(requestStart);
    coarse[coarse.length - 1].copy(goal);
    const spacing = clamp((workerResult.cell || 18) * 0.42, 7, 12);
    let totalDistance = 0;
    const segmentLengths = [];
    for (let index = 1; index < coarse.length; index++) {
      const length = Math.hypot(coarse[index].x - coarse[index - 1].x, coarse[index].z - coarse[index - 1].z);
      segmentLengths.push(length);
      totalDistance += length;
    }
    const samples = [{ point: requestStart.clone(), fraction: 0 }];
    let traversed = 0;
    for (let index = 1; index < coarse.length; index++) {
      const from = coarse[index - 1];
      const to = coarse[index];
      const length = segmentLengths[index - 1];
      const steps = Math.max(1, Math.ceil(length / spacing));
      for (let step = 1; step <= steps; step++) {
        const t = step / steps;
        samples.push({
          point: new THREE.Vector3(THREE.MathUtils.lerp(from.x, to.x, t), 0, THREE.MathUtils.lerp(from.z, to.z, t)),
          fraction: totalDistance > 0 ? (traversed + length * t) / totalDistance : t
        });
      }
      traversed += length;
    }
    samples[samples.length - 1] = { point: goal.clone(), fraction: 1 };

    const cache = new Map();
    const result = [requestStart.clone()];
    let sliceStartedAt = performance.now();
    for (let index = 1; index < samples.length; index++) {
      if (token !== this.navigationBuildToken) return null;
      const sample = samples[index];
      const previous = result[result.length - 1];
      const nextSample = samples[Math.min(samples.length - 1, index + 1)].point;
      const direction = nextSample.clone().sub(previous).setY(0);
      if (direction.lengthSq() > 0.0001) direction.normalize();
      const expectedY = THREE.MathUtils.lerp(requestStart.y, goal.y, sample.fraction);
      const intended = sample.point;
      const projected = this.navigationRepairStep(previous, intended, expectedY, cache, direction);
      if (!projected) return null;
      result.push(projected);
      if (performance.now() - sliceStartedAt >= 2.5) {
        this.setStatus(`Grounding and validating the background route · ${Math.round((index + 1) / samples.length * 100)}%… You can keep moving.`, 0.90 + 0.08 * ((index + 1) / samples.length));
        await yieldToBrowser();
        sliceStartedAt = performance.now();
      }
    }
    if (result.length < 2) return null;
    result[0].copy(requestStart);
    const last = result[result.length - 1];
    if (last.distanceToSquared(goal) > 0.5) {
      const rise = goal.y - last.y;
      if (rise > this.fp.jumpHeight + 1.5 || rise < -this.fp.maxDrop - 2 || this.navigationSegmentBlocked(last, goal)) return null;
      result.push(goal.clone());
    } else {
      last.copy(goal);
    }
    return this.simplifyNavigationPath(result);
  }

  setNavigationBuildActive(active) {
    this.navigationBuildActive = Boolean(active);
    if (this.els?.pathCancel) this.els.pathCancel.hidden = !this.navigationBuildActive;
    if (this.navigationBuildActive) this.navigationBuildStartedAt = performance.now();
  }

  cancelNavigationPathBuild(message = 'Path calculation cancelled. The destination marker remains visible.') {
    if (!this.navigationBuildActive) return;
    const activeIds = [...this.navigationWorkerPending.keys()];
    for (const id of activeIds) this.navigationWorker?.postMessage({ action: 'cancel', id });
    for (const id of activeIds) {
      const pending = this.navigationWorkerPending.get(id);
      pending?.resolve(null);
      this.navigationWorkerPending.delete(id);
    }
    this.navigationWorkerInitResolve?.(false);
    this.navigationWorkerInitResolve = null;
    this.navigationWorkerInitReject = null;
    this.navigationWorkerInitPromise = null;
    this.navigationBuildToken++;
    this.setNavigationBuildActive(false);
    this.setStatus(message, 0);
  }

  clearNavigationGuide() {
    for (const id of this.navigationWorkerPending.keys()) this.navigationWorker?.postMessage({ action: 'cancel', id });
    for (const pending of this.navigationWorkerPending.values()) pending.resolve(null);
    this.navigationWorkerPending.clear();
    this.navigationWorkerInitResolve?.(false);
    this.navigationWorkerInitResolve = null;
    this.navigationWorkerInitReject = null;
    this.navigationWorkerInitPromise = null;
    this.navigationBuildToken++;
    this.setNavigationBuildActive(false);
    this.navigationTarget = null;
    this.navigationPath = [];
    if (this.navigationGuideGroup) this.clearSceneGroup(this.navigationGuideGroup);
    if (this.els?.navigationLabel) {
      this.els.navigationLabel.hidden = true;
      this.els.navigationLabel.textContent = '';
    }
  }

  navigationStartPoint() {
    if (this.mode === 'first' && this.perspective && this.fp) {
      const foot = this.perspective.position.clone();
      foot.y -= this.fp.eyeHeight;
      return this.findGroundPointAt(foot.x, foot.z, foot.y + this.fp.maxStepUp + 2) || foot;
    }
    if (this.lastFirstPersonPose?.position) {
      const pose = this.lastFirstPersonPose.position.clone();
      return this.findGroundPointAt(pose.x, pose.z, pose.y + this.fp.maxStepUp + 2) || pose;
    }
    const spawn = this.findFirstPersonSpawn();
    spawn.y -= this.fp.eyeHeight;
    return spawn;
  }

  addNavigationBeam(ground, label) {
    const zoneHeight = Math.max(1, this.currentBounds.max.y - this.currentBounds.min.y);
    const beamHeight = clamp(zoneHeight * 0.18, NAVIGATION_BEAM_MIN_HEIGHT, NAVIGATION_BEAM_MAX_HEIGHT);
    const beamRoot = new THREE.Group();
    beamRoot.name = 'navigation-destination-beam';
    beamRoot.position.copy(ground);
    beamRoot.userData.eqlCollision = false;
    beamRoot.visible = this.els?.locationBeams?.checked !== false;

    const outerMaterial = new THREE.MeshBasicMaterial({
      color: 0xd5a52e,
      transparent: true,
      opacity: 0.18,
      depthWrite: false,
      side: THREE.DoubleSide,
      blending: THREE.AdditiveBlending,
      toneMapped: false
    });
    const coreMaterial = new THREE.MeshBasicMaterial({
      color: 0xffe8a0,
      transparent: true,
      opacity: 0.58,
      depthWrite: false,
      side: THREE.DoubleSide,
      blending: THREE.AdditiveBlending,
      toneMapped: false
    });
    const outer = new THREE.Mesh(new THREE.CylinderGeometry(3.8, 1.6, beamHeight, 18, 1, true), outerMaterial);
    outer.position.y = beamHeight * 0.5;
    outer.renderOrder = 920;
    outer.userData.eqlCollision = false;
    const core = new THREE.Mesh(new THREE.CylinderGeometry(0.72, 0.72, beamHeight, 12, 1, true), coreMaterial);
    core.position.y = beamHeight * 0.5;
    core.renderOrder = 921;
    core.userData.eqlCollision = false;

    const ringMaterial = new THREE.MeshBasicMaterial({
      color: 0xffcb55,
      transparent: true,
      opacity: 0.78,
      depthWrite: false,
      side: THREE.DoubleSide,
      blending: THREE.AdditiveBlending,
      toneMapped: false
    });
    const ring = new THREE.Mesh(new THREE.RingGeometry(3.8, 5.2, 40), ringMaterial);
    ring.rotation.x = -Math.PI * 0.5;
    ring.position.y = 0.24;
    ring.renderOrder = 922;
    ring.userData.eqlCollision = false;
    beamRoot.add(outer, core, ring);
    this.navigationGuideGroup.add(beamRoot);

    this.navigationTarget.beamHeight = beamHeight;
    this.navigationTarget.labelPosition = ground.clone().add(new THREE.Vector3(0, beamHeight + 5, 0));
    this.els.navigationLabel.textContent = label;
    this.els.navigationLabel.hidden = false;
  }

  navigationSurfacesAt(x, z, cacheKey, cache) {
    if (cache.has(cacheKey)) return cache.get(cacheKey);
    const candidates = this.fp?.surfaceCandidatesAt(x, z) || [];
    if (!candidates.length) {
      cache.set(cacheKey, []);
      return [];
    }
    const top = this.currentBounds.max.y + 800;
    this.navigationRaycaster.set(new THREE.Vector3(x, top, z), new THREE.Vector3(0, -1, 0));
    this.navigationRaycaster.near = 0;
    this.navigationRaycaster.far = Math.max(2000, top - this.currentBounds.min.y + 1200);
    const hits = this.navigationRaycaster.intersectObjects(candidates, false);
    const surfaces = [];
    for (const hit of hits) {
      if (!hit.face) continue;
      this.navigationNormalMatrix.getNormalMatrix(hit.object.matrixWorld);
      this.navigationNormal.copy(hit.face.normal).applyMatrix3(this.navigationNormalMatrix).normalize();
      if (this.navigationNormal.y < 0.42) continue;
      if (surfaces.some(surface => Math.abs(surface.y - hit.point.y) < 1.25)) continue;
      surfaces.push({ x, y: hit.point.y, z, normalY: this.navigationNormal.y });
      if (surfaces.length >= 12) break;
    }
    cache.set(cacheKey, surfaces);
    return surfaces;
  }

  navigationSegmentBlocked(from, to) {
    if (!this.fp?.collision || !this.fp.wallColliderRecords.length) return false;
    const start = from.clone();
    const end = to.clone();
    start.y += this.fp.eyeHeight;
    end.y += this.fp.eyeHeight;
    const direction = end.clone().sub(start);
    direction.y = 0;
    const distance = direction.length();
    if (distance <= 0.001) return false;
    direction.multiplyScalar(1 / distance);
    const candidates = this.fp.wallCandidatesForSweep(start, end);
    const hit = this.fp.findSweptWallHit(start, direction, distance, candidates);
    return Boolean(hit && hit.distance < distance + this.fp.playerRadius * 0.35);
  }

  async findNavigationPath(start, goal, token) {
    const directDistance = Math.hypot(goal.x - start.x, goal.z - start.z);
    const progress = (value, message) => {
      if (token !== this.navigationBuildToken) return;
      this.setStatus(`${message} You can keep moving.`, clamp(value, 0.01, 0.98));
    };

    // The expensive graph search now runs in a dedicated worker against the
    // loaded EQ map lines. The render thread only packs the map cooperatively
    // and projects the small, smoothed result back onto collision geometry.
    try {
      progress(0.02, 'Preparing background pathfinding…');
      const ready = await this.prepareNavigationWorkerMap(token);
      if (ready && token === this.navigationBuildToken) {
        const workerResult = await this.requestNavigationWorkerRoute(start, goal, token);
        if (workerResult && token === this.navigationBuildToken) {
          const projected = await this.projectWorkerNavigationPath(workerResult, start, goal, token);
          if (projected?.length) return projected;
        }
      }
    } catch (error) {
      console.warn('[EQLZoneViewer] Background navigation worker failed; using bounded fallback.', error);
    }
    if (token !== this.navigationBuildToken) return null;

    // Fast direct and dog-leg checks remain useful when a zone has no local
    // map or when its map lines are too incomplete for worker routing.
    const deadline = performance.now() + Math.min(12000, NAVIGATION_PATH_MAX_TOTAL_MS);
    const projected = await this.findProjectedNavigationFallback(start, goal, token, progress, 0.08, 0.42, deadline);
    if (projected?.length) return projected;
    if (token !== this.navigationBuildToken || performance.now() >= deadline) return null;

    // Do not fall back to the old full collision-grid A* here. Even when
    // cooperatively sliced, individual raycasts against a monolithic legacy
    // zone mesh can stall the render thread. The worker route and the small
    // projected dog-leg checks are the complete interactive path pipeline.
    return null;
  }

  async findNavigationPathAttempt(start, goal, attempt, token, deadline, progress, attemptNumber, attemptCount) {
    const cell = attempt.cell;
    const minX = Math.max(this.currentBounds.min.x - cell, Math.min(start.x, goal.x) - attempt.margin);
    const maxX = Math.min(this.currentBounds.max.x + cell, Math.max(start.x, goal.x) + attempt.margin);
    const minZ = Math.max(this.currentBounds.min.z - cell, Math.min(start.z, goal.z) - attempt.margin);
    const maxZ = Math.min(this.currentBounds.max.z + cell, Math.max(start.z, goal.z) + attempt.margin);
    const columns = Math.max(2, Math.floor((maxX - minX) / cell) + 1);
    const rows = Math.max(2, Math.floor((maxZ - minZ) / cell) + 1);
    if (columns * rows > NAVIGATION_PATH_MAX_GRID_CELLS) return null;
    const toGrid = point => ({
      ix: clamp(Math.round((point.x - minX) / cell), 0, columns - 1),
      iz: clamp(Math.round((point.z - minZ) / cell), 0, rows - 1)
    });
    const toWorld = (ix, iz) => ({ x: minX + ix * cell, z: minZ + iz * cell });
    const surfaceCache = new Map();
    const segmentCache = new Map();
    const startGrid = toGrid(start);
    const goalGrid = toGrid(goal);
    const startWorld = { x: start.x, z: start.z };
    const goalWorld = { x: goal.x, z: goal.z };
    const startSurface = { ...startWorld, y: start.y, normalY: 1 };
    const goalSurface = { ...goalWorld, y: goal.y, normalY: 1 };
    const heightBand = value => Math.round(value / Math.max(3, cell * 0.3));
    const stateKey = (ix, iz, y) => `${ix}:${iz}:${heightBand(y)}`;
    const segmentKey = (from, to) => {
      const quantize = value => Math.round(value * 0.2);
      return `${quantize(from.x)},${quantize(from.y)},${quantize(from.z)}>${quantize(to.x)},${quantize(to.y)},${quantize(to.z)}`;
    };
    const segmentBlocked = (from, to) => {
      const key = segmentKey(from, to);
      if (segmentCache.has(key)) return segmentCache.get(key);
      const blocked = this.navigationSegmentBlocked(from, to);
      if (segmentCache.size < 40000) segmentCache.set(key, blocked);
      return blocked;
    };
    const heap = new WorldRouteHeap();
    const scores = new Map();
    const parents = new Map();
    const states = new Map();
    const startKey = stateKey(startGrid.ix, startGrid.iz, startSurface.y);
    const startState = { ix: startGrid.ix, iz: startGrid.iz, point: new THREE.Vector3(startSurface.x, startSurface.y, startSurface.z), key: startKey, score: 0 };
    states.set(startKey, startState);
    scores.set(startKey, 0);
    heap.push(startState, 0);
    const directions = [
      [1, 0, 1], [-1, 0, 1], [0, 1, 1], [0, -1, 1],
      [1, 1, Math.SQRT2], [1, -1, Math.SQRT2], [-1, 1, Math.SQRT2], [-1, -1, Math.SQRT2]
    ];
    let goalKey = null;
    let expanded = 0;
    let sliceStartedAt = performance.now();
    const maybeYield = async force => {
      const now = performance.now();
      if (!force && now - sliceStartedAt < NAVIGATION_PATH_TIME_SLICE_MS) return true;
      const fraction = Math.min(1, expanded / Math.max(1, attempt.maxStates));
      const progressValue = attempt.progressStart + (attempt.progressEnd - attempt.progressStart) * fraction;
      progress(progressValue, `Finding path · pass ${attemptNumber}/${attemptCount} · ${expanded.toLocaleString()} nodes checked…`);
      await yieldToBrowser();
      sliceStartedAt = performance.now();
      return token === this.navigationBuildToken && sliceStartedAt < deadline;
    };

    while (heap.size && expanded++ < attempt.maxStates) {
      if (token !== this.navigationBuildToken || performance.now() >= deadline) return null;
      if (!(await maybeYield(false))) return null;
      const current = heap.pop();
      const currentScore = scores.get(current.key);
      if (currentScore === undefined || Math.abs(currentScore - current.score) > 0.0001) continue;
      const goalCellDistance = Math.max(Math.abs(current.ix - goalGrid.ix), Math.abs(current.iz - goalGrid.iz));
      if (goalCellDistance <= 1 && Math.abs(current.point.y - goalSurface.y) <= this.fp.jumpHeight + 1 && !segmentBlocked(current.point, goal)) {
        goalKey = current.key;
        break;
      }

      for (const [dx, dz, multiplier] of directions) {
        const ix = current.ix + dx;
        const iz = current.iz + dz;
        if (ix < 0 || ix >= columns || iz < 0 || iz >= rows) continue;
        const world = toWorld(ix, iz);
        const surfaces = this.navigationSurfacesAt(world.x, world.z, `${ix}:${iz}`, surfaceCache);
        if (!(await maybeYield(false))) return null;
        if (!surfaces.length) continue;
        const usableSurfaces = surfaces
          .filter(surface => {
            const rise = surface.y - current.point.y;
            return rise <= this.fp.jumpHeight + 0.75 && rise >= -this.fp.maxDrop;
          })
          .sort((left, right) => Math.abs(left.y - current.point.y) - Math.abs(right.y - current.point.y))
          .slice(0, NAVIGATION_PATH_SURFACES_PER_CELL);
        for (const surface of usableSurfaces) {
          const rise = surface.y - current.point.y;
          const nextPoint = new THREE.Vector3(surface.x, surface.y, surface.z);
          if (segmentBlocked(current.point, nextPoint)) continue;
          if (!(await maybeYield(false))) return null;
          const key = stateKey(ix, iz, surface.y);
          const slopePenalty = (1 - surface.normalY) * cell * 1.8;
          const jumpPenalty = rise > this.fp.maxStepUp ? 12 + rise * 2 : Math.max(0, rise) * 0.4;
          const dropPenalty = rise < -this.fp.maxStepUp ? Math.abs(rise) * 0.18 : 0;
          const nextScore = currentScore + cell * multiplier + slopePenalty + jumpPenalty + dropPenalty;
          if (scores.has(key) && scores.get(key) <= nextScore) continue;
          const state = { ix, iz, point: nextPoint, key, score: nextScore };
          scores.set(key, nextScore);
          states.set(key, state);
          parents.set(key, current.key);
          const heuristic = Math.hypot(goal.x - nextPoint.x, goal.z - nextPoint.z) + Math.abs(goal.y - nextPoint.y) * 0.35;
          heap.push(state, nextScore + heuristic);
        }
      }
    }
    await maybeYield(true);
    if (!goalKey) return null;
    const points = [goal.clone()];
    let key = goalKey;
    while (key) {
      const state = states.get(key);
      if (state) points.push(state.point.clone());
      if (key === startKey) break;
      key = parents.get(key);
    }
    points.push(start.clone());
    points.reverse();
    return this.simplifyNavigationPath(points);
  }

  simplifyNavigationPath(points) {
    const clean = [];
    for (const point of points) {
      if (!clean.length || clean[clean.length - 1].distanceToSquared(point) > 0.4) clean.push(point.clone());
    }
    if (clean.length <= 2) return clean;
    const simplified = [clean[0]];
    for (let index = 1; index < clean.length - 1; index++) {
      const before = simplified[simplified.length - 1];
      const current = clean[index];
      const after = clean[index + 1];
      const a = current.clone().sub(before).setY(0).normalize();
      const b = after.clone().sub(current).setY(0).normalize();
      const straight = a.dot(b) > 0.996 && Math.abs(after.y - before.y) <= this.fp.maxStepUp;
      if (!straight) simplified.push(current);
    }
    simplified.push(clean[clean.length - 1]);
    return simplified;
  }

  navigationRibbonGeometry(points, width, yOffset) {
    const positions = [];
    const indices = [];
    for (let index = 0; index < points.length; index++) {
      const before = points[Math.max(0, index - 1)];
      const after = points[Math.min(points.length - 1, index + 1)];
      const tangent = after.clone().sub(before);
      tangent.y = 0;
      if (tangent.lengthSq() < 0.0001) tangent.set(0, 0, -1);
      tangent.normalize();
      const side = new THREE.Vector3(-tangent.z, 0, tangent.x).multiplyScalar(width * 0.5);
      const point = points[index].clone();
      point.y += yOffset;
      const left = point.clone().add(side);
      const right = point.clone().sub(side);
      positions.push(left.x, left.y, left.z, right.x, right.y, right.z);
      if (index < points.length - 1) {
        const base = index * 2;
        indices.push(base, base + 2, base + 1, base + 1, base + 2, base + 3);
      }
    }
    const geometry = new THREE.BufferGeometry();
    geometry.setAttribute('position', new THREE.Float32BufferAttribute(positions, 3));
    geometry.setIndex(indices);
    geometry.computeVertexNormals();
    geometry.computeBoundingSphere();
    return geometry;
  }

  addNavigationPath(points) {
    this.navigationPath = points.map(point => point.clone());
    const glowMaterial = new THREE.MeshBasicMaterial({
      color: 0x9b6810,
      transparent: true,
      opacity: 0.34,
      depthWrite: false,
      side: THREE.DoubleSide,
      blending: THREE.AdditiveBlending,
      toneMapped: false
    });
    const coreMaterial = new THREE.MeshBasicMaterial({
      color: 0xffd15b,
      transparent: true,
      opacity: 0.88,
      depthWrite: false,
      side: THREE.DoubleSide,
      blending: THREE.AdditiveBlending,
      toneMapped: false
    });
    const glow = new THREE.Mesh(this.navigationRibbonGeometry(points, 5.6, 0.22), glowMaterial);
    const core = new THREE.Mesh(this.navigationRibbonGeometry(points, 1.9, 0.3), coreMaterial);
    glow.renderOrder = 910;
    core.renderOrder = 911;
    glow.userData.eqlCollision = false;
    core.userData.eqlCollision = false;
    glow.name = 'navigation-golden-path-glow';
    core.name = 'navigation-golden-path';
    this.navigationGuideGroup.add(glow, core);

    const chevronPositions = [];
    let carry = 0;
    const spacing = 42;
    for (let index = 1; index < points.length; index++) {
      const from = points[index - 1];
      const to = points[index];
      const segment = to.clone().sub(from);
      const length = segment.length();
      if (length < 0.001) continue;
      const direction = segment.clone().multiplyScalar(1 / length);
      let distance = spacing - carry;
      while (distance < length) {
        const center = from.clone().addScaledVector(direction, distance);
        center.y += 0.42;
        const flat = direction.clone().setY(0);
        if (flat.lengthSq() < 0.001) flat.set(0, 0, -1);
        flat.normalize();
        const side = new THREE.Vector3(-flat.z, 0, flat.x);
        const tip = center.clone().addScaledVector(flat, 4.8);
        const left = center.clone().addScaledVector(flat, -3.0).addScaledVector(side, 3.2);
        const right = center.clone().addScaledVector(flat, -3.0).addScaledVector(side, -3.2);
        chevronPositions.push(tip.x, tip.y, tip.z, left.x, left.y, left.z, right.x, right.y, right.z);
        distance += spacing;
      }
      carry = (carry + length) % spacing;
    }
    if (chevronPositions.length) {
      const geometry = new THREE.BufferGeometry();
      geometry.setAttribute('position', new THREE.Float32BufferAttribute(chevronPositions, 3));
      geometry.computeVertexNormals();
      const material = new THREE.MeshBasicMaterial({
        color: 0xffed9f,
        transparent: true,
        opacity: 0.9,
        depthWrite: false,
        side: THREE.DoubleSide,
        blending: THREE.AdditiveBlending,
        toneMapped: false
      });
      const chevrons = new THREE.Mesh(geometry, material);
      chevrons.renderOrder = 912;
      chevrons.userData.eqlCollision = false;
      this.navigationGuideGroup.add(chevrons);
    }
  }

  updateNavigationOverlay() {
    const element = this.els?.navigationLabel;
    const target = this.navigationTarget;
    if (!element || !target?.labelPosition || this.worldMapVisible || !this.navigationGroup.visible || this.els?.locationBeams?.checked === false) {
      if (element) element.hidden = true;
      return;
    }
    const projected = target.labelPosition.clone().project(this.camera);
    const visible = projected.z >= -1 && projected.z <= 1 && projected.x >= -1.15 && projected.x <= 1.15 && projected.y >= -1.15 && projected.y <= 1.15;
    if (!visible) {
      element.hidden = true;
      return;
    }
    const width = Math.max(1, this.els.viewport.clientWidth);
    const height = Math.max(1, this.els.viewport.clientHeight);
    const x = (projected.x * 0.5 + 0.5) * width;
    const y = (-projected.y * 0.5 + 0.5) * height;
    element.hidden = false;
    element.style.transform = `translate(-50%, -100%) translate(${x.toFixed(1)}px, ${y.toFixed(1)}px)`;
  }

  async projectNavigationWaypoints(waypoints, token, progress, progressStart, progressEnd, deadline) {
    const result = [];
    const sampleSpacing = 12;
    let totalSamples = 0;
    for (let segmentIndex = 1; segmentIndex < waypoints.length; segmentIndex++) {
      totalSamples += Math.max(1, Math.ceil(Math.hypot(
        waypoints[segmentIndex].x - waypoints[segmentIndex - 1].x,
        waypoints[segmentIndex].z - waypoints[segmentIndex - 1].z
      ) / sampleSpacing));
    }
    let completedSamples = 0;
    let sliceStartedAt = performance.now();
    for (let segmentIndex = 1; segmentIndex < waypoints.length; segmentIndex++) {
      const from = waypoints[segmentIndex - 1];
      const to = waypoints[segmentIndex];
      const flatDistance = Math.hypot(to.x - from.x, to.z - from.z);
      const steps = Math.max(1, Math.ceil(flatDistance / sampleSpacing));
      for (let step = segmentIndex === 1 ? 0 : 1; step <= steps; step++) {
        if (token !== this.navigationBuildToken || performance.now() >= deadline) return null;
        const t = step / steps;
        const x = THREE.MathUtils.lerp(from.x, to.x, t);
        const z = THREE.MathUtils.lerp(from.z, to.z, t);
        const preferredY = THREE.MathUtils.lerp(from.y, to.y, t) + this.fp.jumpHeight + 3;
        const ground = this.findGroundPointAt(x, z, preferredY);
        if (!ground) return null;
        const previous = result[result.length - 1];
        if (previous) {
          const rise = ground.y - previous.y;
          if (rise > this.fp.jumpHeight + 0.75 || rise < -this.fp.maxDrop) return null;
          if (this.navigationSegmentBlocked(previous, ground)) return null;
        }
        result.push(ground);
        completedSamples++;
        if (performance.now() - sliceStartedAt >= NAVIGATION_PATH_TIME_SLICE_MS) {
          const fraction = completedSamples / Math.max(1, totalSamples);
          progress(progressStart + (progressEnd - progressStart) * fraction, 'Checking a fast collision-safe route…');
          await yieldToBrowser();
          sliceStartedAt = performance.now();
        }
      }
    }
    return result.length >= 2 ? this.simplifyNavigationPath(result) : null;
  }

  async findProjectedNavigationFallback(start, goal, token, progress, progressStart = 0.03, progressEnd = 0.22, deadline = Infinity) {
    const delta = goal.clone().sub(start).setY(0);
    const distance = delta.length();
    if (distance < 0.01) return [start.clone(), goal.clone()];
    const forward = delta.clone().normalize();
    const side = new THREE.Vector3(-forward.z, 0, forward.x);
    const quarter = start.clone().lerp(goal, 0.34);
    const threeQuarter = start.clone().lerp(goal, 0.66);
    const lateral = Math.max(35, Math.min(220, distance * 0.16));
    const offsets = [0, lateral, -lateral, lateral * 1.8, -lateral * 1.8];
    for (let index = 0; index < offsets.length; index++) {
      if (token !== this.navigationBuildToken || performance.now() >= deadline) return null;
      const offset = offsets[index];
      const waypoints = offset === 0
        ? [start, goal]
        : [
            start,
            quarter.clone().addScaledVector(side, offset),
            threeQuarter.clone().addScaledVector(side, offset),
            goal
          ];
      const sliceStart = progressStart + (progressEnd - progressStart) * (index / offsets.length);
      const sliceEnd = progressStart + (progressEnd - progressStart) * ((index + 1) / offsets.length);
      const projected = await this.projectNavigationWaypoints(waypoints, token, progress, sliceStart, sliceEnd, deadline);
      if (projected?.length) return projected;
      await yieldToBrowser();
    }
    return null;
  }

  teleportToNavigationTarget(ground, label) {
    if (this.mode !== 'first') this.setMode('first');
    const spawn = ground.clone().add(new THREE.Vector3(0, this.fp.eyeHeight, 0));
    const forward = this.lastFirstPersonPose?.forward?.clone() || new THREE.Vector3(0, 0, -1);
    this.perspective.position.copy(spawn);
    this.perspective.up.set(0, 1, 0);
    this.perspective.lookAt(spawn.clone().add(forward));
    this.fp.activate(spawn);
    this.captureFirstPersonPose();
    this.updateCoordinateHud(true);
    this.drawMiniMap();
    this.setStatus(`Moved to ${label}. The selected beam and golden path remain visible.`, 0);
    this.requestRender();
  }

  async gotoNpcLabel(query, options = {}) {
    const needle = String(query || '').trim().toLowerCase();
    if (!needle || !this.gotoNpcEntries.length) {
      if (!options.exactOnly) this.setStatus('Enter an NPC or map-label name first.');
      return false;
    }
    let matches = this.gotoNpcEntries.filter(entry => entry.label.toLowerCase() === needle || entry.rawLabel.toLowerCase() === needle);
    if (options.exactOnly && !matches.length) return false;
    if (!matches.length) matches = this.gotoNpcEntries.filter(entry => entry.search.startsWith(needle));
    if (!matches.length) matches = this.gotoNpcEntries.filter(entry => entry.search.includes(needle));
    if (!matches.length) {
      if (!options.exactOnly) this.setStatus(`No loaded map label matches “${query}”.`);
      return false;
    }

    if (this.mapFileVisible) this.setGameMapVisible(false, false);
    const start = this.navigationStartPoint();
    if (this.mode !== 'first') this.setMode('first');
    matches.sort((left, right) => {
      const leftPosition = this.eqMapToThree(left.point.x, left.point.y, left.point.z);
      const rightPosition = this.eqMapToThree(right.point.x, right.point.y, right.point.z);
      return start.distanceToSquared(leftPosition) - start.distanceToSquared(rightPosition);
    });
    const selected = matches[0];
    const target = this.eqMapToThree(selected.point.x, selected.point.y, selected.point.z);
    const ground = this.findGroundPointAt(target.x, target.z, target.y + 35) || target.clone();
    const targetKey = `${selected.label}:${ground.x.toFixed(2)}:${ground.y.toFixed(2)}:${ground.z.toFixed(2)}`;
    if (this.navigationTarget?.key === targetKey) {
      if (options.teleport) this.teleportToNavigationTarget(ground, selected.label);
      return true;
    }
    this.clearNavigationGuide();
    const token = this.navigationBuildToken;
    this.navigationTarget = { key: targetKey, label: selected.label, point: target.clone(), ground: ground.clone(), requestStart: start.clone() };
    this.addNavigationBeam(ground, selected.label);
    this.lastPick = ground.clone();
    this.els.gotoNpc.value = selected.label;
    this.setNavigationBuildActive(true);
    this.setStatus(`Preparing a background path to ${selected.label} from your request position… You can keep moving while it calculates.`, 0.01);
    this.updateNavigationOverlay();
    this.requestRender();
    await nextBrowserFrame();

    const routePromise = this.findNavigationPath(this.navigationTarget.requestStart.clone(), ground, token);
    if (options.teleport) this.teleportToNavigationTarget(ground, selected.label);
    const route = await routePromise;
    if (token !== this.navigationBuildToken) return false;
    this.setNavigationBuildActive(false);
    if (route?.length >= 2) {
      this.addNavigationPath(route);
      const distance = route.slice(1).reduce((sum, point, index) => sum + point.distanceTo(route[index]), 0);
      const moved = options.teleport ? ' · camera moved to destination' : '';
      this.setStatus(`Path to ${selected.label} ready · ${Math.round(distance).toLocaleString()} units${moved}.`, 0);
    } else {
      const moved = options.teleport ? ' You were still moved to the marked location.' : '';
      this.setStatus(`The destination beam marks ${selected.label}, but no collision-valid grounded path could be built.${moved}`, 0);
    }
    this.updateNamedMobLabels();
    this.updateNavigationOverlay();
    this.requestRender();
    return true;
  }

  updateNamedMobLabels() {
    if (!this.namedMobLabels.length) return;
    const enabled = Boolean(this.els.namedMobs.checked && !this.mapFileVisible && this.zoneGroup.visible);
    const firstPerson = this.mode === 'first';
    const width = Math.max(1, this.els.viewport.clientWidth);
    const height = Math.max(1, this.els.viewport.clientHeight);
    const projected = new THREE.Vector3();
    const cameraPosition = this.camera.position;
    const candidates = [];
    // Labels inside this radius are never distance-, floor-, or overlap-culled
    // in First Person. They can still disappear when they are genuinely behind
    // the camera or outside the viewport.
    const nearbyAlwaysVisibleDistance = 300;

    for (const label of this.namedMobLabels) {
      const distance = cameraPosition.distanceTo(label.position);
      const nearby = firstPerson && distance <= nearbyAlwaysVisibleDistance;
      const distanceLimit = firstPerson ? Math.min(this.firstPersonViewDistance * 0.92, 1800) : Infinity;
      // Top Down is an informational map view: every on-screen named label must
      // remain visible regardless of selected floors, distance, or overlap.
      // Floor and distance filtering are only meaningful in First Person.
      const filteredByFloor = firstPerson && !nearby && !this.floorSelectionContainsY(label.eqZ);
      if (!enabled || (firstPerson && !nearby && distance > distanceLimit) || filteredByFloor) {
        label.element.hidden = true;
        label.element.classList.remove('is-targeted');
        continue;
      }
      projected.copy(label.position).project(this.camera);
      const visible = projected.z >= -1 && projected.z <= 1 && projected.x >= -1.05 && projected.x <= 1.05 && projected.y >= -1.05 && projected.y <= 1.05;
      if (!visible) {
        label.element.hidden = true;
        label.element.classList.remove('is-targeted');
        continue;
      }
      const x = (projected.x * 0.5 + 0.5) * width;
      const y = (-projected.y * 0.5 + 0.5) * height;
      const centerDistance = Math.hypot(x - width / 2, y - height / 2);
      const estimatedWidth = clamp(28 + label.title.length * 7.2, 72, 240);
      candidates.push({ label, distance, distanceLimit, nearby, x, y, centerDistance, estimatedWidth });
    }

    // First Person favors the crosshair and nearby labels. Top Down keeps every
    // on-screen label and only uses this ordering to put higher-value labels on
    // top when two labels physically overlap.
    candidates.sort((a, b) => firstPerson
      ? Number(b.nearby) - Number(a.nearby) || a.centerDistance - b.centerDistance || a.distance - b.distance
      : a.label.priority - b.label.priority || b.distance - a.distance || a.y - b.y);
    const occupied = [];
    let targeted = null;
    for (const candidate of candidates) {
      const { label, x, y, estimatedWidth, nearby } = candidate;
      const box = {
        left: x - estimatedWidth / 2 - 3,
        right: x + estimatedWidth / 2 + 3,
        top: y - 29,
        bottom: y + 3
      };
      const overlaps = occupied.some(other => !(box.right < other.left || box.left > other.right || box.bottom < other.top || box.top > other.bottom));
      // Never suppress labels in Top Down. In First Person, nearby labels also
      // remain visible even when they overlap another marker.
      const suppressForOverlap = firstPerson && !nearby && overlaps;
      if (suppressForOverlap) {
        label.element.hidden = true;
        label.element.classList.remove('is-targeted');
        continue;
      }
      occupied.push(box);
      label.element.hidden = false;
      label.element.style.transform = `translate(-50%, -100%) translate(${x.toFixed(1)}px, ${y.toFixed(1)}px)`;
      label.element.style.opacity = firstPerson && !nearby
        ? String(clamp(1.15 - candidate.distance / candidate.distanceLimit, 0.38, 1))
        : '1';
      label.element.style.zIndex = String(firstPerson
        ? (nearby ? 1000 : 100 + label.priority)
        : 100 + label.priority);
      label.element.classList.remove('is-targeted');
      if (!targeted && firstPerson && candidate.centerDistance <= 54) targeted = label;
    }
    this.targetedMobLabel = targeted;
    if (targeted) targeted.element.classList.add('is-targeted');
  }

  updateCoordinateHud(force = false, now = performance.now()) {
    if (!this.els?.coord || (!force && now - this.lastCoordinateUpdate < 80)) return;
    this.lastCoordinateUpdate = now;
    let point = null;
    if (this.mode === 'first' && !this.mapFileVisible) {
      point = this.perspective.position.clone();
      point.y -= this.fp.eyeHeight;
    } else if (this.orbit?.target) {
      point = this.orbit.target.clone();
    } else if (this.lastPick) {
      point = this.lastPick.clone();
    }
    if (!point) {
      this.els.coord.textContent = 'X —  Y —  Z —';
      return;
    }
    const eq = this.threeToEq(point);
    this.els.coord.textContent = `X ${eq.x.toFixed(2)}   Y ${eq.y.toFixed(2)}   Z ${eq.z.toFixed(2)}`;
  }

  captureCameraState(target = this.orbit?.target) {
    return {
      position: this.perspective.position.clone(),
      quaternion: this.perspective.quaternion.clone(),
      up: this.perspective.up.clone(),
      target: target?.clone?.() || this.getTopFitBounds().getCenter(new THREE.Vector3()),
      near: this.perspective.near,
      far: this.perspective.far
    };
  }

  restoreCameraState(state) {
    if (!state) return false;
    this.perspective.position.copy(state.position);
    this.perspective.quaternion.copy(state.quaternion);
    this.perspective.up.copy(state.up || new THREE.Vector3(0, 0, -1));
    if (Number.isFinite(state.near)) this.perspective.near = state.near;
    if (Number.isFinite(state.far)) this.perspective.far = state.far;
    this.perspective.updateProjectionMatrix();
    if (this.orbit?.target && state.target) this.orbit.target.copy(state.target);
    this.orbit?.update();
    return true;
  }

  getTopFitBounds() {
    if (this.topFitBounds?.isEmpty?.() === false) return this.topFitBounds;
    if (this.terrainBounds?.isEmpty?.() === false) return this.terrainBounds;
    return this.currentBounds;
  }

  toggleGameMap(force) {
    if (!this.mapData?.lines?.length) {
      this.setStatus('No matching map file was found under the selected game folder’s maps directory.');
      return;
    }

    if (force === true) {
      this.setMiniMapVisible(false);
      this.setGameMapVisible(true);
      return;
    }
    if (force === false) {
      if (this.mapFileVisible) this.setGameMapVisible(false);
      this.setMiniMapVisible(false);
      return;
    }

    // M cycles Closed -> Full map -> Minimap -> Closed.
    if (this.mapFileVisible) {
      this.setGameMapVisible(false);
      this.setMiniMapVisible(true);
    } else if (this.miniMapVisible) {
      this.setMiniMapVisible(false);
      this.setStatus('Local map closed.');
    } else {
      this.setGameMapVisible(true);
    }
  }

  setMiniMapVisible(visible) {
    const nextVisible = Boolean(visible && this.mapData?.lines?.length && !this.mapFileVisible);
    this.miniMapVisible = nextVisible;
    this.els.miniMap.hidden = !nextVisible;
    if (nextVisible) {
      this.drawMiniMap();
      this.setStatus('Showing the local minimap. Press M to close it.');
    } else {
      this.clearMiniMap();
    }
    this.updateControlHint();
    this.requestRender();
  }

  setGameMapVisible(visible) {
    const nextVisible = Boolean(visible && this.mapData?.lines?.length);
    if (nextVisible === this.mapFileVisible) return;

    if (nextVisible) {
      this.setMiniMapVisible(false);
      this.modeBeforeMap = this.mode;
      const priorTarget = this.orbit?.target?.clone?.() || this.getTopFitBounds().getCenter(new THREE.Vector3());

      if (this.mode === 'first') {
        this.captureFirstPersonPose();
        this.preMapCameraState = this.captureCameraState(priorTarget);
      } else {
        this.savedTopCameraState = this.captureCameraState(priorTarget);
      }

      this.fp.deactivate();
      this.orbit?.dispose();
      this.orbit = null;
      this.mode = 'top';
      this.camera = this.perspective;
      this.mapFileVisible = true;
      this.zoneGroup.visible = false;
      this.propsGroup.visible = false;
      this.mapGroup.visible = true;
      this.scene.fog = null;
      this.renderer.setClearColor(0xe7e2d8, 1);
      this.scene.background.set(0xe7e2d8);
      this.els.firstPersonPrompt.hidden = true;

      this.orbit = this.createOrbitControls(this.perspective, true);
      if (this.modeBeforeMap === 'first') {
        if (!this.restoreCameraState(this.savedFullMapCameraState || this.savedTopCameraState)) {
          this.resetTopView();
        }
      } else {
        this.restoreCameraState(this.savedTopCameraState);
      }
      this.setStatus(`Showing the local map aligned to Top Down from ${this.mapData.sourceLabel}. Press M for the minimap.`);
    } else {
      const fullMapState = this.captureCameraState();
      this.savedFullMapCameraState = fullMapState;
      this.mapFileVisible = false;
      this.clearMapLabels();
      this.mapGroup.visible = false;
      this.zoneGroup.visible = true;
      this.propsGroup.visible = this.els.props.checked;
      this.renderer.setClearColor(0x08101b, 1);
      this.scene.background.set(0x08101b);
      const restoreMode = this.modeBeforeMap === 'first' ? 'first' : 'top';
      this.configureModeControls(restoreMode);
      if (restoreMode === 'first' && this.preMapCameraState) {
        this.restoreCameraState(this.preMapCameraState);
        this.fp.activate(this.perspective.position);
        this.captureFirstPersonPose();
      } else {
        // Panning and zooming the full map becomes the restored Top Down view,
        // so closing the map never jumps back to a different zoom level.
        this.restoreCameraState(fullMapState);
        this.savedTopCameraState = this.captureCameraState();
      }
      this.updateFirstPersonEnvironment(true);
    }
    this.els.top.classList.toggle('is-active', !this.mapFileVisible && this.mode === 'top');
    this.els.first.classList.toggle('is-active', !this.mapFileVisible && this.mode === 'first');
    this.updateCoordinateHud(true);
    this.updateNamedMobLabels();
    this.updateControlHint();
    this.requestRender();
  }

  toggleFly(force) {
    if (!this.fp || this.els.fly.disabled) return;
    const enabled = force === undefined ? !this.fp.fly : Boolean(force);
    this.fp.setFly(enabled);
    this.els.fly.textContent = enabled ? 'Fly' : 'Grounded';
    this.els.fly.classList.toggle('is-active', enabled);
    this.updateFirstPersonPrompt();
    this.updateControlHint();
    this.setStatus(enabled
      ? 'Flight enabled. Forward/backward movement follows mouse pitch.'
      : 'Grounded walking enabled. The camera follows the floor automatically.');
  }

  resetView() {
    if (this.worldMapVisible) {
      this.resetWorldMapView();
      return;
    }
    if (this.mapFileVisible) {
      this.resetTopView();
      this.drawMapLabels();
      return;
    }
    if (this.mode !== 'top') this.configureModeControls('top');
    this.resetTopView();
    this.updateNamedMobLabels();
  }

  fitMapView() {
    if (!this.mapData?.lines?.length || this.mapBounds.isEmpty()) return;
    const center = this.mapBounds.getCenter(new THREE.Vector3());
    const size = this.mapBounds.getSize(new THREE.Vector3());
    const aspect = Math.max(0.25, this.els.viewport.clientWidth / Math.max(1, this.els.viewport.clientHeight));
    const half = Math.max(size.x / Math.max(1, aspect), size.z) * 0.56 + 20;
    this.orthographic.left = -half * aspect;
    this.orthographic.right = half * aspect;
    this.orthographic.top = half;
    this.orthographic.bottom = -half;
    this.orthographic.position.set(center.x, this.mapBounds.max.y + Math.max(100, size.y + 100), center.z);
    this.orthographic.up.set(0, 0, -1);
    this.orthographic.lookAt(center);
    this.orthographic.updateProjectionMatrix();
    this.orbit?.target.copy(center);
    this.orbit?.update();
    this.updateCoordinateHud(true);
    this.requestRender();
  }

  detectFloorLevels() {
    this.floorLevels = this.occlusionDerivedFloorLevels();
    this.floorLevelSource = 'occlusion';
    this.selectedFloorIndices.clear();
    if (this.floorLevels.length < 2) {
      this.els.floorSummary.textContent = 'All floors';
      this.els.floorPanel.innerHTML = '<span class="eqlzv-floor-empty">No overlapping floors detected</span>';
      this.els.floorPicker.dataset.disabled = '1';
      this.els.floorPicker.removeAttribute('open');
    } else {
      this.els.floorPicker.dataset.disabled = '0';
      this.els.floorSummary.textContent = 'All floors';
      this.els.floorPanel.innerHTML = this.floorLevels.map((level, index) =>
        `<label class="eqlzv-check eqlzv-floor-option"><input type="checkbox" data-eql-floor-index="${index}">` +
        `<span>${escapeHtml(level.label)}</span><small>Z ${level.value.toFixed(1)}</small></label>`
      ).join('');
    }
    this.updateFloorBandUniforms();
  }

  applyFloorSelection() {
    this.selectedFloorIndices.clear();
    for (const input of this.els.floorPanel.querySelectorAll('[data-eql-floor-index]:checked')) {
      const index = Number(input.dataset.eqlFloorIndex);
      if (Number.isInteger(index) && this.floorLevels[index]) this.selectedFloorIndices.add(index);
    }
    if (!this.selectedFloorIndices.size) this.els.floorSummary.textContent = 'All floors';
    else if (this.selectedFloorIndices.size === 1) {
      const index = [...this.selectedFloorIndices][0];
      this.els.floorSummary.textContent = this.floorLevels[index]?.label || '1 floor';
    } else this.els.floorSummary.textContent = `${this.selectedFloorIndices.size} floors`;
    this.updateFloorBandUniforms();
    this.updateNamedMobLabels();
    this.requestRender();
  }

  floorSelectionContainsY(y) {
    const bands = this.selectedFloorBands();
    return !bands.length || bands.some(([minimum, maximum]) => y >= minimum && y <= maximum);
  }

  configureClipSlider() {
    const min = this.currentBounds.min.y, max = this.currentBounds.max.y;
    this.els.clip.min = String(min);
    this.els.clip.max = String(max + Math.max(1, max - min) * 0.05);
    this.els.clip.step = String(Math.max(0.1, (max - min) / 500));
    this.els.clip.value = this.els.clip.max;
    this.els.clip.disabled = false;
    this.updateClipping();
  }

  updateClipping() {
    const max = Number(this.els.clip.max), value = Number(this.els.clip.value);
    const off = value >= max - Number(this.els.clip.step || 1);
    this.els.clipValue.textContent = off ? 'Off' : `Z ${value.toFixed(1)}`;
    const planes = off ? [] : [new THREE.Plane(new THREE.Vector3(0, -1, 0), value)];
    for (const mat of [...this.loadedMaterials, ...this.mapMaterials]) { mat.clippingPlanes = planes; mat.clipShadows = false; mat.needsUpdate = true; }
    this.requestRender();
  }

  configureModeControls(mode) {
    this.mode = mode === 'first' ? 'first' : 'top';
    this.els.top.classList.toggle('is-active', this.mode === 'top' && !this.mapFileVisible);
    this.els.first.classList.toggle('is-active', this.mode === 'first' && !this.mapFileVisible);
    this.fp.deactivate();
    this.orbit?.dispose();
    this.orbit = null;
    this.camera = this.perspective;
    if (this.mode === 'top') {
      this.orbit = this.createOrbitControls(this.perspective, false);
      this.els.firstPersonPrompt.hidden = true;
    } else {
      this.updateFirstPersonPrompt();
      this.els.firstPersonPrompt.hidden = false;
    }
    this.updateFirstPersonEnvironment(true);
    this.updateControlHint();
  }

  setMode(mode) {
    if (!this.zoneGroup.children.length || this.els.top.disabled) return;
    if (this.mapFileVisible) this.setGameMapVisible(false);
    const requested = mode === 'first' ? 'first' : 'top';
    const previous = this.mode;
    if (requested === 'first' && previous === 'top' && this.orbit) {
      this.savedTopCameraState = this.captureCameraState();
    }
    this.configureModeControls(requested);
    if (requested === 'first') {
      const spawn = this.findFirstPersonSpawn();
      const center = this.getTopFitBounds().getCenter(new THREE.Vector3());
      this.camera.position.copy(spawn);
      this.camera.up.set(0, 1, 0);
      this.camera.lookAt(center.x, spawn.y, center.z);
      this.fp.activate(spawn);
      this.captureFirstPersonPose();
      this.updateCoordinateHud(true);
    } else if (!this.restoreCameraState(this.savedTopCameraState)) {
      this.resetTopView();
    }
    this.resize();
    this.updateNamedMobLabels();
    this.drawMiniMap();
    this.requestRender();
  }

  resetTopView() {
    if (!this.currentBounds || !this.zoneGroup.children.length) return;
    const fitBounds = this.getTopFitBounds();
    const center = fitBounds.getCenter(new THREE.Vector3());
    const size = fitBounds.getSize(new THREE.Vector3());
    const aspect = Math.max(0.25, this.els.viewport.clientWidth / Math.max(1, this.els.viewport.clientHeight));
    const halfFov = THREE.MathUtils.degToRad(this.perspective.fov * 0.5);
    const tan = Math.max(0.01, Math.tan(halfFov));
    const distanceForHeight = Math.max(size.z, 10) * 0.5 / tan;
    const distanceForWidth = Math.max(size.x, 10) * 0.5 / (tan * aspect);
    const horizontalFitDistance = Math.max(distanceForHeight, distanceForWidth, 50) * 1.055;
    const verticalClearance = Math.max(50, fitBounds.max.y - center.y + 35);
    const distance = Math.max(horizontalFitDistance, verticalClearance);
    this.perspective.near = Math.max(0.1, distance / 10000);
    this.perspective.far = Math.max(200000, distance * 8);
    this.perspective.position.set(center.x, center.y + distance, center.z);
    this.perspective.up.set(0, 0, -1);
    this.perspective.lookAt(center);
    this.perspective.updateProjectionMatrix();
    if (this.orbit) {
      this.orbit.target.copy(center);
      this.orbit.minDistance = Math.max(1, distance * 0.01);
      this.orbit.maxDistance = Math.max(distance * 5, 1000);
      this.orbit.update();
    }
    const state = this.captureCameraState(center);
    if (this.mapFileVisible) this.savedFullMapCameraState = state;
    else this.savedTopCameraState = state;
    this.updateCoordinateHud(true);
    this.drawMiniMap();
    this.requestRender();
  }

  findGroundPointAt(x, z, preferredY = null) {
    const colliders = this.fp?.colliders || [];
    if (!colliders.length) return null;
    const local = this.fp?.surfaceCandidatesAt(x, z) || [];
    const candidates = local.length ? local : colliders;
    const top = Math.max(this.currentBounds.max.y + 1000, Number(preferredY) || -Infinity);
    const raycaster = new THREE.Raycaster(new THREE.Vector3(x, top, z), new THREE.Vector3(0, -1, 0), 0, Math.max(2000, top - this.currentBounds.min.y + 2000));
    const hits = raycaster.intersectObjects(candidates, false);
    if (!hits.length) return null;
    if (Number.isFinite(preferredY)) {
      const below = hits.find(hit => hit.point.y <= preferredY + this.fp.maxStepUp);
      if (below) return below.point.clone();
    }
    return hits[0].point.clone();
  }

  findFirstPersonSpawn() {
    if (this.lastPick) {
      const ground = this.findGroundPointAt(this.lastPick.x, this.lastPick.z, this.lastPick.y + 20) || this.lastPick.clone();
      return ground.add(new THREE.Vector3(0, this.fp.eyeHeight, 0));
    }

    const candidates = [eqToThree(0, 0, 0)];
    if (this.mapData) {
      let nearest = null;
      let nearestDistance = Infinity;
      const consider = (x, y, z) => {
        const distance = x * x + y * y;
        if (distance < nearestDistance) {
          nearestDistance = distance;
          nearest = eqToThree(x, y, z);
        }
      };
      for (const point of this.mapData.points || []) consider(point.x, point.y, point.z);
      for (const line of this.mapData.lines || []) {
        consider(line.x1, line.y1, line.z1);
        consider(line.x2, line.y2, line.z2);
      }
      if (nearest) candidates.push(nearest);
    }
    candidates.push(this.currentBounds.getCenter(new THREE.Vector3()));

    for (const candidate of candidates) {
      const ground = this.findGroundPointAt(candidate.x, candidate.z, candidate.y + 100);
      if (ground) return ground.add(new THREE.Vector3(0, this.fp.eyeHeight, 0));
    }
    const center = this.currentBounds.getCenter(new THREE.Vector3());
    return new THREE.Vector3(center.x, Math.max(this.currentBounds.min.y + this.fp.eyeHeight, center.y), center.z);
  }

  fitView() {
    if (this.mode === 'top') this.resetTopView();
    else if (this.mode === 'first') {
      const spawn = this.findFirstPersonSpawn();
      this.camera.position.copy(spawn);
      this.fp.activate(spawn);
      this.captureFirstPersonPose();
      this.updateCoordinateHud(true);
    }
  }

  pickCoordinate(event) {
    const rect = this.els.canvas.getBoundingClientRect();
    this.pointer.x = ((event.clientX - rect.left) / rect.width) * 2 - 1;
    this.pointer.y = -((event.clientY - rect.top) / rect.height) * 2 + 1;
    this.raycaster.setFromCamera(this.pointer, this.camera);

    if (this.mapFileVisible) {
      const selectedIndex = this.selectedFloorIndices.size === 1 ? [...this.selectedFloorIndices][0] : null;
      const selectedFloor = selectedIndex !== null
        ? this.floorLevels[selectedIndex].value
        : this.mapBounds.getCenter(new THREE.Vector3()).y;
      const point = new THREE.Vector3();
      const plane = new THREE.Plane(new THREE.Vector3(0, 1, 0), -selectedFloor);
      if (!this.raycaster.ray.intersectPlane(plane, point)) return;
      const eq = this.threeToEq(point);
      this.lastPick = point.clone();
      this.els.coord.textContent = `X ${eq.x.toFixed(2)}   Y ${eq.y.toFixed(2)}   Z ${eq.z.toFixed(2)}`;
      return;
    }

    const targets = this.propsGroup.visible
      ? this.pickTargets
      : this.pickTargets.filter(target => !target.userData.eqlProp);
    const hit = this.raycaster.intersectObjects(targets, false)[0];
    if (!hit) return;
    const p = hit.point;
    const eq = this.threeToEq(p);
    this.lastPick = p.clone();
    this.els.coord.textContent = `X ${eq.x.toFixed(2)}   Y ${eq.y.toFixed(2)}   Z ${eq.z.toFixed(2)}`;
  }

  resize() {
    const width = Math.max(1, this.els.viewport.clientWidth);
    const height = Math.max(1, this.els.viewport.clientHeight);
    this.renderer.setSize(width, height, false);
    this.perspective.aspect = width / height; this.perspective.updateProjectionMatrix();
    this.orbit?.handleResize?.();
    if (this.mapFileVisible) this.drawMapLabels();
    if (this.miniMapVisible) this.drawMiniMap();
    if (this.worldMapVisible) this.drawWorldMap();
    this.updateNavigationOverlay();
    this.updateLocationPillarHover();
    this.requestRender();
  }

  animate(now = performance.now()) {
    requestAnimationFrame(t => this.animate(t));
    if (this.worldMapVisible) {
      this.lastTime = now;
      if (this.renderRequested) {
        this.renderRequested = false;
        this.drawWorldMap();
      }
      return;
    }
    if (this.benchmarkUntil > 0 && now >= this.benchmarkUntil) this.finishBenchmark();

    const continuous = (!this.mapFileVisible && this.mode === 'first') || this.benchmarkUntil > 0;
    if (continuous) {
      const elapsedSinceRender = this.lastRenderedAt > 0 ? now - this.lastRenderedAt : Infinity;
      if (elapsedSinceRender + 0.5 < this.targetFrameInterval) return;
      const dt = Math.min(0.1, Math.max(0, (now - this.lastTime) / 1000));
      this.lastTime = now;
      if (!this.mapFileVisible && this.mode === 'first') {
        this.fp.update(dt);
        this.captureFirstPersonPose();
        this.updateFirstPersonEnvironment(false, now);
        this.updateCoordinateHud(false, now);
        this.updateNamedMobLabels();
        this.updateNavigationOverlay();
        this.updateLocationPillarHover();
        if (this.miniMapVisible) this.drawMiniMap();
      }
      this.renderFrame(now);
      return;
    }

    this.lastTime = now;
    if (this.orbit?.enabled) this.orbit.update();
    if (this.renderRequested) this.renderFrame(now);
  }

  renderFrame(now) {
    this.renderRequested = false;
    if (this.worldMapVisible) {
      this.drawWorldMap();
      return;
    }
    const previousRenderedAt = this.lastRenderedAt;
    const renderStartedAt = performance.now();
    this.renderer.render(this.scene, this.camera);
    if (this.mapFileVisible) this.drawMapLabels();
    else {
      this.clearMapLabels();
      this.updateNamedMobLabels();
      if (this.miniMapVisible) this.drawMiniMap();
    }
    this.updateNavigationOverlay();
    this.updateLocationPillarHover();
    this.lastRenderedAt = now;
    this.recordRenderedFrame(now, renderStartedAt, previousRenderedAt);
  }
  requestRender() { this.renderRequested = true; }

  setStatus(message, value = null) {
    this.els.statusText.textContent = message;
    if (value === null) this.els.progress.removeAttribute('value');
    else this.els.progress.value = clamp(Number(value) || 0, 0, 1);
  }
  showError(error) {
    console.error('[EQLZoneViewer]', error);
    const message = error?.message || String(error);
    this.setStatus(`Error: ${message}`, 0);
    this.els.overlay.hidden = false;
    this.els.overlay.innerHTML = `<div class="eqlzv-error"><strong>Unable to complete the request</strong><span>${escapeHtml(message)}</span><small>Open the browser console for technical details.</small></div>`;
  }
}

export function mountZoneViewer(root, config = {}) {
  if (!root || root.dataset.eqlzvMounted === '1') return null;
  root.dataset.eqlzvMounted = '1';
  return new ZoneViewer(root, config);
}
