/**
 * WTT tree-split prototype — in-memory Node model.
 * Shape mirrors planning Node: { id, parentId, name, position, description? }
 *
 * Three demo Projects (switcher):
 *   - Template (nur lesen)
 *   - Composition Simples (editierbar) — Zusammenstellung nur mit Simple-Typen
 *   - BOM Testprojekt (editierbar) — später: quantity / enum / Bauteil-Ref
 *
 * Collection (Q52): enum is created like list — one typed column + (for enum) closed options.
 *
 * Sibling order (Q13): explicit `position`.
 * Edges: { id, from, to, label, props? } — multiplikator carries props.value (int).
 */

const STORAGE_KEY = "wtt-proto-tree-split-v17";
const TABLE_BODY_ROWS = 5;
const PROJECT_KIND_TEMPLATE = "template";
const PROJECT_KIND_COMPOSITION_SIMPLES = "composition-simples";
const PROJECT_KIND_BOM_TEST = "bom-test";
const RIGHT_TABS = ["node", "relations", "table", "table2", "form", "convert"];
const TAB_ARIA = {
  node: "tab-node",
  relations: "tab-relations",
  table: "tab-table",
  table2: "tab-table2",
  form: "tab-form",
  convert: "tab-convert",
};
const REL_HAS_TYPE = "has_type";
/** @deprecated Collection spin: prefer column ─[has_type]→ element type */
const REL_BASE_TYPE = "base_type";
const REL_ALLOWS_PREFIX = "allows_prefix";
/** Präfix ─[multiplikator]→ int, props.value = scale factor */
const REL_MULTIPLIKATOR = "multiplikator";
const SIMPLE_TYPE_NAMES = ["int", "double", "string", "char", "bool"];
const COLLECTION_KIND_NAMES = ["list", "table", "enum"];
const QTY_SEP = "|";
const EDGE_LABEL_SUGGESTIONS = [
  REL_HAS_TYPE,
  REL_BASE_TYPE,
  REL_ALLOWS_PREFIX,
  REL_MULTIPLIKATOR,
  "references",
  "part_of",
  "depends_on",
  "relates_to",
];
/** Fallback SI factors when multiplikator edge missing */
const DEFAULT_PREFIX_FACTORS = {
  p: 1e-12,
  n: 1e-9,
  µ: 1e-6,
  m: 1e-3,
  c: 1e-2,
  k: 1e3,
  M: 1e6,
};

/**
 * @typedef {{ id: string, parentId: string|null, name: string, position: number, description?: string, template?: boolean }} ProtoNode
 */
/**
 * @typedef {{
 *   id: string,
 *   name: string,
 *   description: string,
 *   kind: 'template'|'composition-simples'|'bom-test',
 *   rootId: string,
 *   dataTypesRootId: string,
 *   prefixesRootId: string,
 *   baseUnitsRootId: string,
 * }} ProtoProject
 */
/** @typedef {'node'|'relations'|'table'|'table2'|'form'|'convert'} RightTab */
/**
 * @typedef {{ id: string, from: string, to: string, label: string, props?: { value?: string|number } }} ProtoEdge
 */
/**
 * @typedef {{ leftValue: string, leftKey: string, rightKey: string }} ConvertState
 */
/**
 * @typedef {{
 *   select: string,
 *   radio: string,
 *   checks: string[],
 *   selectMulti: string[],
 *   toggle: boolean,
 *   text: string,
 *   textarea: string,
 *   number: string,
 *   range: number,
 *   color: string,
 *   date: string,
 *   time: string,
 *   email: string,
 *   url: string,
 *   datalist: string,
 * }} FormState
 */

/** @type {Map<string, ProtoNode>} */
let nodes = new Map();
/** @type {ProtoProject[]} */
let projects = [];
let activeProjectId = "";
let rootId = "";
let selectedId = "";
/** @type {Set<string>} */
let collapsed = new Set();
let seq = 1;
/** @type {RightTab} */
let activeTab = "node";
/** @type {Map<string, string[][]>} */
let tableCells = new Map();
/** @type {Map<string, string[][]>} */
let tableCells2 = new Map();
/** @type {Map<string, FormState>} */
let formStates = new Map();
/** @type {ProtoEdge[]} */
let edges = [];
/** @type {Map<string, ConvertState>} */
let convertStates = new Map();
let dataTypesRootId = "";
let prefixesRootId = "";
let baseUnitsRootId = "";

function uid() {
  return `n_${Date.now().toString(36)}_${Math.random().toString(36).slice(2, 7)}`;
}

function childrenOf(parentId) {
  return [...nodes.values()]
    .filter((n) => n.parentId === parentId)
    .sort((a, b) => {
      if (a.position !== b.position) return a.position - b.position;
      return a.name.localeCompare(b.name, "de");
    });
}

function reindexSiblings(parentId) {
  const kids = childrenOf(parentId);
  kids.forEach((k, i) => {
    k.position = i;
  });
}

function nextPosition(parentId) {
  return childrenOf(parentId).length;
}

function createNode(parentId, name, position, opts = {}) {
  const id = uid();
  /** @type {ProtoNode} */
  const node = { id, parentId, name, position, description: opts.description || "" };
  if (opts.template) node.template = true;
  nodes.set(id, node);
  return id;
}

function defaultFormState() {
  return {
    select: "",
    radio: "",
    checks: [],
    selectMulti: [],
    toggle: false,
    text: "",
    textarea: "",
    number: "",
    range: 50,
    color: "#c4a35a",
    date: "",
    time: "",
    email: "",
    url: "",
    datalist: "",
  };
}

function ensureFormState(nodeId) {
  let state = formStates.get(nodeId);
  if (!state) {
    state = defaultFormState();
    formStates.set(nodeId, state);
  }
  return state;
}

function pushEdge(from, to, label, props) {
  if (!nodes.has(from) || !nodes.has(to) || from === to) return null;
  const l = (label || "").trim() || "relates_to";
  if (edges.some((e) => e.from === from && e.to === to && e.label === l)) {
    return edges.find((e) => e.from === from && e.to === to && e.label === l);
  }
  /** @type {ProtoEdge} */
  const e = { id: uid(), from, to, label: l };
  if (props && typeof props === "object") e.props = { ...props };
  edges.push(e);
  return e;
}

/**
 * Pure template core under a project root.
 * Root children are only **Typen** and **Compositionen**; everything else hangs under those.
 * @param {string} projectRootId
 * @param {{ markTemplate?: boolean }} [opts]
 */
function seedTemplateCore(projectRootId, opts = {}) {
  const mark = opts.markTemplate !== false;

  const typesRootId = createNode(projectRootId, "Typen", 0, {
    template: mark,
    description: "Alle Typ-Definitionen: Datentypen, Präfixe, Basiseinheiten.",
  });
  const compositionsRootId = createNode(projectRootId, "Compositionen", 1, {
    template: mark,
    description: "Zusammenstellungen (Composition-Definitionen und -Instanzen).",
  });

  const dataTypesRootId = createNode(typesRootId, "Datentypen", 0, {
    template: mark,
    description: "Simple Typen, quantity und Collection-Kinds (list/table/enum).",
  });
  const tInt = createNode(dataTypesRootId, "int", 0, {
    description: "Ganze Zahl.",
  });
  const tDouble = createNode(dataTypesRootId, "double", 1, {
    description: "Gleitkommazahl.",
  });
  const tString = createNode(dataTypesRootId, "string", 2, {
    description: "Freitext.",
  });
  const tChar = createNode(dataTypesRootId, "char", 3, {
    description: "Einzelnes Zeichen.",
  });
  const tBool = createNode(dataTypesRootId, "bool", 4, {
    description: "Wahrheitswert true/false.",
  });
  const tQuantity = createNode(dataTypesRootId, "quantity", 5, {
    description: "Größe: Wert + optional Präfix + Basiseinheit (nicht Messung).",
  });

  const collectionId = createNode(dataTypesRootId, "Collection", 6, {
    template: mark,
    description: "Oberbegriff: list (1 Spalte), table (n Spalten), enum (geschlossene list).",
  });
  const tList = createNode(collectionId, "list", 0, {
    description: "Collection mit genau einer Spalte; Zeilen offen erweiterbar.",
  });
  const tTable = createNode(collectionId, "table", 1, {
    description: "Collection mit n Spalten; Zeilen offen erweiterbar.",
  });
  const tEnum = createNode(collectionId, "enum", 2, {
    description:
      "Wie list anlegen (1 typisierte Spalte); Optionen fest unter der Spalte — nicht erweiterbar beim Ausfüllen.",
  });

  const prefixesRootId = createNode(typesRootId, "Präfix", 1, {
    template: mark,
    description: "SI-Präfixe; Multiplikator via Relation multiplikator → int.",
  });
  const prefixDefs = [
    ["p", 0, 1e-12, "Piko (10⁻¹²)."],
    ["n", 1, 1e-9, "Nano (10⁻⁹)."],
    ["µ", 2, 1e-6, "Mikro (10⁻⁶)."],
    ["m", 3, 1e-3, "Milli (10⁻³)."],
    ["c", 4, 1e-2, "Zenti (10⁻²)."],
    ["k", 5, 1e3, "Kilo (10³)."],
    ["M", 6, 1e6, "Mega (10⁶)."],
  ];
  /** @type {Record<string, string>} */
  const pref = {};
  for (const [name, pos, factor, desc] of prefixDefs) {
    const id = createNode(prefixesRootId, name, pos, { description: desc });
    pref[name] = id;
    pushEdge(id, tInt, REL_MULTIPLIKATOR, { value: factor });
  }

  const baseUnitsRootId = createNode(typesRootId, "Basiseinheit", 2, {
    template: mark,
    description:
      "Standard-Basiseinheiten im Template. Domäneneinheiten (Ohm, Farad, …) gehören ins Testprojekt.",
  });
  const uMeter = createNode(baseUnitsRootId, "Meter", 0, {
    description: "Länge.",
  });
  const uLiter = createNode(baseUnitsRootId, "Liter", 1, {
    description: "Volumen.",
  });
  const uKilogramm = createNode(baseUnitsRootId, "Kilogramm", 2, {
    description: "Masse (SI-Basiseinheit).",
  });
  const uSekunde = createNode(baseUnitsRootId, "Sekunde", 3, {
    description: "Zeit.",
  });
  const uKelvin = createNode(baseUnitsRootId, "Kelvin", 4, {
    description: "Thermodynamische Temperatur.",
  });
  const uAmpere = createNode(baseUnitsRootId, "Ampere", 5, {
    description: "Elektrische Stromstärke (SI; allgemein, nicht BOM-spezifisch).",
  });

  for (const name of ["µ", "m", "c", "k"]) {
    pushEdge(uMeter, pref[name], REL_ALLOWS_PREFIX);
  }
  for (const name of ["µ", "m", "c", "k"]) {
    pushEdge(uLiter, pref[name], REL_ALLOWS_PREFIX);
  }
  for (const name of ["m", "µ"]) {
    pushEdge(uKilogramm, pref[name], REL_ALLOWS_PREFIX);
  }
  for (const name of ["n", "µ", "m"]) {
    pushEdge(uSekunde, pref[name], REL_ALLOWS_PREFIX);
  }
  for (const name of ["m", "µ", "n", "k"]) {
    pushEdge(uAmpere, pref[name], REL_ALLOWS_PREFIX);
  }
  void uKelvin;

  return {
    typesRootId,
    compositionsRootId,
    dataTypesRootId,
    prefixesRootId,
    baseUnitsRootId,
    collectionId,
    pref,
    types: {
      tInt,
      tDouble,
      tString,
      tChar,
      tBool,
      tQuantity,
      tList,
      tTable,
      tEnum,
    },
  };
}

/**
 * Phase-1 Composition demo under Compositionen: columns = simple types only.
 * @param {string} compositionsRootId
 * @param {ReturnType<typeof seedTemplateCore>} core
 * @returns {string} composition node id
 */
function seedCompositionSimplesDemo(compositionsRootId, core) {
  const { types } = core;
  const compId = createNode(compositionsRootId, "Rezept — Backzutaten", 0, {
    description:
      "Composition-Demo Phase 1 (Simples): Rezept-Zusammenstellung nur mit simple Spaltentypen.",
  });
  const cName = createNode(compId, "Bezeichnung", 0, {
    description: "Spalte → string",
  });
  const cCount = createNode(compId, "Anzahl", 1, {
    description: "Spalte → int (hier: Mengenangabe roh, noch ohne quantity)",
  });
  const cActive = createNode(compId, "Aktiv", 2, {
    description: "Spalte → bool",
  });
  const cCode = createNode(compId, "Code", 3, {
    description: "Spalte → char",
  });
  const cFactor = createNode(compId, "Faktor", 4, {
    description: "Spalte → double",
  });
  pushEdge(cName, types.tString, REL_HAS_TYPE);
  pushEdge(cCount, types.tInt, REL_HAS_TYPE);
  pushEdge(cActive, types.tBool, REL_HAS_TYPE);
  pushEdge(cCode, types.tChar, REL_HAS_TYPE);
  pushEdge(cFactor, types.tDouble, REL_HAS_TYPE);

  tableCells.set(compId, [
    ["Mehl", "200", "true", "M", "1"],
    ["Zucker", "50", "true", "Z", "0.5"],
    ["Salz", "5", "false", "S", "0.1"],
    ["", "", "", "", ""],
    ["", "", "", "", ""],
  ]);

  return compId;
}

/**
 * BOM demo extras under Compositionen (+ electronics units under Typen/Basiseinheit).
 * @param {string} compositionsRootId
 * @param {ReturnType<typeof seedTemplateCore>} core
 */
function seedBomTestData(compositionsRootId, core) {
  const { types, pref, baseUnitsRootId } = core;

  // enum like list: Bauart → Option ─[has_type]→ string → closed options
  const bauart = createNode(types.tEnum, "Bauart", 0, {
    description: "Konkretes enum (wie list): eine Spalte + feste Optionen.",
  });
  const bauartCol = createNode(bauart, "Option", 0, {
    description: "Einzige Spalte der enum-Collection.",
  });
  pushEdge(bauartCol, types.tString, REL_HAS_TYPE);
  for (const [i, name] of ["0201", "0402", "0603", "0805", "axial"].entries()) {
    createNode(bauartCol, name, i, { description: `Bauart-Option ${name}.` });
  }

  // open list: RefDes → Element ─[has_type]→ string (no fixed children)
  const refDes = createNode(types.tList, "RefDes", 0, {
    description: "Offene list für Board-Referenzen (R1, R2, …).",
  });
  const refCol = createNode(refDes, "Element", 0, {
    description: "Einzige Spalte der list-Collection.",
  });
  pushEdge(refCol, types.tString, REL_HAS_TYPE);

  // Electronics units (BOM-specific) stay under Typen → Basiseinheit
  const nextPos = childrenOf(baseUnitsRootId).length;
  const uOhm = createNode(baseUnitsRootId, "Ohm", nextPos, {
    description: "BOM: Widerstand.",
  });
  const uFarad = createNode(baseUnitsRootId, "Farad", nextPos + 1, {
    description: "BOM: Kapazität — typisch p/n/µ/m, kein k/M.",
  });
  const uWatt = createNode(baseUnitsRootId, "Watt", nextPos + 2, {
    description: "BOM: Leistung.",
  });
  const uVolt = createNode(baseUnitsRootId, "Volt", nextPos + 3, {
    description: "BOM: elektrische Spannung.",
  });

  for (const u of [uOhm, uWatt, uVolt]) {
    for (const name of ["m", "k", "M", "µ", "n", "p"]) {
      pushEdge(u, pref[name], REL_ALLOWS_PREFIX);
    }
  }
  for (const name of ["p", "n", "µ", "m"]) {
    pushEdge(uFarad, pref[name], REL_ALLOWS_PREFIX);
  }

  const schemaId = createNode(compositionsRootId, "Spalten (BOM-Zeile)", 0, {
    description: "BOM-Test: konkrete table — Kinder = Spalten mit has_type.",
  });
  pushEdge(schemaId, types.tTable, REL_HAS_TYPE);

  const cRef = createNode(schemaId, "Reference", 0, {
    description: "RefDes-Liste — has_type → RefDes (list).",
  });
  const cVal = createNode(schemaId, "Value", 1, {
    description: "Bauteilwert als quantity.",
  });
  const cFp = createNode(schemaId, "Footprint", 2, {
    description: "Bauform — has_type → Bauart (enum).",
  });
  const cQty = createNode(schemaId, "Menge", 3, {
    description: "Stückzahl (int) — nicht quantity/Größe.",
  });
  const cLcsc = createNode(schemaId, "LCSC", 4, {
    description: "Lieferantennummer.",
  });
  const cStock = createNode(schemaId, "Stock", 5, {
    description: "Lagerflag.",
  });

  pushEdge(cRef, refDes, REL_HAS_TYPE);
  pushEdge(cVal, types.tQuantity, REL_HAS_TYPE);
  pushEdge(cFp, bauart, REL_HAS_TYPE);
  pushEdge(cQty, types.tInt, REL_HAS_TYPE);
  pushEdge(cLcsc, types.tString, REL_HAS_TYPE);
  pushEdge(cStock, types.tBool, REL_HAS_TYPE);

  const listId = createNode(compositionsRootId, "Stückliste", 1, {
    description: "BOM-Test: Beispiel-Stückliste (Instanz-Knoten).",
  });
  createNode(listId, "C1 — 100 nF 0603", 0, { description: "Kondensator-Zeile." });
  createNode(listId, "R1 — 10 kΩ 0603", 1, { description: "Widerstands-Zeile." });
  createNode(listId, "U1 — ESP32-WROOM-32", 2, { description: "IC-Zeile." });
  createNode(listId, "D1 — LED green 0805", 3, { description: "Diode/LED-Zeile." });

  const partsId = createNode(compositionsRootId, "Bauteile", 2, {
    description: "Katalog-Ast für Compositionen (Demo) — Bauteile ≠ Composition.",
  });
  createNode(partsId, "Kondensator", 0, { description: "Kategorie Kondensator." });
  createNode(partsId, "Widerstand", 1, { description: "Kategorie Widerstand." });
  createNode(partsId, "IC", 2, { description: "Kategorie IC." });
  createNode(partsId, "Diode / LED", 3, { description: "Kategorie Diode/LED." });

  return schemaId;
}

function activeProject() {
  return projects.find((p) => p.id === activeProjectId) || null;
}

/** Template project is read-only; BOM test is editable. */
function isProjectEditable() {
  const p = activeProject();
  return p != null && p.kind !== PROJECT_KIND_TEMPLATE;
}

function applyProject(project) {
  activeProjectId = project.id;
  rootId = project.rootId;
  dataTypesRootId = project.dataTypesRootId;
  prefixesRootId = project.prefixesRootId;
  baseUnitsRootId = project.baseUnitsRootId;
}

function setActiveProject(projectId) {
  const project = projects.find((p) => p.id === projectId);
  if (!project) return;
  applyProject(project);
  selectedId = project.rootId;
  persist();
  render();
}

function isProjectRoot(id) {
  return projects.some((p) => p.rootId === id);
}

function createInitial() {
  nodes = new Map();
  collapsed = new Set();
  seq = 1;
  tableCells = new Map();
  tableCells2 = new Map();
  formStates = new Map();
  edges = [];
  convertStates = new Map();
  projects = [];
  dataTypesRootId = "";
  prefixesRootId = "";
  baseUnitsRootId = "";
  activeTab = "node";

  // 1) Pure template project — read-only; no BOM test data
  const templateRootId = createNode(null, "Template", 0, {
    template: true,
    description:
      "Reines Template (read-only): Datentypen inkl. Collection(list/table/enum), Präfixe, Standard-Basiseinheiten.",
  });
  const templateCore = seedTemplateCore(templateRootId, { markTemplate: true });
  const templateProject = {
    id: "proj-template",
    name: "Template",
    description: "Reiner Template-Baum — nicht änderbar.",
    kind: PROJECT_KIND_TEMPLATE,
    rootId: templateRootId,
    dataTypesRootId: templateCore.dataTypesRootId,
    prefixesRootId: templateCore.prefixesRootId,
    baseUnitsRootId: templateCore.baseUnitsRootId,
  };

  // 2) Composition Simples — editable; columns = int/double/string/char/bool only
  const simplesRootId = createNode(null, "Composition Simples", 1, {
    template: false,
    description:
      "Phase 1: Zusammenstellung nur mit Simple-Typen. Später: quantity / enum / Bauteil-Ref.",
  });
  const simplesCore = seedTemplateCore(simplesRootId, { markTemplate: false });
  const simplesCompId = seedCompositionSimplesDemo(
    simplesCore.compositionsRootId,
    simplesCore
  );
  const simplesProject = {
    id: "proj-composition-simples",
    name: "Composition Simples",
    description: "Composition-Demo Phase 1 — nur Simples.",
    kind: PROJECT_KIND_COMPOSITION_SIMPLES,
    rootId: simplesRootId,
    dataTypesRootId: simplesCore.dataTypesRootId,
    prefixesRootId: simplesCore.prefixesRootId,
    baseUnitsRootId: simplesCore.baseUnitsRootId,
  };

  // 3) BOM test project — editable; template core copy + Collection instances + BOM trees
  const bomRootId = createNode(null, "BOM Testprojekt", 2, {
    template: false,
    description:
      "Editierbar: Bauart (enum wie list), RefDes (list), Spalten (table), Ohm/Farad/…, Stückliste, Bauteile.",
  });
  const bomCore = seedTemplateCore(bomRootId, { markTemplate: false });
  const schemaId = seedBomTestData(bomCore.compositionsRootId, bomCore);
  const bomProject = {
    id: "proj-bom-test",
    name: "BOM Testprojekt",
    description: "BOM-Demo — änderbar.",
    kind: PROJECT_KIND_BOM_TEST,
    rootId: bomRootId,
    dataTypesRootId: bomCore.dataTypesRootId,
    prefixesRootId: bomCore.prefixesRootId,
    baseUnitsRootId: bomCore.baseUnitsRootId,
  };

  projects = [templateProject, simplesProject, bomProject];
  applyProject(simplesProject);
  selectedId = simplesCompId;
  void schemaId;
}

function nodeBelongsToActiveRoot(nodeId) {
  let cur = nodes.get(nodeId);
  let guard = 0;
  while (cur && guard++ < 64) {
    if (cur.id === rootId) return true;
    cur = cur.parentId ? nodes.get(cur.parentId) : null;
  }
  return false;
}

function healNamedRoot(currentId, name) {
  if (currentId && nodes.has(currentId)) return currentId;
  const found = [...nodes.values()].find(
    (n) => n.name === name && nodeBelongsToActiveRoot(n.id)
  );
  return found ? found.id : "";
}

function dataTypeNodes() {
  dataTypesRootId = healNamedRoot(dataTypesRootId, "Datentypen");
  if (!dataTypesRootId) return [];
  return childrenOf(dataTypesRootId);
}

function prefixOptionNames() {
  prefixesRootId = healNamedRoot(prefixesRootId, "Präfix");
  if (!prefixesRootId) return [];
  return childrenOf(prefixesRootId).map((c) => c.name);
}

function baseUnitOptionNames() {
  baseUnitsRootId = healNamedRoot(baseUnitsRootId, "Basiseinheit");
  if (!baseUnitsRootId) return [];
  return childrenOf(baseUnitsRootId).map((c) => c.name);
}

function isBaseUnitNode(node) {
  if (!node) return false;
  baseUnitsRootId = healNamedRoot(baseUnitsRootId, "Basiseinheit");
  return Boolean(baseUnitsRootId && node.parentId === baseUnitsRootId);
}

function edgesFrom(id) {
  return edges.filter((e) => e.from === id);
}

function edgesTo(id) {
  return edges.filter((e) => e.to === id);
}

function findEdge(from, label) {
  return edges.find((e) => e.from === from && e.label === label) || null;
}

function allowedPrefixIds(baseUnitId) {
  return edges
    .filter((e) => e.from === baseUnitId && e.label === REL_ALLOWS_PREFIX)
    .map((e) => e.to)
    .filter((id) => nodes.has(id));
}

/** Scale from Präfix ─[multiplikator]→ int (props.value). */
function prefixFactor(prefixNode) {
  if (!prefixNode) return 1;
  const e = findEdge(prefixNode.id, REL_MULTIPLIKATOR);
  if (e && e.props?.value != null) {
    const n = Number(e.props.value);
    if (Number.isFinite(n) && n !== 0) return n;
  }
  const named = DEFAULT_PREFIX_FACTORS[prefixNode.name];
  if (typeof named === "number") return named;
  return 1;
}

/**
 * Derived unit choices for a Basiseinheit (Vater + allows_prefix Kinder).
 * @returns {{ key: string, prefixId: string|null, label: string, factor: number }[]}
 */
function unitChoices(baseUnitId) {
  const unit = nodes.get(baseUnitId);
  if (!unit || !isBaseUnitNode(unit)) return [];
  const choices = [
    { key: "", prefixId: null, label: unit.name, factor: 1 },
  ];
  for (const pid of allowedPrefixIds(baseUnitId)) {
    const pref = nodes.get(pid);
    if (!pref) continue;
    choices.push({
      key: pid,
      prefixId: pid,
      label: `${pref.name}${unit.name}`,
      factor: prefixFactor(pref),
    });
  }
  return choices;
}

function nodePath(id) {
  const parts = [];
  let cur = nodes.get(id);
  let guard = 0;
  while (cur && guard++ < 64) {
    parts.unshift(cur.name);
    cur = cur.parentId ? nodes.get(cur.parentId) : null;
  }
  return parts.join(" / ");
}

function addEdge(from, to, label, props) {
  if (!isProjectEditable()) {
    window.alert("Template ist schreibgeschützt.");
    return;
  }
  const e = pushEdge(from, to, label, props);
  if (e) {
    persist();
    render();
  }
}

function removeEdge(edgeId) {
  if (!isProjectEditable()) {
    window.alert("Template ist schreibgeschützt.");
    return;
  }
  const before = edges.length;
  edges = edges.filter((e) => e.id !== edgeId);
  if (edges.length !== before) {
    persist();
    render();
  }
}

function setEdgeValue(edgeId, value) {
  if (!isProjectEditable()) return;
  const e = edges.find((x) => x.id === edgeId);
  if (!e) return;
  if (!e.props) e.props = {};
  e.props.value = value;
  persist();
  render();
}

function defaultConvertState() {
  return { leftValue: "10", leftKey: "", rightKey: "" };
}

function ensureConvertState(baseUnitId) {
  let st = convertStates.get(baseUnitId);
  if (!st) {
    st = defaultConvertState();
    const choices = unitChoices(baseUnitId);
    // Prefer demo: left kOhm-like if k exists, right bare base
    const kChoice = choices.find((c) => nodes.get(c.key)?.name === "k");
    if (kChoice) st.leftKey = kChoice.key;
    st.rightKey = "";
    convertStates.set(baseUnitId, st);
  }
  const keys = new Set(unitChoices(baseUnitId).map((c) => c.key));
  if (!keys.has(st.leftKey)) st.leftKey = "";
  if (!keys.has(st.rightKey)) st.rightKey = "";
  return st;
}

function convertValue(leftValue, leftFactor, rightFactor) {
  const n = Number(leftValue);
  if (!Number.isFinite(n) || !leftFactor || !rightFactor) return "";
  const base = n * leftFactor;
  const out = base / rightFactor;
  if (!Number.isFinite(out)) return "";
  // Trim float noise for demo display
  const rounded = Math.abs(out) >= 1e6 || (Math.abs(out) > 0 && Math.abs(out) < 1e-4)
    ? out.toExponential(6)
    : Number(out.toPrecision(12));
  return String(rounded);
}

function typeNodeOf(slotId) {
  const e = findEdge(slotId, REL_HAS_TYPE);
  return e && nodes.has(e.to) ? nodes.get(e.to) : null;
}

/**
 * Collection kind of a concrete type: parent under list|table|enum, or has_type → kind.
 * @param {ProtoNode|null|undefined} typeNode
 * @returns {'list'|'table'|'enum'|null}
 */
function collectionKindOf(typeNode) {
  if (!typeNode) return null;
  const parent = typeNode.parentId ? nodes.get(typeNode.parentId) : null;
  if (parent) {
    const pk = parent.name.trim().toLowerCase();
    if (COLLECTION_KIND_NAMES.includes(pk)) return /** @type {'list'|'table'|'enum'} */ (pk);
  }
  const ht = findEdge(typeNode.id, REL_HAS_TYPE);
  if (ht && nodes.has(ht.to)) {
    const target = nodes.get(ht.to);
    const tk = target.name.trim().toLowerCase();
    if (COLLECTION_KIND_NAMES.includes(tk)) return /** @type {'list'|'table'|'enum'} */ (tk);
  }
  // Legacy: base_type edge meant enum
  if (findEdge(typeNode.id, REL_BASE_TYPE)) return "enum";
  return null;
}

/** First schema column of a Collection instance (child with has_type, else first child). */
function collectionColumn(typeNodeId) {
  const kids = childrenOf(typeNodeId);
  const typed = kids.find((k) => findEdge(k.id, REL_HAS_TYPE));
  return typed || kids[0] || null;
}

/** Element type of a Collection's (first) column — replaces legacy base_type. */
function columnElementType(collectionTypeId) {
  const col = collectionColumn(collectionTypeId);
  if (col) {
    const t = typeNodeOf(col.id);
    if (t) return t;
  }
  const legacy = findEdge(collectionTypeId, REL_BASE_TYPE);
  return legacy && nodes.has(legacy.to) ? nodes.get(legacy.to) : null;
}

function baseTypeOf(enumId) {
  return columnElementType(enumId);
}

/** Normalize type node name → widget key. */
function typeKey(typeNode) {
  if (!typeNode) return "string";
  const k = typeNode.name.trim().toLowerCase();
  if (["int", "integer"].includes(k)) return "int";
  if (["double", "float", "number"].includes(k)) return "double";
  if (["bool", "boolean"].includes(k)) return "bool";
  if (["char"].includes(k)) return "char";
  if (["string", "text"].includes(k)) return "string";
  if (["quantity", "größe", "groesse"].includes(k)) return "quantity";
  const kind = collectionKindOf(typeNode);
  if (kind === "enum") return "enum";
  if (kind === "list") return "list";
  if (kind === "table") return "table";
  if (["enum", "list", "table"].includes(k)) return k;
  return "string";
}

function isSimpleTypeNode(node) {
  if (!node) return false;
  return SIMPLE_TYPE_NAMES.includes(node.name.trim().toLowerCase());
}

function isEnumTypeNode(node) {
  if (!node) return false;
  return typeKey(node) === "enum";
}

function isQuantityTypeNode(node) {
  if (!node) return false;
  return typeKey(node) === "quantity";
}

function isPrefixNode(node) {
  if (!node) return false;
  prefixesRootId = healNamedRoot(prefixesRootId, "Präfix");
  return Boolean(prefixesRootId && node.parentId === prefixesRootId);
}

/** @returns {{ v: string, p: string, u: string }} */
function parseQuantityCell(raw) {
  if (raw == null || raw === "") return { v: "", p: "", u: "" };
  const s = String(raw);
  if (s.includes(QTY_SEP)) {
    const [v = "", p = "", u = ""] = s.split(QTY_SEP);
    return { v, p, u };
  }
  return { v: s, p: "", u: "" };
}

function formatQuantityCell(parts) {
  return [parts.v || "", parts.p || "", parts.u || ""].join(QTY_SEP);
}

function formatQuantityDisplay(parts) {
  const num = parts.v || "";
  const unit = `${parts.p || ""}${parts.u || ""}`;
  if (!num && !unit) return "—";
  return unit ? `${num} ${unit}`.trim() : num;
}

function simpleTypeNodes() {
  return dataTypeNodes().filter(isSimpleTypeNode);
}

/** Closed enum options: children of the typed column (or legacy direct children). */
function enumValueNames(enumNodeId) {
  const col = collectionColumn(enumNodeId);
  if (col && findEdge(col.id, REL_HAS_TYPE)) {
    return childrenOf(col.id).map((c) => c.name);
  }
  // Legacy: options hung directly under the enum type
  return childrenOf(enumNodeId)
    .filter((c) => !findEdge(c.id, REL_HAS_TYPE))
    .map((c) => c.name);
}

function createTypedCellControl(typeNode, value, onChange, ariaLabel) {
  const key = typeKey(typeNode);
  if (key === "bool") {
    const label = document.createElement("label");
    label.className = "choice-row";
    const input = document.createElement("input");
    input.type = "checkbox";
    input.checked = value === true || value === "true" || value === "1";
    input.setAttribute("aria-label", ariaLabel);
    input.addEventListener("change", () => onChange(input.checked ? "true" : "false"));
    const span = document.createElement("span");
    span.className = "muted";
    span.textContent = input.checked ? "true" : "false";
    input.addEventListener("change", () => {
      span.textContent = input.checked ? "true" : "false";
    });
    label.append(input, span);
    return label;
  }

  if (key === "enum" && typeNode) {
    const select = document.createElement("select");
    select.className = "form-control";
    select.setAttribute("aria-label", ariaLabel);
    const empty = document.createElement("option");
    empty.value = "";
    empty.textContent = "—";
    select.append(empty);
    const vals = enumValueNames(typeNode.id);
    const cur = value == null ? "" : String(value);
    for (const v of vals) {
      const opt = document.createElement("option");
      opt.value = v;
      opt.textContent = v;
      select.append(opt);
    }
    if (vals.includes(cur)) select.value = cur;
    select.addEventListener("change", () => onChange(select.value));
    return select;
  }

  if (key === "list") {
    const input = document.createElement("input");
    input.type = "text";
    input.className = "form-control";
    input.setAttribute("aria-label", ariaLabel);
    input.placeholder = "z. B. R1, R2, R5";
    input.title = "Offene list — Werte kommasepariert (Demo)";
    input.value = value == null ? "" : String(value);
    input.addEventListener("change", () => onChange(input.value));
    return input;
  }

  if (key === "quantity") {
    const wrap = document.createElement("div");
    wrap.className = "qty-cell";
    wrap.style.display = "flex";
    wrap.style.gap = "0.25rem";
    wrap.style.alignItems = "center";
    wrap.style.flexWrap = "wrap";
    const parts = parseQuantityCell(value);
    const num = document.createElement("input");
    num.type = "number";
    num.step = "any";
    num.inputMode = "decimal";
    num.className = "form-control";
    num.style.width = "4.5rem";
    num.value = parts.v;
    num.setAttribute("aria-label", `${ariaLabel} Wert`);
    const pref = document.createElement("select");
    pref.className = "form-control";
    pref.style.width = "3.2rem";
    pref.setAttribute("aria-label", `${ariaLabel} Präfix`);
    const pEmpty = document.createElement("option");
    pEmpty.value = "";
    pEmpty.textContent = "—";
    pref.append(pEmpty);
    for (const name of prefixOptionNames()) {
      const opt = document.createElement("option");
      opt.value = name;
      opt.textContent = name;
      pref.append(opt);
    }
    if ([...pref.options].some((o) => o.value === parts.p)) pref.value = parts.p;
    const unit = document.createElement("select");
    unit.className = "form-control";
    unit.style.width = "5rem";
    unit.setAttribute("aria-label", `${ariaLabel} Einheit`);
    const uEmpty = document.createElement("option");
    uEmpty.value = "";
    uEmpty.textContent = "—";
    unit.append(uEmpty);
    for (const name of baseUnitOptionNames()) {
      const opt = document.createElement("option");
      opt.value = name;
      opt.textContent = name;
      unit.append(opt);
    }
    if ([...unit.options].some((o) => o.value === parts.u)) unit.value = parts.u;
    const emit = () =>
      onChange(formatQuantityCell({ v: num.value, p: pref.value, u: unit.value }));
    num.addEventListener("change", emit);
    num.addEventListener("input", emit);
    pref.addEventListener("change", emit);
    unit.addEventListener("change", emit);
    wrap.append(num, pref, unit);
    return wrap;
  }

  const input = document.createElement("input");
  input.setAttribute("aria-label", ariaLabel);
  if (key === "int") {
    input.type = "number";
    input.step = "1";
    input.inputMode = "numeric";
  } else if (key === "double") {
    input.type = "number";
    input.step = "any";
    input.inputMode = "decimal";
  } else if (key === "char") {
    input.type = "text";
    input.maxLength = 1;
    input.placeholder = "·";
  } else {
    input.type = "text";
    input.placeholder = "—";
  }
  input.value = value == null ? "" : String(value);
  input.addEventListener("change", () => {
    let v = input.value;
    if (key === "char") v = v.slice(0, 1);
    onChange(v);
  });
  return input;
}

function addChild(parentId) {
  if (!isProjectEditable()) {
    window.alert("Template ist schreibgeschützt.");
    return;
  }
  if (!nodes.get(parentId)) return;
  const id = createNode(parentId, `Knoten ${seq++}`, nextPosition(parentId));
  reindexSiblings(parentId);
  collapsed.delete(parentId);
  selectedId = id;
  persist();
  render();
}

function descendants(id) {
  const out = [];
  const stack = [id];
  while (stack.length) {
    const cur = stack.pop();
    for (const c of childrenOf(cur)) {
      out.push(c.id);
      stack.push(c.id);
    }
  }
  return out;
}

function deleteNode(id) {
  if (!isProjectEditable()) {
    window.alert("Template ist schreibgeschützt.");
    return;
  }
  if (isProjectRoot(id)) {
    window.alert("Projekt-Root kann nicht gelöscht werden.");
    return;
  }
  const node = nodes.get(id);
  if (!node) return;
  if (!window.confirm(`„${node.name}“ und Unterknoten löschen?`)) return;

  const parentId = node.parentId;
  const kill = new Set([id, ...descendants(id)]);
  for (const k of kill) {
    nodes.delete(k);
    tableCells.delete(k);
    tableCells2.delete(k);
    formStates.delete(k);
    convertStates.delete(k);
  }
  edges = edges.filter((e) => !kill.has(e.from) && !kill.has(e.to));
  for (const p of projects) {
    if (kill.has(p.dataTypesRootId)) p.dataTypesRootId = "";
    if (kill.has(p.prefixesRootId)) p.prefixesRootId = "";
    if (kill.has(p.baseUnitsRootId)) p.baseUnitsRootId = "";
  }
  if (kill.has(dataTypesRootId)) dataTypesRootId = "";
  if (kill.has(prefixesRootId)) prefixesRootId = "";
  if (kill.has(baseUnitsRootId)) baseUnitsRootId = "";

  if (parentId != null && nodes.has(parentId)) reindexSiblings(parentId);

  if (kill.has(selectedId)) {
    selectedId = nodes.has(parentId) ? parentId : rootId;
  }
  persist();
  render();
}

function moveSibling(id, delta) {
  if (!isProjectEditable()) return false;
  const node = nodes.get(id);
  if (!node || node.parentId === null) return false;

  const siblings = childrenOf(node.parentId);
  const index = siblings.findIndex((s) => s.id === id);
  if (index < 0) return false;

  const target = index + delta;
  if (target < 0 || target >= siblings.length) return false;

  const other = siblings[target];
  const tmp = node.position;
  node.position = other.position;
  other.position = tmp;
  reindexSiblings(node.parentId);

  persist();
  render();
  return true;
}

function selectNode(id) {
  if (!nodes.has(id)) return;
  selectedId = id;
  persist();
  render();
}

function setActiveTab(tab) {
  if (!RIGHT_TABS.includes(tab)) return;
  activeTab = /** @type {RightTab} */ (tab);
  persist();
  renderRight();
  syncTabs();
}

function renameSelected(name) {
  if (!isProjectEditable()) return;
  const n = nodes.get(selectedId);
  if (!n) return;
  n.name = name.trim() || n.name;
  persist();
  renderTree();
  renderRight();
}

function toggleCollapse(id, event) {
  event.stopPropagation();
  if (collapsed.has(id)) collapsed.delete(id);
  else collapsed.add(id);
  persist();
  renderTree();
}

function siblingIndex(node) {
  if (node.parentId === null) return { index: 0, total: 1 };
  const siblings = childrenOf(node.parentId);
  return {
    index: siblings.findIndex((s) => s.id === node.id),
    total: siblings.length,
  };
}

/** @param {Map<string, string[][]>} store */
function ensureTableGrid(store, schemaNodeId, colIds) {
  let rows = store.get(schemaNodeId);
  if (!rows) {
    rows = Array.from({ length: TABLE_BODY_ROWS }, () =>
      colIds.map(() => "")
    );
    store.set(schemaNodeId, rows);
    return rows;
  }
  while (rows.length < TABLE_BODY_ROWS) {
    rows.push(colIds.map(() => ""));
  }
  if (rows.length > TABLE_BODY_ROWS) rows.length = TABLE_BODY_ROWS;
  for (let r = 0; r < rows.length; r++) {
    const row = rows[r] || [];
    rows[r] = colIds.map((_, c) => (row[c] != null ? String(row[c]) : ""));
  }
  store.set(schemaNodeId, rows);
  return rows;
}

/** @param {Map<string, string[][]>} store */
function setCellValue(store, schemaNodeId, rowIndex, colIndex, value) {
  const cols = childrenOf(schemaNodeId);
  const rows = ensureTableGrid(
    store,
    schemaNodeId,
    cols.map((c) => c.id)
  );
  if (!rows[rowIndex]) return;
  rows[rowIndex][colIndex] = value;
  persist();
}

function mapToObject(map) {
  const obj = {};
  for (const [k, v] of map) obj[k] = v;
  return obj;
}

function restoreStringGridMap(raw, into) {
  if (!raw || typeof raw !== "object") return;
  for (const [k, rows] of Object.entries(raw)) {
    if (nodes.has(k) && Array.isArray(rows)) into.set(k, rows);
  }
}

function persist() {
  const payload = {
    version: 17,
    projects,
    activeProjectId,
    rootId,
    selectedId,
    seq,
    activeTab,
    dataTypesRootId,
    prefixesRootId,
    baseUnitsRootId,
    collapsed: [...collapsed],
    nodes: [...nodes.values()],
    tableCells: mapToObject(tableCells),
    tableCells2: mapToObject(tableCells2),
    formStates: mapToObject(formStates),
    edges,
    convertStates: mapToObject(convertStates),
  };
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(payload));
  } catch {
    /* ignore quota */
  }
}

function restore() {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (!raw) return false;
    const data = JSON.parse(raw);
    if (!data?.rootId || !Array.isArray(data.nodes)) return false;
    nodes = new Map(data.nodes.map((n) => [n.id, n]));
    if (!nodes.has(data.rootId)) return false;

    if (Array.isArray(data.projects) && data.projects.length > 0) {
      projects = data.projects
        .filter((p) => p && p.rootId && nodes.has(p.rootId))
        .map((p) => ({
          id: p.id || `proj-${p.rootId}`,
          name: p.name || nodes.get(p.rootId)?.name || "Project",
          description: p.description || "",
          kind: p.kind === PROJECT_KIND_BOM_TEST ? PROJECT_KIND_BOM_TEST : PROJECT_KIND_TEMPLATE,
          rootId: p.rootId,
          dataTypesRootId:
            p.dataTypesRootId && nodes.has(p.dataTypesRootId) ? p.dataTypesRootId : "",
          prefixesRootId:
            p.prefixesRootId && nodes.has(p.prefixesRootId) ? p.prefixesRootId : "",
          baseUnitsRootId:
            p.baseUnitsRootId && nodes.has(p.baseUnitsRootId) ? p.baseUnitsRootId : "",
        }));
    } else {
      // Legacy single-root payloads are not migrated — force fresh seed
      return false;
    }
    if (projects.length === 0) return false;

    const preferred =
      projects.find((p) => p.id === data.activeProjectId) ||
      projects.find((p) => p.rootId === data.rootId) ||
      projects[0];
    applyProject(preferred);

    selectedId = nodes.has(data.selectedId) ? data.selectedId : rootId;
    seq = Number(data.seq) || nodes.size;
    collapsed = new Set(
      (data.collapsed || []).filter((id) => nodes.has(id))
    );
    activeTab = RIGHT_TABS.includes(data.activeTab) ? data.activeTab : "node";
    tableCells = new Map();
    tableCells2 = new Map();
    formStates = new Map();
    edges = [];
    convertStates = new Map();
    restoreStringGridMap(data.tableCells, tableCells);
    restoreStringGridMap(data.tableCells2, tableCells2);
    if (data.formStates && typeof data.formStates === "object") {
      for (const [k, st] of Object.entries(data.formStates)) {
        if (!nodes.has(k) || !st || typeof st !== "object") continue;
        formStates.set(k, { ...defaultFormState(), ...st });
      }
    }
    if (Array.isArray(data.edges)) {
      edges = data.edges
        .filter(
          (e) =>
            e &&
            nodes.has(e.from) &&
            nodes.has(e.to) &&
            typeof e.label === "string"
        )
        .map((e) => ({
          id: e.id || uid(),
          from: e.from,
          to: e.to,
          label: e.label,
          ...(e.props && typeof e.props === "object" ? { props: { ...e.props } } : {}),
        }));
    }
    // Heal: ensure every node has description string
    for (const n of nodes.values()) {
      if (typeof n.description !== "string") n.description = "";
    }
    if (data.convertStates && typeof data.convertStates === "object") {
      for (const [uidUnit, st] of Object.entries(data.convertStates)) {
        if (!nodes.has(uidUnit) || !st || typeof st !== "object") continue;
        convertStates.set(uidUnit, {
          ...defaultConvertState(),
          leftValue: st.leftValue != null ? String(st.leftValue) : "10",
          leftKey: st.leftKey != null ? String(st.leftKey) : "",
          rightKey: st.rightKey != null ? String(st.rightKey) : "",
        });
      }
    }
    dataTypeNodes();
    prefixOptionNames();
    baseUnitOptionNames();
    // Heal missing multiplikator edges on Präfixe
    const intNode = dataTypeNodes().find((n) => n.name === "int");
    if (intNode && prefixesRootId) {
      for (const p of childrenOf(prefixesRootId)) {
        if (!findEdge(p.id, REL_MULTIPLIKATOR)) {
          const f = DEFAULT_PREFIX_FACTORS[p.name];
          if (f != null) pushEdge(p.id, intNode.id, REL_MULTIPLIKATOR, { value: f });
        }
      }
    }
    const parents = new Set(
      [...nodes.values()].map((n) => n.parentId).filter((p) => p != null)
    );
    for (const p of parents) reindexSiblings(p);
    reindexSiblings(null);
    return true;
  } catch {
    return false;
  }
}

function resetAll() {
  if (!window.confirm("Beide Projekte zurücksetzen (Template + BOM Testprojekt)?")) return;
  localStorage.removeItem(STORAGE_KEY);
  try {
    localStorage.removeItem("wtt-proto-tree-split");
    localStorage.removeItem("wtt-proto-tree-split-v2");
    localStorage.removeItem("wtt-proto-tree-split-v3");
    localStorage.removeItem("wtt-proto-tree-split-v4");
    localStorage.removeItem("wtt-proto-tree-split-v5");
    localStorage.removeItem("wtt-proto-tree-split-v6");
    localStorage.removeItem("wtt-proto-tree-split-v7");
    localStorage.removeItem("wtt-proto-tree-split-v8");
    localStorage.removeItem("wtt-proto-tree-split-v9");
    localStorage.removeItem("wtt-proto-tree-split-v10");
    localStorage.removeItem("wtt-proto-tree-split-v11");
    localStorage.removeItem("wtt-proto-tree-split-v12");
    localStorage.removeItem("wtt-proto-tree-split-v13");
    localStorage.removeItem("wtt-proto-tree-split-v14");
    localStorage.removeItem("wtt-proto-tree-split-v15");
    localStorage.removeItem("wtt-proto-tree-split-v16");
  } catch {
    /* ignore */
  }
  createInitial();
  persist();
  render();
}

function makeIconButton(label, title, onClick, opts = {}) {
  const btn = document.createElement("button");
  btn.type = "button";
  btn.className = `btn icon${opts.danger ? " danger" : ""}`;
  btn.title = title;
  btn.setAttribute("aria-label", title);
  btn.textContent = label;
  if (opts.disabled) btn.disabled = true;
  btn.addEventListener("click", (e) => {
    e.stopPropagation();
    onClick();
  });
  return btn;
}

function renderTreeRow(node, depth) {
  const kids = childrenOf(node.id);
  const hasKids = kids.length > 0;
  const isCollapsed = collapsed.has(node.id);
  const isSelected = node.id === selectedId;
  const { index, total } = siblingIndex(node);
  const canMove = node.parentId !== null;

  const row = document.createElement("div");
  row.className = `tree-row${isSelected ? " is-selected" : ""}`;
  row.style.paddingLeft = `${0.35 + depth * 0.15}rem`;
  row.setAttribute("role", "treeitem");
  row.setAttribute("aria-selected", String(isSelected));
  row.dataset.id = node.id;

  const twist = document.createElement("button");
  twist.type = "button";
  twist.className = "twist";
  twist.tabIndex = -1;
  if (hasKids) {
    twist.textContent = isCollapsed ? "▶" : "▼";
    twist.addEventListener("click", (e) => toggleCollapse(node.id, e));
  } else {
    twist.textContent = "·";
    twist.style.visibility = "hidden";
  }

  const order = document.createElement("span");
  order.className = "order";
  order.title = "Geschwister-Position (explizit, Q13)";
  order.textContent = canMove ? String(index + 1) : "·";

  const label = document.createElement("span");
  label.className = "label";
  label.textContent = node.name;

  const actions = document.createElement("div");
  actions.className = "actions";
  const editable = isProjectEditable();

  if (canMove && editable) {
    actions.append(
      makeIconButton("↑", "Nach oben (Position −1)", () => moveSibling(node.id, -1), {
        disabled: index <= 0,
      }),
      makeIconButton("↓", "Nach unten (Position +1)", () => moveSibling(node.id, 1), {
        disabled: index >= total - 1,
      })
    );
  }

  if (editable) {
    actions.append(
      makeIconButton("+", "Kind hinzufügen", () => addChild(node.id)),
      makeIconButton("×", "Löschen", () => deleteNode(node.id), {
        danger: true,
        disabled: isProjectRoot(node.id),
      })
    );
    if (isProjectRoot(node.id)) {
      const del = actions.querySelector(".danger");
      if (del) del.style.visibility = "hidden";
    }
  } else if (isProjectRoot(node.id)) {
    const badge = document.createElement("span");
    badge.className = "readonly-badge";
    badge.textContent = "nur lesen";
    badge.title = "Template-Projekt ist schreibgeschützt";
    actions.append(badge);
  }

  row.append(twist, order, label, actions);
  row.addEventListener("click", () => selectNode(node.id));

  const wrap = document.createDocumentFragment();
  wrap.append(row);

  if (hasKids && !isCollapsed) {
    const group = document.createElement("div");
    group.className = "tree-children";
    group.setAttribute("role", "group");
    for (const child of kids) {
      group.append(renderTreeRow(child, depth + 1));
    }
    wrap.append(group);
  }

  return wrap;
}

function syncProjectSelect() {
  const sel = document.getElementById("project-select");
  if (!sel) return;
  const current = sel.value;
  sel.replaceChildren();
  for (const p of projects) {
    const opt = document.createElement("option");
    opt.value = p.id;
  opt.textContent =
      p.kind === PROJECT_KIND_TEMPLATE
        ? `${p.name} (nur lesen)`
        : p.kind === PROJECT_KIND_COMPOSITION_SIMPLES
          ? `${p.name} (Phase 1 · Simples)`
          : `${p.name} (editierbar)`;
    sel.append(opt);
  }
  sel.value = projects.some((p) => p.id === activeProjectId)
    ? activeProjectId
    : projects[0]?.id || "";
  if (sel.value !== current && current && !projects.some((p) => p.id === current)) {
    /* ok */
  }
}

function renderTree() {
  const mount = document.getElementById("tree-root");
  if (!mount) return;
  mount.replaceChildren();
  syncProjectSelect();
  const root = nodes.get(rootId);
  if (!root) return;
  mount.append(renderTreeRow(root, 0));
}

function syncTabs() {
  const tabs = document.querySelectorAll(".tab[data-tab]");
  for (const tab of tabs) {
    const isActive = tab.dataset.tab === activeTab;
    tab.classList.toggle("is-active", isActive);
    tab.setAttribute("aria-selected", String(isActive));
  }
  const panel = document.getElementById("detail");
  if (panel) {
    panel.setAttribute("aria-labelledby", TAB_ARIA[activeTab] || "tab-node");
  }
}

function renameDescription(value) {
  if (!isProjectEditable()) return;
  const n = nodes.get(selectedId);
  if (!n) return;
  n.description = String(value ?? "");
  persist();
}

/**
 * Relationen tab: list outgoing (+ incoming) edges; add/remove.
 * @param {ProtoNode} node
 */
function buildEdgesBlock(node) {
  const block = document.createElement("div");
  block.className = "order-block edges-block";
  const editable = isProjectEditable();

  const lab = document.createElement("div");
  lab.className = "field-label";
  lab.textContent = "Ausgehende Relationen";
  block.append(lab);

  const outgoing = edgesFrom(node.id);
  if (outgoing.length === 0) {
    const empty = document.createElement("p");
    empty.className = "muted";
    empty.style.margin = "0.35rem 0";
    empty.style.fontSize = "0.8rem";
    empty.textContent = "Keine ausgehenden Kanten.";
    block.append(empty);
  } else {
    const list = document.createElement("ul");
    list.className = "edge-list";
    for (const e of outgoing) {
      const li = document.createElement("li");
      li.className = "edge-item";

      const textEl = document.createElement("span");
      textEl.className = "edge-text";
      const lbl = document.createElement("span");
      lbl.className = "edge-label";
      lbl.textContent = e.label;
      const arrow = document.createElement("span");
      arrow.className = "edge-arrow";
      arrow.textContent = "→";
      const target = nodes.get(e.to);
      const tgt = document.createElement("button");
      tgt.type = "button";
      tgt.className = "linkish";
      tgt.textContent = target ? target.name : "(gelöscht)";
      if (target) tgt.addEventListener("click", () => selectNode(e.to));
      textEl.append(lbl, arrow, tgt);

      if (e.label === REL_MULTIPLIKATOR) {
        const val = document.createElement("input");
        val.className = "form-control edge-value";
        val.type = "number";
        val.step = "any";
        val.title = "multiplikator value (int/number)";
        val.setAttribute("aria-label", "Multiplikator-Wert");
        val.value = e.props?.value != null ? String(e.props.value) : "";
        val.readOnly = !editable;
        val.disabled = !editable;
        if (editable) {
          val.addEventListener("change", () => setEdgeValue(e.id, val.value));
        }
        textEl.append(val);
      } else if (e.props?.value != null) {
        const chip = document.createElement("span");
        chip.className = "edge-prop";
        chip.textContent = `value=${e.props.value}`;
        textEl.append(chip);
      }

      if (editable) {
        const del = makeIconButton("×", "Kante entfernen", () => removeEdge(e.id), {
          danger: true,
        });
        li.append(textEl, del);
      } else {
        li.append(textEl);
      }
      list.append(li);
    }
    block.append(list);
  }

  const inLab = document.createElement("div");
  inLab.className = "field-label";
  inLab.style.marginTop = "1rem";
  inLab.textContent = "Eingehende Relationen";
  block.append(inLab);
  const incoming = edgesTo(node.id);
  if (incoming.length === 0) {
    const empty = document.createElement("p");
    empty.className = "muted";
    empty.style.margin = "0.35rem 0";
    empty.style.fontSize = "0.8rem";
    empty.textContent = "Keine eingehenden Kanten.";
    block.append(empty);
  } else {
    const list = document.createElement("ul");
    list.className = "edge-list";
    for (const e of incoming) {
      const li = document.createElement("li");
      li.className = "edge-item";
      const textEl = document.createElement("span");
      textEl.className = "edge-text";
      const src = nodes.get(e.from);
      const srcBtn = document.createElement("button");
      srcBtn.type = "button";
      srcBtn.className = "linkish";
      srcBtn.textContent = src ? src.name : "(?)";
      if (src) srcBtn.addEventListener("click", () => selectNode(e.from));
      const lbl = document.createElement("span");
      lbl.className = "edge-label";
      lbl.textContent = e.label;
      const arrow = document.createElement("span");
      arrow.className = "edge-arrow";
      arrow.textContent = "→";
      const me = document.createElement("span");
      me.textContent = node.name;
      textEl.append(srcBtn, lbl, arrow, me);
      if (e.label === REL_MULTIPLIKATOR && e.props?.value != null) {
        const chip = document.createElement("span");
        chip.className = "edge-prop";
        chip.textContent = `× ${e.props.value}`;
        textEl.append(chip);
      }
      li.append(textEl);
      list.append(li);
    }
    block.append(list);
  }

  const addRow = document.createElement("div");
  addRow.className = "edge-add";

  if (!editable) {
    const locked = document.createElement("p");
    locked.className = "muted";
    locked.style.margin = "0.75rem 0 0";
    locked.style.fontSize = "0.8rem";
    locked.textContent = "Template — Relationen nicht änderbar.";
    block.append(locked);
    return block;
  }

  const labelInput = document.createElement("input");
  labelInput.className = "form-control";
  labelInput.type = "text";
  labelInput.value = isPrefixNode(node)
    ? REL_MULTIPLIKATOR
    : isBaseUnitNode(node)
      ? REL_ALLOWS_PREFIX
      : REL_HAS_TYPE;
  labelInput.setAttribute("list", "edge-label-suggestions");
  labelInput.setAttribute("aria-label", "Relationstyp");

  const suggestions = document.createElement("datalist");
  suggestions.id = "edge-label-suggestions";
  for (const s of EDGE_LABEL_SUGGESTIONS) {
    const o = document.createElement("option");
    o.value = s;
    suggestions.append(o);
  }

  const targetSelect = document.createElement("select");
  targetSelect.className = "form-control";
  targetSelect.setAttribute("aria-label", "Zielknoten");
  const ph = document.createElement("option");
  ph.value = "";
  ph.textContent = "— Zielknoten wählen —";
  targetSelect.append(ph);
  const inProject = new Set([rootId, ...descendants(rootId)]);
  const candidates = [...nodes.values()]
    .filter((n) => n.id !== node.id && inProject.has(n.id))
    .sort((a, b) => nodePath(a.id).localeCompare(nodePath(b.id), "de"));
  for (const c of candidates) {
    const o = document.createElement("option");
    o.value = c.id;
    o.textContent = nodePath(c.id);
    targetSelect.append(o);
  }

  const valueInput = document.createElement("input");
  valueInput.className = "form-control edge-value";
  valueInput.type = "number";
  valueInput.step = "any";
  valueInput.placeholder = "value (optional)";
  valueInput.setAttribute("aria-label", "Kanten-Wert / Multiplikator");
  valueInput.title = "Für multiplikator: Zahlenwert (z. B. 1000)";

  const addBtn = document.createElement("button");
  addBtn.type = "button";
  addBtn.className = "btn";
  addBtn.textContent = "Relation hinzufügen";
  addBtn.addEventListener("click", () => {
    if (!targetSelect.value) return;
    const props =
      valueInput.value !== ""
        ? { value: valueInput.value }
        : undefined;
    addEdge(node.id, targetSelect.value, labelInput.value, props);
  });

  addRow.append(labelInput, targetSelect, valueInput, addBtn);
  block.append(addRow, suggestions);

  const hint = document.createElement("p");
  hint.className = "muted";
  hint.style.margin = "0.35rem 0 0";
  hint.style.fontSize = "0.8rem";
  hint.textContent =
    "Beispiele: has_type → Datentyp · allows_prefix → Präfix · multiplikator → int (+ value). Vorwärts/Rückwärts in Umrechnung über denselben Faktor.";
  block.append(hint);

  return block;
}

function renderRelations() {
  const mount = document.getElementById("detail");
  if (!mount) return;
  const node = nodes.get(selectedId);
  if (!node) {
    mount.innerHTML = `<p class="muted">Knoten auswählen.</p>`;
    return;
  }
  const wrap = document.createElement("div");
  wrap.className = "detail-card";
  const h2 = document.createElement("h2");
  h2.className = "detail-title";
  h2.textContent = `Relationen — ${node.name}`;
  const lead = document.createElement("p");
  lead.className = "muted";
  lead.style.marginTop = "0";
  lead.textContent =
    "Relationen leben hier (nicht unter Knoten). Multiplikator am Präfix: Relation „multiplikator“ → int mit value.";
  wrap.append(h2, lead, buildEdgesBlock(node));
  mount.replaceChildren(wrap);
}

function renderDetail() {
  const mount = document.getElementById("detail");
  if (!mount) return;
  const node = nodes.get(selectedId);
  if (!node) {
    mount.innerHTML = `<p class="muted">Knoten auswählen.</p>`;
    return;
  }

  const kids = childrenOf(node.id);
  const parent = node.parentId ? nodes.get(node.parentId) : null;
  const { index, total } = siblingIndex(node);
  const canMove = node.parentId !== null;
  const editable = isProjectEditable();

  mount.replaceChildren();
  const card = document.createElement("div");
  card.className = "detail-card" + (editable ? "" : " is-readonly");

  const h2 = document.createElement("h2");
  h2.textContent = node.name;
  if (!editable) {
    const badge = document.createElement("span");
    badge.className = "readonly-badge";
    badge.textContent = "nur lesen";
    h2.append(" ", badge);
  }

  const field = document.createElement("div");
  field.className = "field";
  const lab = document.createElement("label");
  lab.htmlFor = "node-name";
  lab.textContent = "Name";
  const input = document.createElement("input");
  input.id = "node-name";
  input.type = "text";
  input.value = node.name;
  input.readOnly = !editable;
  input.disabled = !editable;
  if (editable) {
    input.addEventListener("change", () => renameSelected(input.value));
    input.addEventListener("keydown", (e) => {
      if (e.key === "Enter") {
        e.preventDefault();
        input.blur();
      }
    });
  }
  field.append(lab, input);

  const descField = document.createElement("div");
  descField.className = "field";
  const descLab = document.createElement("label");
  descLab.htmlFor = "node-desc";
  descLab.textContent = "Beschreibung";
  const desc = document.createElement("textarea");
  desc.id = "node-desc";
  desc.className = "form-control";
  desc.rows = 3;
  desc.value = node.description || "";
  desc.placeholder = "Kurzbeschreibung des Knotens…";
  desc.readOnly = !editable;
  desc.disabled = !editable;
  if (editable) {
    desc.addEventListener("change", () => renameDescription(desc.value));
  }
  descField.append(descLab, desc);

  const orderBlock = document.createElement("div");
  orderBlock.className = "order-block";
  const orderTitle = document.createElement("div");
  orderTitle.className = "field-label";
  orderTitle.textContent = "Reihenfolge unter Geschwistern (position)";
  const orderRow = document.createElement("div");
  orderRow.className = "order-row";
  const up = makeIconButton("↑", "Nach oben (Alt+↑)", () =>
    moveSibling(node.id, -1)
  );
  const down = makeIconButton("↓", "Nach unten (Alt+↓)", () =>
    moveSibling(node.id, 1)
  );
  up.disabled = !editable || !canMove || index <= 0;
  down.disabled = !editable || !canMove || index < 0 || index >= total - 1;
  const orderMeta = document.createElement("span");
  orderMeta.className = "muted";
  orderMeta.textContent = !editable
    ? "Template — schreibgeschützt"
    : canMove
      ? `${index + 1} / ${total}`
      : "Root — keine Geschwistersortierung";
  orderRow.append(up, down, orderMeta);
  orderBlock.append(orderTitle, orderRow);

  if (kids.length) {
    const childList = document.createElement("ul");
    childList.className = "child-list";
    for (const c of kids) {
      const li = document.createElement("li");
      const link = document.createElement("button");
      link.type = "button";
      link.className = "linkish";
      link.textContent = c.name;
      link.addEventListener("click", () => selectNode(c.id));
      li.append(link);
      childList.append(li);
    }
    const childLab = document.createElement("div");
    childLab.className = "field-label";
    childLab.style.marginTop = "1rem";
    childLab.textContent =
      "Kinder (= Spalten in Tabelle / Optionen in Formular)";
    orderBlock.append(childLab, childList);
  }

  const meta = document.createElement("div");
  meta.className = "meta";
  meta.innerHTML = `
    <div>id: <span>${node.id}</span></div>
    <div>parent: <span>${parent ? parent.name : "— (root)"}</span></div>
    <div>position: <span>${node.position}</span> <em class="hint">(Sortierschlüssel)</em></div>
    <div>children: <span>${kids.length}</span></div>
    <div>template: <span>${node.template ? "yes" : "—"}</span></div>
    <div>relations: <span>${edgesFrom(node.id).length} out / ${edgesTo(node.id).length} in</span> <em class="hint">(Tab Relationen)</em></div>
  `;

  const hint = document.createElement("p");
  hint.className = "muted";
  hint.style.marginTop = "1.5rem";
  hint.textContent =
    "Knoten: Name + Beschreibung. Relationen (has_type, allows_prefix, multiplikator, …) nur im Tab Relationen.";

  card.append(h2, field, descField, orderBlock, meta, hint);
  mount.append(card);
}

/**
 * @param {Map<string, string[][]>} store
 * @param {string} title
 */
function renderTableView(store, title) {
  const mount = document.getElementById("detail");
  if (!mount) return;
  const node = nodes.get(selectedId);
  if (!node) {
    mount.innerHTML = `<p class="muted">Knoten auswählen.</p>`;
    return;
  }

  const cols = childrenOf(node.id);
  const wrap = document.createElement("div");
  wrap.className = "table-view";

  const lead = document.createElement("p");
  lead.className = "lead";
  if (cols.length === 0) {
    lead.innerHTML = `<strong>${escapeHtml(title)}</strong> — Schema: <strong>${escapeHtml(node.name)}</strong>. Keine Kinder; Kindnamen werden Spaltenköpfe.`;
    wrap.append(lead);
    mount.replaceChildren(wrap);
    return;
  }

  lead.innerHTML = `<strong>${escapeHtml(title)}</strong> — Composition-Instanz: <strong>${escapeHtml(node.name)}</strong> · ${cols.length} Spalte${cols.length === 1 ? "" : "n"} · Typ-Widgets via <code>has_type</code> · ${TABLE_BODY_ROWS} Zeilen (= CompositionRows).`;
  wrap.append(lead);

  const grid = ensureTableGrid(
    store,
    node.id,
    cols.map((c) => c.id)
  );

  const tableWrap = document.createElement("div");
  tableWrap.className = "table-wrap";
  const table = document.createElement("table");
  table.className = "data-table";

  const thead = document.createElement("thead");
  const headRow = document.createElement("tr");
  const thNum = document.createElement("th");
  thNum.className = "row-num";
  thNum.textContent = "#";
  thNum.scope = "col";
  headRow.append(thNum);
  for (const col of cols) {
    const th = document.createElement("th");
    th.scope = "col";
    const tn = typeNodeOf(col.id);
    const label = document.createElement("span");
    label.textContent = col.name;
    th.append(label);
    if (tn) {
      const badge = document.createElement("span");
      badge.className = "type-badge";
      badge.textContent = tn.name;
      th.append(document.createTextNode(" "), badge);
    }
    th.title = tn
      ? `„${col.name}“ has_type ${tn.name} → ${typeKey(tn)}`
      : `Spalte „${col.name}“ ohne Typ (Text)`;
    headRow.append(th);
  }
  thead.append(headRow);

  const tbody = document.createElement("tbody");
  for (let r = 0; r < TABLE_BODY_ROWS; r++) {
    const tr = document.createElement("tr");
    const tdNum = document.createElement("td");
    tdNum.className = "row-num";
    tdNum.textContent = String(r + 1);
    tr.append(tdNum);
    for (let c = 0; c < cols.length; c++) {
      const td = document.createElement("td");
      const col = cols[c];
      const tn = typeNodeOf(col.id);
      const control = createTypedCellControl(
        tn,
        grid[r]?.[c] ?? "",
        (v) => setCellValue(store, node.id, r, c, v),
        `${title}: ${col.name}, Zeile ${r + 1}`
      );
      td.append(control);
      tr.append(td);
    }
    tbody.append(tr);
  }

  table.append(thead, tbody);
  tableWrap.append(table);
  wrap.append(tableWrap);
  mount.replaceChildren(wrap);
}

function formSection(title, hintText, ...children) {
  const section = document.createElement("section");
  section.className = "form-section";
  const h3 = document.createElement("h3");
  h3.textContent = title;
  section.append(h3);
  if (hintText) {
    const hint = document.createElement("p");
    hint.className = "hint-line";
    hint.textContent = hintText;
    section.append(hint);
  }
  for (const child of children) section.append(child);
  return section;
}

function renderForm() {
  const mount = document.getElementById("detail");
  if (!mount) return;
  const node = nodes.get(selectedId);
  if (!node) {
    mount.innerHTML = `<p class="muted">Knoten auswählen.</p>`;
    return;
  }

  const kids = childrenOf(node.id);
  const state = ensureFormState(node.id);
  const wrap = document.createElement("div");
  wrap.className = "form-view";

  const lead = document.createElement("p");
  lead.className = "lead";
  lead.innerHTML = `Formular-Kontext: <strong>${escapeHtml(node.name)}</strong>. Auswahlfelder nutzen die <strong>${kids.length}</strong> Kindknoten als Optionen (Reihenfolge = <code>position</code>).`;
  wrap.append(lead);

  // --- Dropdown ---
  const select = document.createElement("select");
  select.className = "form-control";
  select.id = "form-select";
  const emptyOpt = document.createElement("option");
  emptyOpt.value = "";
  emptyOpt.textContent = kids.length ? "— wählen —" : "— keine Kinder —";
  select.append(emptyOpt);
  for (const k of kids) {
    const opt = document.createElement("option");
    opt.value = k.id;
    opt.textContent = k.name;
    select.append(opt);
  }
  if (kids.some((k) => k.id === state.select)) select.value = state.select;
  else state.select = "";
  select.disabled = kids.length === 0;
  select.addEventListener("change", () => {
    state.select = select.value;
    persist();
    refreshFormSnapshot();
  });
  wrap.append(
    formSection(
      "Dropdown (select)",
      "Optionen = Kindknoten",
      select
    )
  );

  // --- Radio ---
  const radioList = document.createElement("div");
  radioList.className = "choice-list";
  radioList.setAttribute("role", "radiogroup");
  radioList.setAttribute("aria-label", `Auswahl für ${node.name}`);
  if (kids.length === 0) {
    const empty = document.createElement("p");
    empty.className = "hint-line";
    empty.textContent = "Keine Kinder — keine Radio-Optionen.";
    radioList.append(empty);
  } else {
    if (!kids.some((k) => k.id === state.radio)) state.radio = "";
    for (const k of kids) {
      const row = document.createElement("label");
      row.className = "choice-row";
      const input = document.createElement("input");
      input.type = "radio";
      input.name = `radio-${node.id}`;
      input.value = k.id;
      input.checked = state.radio === k.id;
      input.addEventListener("change", () => {
        if (input.checked) {
          state.radio = k.id;
          persist();
          refreshFormSnapshot();
        }
      });
      const span = document.createElement("span");
      span.textContent = k.name;
      row.append(input, span);
      radioList.append(row);
    }
  }
  wrap.append(
    formSection("Radio buttons", "Eine Option aus Kindknoten", radioList)
  );

  // --- Checkboxes (multi) ---
  const checkList = document.createElement("div");
  checkList.className = "choice-list";
  const validChecks = new Set(kids.map((k) => k.id));
  state.checks = (state.checks || []).filter((id) => validChecks.has(id));
  if (kids.length === 0) {
    const empty = document.createElement("p");
    empty.className = "hint-line";
    empty.textContent = "Keine Kinder — keine Checkboxen.";
    checkList.append(empty);
  } else {
    for (const k of kids) {
      const row = document.createElement("label");
      row.className = "choice-row";
      const input = document.createElement("input");
      input.type = "checkbox";
      input.value = k.id;
      input.checked = state.checks.includes(k.id);
      input.addEventListener("change", () => {
        if (input.checked) {
          if (!state.checks.includes(k.id)) state.checks.push(k.id);
        } else {
          state.checks = state.checks.filter((id) => id !== k.id);
        }
        persist();
        refreshFormSnapshot();
      });
      const span = document.createElement("span");
      span.textContent = k.name;
      row.append(input, span);
      checkList.append(row);
    }
  }
  wrap.append(
    formSection(
      "Checkboxen (Mehrfach)",
      "Mehrere Kindknoten gleichzeitig",
      checkList
    )
  );

  // --- Select multiple ---
  const selectMulti = document.createElement("select");
  selectMulti.className = "form-control";
  selectMulti.multiple = true;
  selectMulti.size = Math.min(6, Math.max(3, kids.length || 3));
  for (const k of kids) {
    const opt = document.createElement("option");
    opt.value = k.id;
    opt.textContent = k.name;
    opt.selected = (state.selectMulti || []).includes(k.id);
    selectMulti.append(opt);
  }
  state.selectMulti = (state.selectMulti || []).filter((id) =>
    validChecks.has(id)
  );
  selectMulti.disabled = kids.length === 0;
  selectMulti.addEventListener("change", () => {
    state.selectMulti = [...selectMulti.selectedOptions].map((o) => o.value);
    persist();
    refreshFormSnapshot();
  });
  wrap.append(
    formSection(
      "Mehrfach-Select",
      "HTML select[multiple] — Optionen = Kinder",
      selectMulti
    )
  );

  // --- Switch (boolean) ---
  const switchRow = document.createElement("div");
  switchRow.className = "switch-row";
  const switchLab = document.createElement("span");
  switchLab.textContent = `${node.name} aktiv?`;
  const switchWrap = document.createElement("label");
  switchWrap.className = "switch";
  const switchInput = document.createElement("input");
  switchInput.type = "checkbox";
  switchInput.setAttribute("role", "switch");
  switchInput.checked = !!state.toggle;
  switchInput.setAttribute("aria-label", `${node.name} Umschalter`);
  const track = document.createElement("span");
  track.className = "switch-track";
  switchInput.addEventListener("change", () => {
    state.toggle = switchInput.checked;
    persist();
    refreshFormSnapshot();
  });
  switchWrap.append(switchInput, track);
  switchRow.append(switchLab, switchWrap);
  wrap.append(
    formSection(
      "Switch (boolean)",
      "Boolean-Umschalter — Label vom selektierten Knoten",
      switchRow
    )
  );

  // --- Text / textarea ---
  const textInput = document.createElement("input");
  textInput.className = "form-control";
  textInput.type = "text";
  textInput.value = state.text || "";
  textInput.placeholder = `Text zu „${node.name}“`;
  textInput.addEventListener("change", () => {
    state.text = textInput.value;
    persist();
    refreshFormSnapshot();
  });

  const area = document.createElement("textarea");
  area.className = "form-control-area";
  area.value = state.textarea || "";
  area.placeholder = "Freitext / Notiz";
  area.addEventListener("change", () => {
    state.textarea = area.value;
    persist();
    refreshFormSnapshot();
  });
  wrap.append(
    formSection(
      "Text & Textarea",
      "Freie Eingabe zum selektierten Knoten",
      textInput,
      area
    )
  );

  // --- Number + range ---
  const num = document.createElement("input");
  num.className = "form-control";
  num.type = "number";
  num.value = state.number ?? "";
  num.placeholder = "Zahl";
  num.addEventListener("change", () => {
    state.number = num.value;
    persist();
    refreshFormSnapshot();
  });

  const rangeWrap = document.createElement("div");
  rangeWrap.className = "range-row";
  const range = document.createElement("input");
  range.type = "range";
  range.min = "0";
  range.max = "100";
  range.value = String(state.range ?? 50);
  const rangeVal = document.createElement("span");
  rangeVal.className = "hint-line";
  rangeVal.textContent = `Wert: ${range.value}`;
  range.addEventListener("input", () => {
    state.range = Number(range.value);
    rangeVal.textContent = `Wert: ${range.value}`;
    persist();
    refreshFormSnapshot();
  });
  rangeWrap.append(range, rangeVal);
  wrap.append(
    formSection("Number & Range", "Numerische Eingaben", num, rangeWrap)
  );

  // --- Color / date / time ---
  const inline = document.createElement("div");
  inline.className = "inline-fields";

  const color = document.createElement("input");
  color.className = "form-control";
  color.type = "color";
  color.value = state.color || "#c4a35a";
  color.addEventListener("input", () => {
    state.color = color.value;
    persist();
    refreshFormSnapshot();
  });

  const date = document.createElement("input");
  date.className = "form-control";
  date.type = "date";
  date.value = state.date || "";
  date.addEventListener("change", () => {
    state.date = date.value;
    persist();
    refreshFormSnapshot();
  });

  const time = document.createElement("input");
  time.className = "form-control";
  time.type = "time";
  time.value = state.time || "";
  time.addEventListener("change", () => {
    state.time = time.value;
    persist();
    refreshFormSnapshot();
  });

  inline.append(color, date, time);
  wrap.append(
    formSection("Color / Date / Time", "Weitere HTML5-Eingabetypen", inline)
  );

  // --- Email / URL ---
  const email = document.createElement("input");
  email.className = "form-control";
  email.type = "email";
  email.value = state.email || "";
  email.placeholder = "name@example.com";
  email.addEventListener("change", () => {
    state.email = email.value;
    persist();
    refreshFormSnapshot();
  });

  const url = document.createElement("input");
  url.className = "form-control";
  url.type = "url";
  url.value = state.url || "";
  url.placeholder = "https://…";
  url.addEventListener("change", () => {
    state.url = url.value;
    persist();
    refreshFormSnapshot();
  });
  wrap.append(
    formSection("Email & URL", "Validierte Textfelder", email, url)
  );

  // --- Datalist (suggestions from children) ---
  const listId = `datalist-${node.id}`;
  const combo = document.createElement("input");
  combo.className = "form-control";
  combo.type = "text";
  combo.setAttribute("list", listId);
  combo.value = state.datalist || "";
  combo.placeholder = kids.length
    ? "Tippen oder Vorschlag wählen"
    : "Keine Kind-Vorschläge";
  combo.addEventListener("change", () => {
    state.datalist = combo.value;
    persist();
    refreshFormSnapshot();
  });
  const datalist = document.createElement("datalist");
  datalist.id = listId;
  for (const k of kids) {
    const opt = document.createElement("option");
    opt.value = k.name;
    datalist.append(opt);
  }
  wrap.append(
    formSection(
      "Datalist (Autocomplete)",
      "Vorschläge = Kindnamen",
      combo,
      datalist
    )
  );

  // --- File (UI only) ---
  const file = document.createElement("input");
  file.className = "form-control";
  file.type = "file";
  wrap.append(
    formSection(
      "File",
      "Nur UI-Demo — Upload wird nicht persistiert",
      file
    )
  );

  // --- Actions + snapshot ---
  const actions = document.createElement("div");
  actions.className = "form-actions";
  const resetBtn = document.createElement("button");
  resetBtn.type = "button";
  resetBtn.className = "btn";
  resetBtn.textContent = "Formularwerte leeren";
  resetBtn.addEventListener("click", () => {
    formStates.set(node.id, defaultFormState());
    persist();
    renderForm();
  });
  actions.append(resetBtn);

  const snap = document.createElement("pre");
  snap.className = "form-snapshot";
  snap.id = "form-snapshot";

  wrap.append(formSection("Zustand", "Aktuelle Werte (Prototype)", actions, snap));
  mount.replaceChildren(wrap);
  refreshFormSnapshot();

  function refreshFormSnapshot() {
    const el = document.getElementById("form-snapshot");
    if (!el) return;
    const s = ensureFormState(node.id);
    const nameOf = (id) => nodes.get(id)?.name || id || "—";
    const payload = {
      node: node.name,
      select: nameOf(s.select),
      radio: nameOf(s.radio),
      checks: s.checks.map(nameOf),
      selectMulti: s.selectMulti.map(nameOf),
      toggle: s.toggle,
      text: s.text,
      textarea: s.textarea,
      number: s.number,
      range: s.range,
      color: s.color,
      date: s.date,
      time: s.time,
      email: s.email,
      url: s.url,
      datalist: s.datalist,
    };
    el.textContent = JSON.stringify(payload, null, 2);
  }
}

function escapeHtml(s) {
  return String(s)
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;");
}

function fillUnitSelect(select, choices, currentKey) {
  select.replaceChildren();
  for (const c of choices) {
    const opt = document.createElement("option");
    opt.value = c.key;
    opt.textContent = c.label;
    select.append(opt);
  }
  if (choices.some((c) => c.key === currentKey)) select.value = currentKey;
  else select.value = choices[0]?.key ?? "";
}

function renderConvert() {
  const mount = document.getElementById("detail");
  if (!mount) return;
  const node = nodes.get(selectedId);

  const wrap = document.createElement("div");
  wrap.className = "convert-view";

  const h2 = document.createElement("h2");
  h2.className = "detail-title";
  h2.textContent = "Umrechnung (Q51)";

  const intro = document.createElement("p");
  intro.className = "muted";
  intro.style.marginTop = "0";
  intro.textContent =
    "Basiseinheit im Baum wählen (z. B. Ohm). Links Menge + Einheit, rechts berechneter Wert in einer Variante derselben Basiseinheit.";

  wrap.append(h2, intro);

  const active = isBaseUnitNode(node);
  const panel = document.createElement("div");
  panel.className = "convert-panel" + (active ? "" : " is-disabled");

  if (!active) {
    const ban = document.createElement("p");
    ban.className = "convert-banner";
    ban.textContent = node
      ? `„${node.name}“ ist keine Basiseinheit — Felder gesperrt. Unter Basiseinheit z. B. Ohm wählen.`
      : "Basiseinheit im Baum wählen.";
    wrap.append(ban, panel);
    // Disabled placeholder controls
    const row = document.createElement("div");
    row.className = "convert-row";
    for (const side of ["Eingabe", "Ergebnis"]) {
      const card = document.createElement("fieldset");
      card.className = "convert-card";
      card.disabled = true;
      const leg = document.createElement("legend");
      leg.textContent = side;
      const num = document.createElement("input");
      num.className = "form-control";
      num.type = "number";
      num.disabled = true;
      num.placeholder = "Wert";
      const sel = document.createElement("select");
      sel.className = "form-control";
      sel.disabled = true;
      const o = document.createElement("option");
      o.textContent = "—";
      sel.append(o);
      card.append(leg, num, sel);
      row.append(card);
    }
    panel.append(row);
    mount.replaceChildren(wrap);
    return;
  }

  const baseUnit = /** @type {ProtoNode} */ (node);
  const choices = unitChoices(baseUnit.id);
  const st = ensureConvertState(baseUnit.id);
  const leftChoice = choices.find((c) => c.key === st.leftKey) || choices[0];
  const rightChoice = choices.find((c) => c.key === st.rightKey) || choices[0];
  const rightValue = convertValue(
    st.leftValue,
    leftChoice?.factor ?? 1,
    rightChoice?.factor ?? 1
  );

  const meta = document.createElement("p");
  meta.className = "muted convert-meta";
  meta.innerHTML = `Basiseinheit: <strong>${escapeHtml(baseUnit.name)}</strong> · allows_prefix: ${
    choices.length - 1
  } Präfixe · multiplikator-Relation (value) auf Präfix`;

  const row = document.createElement("div");
  row.className = "convert-row";

  // Left: input
  const leftCard = document.createElement("fieldset");
  leftCard.className = "convert-card";
  const leftLeg = document.createElement("legend");
  leftLeg.textContent = "Eingabe";
  const leftLab = document.createElement("div");
  leftLab.className = "field-label";
  leftLab.textContent = "Menge";
  const leftNum = document.createElement("input");
  leftNum.className = "form-control";
  leftNum.type = "number";
  leftNum.step = "any";
  leftNum.inputMode = "decimal";
  leftNum.value = st.leftValue;
  leftNum.setAttribute("aria-label", "Eingabemenge");
  const leftUnitLab = document.createElement("div");
  leftUnitLab.className = "field-label";
  leftUnitLab.textContent = "Mengeneinheit (abgeleitet)";
  const leftSel = document.createElement("select");
  leftSel.className = "form-control";
  leftSel.setAttribute("aria-label", "Eingabeeinheit");
  fillUnitSelect(leftSel, choices, st.leftKey);
  leftCard.append(leftLeg, leftLab, leftNum, leftUnitLab, leftSel);

  // Arrow
  const arrow = document.createElement("div");
  arrow.className = "convert-arrow";
  arrow.setAttribute("aria-hidden", "true");
  arrow.textContent = "→";

  // Right: computed
  const rightCard = document.createElement("fieldset");
  rightCard.className = "convert-card";
  const rightLeg = document.createElement("legend");
  rightLeg.textContent = "Ergebnis";
  const rightLab = document.createElement("div");
  rightLab.className = "field-label";
  rightLab.textContent = "Wert (errechnet)";
  const rightNum = document.createElement("input");
  rightNum.className = "form-control";
  rightNum.type = "text";
  rightNum.readOnly = true;
  rightNum.value = rightValue;
  rightNum.setAttribute("aria-label", "Errechneter Wert");
  const rightUnitLab = document.createElement("div");
  rightUnitLab.className = "field-label";
  rightUnitLab.textContent = "Ziel-Einheit (gleiche Basiseinheit)";
  const rightSel = document.createElement("select");
  rightSel.className = "form-control";
  rightSel.setAttribute("aria-label", "Zieleinheit");
  fillUnitSelect(rightSel, choices, st.rightKey);
  rightCard.append(rightLeg, rightLab, rightNum, rightUnitLab, rightSel);

  row.append(leftCard, arrow, rightCard);

  const formula = document.createElement("pre");
  formula.className = "form-snapshot convert-formula";
  const leftF = leftChoice?.factor ?? 1;
  const rightF = rightChoice?.factor ?? 1;
  formula.textContent = [
    `${st.leftValue || "?"} ${leftChoice?.label || "?"}  →  Basis`,
    `  ${st.leftValue || "?"} × ${leftF} = ${
      Number.isFinite(Number(st.leftValue)) ? Number(st.leftValue) * leftF : "?"
    } ${baseUnit.name}`,
    `  → / ${rightF} = ${rightValue || "?"} ${rightChoice?.label || "?"}`,
  ].join("\n");

  const hint = document.createElement("p");
  hint.className = "muted";
  hint.style.fontSize = "0.8rem";
  hint.textContent =
    "Familie bleibt an der gewählten Basiseinheit (Ohm → nur Ohm/kOhm/…). Keine kOhm-Knoten — Labels aus Vater + Präfix.";

  function persistConvert() {
    convertStates.set(baseUnit.id, {
      leftValue: leftNum.value,
      leftKey: leftSel.value,
      rightKey: rightSel.value,
    });
    persist();
  }

  function refreshResult() {
    persistConvert();
    const st2 = ensureConvertState(baseUnit.id);
    const lc = choices.find((c) => c.key === st2.leftKey) || choices[0];
    const rc = choices.find((c) => c.key === st2.rightKey) || choices[0];
    const out = convertValue(st2.leftValue, lc?.factor ?? 1, rc?.factor ?? 1);
    rightNum.value = out;
    formula.textContent = [
      `${st2.leftValue || "?"} ${lc?.label || "?"}  →  Basis`,
      `  ${st2.leftValue || "?"} × ${lc?.factor ?? 1} = ${
        Number.isFinite(Number(st2.leftValue))
          ? Number(st2.leftValue) * (lc?.factor ?? 1)
          : "?"
      } ${baseUnit.name}`,
      `  → / ${rc?.factor ?? 1} = ${out || "?"} ${rc?.label || "?"}`,
    ].join("\n");
  }

  leftNum.addEventListener("input", refreshResult);
  leftNum.addEventListener("change", refreshResult);
  leftSel.addEventListener("change", () => {
    // Keep right in same family; if right empty was intentional, leave it
    refreshResult();
  });
  rightSel.addEventListener("change", refreshResult);

  panel.append(meta, row, formula, hint);
  wrap.append(panel);
  mount.replaceChildren(wrap);
}

function renderRight() {
  if (activeTab === "table") renderTableView(tableCells, "Tabelle");
  else if (activeTab === "table2") renderTableView(tableCells2, "Tabelle 2");
  else if (activeTab === "form") renderForm();
  else if (activeTab === "convert") renderConvert();
  else if (activeTab === "relations") renderRelations();
  else renderDetail();
}

function render() {
  renderTree();
  syncTabs();
  renderRight();
}

function onKeyDown(e) {
  if (!e.altKey) return;
  if (e.key !== "ArrowUp" && e.key !== "ArrowDown") return;
  const tag = (e.target && e.target.tagName) || "";
  if (tag === "INPUT" || tag === "TEXTAREA" || tag === "SELECT") return;
  e.preventDefault();
  moveSibling(selectedId, e.key === "ArrowUp" ? -1 : 1);
}

function init() {
  if (!restore()) createInitial();
  document.getElementById("btn-reset")?.addEventListener("click", resetAll);
  document.getElementById("project-select")?.addEventListener("change", (e) => {
    const t = /** @type {HTMLSelectElement} */ (e.target);
    setActiveProject(t.value);
  });
  document.querySelectorAll(".tab[data-tab]").forEach((el) => {
    el.addEventListener("click", () => setActiveTab(el.dataset.tab));
  });
  document.addEventListener("keydown", onKeyDown);
  persist();
  render();
}

init();
