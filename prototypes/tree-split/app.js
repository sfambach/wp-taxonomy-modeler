/**
 * WTT tree-split prototype — in-memory Node model.
 * Shape mirrors planning Node: { id, parentId, name, position, description? }
 *
 * Projects (switcher):
 *   - Template (nur lesen)
 *   - Demo (editierbar) — Compositionen (Rezept/BOM) + Bauteile-Katalog
 *
 * Collection (Q52): enum is created like list — one typed column + (for enum) closed options.
 *
 * Sibling order (Q13): explicit `position`.
 * Edges: { id, from, to, label, props? } — multiplikator carries props.value (int);
 *   subtree uses ref_scope → catalog root; node_ref = free jump to any node.
 *   Slot Pflicht = Node.config.required. Datentypen → Simple | Complex.
 *   Tree = Definition (BOM structure name). WP page = Instanz (Projektname + rows).
 *   Projektname = Collection attribute (inherited); title uses instance value.
 */

const STORAGE_KEY = "wtt-proto-tree-split-v32";
const TABLE_BODY_ROWS = 5;
const PROJECT_KIND_TEMPLATE = "template";
const PROJECT_KIND_COMPOSITION_SIMPLES = "composition-simples";
const PROJECT_KIND_BOM_TEST = "bom-test";
/** Left→right: node props · WP/data entry · page preview · HTML field playground (kept for later). */
const RIGHT_TABS = ["node", "backend", "frontend", "form"];
const TAB_ARIA = {
  node: "tab-node",
  backend: "tab-backend",
  frontend: "tab-frontend",
  form: "tab-form",
};
const LEGACY_TAB_MAP = {
  relations: "node",
  table: "backend",
  table2: "backend",
  convert: "node",
};
const REL_HAS_TYPE = "has_type";
/** @deprecated Collection spin: prefer column ─[has_type]→ element type */
const REL_BASE_TYPE = "base_type";
const REL_ALLOWS_PREFIX = "allows_prefix";
/** Präfix ─[multiplikator]→ int, props.value = scale factor */
const REL_MULTIPLIKATOR = "multiplikator";
/** subtree slot/type ─[ref_scope]→ catalog root (children = selectable targets) */
const REL_REF_SCOPE = "ref_scope";
const SIMPLE_TYPE_NAMES = ["int", "double", "text", "textarea", "char", "bool", "node_ref"];
const COLLECTION_KIND_NAMES = ["list", "table", "enum"];
const QTY_SEP = "|";
const EDGE_LABEL_SUGGESTIONS = [
  REL_HAS_TYPE,
  REL_BASE_TYPE,
  REL_ALLOWS_PREFIX,
  REL_MULTIPLIKATOR,
  REL_REF_SCOPE,
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
 * @typedef {{
 *   id: string,
 *   parentId: string|null,
 *   name: string,
 *   position: number,
 *   description?: string,
 *   template?: boolean,
 *   config?: {
 *     required?: boolean,
 *     allowed_types?: string[],
 *     allowed_base_units?: string[],
 *     footer?: { enabled?: boolean },
 *     footer_op?: 'none'|'label'|'sum'|'avg'|'min'|'max'|'count',
 *   },
 * }} ProtoNode
 */
const FOOTER_OPS = ["none", "label", "sum", "avg", "min", "max", "count"];
/**
 * @typedef {{
 *   id: string,
 *   name: string,
 *   description: string,
 *   kind: 'template'|'composition-simples'|'bom-test',
 *   rootId: string,
 *   typesRootId: string,
 *   dataTypesRootId: string,
 *   prefixesRootId: string,
 *   baseUnitsRootId: string,
 *   startNodeId: string,
 * }} ProtoProject
 */
/** @typedef {'node'|'backend'|'frontend'|'form'} RightTab */
/**
 * @typedef {{ id: string, from: string, to: string, label: string, props?: { value?: string|number } }} ProtoEdge
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
/** Focus Name field after addChild / when requested. */
let focusNameAfterRender = false;
/** @type {Map<string, string[][]>} */
let tableCells = new Map();
/** @type {Map<string, FormState>} */
let formStates = new Map();
/** Instance attrs on a Composition (WP page values) — e.g. Projektname. Key = composition node id. */
/** @type {Map<string, Record<string, string>>} */
let instanceAttrs = new Map();
/** @type {ProtoEdge[]} */
let edges = [];
let dataTypesRootId = "";
let prefixesRootId = "";
let baseUnitsRootId = "";
let collectionRootId = "";

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


/** Collapse every node that currently has children (default tree state). */
function collapseAllBranches() {
  collapsed = new Set();
  for (const n of nodes.values()) {
    if (childrenOf(n.id).length > 0) collapsed.add(n.id);
  }
}

function createNode(parentId, name, position, opts = {}) {
  const id = uid();
  /** @type {ProtoNode} */
  const node = { id, parentId, name, position, description: opts.description || "" };
  if (opts.template) node.template = true;
  if (opts.config && typeof opts.config === "object") {
    node.config = { ...opts.config };
  }
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
 * Root children: **Typen**, **Compositionen**, **Bauteile** (Katalog ≠ Composition/Tabelle).
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
    description: "Zusammenstellungen (Composition-Definitionen und -Instanzen) — Tabellen/Zeilen.",
  });
  const bauteileRootId = createNode(projectRootId, "Bauteile", 2, {
    template: mark,
    description:
      "Katalog (kein Composition): Bauteilgruppen mit Parametern. In BOM via subtree + ref_scope → diese Wurzel.",
  });

  const dataTypesRootId = createNode(typesRootId, "Datentypen", 0, {
    template: mark,
    description: "Zwei Gruppen: Simple (Skalare + node_ref) · Complex (quantity, subtree, Collection).",
  });
  const simpleRootId = createNode(dataTypesRootId, "Simple", 0, {
    template: mark,
    description: "Skalare + freier Node-Absprung (node_ref).",
  });
  const complexRootId = createNode(dataTypesRootId, "Complex", 1, {
    template: mark,
    description: "Zusammengesetzt / scoped: quantity · subtree · Collection.",
  });

  const tInt = createNode(simpleRootId, "int", 0, {
    description: "Ganze Zahl.",
  });
  const tDouble = createNode(simpleRootId, "double", 1, {
    description: "Gleitkommazahl.",
  });
  const tText = createNode(simpleRootId, "text", 2, {
    description: "Einzeiliger Text — HTML <input type=text> / DB VARCHAR-ähnlich.",
  });
  const tTextarea = createNode(simpleRootId, "textarea", 3, {
    description:
      "Mehrzeiliger Text — HTML <textarea>. Später: Format/Interpreter (plain, markdown, html, …).",
  });
  const tChar = createNode(simpleRootId, "char", 4, {
    description: "Einzelnes Zeichen.",
  });
  const tBool = createNode(simpleRootId, "bool", 5, {
    description: "Wahrheitswert true/false.",
  });
  const tNodeRef = createNode(simpleRootId, "node_ref", 6, {
    description:
      "Absprungpunkt zu einem beliebigen anderen Node (freie id-Referenz). Kein ref_scope.",
  });

  const tQuantity = createNode(complexRootId, "quantity", 0, {
    description: "Größe: Wert + optional Präfix + Basiseinheit (nicht Messung).",
  });
  const tSubtree = createNode(complexRootId, "subtree", 1, {
    description:
      "Auswahl unter einer Katalogwurzel: Relation ref_scope → Root; Optionen = direkte Kinder (z. B. Bauteile).",
  });

  const collectionId = createNode(complexRootId, "Collection", 2, {
    template: mark,
    description:
      "Oberbegriff: list/table/enum. Attribute hier (z. B. Projektname) vererben sich auf alle Unterknoten — Werte erst auf der WP-Seite (Instanz).",
  });
  // Q61: Projektname = Collection attribute (definition); instance filled on WP page
  const slotProjektname = createNode(collectionId, "Projektname", 0, {
    template: mark,
    description:
      "Attribut von Collection — vererbt an list/table/enum. Pflicht-Instanzwert beim Einfügen auf einer WP-Seite (nicht der Baumname).",
    config: { required: true },
  });
  pushEdge(slotProjektname, tText, REL_HAS_TYPE);
  const tList = createNode(collectionId, "list", 1, {
    description: "Collection mit genau einer Spalte; Zeilen offen erweiterbar. Erbt Projektname.",
  });
  const tTable = createNode(collectionId, "table", 2, {
    description: "Collection mit n Spalten; Zeilen offen erweiterbar. Erbt Projektname.",
  });
  const tEnum = createNode(collectionId, "enum", 3, {
    description:
      "Wie list anlegen (1 typisierte Spalte); Optionen fest unter der Spalte. Erbt Projektname.",
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
    bauteileRootId,
    dataTypesRootId,
    prefixesRootId,
    baseUnitsRootId,
    collectionId,
    pref,
    simpleRootId,
    complexRootId,
    types: {
      tInt,
      tDouble,
      tText,
      tTextarea,
      tChar,
      tBool,
      tNodeRef,
      tQuantity,
      tSubtree,
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
    description: "Spalte → text (einzeilig)",
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
  pushEdge(cName, types.tText, REL_HAS_TYPE);
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
 * BOM under Compositionen + Bauteile catalog under project Bauteile root.
 * @param {string} compositionsRootId
 * @param {ReturnType<typeof seedTemplateCore>} core
 */
function seedBomTestData(compositionsRootId, core) {
  const { types, pref, baseUnitsRootId, prefixesRootId, bauteileRootId } = core;

  // enum like list: Bauart → Option ─[has_type]→ text → closed options
  const bauart = createNode(types.tEnum, "Bauart", 0, {
    description: "Konkretes enum (wie list): eine Spalte + feste Optionen.",
  });
  const bauartCol = createNode(bauart, "Option", 0, {
    description: "Einzige Spalte der enum-Collection.",
  });
  pushEdge(bauartCol, types.tText, REL_HAS_TYPE);
  for (const [i, name] of ["0201", "0402", "0603", "0805", "axial"].entries()) {
    createNode(bauartCol, name, i, { description: `Bauart-Option ${name}.` });
  }

  // open list: RefDes → Element ─[has_type]→ text (no fixed children)
  const refDes = createNode(types.tList, "RefDes", 0, {
    description: "Offene list für Board-Referenzen (R1, R2, …).",
  });
  const refCol = createNode(refDes, "Element", 0, {
    description: "Einzige Spalte der list-Collection.",
  });
  pushEdge(refCol, types.tText, REL_HAS_TYPE);

  // Electronics units (BOM-specific) stay under Typen → Basiseinheit
  const nextPos = childrenOf(baseUnitsRootId).length;
  const uOhm = createNode(baseUnitsRootId, "Ohm", nextPos, {
    description: "Fixe Einheit für Widerstand. Zulässige Präfixe via allows_prefix.",
  });
  const uFarad = createNode(baseUnitsRootId, "Farad", nextPos + 1, {
    description: "Fixe Einheit für Kondensator. Zulässige Präfixe via allows_prefix.",
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

  // Katalog unter Projekt-Root → Bauteile (nicht unter Compositionen)
  const partsId = core.bauteileRootId;
  const partNode = nodes.get(partsId);
  if (partNode) {
    partNode.description =
      "Katalog: Bauteilgruppe → Parameter-Schema. Kein Composition/keine Tabelle. Einheit typfest; Präfixe = allows_prefix.";
  }

  const widerstand = createNode(partsId, "Widerstand", 0, {
    description: "Bauteilgruppe: Wert (double) + Präfix; Einheit immer Ohm.",
  });
  const wWert = createNode(widerstand, "Wert", 0, {
    description: "Betrag — has_type → double.",
  });
  const wPref = createNode(widerstand, "Präfix", 1, {
    description: "SI-Präfix — has_type → Präfixe; aktive Kinder = allows_prefix von Ohm.",
  });
  const wUnit = createNode(widerstand, "Einheit", 2, {
    description: "Immer Ohm — has_type → Ohm (fix).",
  });
  pushEdge(wWert, types.tDouble, REL_HAS_TYPE);
  pushEdge(wPref, prefixesRootId, REL_HAS_TYPE);
  pushEdge(wUnit, uOhm, REL_HAS_TYPE);

  const kondensator = createNode(partsId, "Kondensator", 1, {
    description: "Bauteilgruppe: Wert (double) + Präfix; Einheit immer Farad.",
  });
  const kWert = createNode(kondensator, "Wert", 0, {
    description: "Betrag — has_type → double.",
  });
  const kPref = createNode(kondensator, "Präfix", 1, {
    description: "SI-Präfix — has_type → Präfixe; aktive Kinder = allows_prefix von Farad.",
  });
  const kUnit = createNode(kondensator, "Einheit", 2, {
    description: "Immer Farad — has_type → Farad (fix).",
  });
  pushEdge(kWert, types.tDouble, REL_HAS_TYPE);
  pushEdge(kPref, prefixesRootId, REL_HAS_TYPE);
  pushEdge(kUnit, uFarad, REL_HAS_TYPE);

  // Q61/Q63: Tree structure name stays "BOM". Projektname = instance value (WP page).
  const bomCompId = createNode(compositionsRootId, "BOM", nextPosition(compositionsRootId), {
    description:
      "Definition im Baum: Strukturname BOM + Spalten. Projektname kommt von Collection (vererbt) und wird erst auf der WP-Seite gefüllt.",
    config: {
      footer: { enabled: true },
      // empty = all under Typ-Ast / Basiseinheit; demo pre-selects common types + electronics units
      allowed_types: [
        types.tInt,
        types.tDouble,
        types.tText,
        types.tTextarea,
        types.tQuantity,
        types.tSubtree,
        types.tTable,
        refDes,
        bauart,
      ],
      allowed_base_units: [uOhm, uFarad, uWatt, uVolt],
    },
  });
  pushEdge(bomCompId, types.tTable, REL_HAS_TYPE);

  const cPart = createNode(bomCompId, "Bauteil Wahl", 0, {
    description:
      "subtree → Kataloggruppe. has_type → subtree; ref_scope → Bauteile. Pflicht (config.required).",
    config: { required: true, footer_op: "count" },
  });
  const cRef = createNode(bomCompId, "Reference", 1, {
    description: "RefDes — has_type → RefDes (list). Pflicht.",
    config: { required: true, footer_op: "none" },
  });
  const cVal = createNode(bomCompId, "Wert", 2, {
    description: "Größe aus Bauteil-Schema: double + Präfix; Einheit von Bauteil.Einheit. Pflicht.",
    config: { required: true, footer_op: "none" },
  });
  pushEdge(cVal, types.tQuantity, REL_HAS_TYPE);
  const cFp = createNode(bomCompId, "Footprint", 3, {
    description: "Bauform — has_type → Bauart (enum). Optional.",
    config: { required: false, footer_op: "none" },
  });
  const cQty = createNode(bomCompId, "Menge", 4, {
    description:
      "Stückzahl — Einheit Stück (int, nicht quantity). Pflicht. Fußzeile: footer_op=sum → Σ Stück.",
    config: { required: true, footer_op: "sum" },
  });
  const cDesc = createNode(bomCompId, "Beschreibung", 5, {
    description: "Freitext — has_type → textarea. Optional. Fußzeile leer (footer_op=none).",
    config: { required: false, footer_op: "none" },
  });

  pushEdge(cPart, types.tSubtree, REL_HAS_TYPE);
  pushEdge(cPart, bauteileRootId, REL_REF_SCOPE);
  pushEdge(cRef, refDes, REL_HAS_TYPE);
  pushEdge(cFp, bauart, REL_HAS_TYPE);
  pushEdge(cQty, types.tInt, REL_HAS_TYPE);
  pushEdge(cDesc, types.tTextarea, REL_HAS_TYPE);

  tableCells.set(bomCompId, [
    [
      widerstand,
      "R1",
      formatQuantityCell({ v: "10", p: "k", u: "Ohm" }),
      "0603",
      "2",
      "10k Pull-up",
    ],
    [
      kondensator,
      "C1",
      formatQuantityCell({ v: "100", p: "n", u: "Farad" }),
      "0603",
      "4",
      "Entkopplung",
    ],
    ["", "", "", "", "", ""],
    ["", "", "", "", "", ""],
    ["", "", "", "", "", ""],
  ]);
  // Demo instance value (as if already set on a WP page)
  instanceAttrs.set(bomCompId, { Projektname: "Platine XY" });

  return bomCompId;
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
  if (!project.typesRootId) {
    const t = childrenOf(project.rootId).find((n) => n.name === "Typen");
    project.typesRootId = t ? t.id : "";
  }
  if (!project.startNodeId || !nodes.has(project.startNodeId)) {
    project.startNodeId = project.rootId;
  }
}

function setActiveProject(projectId) {
  const project = projects.find((p) => p.id === projectId);
  if (!project) return;
  applyProject(project);
  // Q59: Setup-Startknoten = Standard-Fokus (Demo-Seed: BOM structure)
  selectedId =
    project.startNodeId && nodes.has(project.startNodeId)
      ? project.startNodeId
      : project.rootId;
  // Root + Compositionen aufklappen, damit Compositionen sichtbar sind
  collapsed.delete(project.rootId);
  const comps = childrenOf(project.rootId).find((n) => n.name === "Compositionen");
  const bauteile = childrenOf(project.rootId).find((n) => n.name === "Bauteile");
  if (bauteile) collapsed.delete(bauteile.id);
  if (comps) collapsed.delete(comps.id);
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
  formStates = new Map();
  instanceAttrs = new Map();
  edges = [];
  projects = [];
  dataTypesRootId = "";
  prefixesRootId = "";
  baseUnitsRootId = "";
  collectionRootId = "";
  activeTab = "node";
  focusNameAfterRender = false;

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
    typesRootId: templateCore.typesRootId,
    dataTypesRootId: templateCore.dataTypesRootId,
    prefixesRootId: templateCore.prefixesRootId,
    baseUnitsRootId: templateCore.baseUnitsRootId,
    startNodeId: templateCore.typesRootId,
  };

  // 2) Demo — editable; Rezept (simples) + BOM under Compositionen
  const demoRootId = createNode(null, "Demo", 1, {
    template: false,
    description:
      "Editierbares Demo: Compositionen (Rezept/BOM) + Bauteile-Katalog (kein Composition).",
  });
  const demoCore = seedTemplateCore(demoRootId, { markTemplate: false });
  const rezeptId = seedCompositionSimplesDemo(
    demoCore.compositionsRootId,
    demoCore
  );
  const bomCompId = seedBomTestData(demoCore.compositionsRootId, demoCore);
  const demoProject = {
    id: "proj-demo",
    name: "Demo",
    description: "Compositionen + Bauteile-Katalog (getrennt).",
    kind: PROJECT_KIND_COMPOSITION_SIMPLES,
    rootId: demoRootId,
    typesRootId: demoCore.typesRootId,
    dataTypesRootId: demoCore.dataTypesRootId,
    prefixesRootId: demoCore.prefixesRootId,
    baseUnitsRootId: demoCore.baseUnitsRootId,
    startNodeId: bomCompId, // Setup-Standard: BOM (structure)
  };

  projects = [templateProject, demoProject];
  collectionRootId = demoCore.collectionId || "";
  collapseAllBranches();
  applyProject(demoProject);
  selectedId = demoProject.startNodeId;
  activeTab = "backend"; // Demo: Instanz-Tabelle + Projektname-Titel
  // Compositionen + Collection aufgeklappt
  collapsed.delete(demoRootId);
  collapsed.delete(demoCore.compositionsRootId);
  if (demoCore.bauteileRootId) collapsed.delete(demoCore.bauteileRootId);
  if (demoCore.collectionId) {
    collapsed.delete(demoCore.dataTypesRootId);
    collapsed.delete(demoCore.complexRootId);
    collapsed.delete(demoCore.collectionId);
  }
  void rezeptId;
}

/** BOM-ähnliche Composition: subtree-Spalte oder Menge-Spalte. */
function isBomComposition(node) {
  if (!node) return false;
  const cols = childrenOf(node.id);
  return (
    cols.some((c) => typeKey(typeNodeOf(c.id)) === "subtree") ||
    cols.some((c) => c.name.trim().toLowerCase() === "menge")
  );
}

/** Inherited Collection attribute slot "Projektname" (definition in tree). */
function projektnameSlot() {
  collectionRootId = healNamedRoot(collectionRootId, "Collection");
  if (!collectionRootId) return null;
  return (
    childrenOf(collectionRootId).find(
      (c) => c.name.trim().toLowerCase() === "projektname"
    ) || null
  );
}

function getInstanceAttr(compId, key) {
  const row = instanceAttrs.get(compId);
  return row && row[key] != null ? String(row[key]) : "";
}

function setInstanceAttr(compId, key, value) {
  const prev = instanceAttrs.get(compId) || {};
  instanceAttrs.set(compId, { ...prev, [key]: value });
  persist();
}

/** Display title under BOM table from instance Projektname (Q61). */
function bomDisplayTitle(node) {
  const pn = getInstanceAttr(node?.id, "Projektname").trim() || "—";
  return `BOM als Bauteilliste – ${pn}`;
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
  const top = childrenOf(dataTypesRootId);
  const out = [];
  for (const g of top) {
    const gn = g.name.trim().toLowerCase();
    if (gn === "simple" || gn === "complex") {
      out.push(...childrenOf(g.id));
    } else {
      // Legacy flat Datentypen children
      out.push(g);
    }
  }
  return out;
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
  if (!typeNode) return "text";
  const k = typeNode.name.trim().toLowerCase();
  if (["int", "integer"].includes(k)) return "int";
  if (["double", "float", "number"].includes(k)) return "double";
  if (["bool", "boolean"].includes(k)) return "bool";
  if (["char"].includes(k)) return "char";
  // HTML/DB lean: text = single-line; textarea = multi-line (legacy "string" → text)
  if (["text", "string", "varchar"].includes(k)) return "text";
  if (["textarea", "longtext", "multiline"].includes(k)) return "textarea";
  if (["quantity", "größe", "groesse"].includes(k)) return "quantity";
  if (["subtree", "sub_tree", "catalog_ref"].includes(k)) return "subtree";
  if (["node_ref", "noderef", "ref"].includes(k)) return "node_ref";
  const kind = collectionKindOf(typeNode);
  if (kind === "enum") return "enum";
  if (kind === "list") return "list";
  if (kind === "table") return "table";
  if (["enum", "list", "table"].includes(k)) return k;
  return "text";
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


/** Catalog root „Bauteile“ — sibling of Typen/Compositionen (not a Composition). */
function findBauteileRoot() {
  return childrenOf(rootId).find((n) => n.name === "Bauteile") || null;
}

/** Selectable Bauteilgruppen (direct children of Bauteile). */
function bauteilCatalogOptions() {
  const root = findBauteileRoot();
  return root ? childrenOf(root.id) : [];
}

/**
 * Catalog root for a subtree slot/type via Relation ref_scope.
 * Prefer slot edge; fall back to type edge (specialized subtree).
 * @param {string} slotId
 * @returns {ProtoNode|null}
 */
function refScopeRootOf(slotId) {
  const se = findEdge(slotId, REL_REF_SCOPE);
  if (se && nodes.has(se.to)) return nodes.get(se.to);
  const tn = typeNodeOf(slotId);
  if (tn) {
    const te = findEdge(tn.id, REL_REF_SCOPE);
    if (te && nodes.has(te.to)) return nodes.get(te.to);
  }
  return null;
}

/** Selectable targets for a subtree slot = children of ref_scope root. */
function subtreeOptions(slotId) {
  const root = refScopeRootOf(slotId);
  if (root) return childrenOf(root.id);
  // Legacy fallback: Bauteile catalog when scope missing
  return bauteilCatalogOptions();
}

/** All nodes in the active project — for free node_ref (Absprungpunkt). */
function allProjectNodes() {
  return [...nodes.values()]
    .filter((n) => nodeBelongsToActiveRoot(n.id) && n.id !== rootId)
    .sort((a, b) => nodePath(a.id).localeCompare(nodePath(b.id), "de"));
}

/** Slot/parameter Pflicht? Lives on the Node (config.required), not on has_type. */
function isSlotRequired(nodeOrId) {
  const n = typeof nodeOrId === "string" ? nodes.get(nodeOrId) : nodeOrId;
  return Boolean(n?.config?.required);
}

function setSlotRequired(nodeId, required) {
  if (!isProjectEditable()) return;
  const n = nodes.get(nodeId);
  if (!n) return;
  if (!n.config) n.config = {};
  n.config.required = Boolean(required);
  persist();
  renderRight();
}

/** Replace outgoing edge with label (at most one). Empty toId removes it. */
function setLabeledEdge(fromId, label, toId) {
  if (!isProjectEditable()) return;
  edges = edges.filter((e) => !(e.from === fromId && e.label === label));
  if (toId && nodes.has(toId) && toId !== fromId) {
    pushEdge(fromId, toId, label);
  }
  persist();
  renderRight();
}

function setHasType(slotId, typeId) {
  setLabeledEdge(slotId, REL_HAS_TYPE, typeId || "");
}

function setRefScope(slotId, rootId) {
  setLabeledEdge(slotId, REL_REF_SCOPE, rootId || "");
}

/**
 * Is node under the project Typ-Ast (Typen → …)? Q26: type search only here.
 * @param {string} nodeId
 */
function isUnderTypeBranch(nodeId) {
  const p = activeProject();
  const typesRoot = p?.typesRootId || healNamedRoot("", "Typen");
  if (!typesRoot) return false;
  let cur = nodes.get(nodeId);
  let guard = 0;
  while (cur && guard++ < 64) {
    if (cur.id === typesRoot) return true;
    cur = cur.parentId ? nodes.get(cur.parentId) : null;
  }
  return false;
}

/** Owning Composition for a slot column (parent with table type / under Compositionen). */
function compositionOwnerOf(slotId) {
  const slot = nodes.get(slotId);
  if (!slot?.parentId) return null;
  const parent = nodes.get(slot.parentId);
  if (!parent) return null;
  const pk = typeKey(typeNodeOf(parent.id));
  if (pk === "table" || pk === "list") return parent;
  return null;
}

/**
 * Candidates for has_type picker — **only Typ-Ast** (Q26).
 * When slot belongs to a Composition with allowed_types, filter to that allowlist (Q60).
 * @param {string} [slotId]
 */
function typePickerCandidates(slotId) {
  /** @type {ProtoNode[]} */
  const list = [];
  const seen = new Set();
  const add = (n) => {
    if (!n || seen.has(n.id) || n.id === rootId) return;
    if (!isUnderTypeBranch(n.id)) return;
    seen.add(n.id);
    list.push(n);
  };
  for (const n of dataTypeNodes()) {
    add(n);
    if (n.name.trim().toLowerCase() === "collection") {
      for (const kind of childrenOf(n.id)) {
        add(kind);
        for (const concrete of childrenOf(kind.id)) add(concrete);
      }
    }
  }
  // Einheit-Slots may still bind Basiseinheit via has_type (legacy demo) — only under Typ-Ast
  baseUnitsRootId = healNamedRoot(baseUnitsRootId, "Basiseinheit");
  if (baseUnitsRootId) {
    for (const u of childrenOf(baseUnitsRootId)) add(u);
  }
  prefixesRootId = healNamedRoot(prefixesRootId, "Präfixe");
  if (prefixesRootId) add(nodes.get(prefixesRootId));

  const owner = slotId ? compositionOwnerOf(slotId) : null;
  const allow = owner?.config?.allowed_types;
  if (Array.isArray(allow) && allow.length > 0) {
    const set = new Set(allow);
    return list
      .filter((n) => set.has(n.id))
      .sort((a, b) => nodePath(a.id).localeCompare(nodePath(b.id), "de"));
  }
  return list.sort((a, b) => nodePath(a.id).localeCompare(nodePath(b.id), "de"));
}

/** Catalog roots for ref_scope (project top-level folders + their useful children). */
function refScopeCandidates() {
  const top = childrenOf(rootId);
  /** @type {ProtoNode[]} */
  const list = [...top];
  // Also allow deeper catalog folders (e.g. future Bauteile/Passiv)
  for (const t of top) {
    if (t.name.trim().toLowerCase() === "bauteile") {
      list.push(...childrenOf(t.id));
    }
  }
  return list.sort((a, b) => nodePath(a.id).localeCompare(nodePath(b.id), "de"));
}

/**
 * Show Typ-Bindung only for schema-ish slots — not every folder/type Node.
 * Heuristic: already typed, composition column, Bauteil-Gruppe param, or typed siblings.
 * @param {ProtoNode} node
 */
function isSlotLikeNode(node) {
  if (!node || node.parentId == null) return false;
  if (findEdge(node.id, REL_HAS_TYPE)) return true;
  const parent = nodes.get(node.parentId);
  if (!parent) return false;
  const parentType = typeNodeOf(parent.id);
  const pk = typeKey(parentType);
  if (pk === "table" || pk === "list" || pk === "enum") return true;
  if (collectionKindOf(parent)) return true;
  const sibs = childrenOf(parent.id);
  if (sibs.some((c) => c.id !== node.id && findEdge(c.id, REL_HAS_TYPE))) return true;
  const bau = findBauteileRoot();
  if (bau && parent.parentId === bau.id) return true;
  return false;
}

/**
 * Guided Typ-Bindung: has_type (+ ref_scope when subtree).
 * Answers "woher weiß ich, dass ich eine Node-Referenz brauche?"
 * @param {ProtoNode} node
 */
function buildTypeBindingPanel(node) {
  const block = document.createElement("div");
  block.className = "order-block type-binding";
  const editable = isProjectEditable();

  const lab = document.createElement("div");
  lab.className = "field-label";
  lab.textContent = "Typ-Bindung (has_type)";
  block.append(lab);

  const lead = document.createElement("p");
  lead.className = "muted";
  lead.style.margin = "0.25rem 0 0.5rem";
  lead.style.fontSize = "0.8rem";
  lead.textContent =
    "Typ nur aus dem Typ-Ast (Q26). Katalog-Auswahl → subtree (+ Katalogwurzel). Freier Absprung → node_ref. BOM kann Typen weiter einschränken (Q60).";
  block.append(lead);

  const typeRow = document.createElement("div");
  typeRow.className = "field";
  const typeLab = document.createElement("label");
  typeLab.textContent = "Typ";
  typeLab.htmlFor = "slot-has-type";
  const typeSel = document.createElement("select");
  typeSel.id = "slot-has-type";
  typeSel.className = "form-control";
  typeSel.disabled = !editable;
  const empty = document.createElement("option");
  empty.value = "";
  empty.textContent = "— kein Typ (Freitext) —";
  typeSel.append(empty);
  const curType = typeNodeOf(node.id);
  for (const c of typePickerCandidates(node.id)) {
    const o = document.createElement("option");
    o.value = c.id;
    const key = typeKey(c);
    o.textContent = `${nodePath(c.id)}  [${key}]`;
    typeSel.append(o);
  }
  if (curType && [...typeSel.options].some((o) => o.value === curType.id)) {
    typeSel.value = curType.id;
  }
  typeSel.addEventListener("change", () => setHasType(node.id, typeSel.value));
  typeRow.append(typeLab, typeSel);
  block.append(typeRow);

  const key = typeKey(curType);
  const help = document.createElement("p");
  help.className = "muted";
  help.style.margin = "0.35rem 0 0";
  help.style.fontSize = "0.8rem";

  if (!curType) {
    help.textContent =
      "Ohne Typ → Backend zeigt Freitext. Für „Bauteil Wahl“: Typ = subtree, danach Katalogwurzel setzen.";
    block.append(help);
  } else if (key === "subtree") {
    help.innerHTML =
      "<strong>subtree</strong> = Auswahl unter einer Katalogwurzel. Du brauchst zusätzlich <code>ref_scope</code> → z. B. <em>Bauteile</em>.";
    block.append(help);

    const scopeRow = document.createElement("div");
    scopeRow.className = "field";
    scopeRow.style.marginTop = "0.75rem";
    const scopeLab = document.createElement("label");
    scopeLab.textContent = "Katalogwurzel (ref_scope)";
    scopeLab.htmlFor = "slot-ref-scope";
    const scopeSel = document.createElement("select");
    scopeSel.id = "slot-ref-scope";
    scopeSel.className = "form-control";
    scopeSel.disabled = !editable;
    const sEmpty = document.createElement("option");
    sEmpty.value = "";
    sEmpty.textContent = "— Katalogwurzel wählen (Pflicht für subtree) —";
    scopeSel.append(sEmpty);
    const curScope = refScopeRootOf(node.id);
    // Prefer slot's own edge for display (refScopeRootOf also falls back to type)
    const slotScopeEdge = findEdge(node.id, REL_REF_SCOPE);
    for (const c of refScopeCandidates()) {
      const o = document.createElement("option");
      o.value = c.id;
      o.textContent = nodePath(c.id);
      scopeSel.append(o);
    }
    const scopeVal = slotScopeEdge?.to || curScope?.id || "";
    if (scopeVal && [...scopeSel.options].some((o) => o.value === scopeVal)) {
      scopeSel.value = scopeVal;
    }
    scopeSel.addEventListener("change", () => setRefScope(node.id, scopeSel.value));
    scopeRow.append(scopeLab, scopeSel);
    block.append(scopeRow);

    if (!scopeVal) {
      const warn = document.createElement("p");
      warn.className = "type-binding-warn";
      warn.textContent =
        "Noch keine Katalogwurzel — Backend kann keine Optionen anbieten.";
      block.append(warn);
    } else {
      const ok = document.createElement("p");
      ok.className = "muted";
      ok.style.margin = "0.35rem 0 0";
      ok.style.fontSize = "0.8rem";
      const opts = childrenOf(scopeVal);
      ok.textContent = `Optionen: ${opts.length} Kind(er) von „${nodes.get(scopeVal)?.name || "?"}“ (${opts.map((n) => n.name).join(", ") || "—"}).`;
      block.append(ok);
    }
  } else if (key === "node_ref") {
    help.innerHTML =
      "<strong>node_ref</strong> = freier Absprung zu einem beliebigen Knoten. Kein <code>ref_scope</code> nötig — Wert = Node-id.";
    block.append(help);
  } else {
    help.textContent = `Typ „${curType.name}“ → Widget „${key}“. Relationen unten bleiben für Spezialfälle.`;
    block.append(help);
  }

  return block;
}

/** Named parameter child of a Bauteilgruppe (Wert / Präfix / Einheit). */
function bauteilParam(partId, paramName) {
  if (!partId || !nodes.has(partId)) return null;
  const want = String(paramName).trim().toLowerCase();
  return (
    childrenOf(partId).find((c) => c.name.trim().toLowerCase() === want) || null
  );
}

/** Fixed unit node from Bauteilgruppe.Einheit ─[has_type]→ Ohm|Farad|… */
function bauteilFixedUnit(partId) {
  const einheit = bauteilParam(partId, "Einheit");
  if (!einheit) return null;
  return typeNodeOf(einheit.id);
}

/** Prefix nodes allowed for a Basiseinheit via allows_prefix. */
function allowedPrefixNodesForUnit(unitId) {
  if (!unitId) return [];
  return edges
    .filter((e) => e.from === unitId && e.label === REL_ALLOWS_PREFIX && nodes.has(e.to))
    .map((e) => nodes.get(e.to))
    .filter(Boolean);
}

function allowedPrefixNamesForUnit(unitId) {
  return allowedPrefixNodesForUnit(unitId).map((n) => n.name);
}

function toggleAllowsPrefix(unitId, prefixId, enabled) {
  if (!isProjectEditable()) return;
  const existing = edges.find(
    (e) => e.from === unitId && e.to === prefixId && e.label === REL_ALLOWS_PREFIX
  );
  if (enabled && !existing) {
    pushEdge(unitId, prefixId, REL_ALLOWS_PREFIX);
  } else if (!enabled && existing) {
    edges = edges.filter((e) => e.id !== existing.id);
  }
  persist();
  renderRight();
}

/** @param {ProtoNode|null|undefined} col */
function columnFooterOp(col) {
  const op = col?.config?.footer_op;
  return FOOTER_OPS.includes(op) ? op : "none";
}

/**
 * Parse numeric values from a column for footer aggregates.
 * @param {string[][]} grid
 * @param {number} colIdx
 * @param {ProtoNode} col
 */
function columnNumericValues(grid, colIdx, col) {
  const key = typeKey(typeNodeOf(col.id));
  /** @type {number[]} */
  const nums = [];
  for (let r = 0; r < TABLE_BODY_ROWS; r++) {
    const raw = String(grid[r]?.[colIdx] ?? "").trim();
    if (!raw) continue;
    if (key === "quantity") {
      const q = parseQuantityCell(raw);
      const v = Number.parseFloat(String(q.v ?? "").replace(",", "."));
      if (Number.isFinite(v)) nums.push(v);
      continue;
    }
    const n = Number.parseFloat(raw.replace(",", "."));
    if (Number.isFinite(n)) nums.push(n);
  }
  return nums;
}

/**
 * @param {string[][]} grid
 * @param {number} colIdx
 * @param {ProtoNode} col
 */
function computeFooterCell(grid, colIdx, col) {
  const op = columnFooterOp(col);
  if (op === "none") return "";
  if (op === "label") return col.config?.footer_label || col.name;
  const filled = grid.filter((row) => String(row?.[colIdx] ?? "").trim() !== "").length;
  if (op === "count") return String(filled);
  const nums = columnNumericValues(grid, colIdx, col);
  if (!nums.length) return "—";
  const isMenge = col.name.trim().toLowerCase() === "menge";
  const fmt = (n) => {
    const s = Number.isInteger(n) ? String(n) : n.toFixed(2).replace(/\.?0+$/, "");
    return isMenge && (op === "sum" || op === "avg") ? `${s} Stück` : s;
  };
  if (op === "sum") return fmt(nums.reduce((a, b) => a + b, 0));
  if (op === "avg") return fmt(nums.reduce((a, b) => a + b, 0) / nums.length);
  if (op === "min") return fmt(Math.min(...nums));
  if (op === "max") return fmt(Math.max(...nums));
  return "";
}

/**
 * Composition/BOM: zulässige Typen + Basiseinheiten (Q60) + Fußzeile (Q57).
 * @param {ProtoNode} compNode
 */
function buildCompositionAllowlistPanel(compNode) {
  const block = document.createElement("div");
  block.className = "order-block composition-allowlists";
  const editable = isProjectEditable();

  const lab = document.createElement("div");
  lab.className = "field-label";
  lab.textContent = "BOM / Composition — Zulässigkeiten & Fußzeile";
  block.append(lab);

  const lead = document.createElement("p");
  lead.className = "muted";
  lead.style.margin = "0.25rem 0 0.5rem";
  lead.style.fontSize = "0.8rem";
  lead.textContent =
    "Typen nur aus dem Typ-Ast; Basiseinheiten nur unter Basiseinheit. Leer = alle. Fußzeile = gleiche Spaltenzahl; pro Spalte sum/avg/min/max/count.";
  block.append(lead);

  if (!compNode.config) compNode.config = {};
  const cfg = compNode.config;
  if (!cfg.footer) cfg.footer = { enabled: true };

  const footRow = document.createElement("label");
  footRow.className = "choice-row";
  const footCb = document.createElement("input");
  footCb.type = "checkbox";
  footCb.checked = cfg.footer.enabled !== false;
  footCb.disabled = !editable;
  footCb.addEventListener("change", () => {
    if (!compNode.config) compNode.config = {};
    compNode.config.footer = { enabled: footCb.checked };
    persist();
    renderRight();
  });
  footRow.append(
    footCb,
    document.createTextNode(" Fußzeile aktiv (gleiche Spaltenzahl, Ops pro Spalte)")
  );
  block.append(footRow);

  const cols = childrenOf(compNode.id);
  if (cols.length) {
    const opBlock = document.createElement("div");
    opBlock.style.marginTop = "0.75rem";
    const opLab = document.createElement("div");
    opLab.className = "field-label";
    opLab.textContent = "Fußzeile pro Spalte (footer_op)";
    opBlock.append(opLab);
    for (const col of cols) {
      const row = document.createElement("div");
      row.className = "field footer-op-row";
      const l = document.createElement("label");
      l.textContent = col.name;
      l.htmlFor = `footer-op-${col.id}`;
      const sel = document.createElement("select");
      sel.id = `footer-op-${col.id}`;
      sel.className = "form-control";
      sel.disabled = !editable;
      for (const op of FOOTER_OPS) {
        const o = document.createElement("option");
        o.value = op;
        o.textContent = op;
        sel.append(o);
      }
      sel.value = columnFooterOp(col);
      sel.addEventListener("change", () => {
        if (!col.config) col.config = {};
        col.config.footer_op = sel.value;
        persist();
        renderRight();
      });
      row.append(l, sel);
      opBlock.append(row);
    }
    block.append(opBlock);
  }

  /** @param {string} key @param {string} title @param {() => ProtoNode[]} getCandidates */
  const checklist = (key, title, getCandidates) => {
    const sub = document.createElement("div");
    sub.style.marginTop = "0.75rem";
    const t = document.createElement("div");
    t.className = "field-label";
    t.textContent = title;
    sub.append(t);
    const wrap = document.createElement("div");
    wrap.className = "allowlist-grid";
    const selected = new Set(
      Array.isArray(cfg[key]) ? cfg[key] : []
    );
    const cands = getCandidates();
    if (!cands.length) {
      const empty = document.createElement("p");
      empty.className = "muted";
      empty.textContent = "Keine Kandidaten.";
      sub.append(empty);
      return sub;
    }
    for (const c of cands) {
      const row = document.createElement("label");
      row.className = "choice-row";
      const cb = document.createElement("input");
      cb.type = "checkbox";
      cb.checked = selected.size === 0 ? true : selected.has(c.id);
      cb.disabled = !editable;
      cb.addEventListener("change", () => {
        if (!compNode.config) compNode.config = {};
        const cur = Array.isArray(compNode.config[key])
          ? compNode.config[key]
          : [];
        // empty allowlist = all allowed → expand before toggle
        let next = cur.length === 0 ? cands.map((x) => x.id) : [...cur];
        if (cb.checked) {
          if (!next.includes(c.id)) next.push(c.id);
        } else {
          next = next.filter((id) => id !== c.id);
        }
        // all checked → store empty (= all allowed)
        const allOn = cands.every((x) => next.includes(x.id));
        compNode.config[key] = allOn ? [] : next;
        persist();
        renderRight();
      });
      const span = document.createElement("span");
      span.textContent = c.name;
      span.title = nodePath(c.id);
      row.append(cb, span);
      wrap.append(row);
    }
    sub.append(wrap);
    return sub;
  };

  block.append(
    checklist("allowed_types", "Zulässige Typen (Typ-Ast)", () => {
      /** @type {ProtoNode[]} */
      const out = [];
      for (const n of dataTypeNodes()) {
        out.push(n);
        if (n.name.trim().toLowerCase() === "collection") {
          for (const kind of childrenOf(n.id)) {
            out.push(kind);
            out.push(...childrenOf(kind.id));
          }
        }
      }
      return out;
    }),
    checklist("allowed_base_units", "Zulässige Basiseinheiten", () => {
      baseUnitsRootId = healNamedRoot(baseUnitsRootId, "Basiseinheit");
      return baseUnitsRootId ? childrenOf(baseUnitsRootId) : [];
    })
  );
  return block;
}

/**
 * Project Setup: Startknoten (Q59).
 * @param {ProtoNode} rootNode
 */
function buildProjectSetupPanel(rootNode) {
  const p = activeProject();
  if (!p || p.rootId !== rootNode.id) return null;
  const block = document.createElement("div");
  block.className = "order-block project-setup";
  const editable = isProjectEditable();

  const lab = document.createElement("div");
  lab.className = "field-label";
  lab.textContent = "Setup — Startknoten";
  block.append(lab);

  const lead = document.createElement("p");
  lead.className = "muted";
  lead.style.margin = "0.25rem 0 0.5rem";
  lead.style.fontSize = "0.8rem";
  lead.textContent =
    "Standard-Fokus beim Öffnen des Projekts. Typ-Suche bleibt unabhängig nur im Typ-Ast.";
  block.append(lead);

  const row = document.createElement("div");
  row.className = "field";
  const selLab = document.createElement("label");
  selLab.textContent = "Startknoten";
  selLab.htmlFor = "project-start-node";
  const sel = document.createElement("select");
  sel.id = "project-start-node";
  sel.className = "form-control";
  sel.disabled = !editable;
  /** @type {ProtoNode[]} */
  const opts = [rootNode];
  const walk = (id, depth) => {
    for (const c of childrenOf(id)) {
      opts.push(c);
      if (depth < 3) walk(c.id, depth + 1);
    }
  };
  walk(rootNode.id, 0);
  for (const n of opts) {
    const o = document.createElement("option");
    o.value = n.id;
    o.textContent = nodePath(n.id);
    sel.append(o);
  }
  if (p.startNodeId && [...sel.options].some((o) => o.value === p.startNodeId)) {
    sel.value = p.startNodeId;
  }
  sel.addEventListener("change", () => {
    p.startNodeId = sel.value;
    persist();
  });
  row.append(selLab, sel);
  block.append(row);
  return block;
}

/**
 * Checkbox panel: which Präfixe-Kinder are active for this Basiseinheit.
 * @param {ProtoNode} unitNode
 */
function buildAllowedPrefixesPanel(unitNode) {
  const block = document.createElement("div");
  block.className = "order-block allowed-prefixes";
  const editable = isProjectEditable();
  const lab = document.createElement("div");
  lab.className = "field-label";
  lab.textContent = "Zulässige Präfixe (allows_prefix → Kind von Präfixe)";
  block.append(lab);
  const hint = document.createElement("p");
  hint.className = "muted";
  hint.style.margin = "0.25rem 0 0.5rem";
  hint.style.fontSize = "0.8rem";
  hint.textContent =
    "Nach Typ Basiseinheit: aktive Präfix-Kinder festlegen. BOM nutzt nur diese für Wert/Präfix.";
  block.append(hint);

  prefixesRootId = healNamedRoot(prefixesRootId, "Präfixe");
  const prefixes = prefixesRootId ? childrenOf(prefixesRootId) : [];
  if (prefixes.length === 0) {
    const empty = document.createElement("p");
    empty.className = "muted";
    empty.textContent = "Keine Präfixe im Baum.";
    block.append(empty);
    return block;
  }

  const allowed = new Set(allowedPrefixNodesForUnit(unitNode.id).map((n) => n.id));
  const list = document.createElement("div");
  list.className = "prefix-allow-list";
  for (const p of prefixes) {
    const row = document.createElement("label");
    row.className = "choice-row";
    const input = document.createElement("input");
    input.type = "checkbox";
    input.checked = allowed.has(p.id);
    input.disabled = !editable;
    input.addEventListener("change", () =>
      toggleAllowsPrefix(unitNode.id, p.id, input.checked)
    );
    const span = document.createElement("span");
    span.textContent = p.name;
    row.append(input, span);
    list.append(row);
  }
  block.append(list);
  return block;
}

function createTypedCellControl(typeNode, value, onChange, ariaLabel, opts = {}) {
  const key = typeKey(typeNode);

  if (key === "subtree") {
    const select = document.createElement("select");
    select.className = "form-control";
    select.setAttribute("aria-label", ariaLabel);
    const empty = document.createElement("option");
    empty.value = "";
    const scope = opts.slotId ? refScopeRootOf(opts.slotId) : null;
    empty.textContent = scope
      ? `— ${scope.name} wählen —`
      : "— Unterbaum wählen —";
    select.append(empty);
    const cur = value == null ? "" : String(value);
    const options = opts.slotId ? subtreeOptions(opts.slotId) : bauteilCatalogOptions();
    for (const p of options) {
      const opt = document.createElement("option");
      opt.value = p.id;
      opt.textContent = p.name;
      select.append(opt);
    }
    if ([...select.options].some((o) => o.value === cur)) select.value = cur;
    select.addEventListener("change", () => onChange(select.value));
    return select;
  }

  if (key === "node_ref") {
    // Free Absprungpunkt: any project node (path label); value = node id
    const wrap = document.createElement("div");
    wrap.className = "node-ref-cell";
    wrap.style.display = "flex";
    wrap.style.gap = "0.25rem";
    wrap.style.alignItems = "center";
    const select = document.createElement("select");
    select.className = "form-control";
    select.setAttribute("aria-label", ariaLabel);
    select.title = "node_ref — Absprung zu beliebigem Knoten";
    const empty = document.createElement("option");
    empty.value = "";
    empty.textContent = "— Knoten wählen —";
    select.append(empty);
    const cur = value == null ? "" : String(value);
    for (const p of allProjectNodes()) {
      const opt = document.createElement("option");
      opt.value = p.id;
      opt.textContent = nodePath(p.id);
      select.append(opt);
    }
    if ([...select.options].some((o) => o.value === cur)) select.value = cur;
    select.addEventListener("change", () => onChange(select.value));
    const jump = document.createElement("button");
    jump.type = "button";
    jump.className = "btn ghost";
    jump.textContent = "→";
    jump.title = "Zum Knoten springen";
    jump.disabled = !cur || !nodes.has(cur);
    jump.addEventListener("click", () => {
      if (select.value && nodes.has(select.value)) {
        selectNode(select.value);
        setActiveTab("node");
      }
    });
    select.addEventListener("change", () => {
      jump.disabled = !select.value || !nodes.has(select.value);
    });
    wrap.append(select, jump);
    return wrap;
  }

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
    const fixedUnit = opts.fixedUnit || null;
    const unitName = fixedUnit ? fixedUnit.name : parts.u;
    const prefixNames = fixedUnit
      ? allowedPrefixNamesForUnit(fixedUnit.id)
      : prefixOptionNames();
    const locked = Boolean(fixedUnit);
    if (opts.requirePart && !fixedUnit) {
      const msg = document.createElement("span");
      msg.className = "muted";
      msg.textContent = "Zuerst Bauteil wählen";
      return msg;
    }

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
    for (const name of prefixNames) {
      const opt = document.createElement("option");
      opt.value = name;
      opt.textContent = name;
      pref.append(opt);
    }
    if ([...pref.options].some((o) => o.value === parts.p)) pref.value = parts.p;
    else pref.value = "";

    const unit = document.createElement("select");
    unit.className = "form-control";
    unit.style.width = "5rem";
    unit.setAttribute("aria-label", `${ariaLabel} Einheit`);
    if (locked) {
      unit.disabled = true;
      unit.title = `Fixe Einheit für Bauteil: ${unitName}`;
      const opt = document.createElement("option");
      opt.value = unitName;
      opt.textContent = unitName;
      unit.append(opt);
      unit.value = unitName;
    } else {
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
    }
    const emit = () =>
      onChange(
        formatQuantityCell({
          v: num.value,
          p: pref.value,
          u: locked ? unitName : unit.value,
        })
      );
    num.addEventListener("change", emit);
    num.addEventListener("input", emit);
    pref.addEventListener("change", emit);
    unit.addEventListener("change", emit);
    wrap.append(num, pref, unit);
    return wrap;
  }

  if (key === "textarea") {
    const area = document.createElement("textarea");
    area.className = "form-control";
    area.rows = 3;
    area.setAttribute("aria-label", ariaLabel);
    area.placeholder = "Mehrzeilig…";
    area.title = "textarea — später Format/Interpreter (plain/markdown/html)";
    area.value = value == null ? "" : String(value);
    area.addEventListener("change", () => onChange(area.value));
    area.addEventListener("input", () => onChange(area.value));
    return area;
  }

  const input = document.createElement("input");
  input.setAttribute("aria-label", ariaLabel);
  input.className = "form-control";
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
    // text (einzeilig) — HTML input type=text
    input.type = "text";
    input.placeholder = "—";
    input.title = "text — einzeilig";
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
  activeTab = "node";
  focusNameAfterRender = true;
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
    formStates.delete(k);
    instanceAttrs.delete(k);
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
  flushNodeFields();
  selectedId = id;
  activeTab = "node";
  persist();
  render();
}

function normalizeTab(tab) {
  if (RIGHT_TABS.includes(tab)) return /** @type {RightTab} */ (tab);
  if (tab && LEGACY_TAB_MAP[tab]) return /** @type {RightTab} */ (LEGACY_TAB_MAP[tab]);
  return "node";
}

function setActiveTab(tab) {
  activeTab = normalizeTab(tab);
  persist();
  renderRight();
  syncTabs();
}


function treeRowLabelEl(nodeId) {
  try {
    return document.querySelector(
      `.tree-row[data-id="${CSS.escape(nodeId)}"] .label`
    );
  } catch {
    return document.querySelector(`.tree-row[data-id="${nodeId}"] .label`);
  }
}

function applyNodeName(nodeId, rawName, opts = {}) {
  const n = nodes.get(nodeId);
  if (!n) return;
  const next = String(rawName ?? "").trim();
  // Name is display text only — id is the stable key
  if (next) n.name = next;
  persist();
  const label = treeRowLabelEl(n.id);
  if (label) label.textContent = n.name;
  if (!opts.skipTitle) {
    const title = document.querySelector("#detail .detail-card > h2");
    if (title && selectedId === nodeId) {
      const badge = title.querySelector(".readonly-badge");
      title.textContent = n.name;
      if (badge) title.append(" ", badge);
    }
  }
  if (!opts.soft) {
    renderTree();
    renderRight();
  }
}

function applyNodeDescription(nodeId, value) {
  const n = nodes.get(nodeId);
  if (!n) return;
  n.description = String(value ?? "");
  persist();
}

function renameSelected(name, opts = {}) {
  applyNodeName(selectedId, name, opts);
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
    version: 32,
    projects,
    activeProjectId,
    rootId,
    selectedId,
    seq,
    activeTab,
    dataTypesRootId,
    prefixesRootId,
    baseUnitsRootId,
    collectionRootId,
    collapsed: [...collapsed],
    nodes: [...nodes.values()],
    tableCells: mapToObject(tableCells),
    formStates: mapToObject(formStates),
    instanceAttrs: mapToObject(instanceAttrs),
    edges,
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
          kind:
            p.kind === PROJECT_KIND_BOM_TEST
              ? PROJECT_KIND_BOM_TEST
              : p.kind === PROJECT_KIND_COMPOSITION_SIMPLES
                ? PROJECT_KIND_COMPOSITION_SIMPLES
                : PROJECT_KIND_TEMPLATE,
          rootId: p.rootId,
          typesRootId:
            p.typesRootId && nodes.has(p.typesRootId) ? p.typesRootId : "",
          dataTypesRootId:
            p.dataTypesRootId && nodes.has(p.dataTypesRootId) ? p.dataTypesRootId : "",
          prefixesRootId:
            p.prefixesRootId && nodes.has(p.prefixesRootId) ? p.prefixesRootId : "",
          baseUnitsRootId:
            p.baseUnitsRootId && nodes.has(p.baseUnitsRootId) ? p.baseUnitsRootId : "",
          startNodeId:
            p.startNodeId && nodes.has(p.startNodeId) ? p.startNodeId : p.rootId,
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
    activeTab = normalizeTab(data.activeTab);
    tableCells = new Map();
    formStates = new Map();
    instanceAttrs = new Map();
    edges = [];
    collectionRootId = data.collectionRootId && nodes.has(data.collectionRootId)
      ? data.collectionRootId
      : "";
    restoreStringGridMap(data.tableCells, tableCells);
    if (data.formStates && typeof data.formStates === "object") {
      for (const [k, st] of Object.entries(data.formStates)) {
        if (!nodes.has(k) || !st || typeof st !== "object") continue;
        formStates.set(k, { ...defaultFormState(), ...st });
      }
    }
    if (data.instanceAttrs && typeof data.instanceAttrs === "object") {
      for (const [k, attrs] of Object.entries(data.instanceAttrs)) {
        if (!nodes.has(k) || !attrs || typeof attrs !== "object") continue;
        /** @type {Record<string, string>} */
        const clean = {};
        for (const [ak, av] of Object.entries(attrs)) {
          clean[ak] = String(av ?? "");
        }
        instanceAttrs.set(k, clean);
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
    // Heal: ensure every node has description string; normalize config
    for (const n of nodes.values()) {
      if (typeof n.description !== "string") n.description = "";
      if (n.config != null && typeof n.config !== "object") delete n.config;
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
  if (!window.confirm("Projekte zurücksetzen (Template + Demo)?")) return;
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
    localStorage.removeItem("wtt-proto-tree-split-v17");
    localStorage.removeItem("wtt-proto-tree-split-v18");
    localStorage.removeItem("wtt-proto-tree-split-v19");
    localStorage.removeItem("wtt-proto-tree-split-v20");
    localStorage.removeItem("wtt-proto-tree-split-v21");
    localStorage.removeItem("wtt-proto-tree-split-v22");
    localStorage.removeItem("wtt-proto-tree-split-v23");
    localStorage.removeItem("wtt-proto-tree-split-v24");
    localStorage.removeItem("wtt-proto-tree-split-v25");
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
  const extra = [opts.danger ? "danger" : "", opts.add ? "add" : "", opts.className || ""]
    .filter(Boolean)
    .join(" ");
  btn.className = `btn icon${extra ? ` ${extra}` : ""}`;
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
    const addBtn = makeIconButton("+", "Kind hinzufügen", () => addChild(node.id), {
      add: true,
    });
    actions.append(addBtn);
    if (!isProjectRoot(node.id)) {
      const sep = document.createElement("span");
      sep.className = "action-sep";
      sep.setAttribute("aria-hidden", "true");
      actions.append(
        sep,
        makeIconButton("×", "Löschen", () => deleteNode(node.id), { danger: true })
      );
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
  applyNodeDescription(selectedId, value);
}

/** Flush in-progress name/description before the detail pane is torn down. */
function flushNodeFields() {
  const nameEl = document.getElementById("node-name");
  const descEl = document.getElementById("node-desc");
  const nodeId = nameEl?.dataset?.nodeId || descEl?.dataset?.nodeId || selectedId;
  const n = nodes.get(nodeId);
  if (!n) return;
  if (nameEl) {
    const next = nameEl.value.trim();
    if (next) n.name = next;
  }
  if (descEl) {
    n.description = descEl.value;
  }
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
    "Beispiele: has_type → Typ · ref_scope → Katalogwurzel (bei subtree) · allows_prefix → Präfix · multiplikator → int (+ value). Typ-Bindung besser oben im Panel setzen.";
  block.append(hint);

  return block;
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
  const editingId = node.id;
  input.id = "node-name";
  input.type = "text";
  input.className = "form-control node-edit";
  input.value = node.name;
  input.autocomplete = "off";
  input.spellcheck = false;
  input.dataset.nodeId = editingId;
  input.title = "Anzeigename (frei) — Schlüssel ist die id";
  {
    const stop = (e) => e.stopPropagation();
    input.addEventListener("mousedown", stop);
    input.addEventListener("click", stop);
    input.addEventListener("pointerdown", stop);
    input.addEventListener("input", () =>
      applyNodeName(editingId, input.value, { soft: true })
    );
    input.addEventListener("change", () =>
      applyNodeName(editingId, input.value, { soft: true })
    );
    input.addEventListener("keydown", (e) => {
      e.stopPropagation();
      if (e.key === "Enter") {
        e.preventDefault();
        input.blur();
      }
    });
  }
  field.append(lab, input);
  if (isBomComposition(node)) {
    lab.textContent = "Strukturname (Baum / Definition)";
    input.title = "Bleibt „BOM“ — Projektname ist Instanzattribut von Collection, nicht dieser Name.";
    const nameHint = document.createElement("p");
    nameHint.className = "muted bom-name-hint";
    nameHint.style.fontSize = "0.8rem";
    nameHint.style.margin = "0.25rem 0 0";
    nameHint.textContent =
      "Projektname = Attribut unter Collection (vererbt). Wert erst auf der WP-Seite (Tab Backend/Block).";
    field.append(nameHint);
  }

  const descField = document.createElement("div");
  descField.className = "field";
  const descLab = document.createElement("label");
  descLab.htmlFor = "node-desc";
  descLab.textContent = "Beschreibung";
  const desc = document.createElement("textarea");
  desc.id = "node-desc";
  desc.className = "form-control node-edit";
  desc.rows = 3;
  desc.value = node.description || "";
  desc.placeholder = "Kurzbeschreibung des Knotens…";
  desc.dataset.nodeId = editingId;
  {
    const stop = (e) => e.stopPropagation();
    desc.addEventListener("mousedown", stop);
    desc.addEventListener("click", stop);
    desc.addEventListener("pointerdown", stop);
    desc.addEventListener("keydown", stop);
    desc.addEventListener("input", () => applyNodeDescription(editingId, desc.value));
    desc.addEventListener("change", () => applyNodeDescription(editingId, desc.value));
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
    const parentType = typeNodeOf(node.id);
    const pKey = typeKey(parentType);
    childLab.textContent =
      pKey === "table" || pKey === "list"
        ? "Kinder (= Spalten im Backend)"
        : pKey === "enum" || collectionKindOf(node) === "enum"
          ? "Kinder (= Optionen / Spalte)"
          : "Kinder";
    orderBlock.append(childLab, childList);
  }

  const meta = document.createElement("div");
  meta.className = "meta";
  meta.innerHTML = `
    <div>id: <span>${node.id}</span> <em class="hint">(Schlüssel — unveränderlich)</em></div>
    <div>name: <span>freier Anzeigetext</span></div>
    <div>parent: <span>${parent ? parent.name : "— (root)"}</span></div>
    <div>position: <span>${node.position}</span> <em class="hint">(Sortierschlüssel)</em></div>
    <div>children: <span>${kids.length}</span></div>
    <div>template: <span>${node.template ? "yes" : "—"}</span></div>
    <div>relations: <span>${edgesFrom(node.id).length} out / ${edgesTo(node.id).length} in</span></div>
  `;

  const edgesTitle = document.createElement("h3");
  edgesTitle.className = "detail-subtitle";
  edgesTitle.textContent = "Relationen";
  edgesTitle.style.marginTop = "1.5rem";

  const hint = document.createElement("p");
  hint.className = "muted";
  hint.style.marginTop = "1.5rem";
  hint.textContent =
    "Name/Beschreibung sind freier Text (Schlüssel = id). Struktur ändern (Kinder/Relationen) im Template gesperrt.";

  /** Slot constraint: Pflicht/Optional hangs on the Node (config.required), not on has_type. */
  let requiredBlock = null;
  if (isSlotLikeNode(node) || findEdge(node.id, REL_HAS_TYPE)) {
    requiredBlock = document.createElement("div");
    requiredBlock.className = "order-block slot-required";
    const rl = document.createElement("div");
    rl.className = "field-label";
    rl.textContent = "Pflichtfeld";
    const row = document.createElement("label");
    row.className = "choice-row";
    const cb = document.createElement("input");
    cb.type = "checkbox";
    cb.checked = isSlotRequired(node);
    cb.disabled = !editable;
    cb.addEventListener("change", () => setSlotRequired(node.id, cb.checked));
    const span = document.createElement("span");
    span.textContent = "Pflichtfeld";
    row.append(cb, span);
    const rh = document.createElement("p");
    rh.className = "muted";
    rh.style.margin = "0.25rem 0 0";
    rh.style.fontSize = "0.8rem";
    rh.textContent =
      "Am Slot, nicht an has_type. Typ = Form; Pflicht = Ausfüllregel.";
    requiredBlock.append(rl, row, rh);
  }

  const typeBinding = isSlotLikeNode(node) ? buildTypeBindingPanel(node) : null;
  const setupPanel = buildProjectSetupPanel(node);
  const isCompositionTable =
    typeKey(typeNodeOf(node.id)) === "table" &&
    (() => {
      const parent = node.parentId ? nodes.get(node.parentId) : null;
      return parent && parent.name.trim().toLowerCase() === "compositionen";
    })();

  card.append(h2, field, descField);
  if (setupPanel) card.append(setupPanel);
  if (typeBinding) card.append(typeBinding);
  if (requiredBlock) card.append(requiredBlock);
  if (isCompositionTable) card.append(buildCompositionAllowlistPanel(node));
  card.append(orderBlock);
  if (isBaseUnitNode(node)) {
    card.append(buildAllowedPrefixesPanel(node));
  }

  const edgesDetails = document.createElement("details");
  edgesDetails.className = "edges-details";
  edgesDetails.open = false;
  const edgesSum = document.createElement("summary");
  edgesSum.textContent = "Erweitert: Relationen (Roh-Editor)";
  edgesDetails.append(edgesSum, edgesTitle, buildEdgesBlock(node));
  card.append(edgesDetails, meta, hint);
  mount.append(card);

  if (focusNameAfterRender) {
    focusNameAfterRender = false;
    if (editable) {
      queueMicrotask(() => {
        input.focus();
        input.select();
      });
    }
  }
}

/**
 * Instance field Projektname (Q61/Q63) — WP page value, not tree Node.name.
 * @param {ProtoNode} node composition
 * @param {HTMLElement} [titleEl] live title element to refresh
 */
function buildProjektnameInstanceField(node, titleEl) {
  const field = document.createElement("div");
  field.className = "field bom-name-field";
  const lab = document.createElement("label");
  lab.htmlFor = "instance-projektname";
  lab.textContent = "Projektname (Instanz / WP-Seite) *";
  const input = document.createElement("input");
  input.id = "instance-projektname";
  input.type = "text";
  input.className = "form-control";
  input.value = getInstanceAttr(node.id, "Projektname");
  input.placeholder = "z. B. Platinenname oder Projektname";
  input.disabled = !isProjectEditable();
  const slot = projektnameSlot();
  const hint = document.createElement("p");
  hint.className = "muted";
  hint.style.fontSize = "0.8rem";
  hint.style.margin = "0.25rem 0 0";
  hint.textContent = slot
    ? `Definition: Slot „${slot.name}“ unter Collection (vererbt). Baumknoten heißt weiter „${node.name}“.`
    : "Definition: Projektname sollte unter Collection liegen.";
  const syncTitle = () => {
    if (titleEl) titleEl.textContent = bomDisplayTitle(node);
  };
  input.addEventListener("input", () => {
    setInstanceAttr(node.id, "Projektname", input.value);
    syncTitle();
  });
  input.addEventListener("change", () => {
    const next = input.value.trim();
    if (!next) {
      window.alert("Projektname ist Pflicht (Instanzwert auf der Seite).");
      input.value = getInstanceAttr(node.id, "Projektname");
      return;
    }
    setInstanceAttr(node.id, "Projektname", next);
    syncTitle();
  });
  field.append(lab, input, hint);
  return field;
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
  const bomLike = isBomComposition(node);

  const lead = document.createElement("p");
  lead.className = "lead";
  if (cols.length === 0) {
    lead.innerHTML = `<strong>${escapeHtml(title)}</strong> — <strong>${escapeHtml(node.name)}</strong>. Keine Kinder (= Spalten); Kindknoten werden Spaltenköpfe.`;
    wrap.append(lead);
    mount.replaceChildren(wrap);
    return;
  }

  /** @type {HTMLElement|null} */
  let bomTitleEl = null;
  if (title !== "Block") {
    if (bomLike) {
      lead.innerHTML = `<strong>${escapeHtml(title)}</strong> — <em>Instanz</em> (wie WP-Seite): Projektname + Zeilen. Baum-Definition heißt „${escapeHtml(node.name)}“. Menge = Stück.`;
    } else {
      lead.innerHTML = `<strong>${escapeHtml(title)}</strong> — WP-Dateneingabe für <strong>${escapeHtml(node.name)}</strong> · ${cols.length} Spalte${cols.length === 1 ? "" : "n"} · Typ via <code>has_type</code> · ${TABLE_BODY_ROWS} Zeilen.`;
    }
    wrap.append(lead);
  }
  if (bomLike) {
    bomTitleEl = document.createElement("p");
    bomTitleEl.className = "bom-table-title";
    bomTitleEl.textContent = bomDisplayTitle(node);
    wrap.append(buildProjektnameInstanceField(node, bomTitleEl));
  }

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
    if (isSlotRequired(col)) {
      const star = document.createElement("abbr");
      star.className = "req-star";
      star.title = "Pflichtfeld (config.required)";
      star.textContent = "*";
      label.append(star);
    }
    th.append(label);
    if (tn) {
      const badge = document.createElement("span");
      badge.className = "type-badge";
      badge.textContent = tn.name;
      th.append(document.createTextNode(" "), badge);
    }
    const reqHint = isSlotRequired(col) ? " · Pflicht" : " · optional";
    th.title = tn
      ? `„${col.name}“ has_type ${tn.name} → ${typeKey(tn)}${reqHint}`
      : `Spalte „${col.name}“ ohne Typ (Text)${reqHint}`;
    headRow.append(th);
  }
  thead.append(headRow);

  // Part-driven: first subtree column (scoped catalog pick), not hardcoded name
  const partColIdx = cols.findIndex((c) => typeKey(typeNodeOf(c.id)) === "subtree");
  const isBomPartDriven = partColIdx >= 0;

  if (isBomPartDriven && title !== "Block" && lead.isConnected) {
    lead.innerHTML = `<strong>${escapeHtml(title)}</strong> — <strong>${escapeHtml(node.name)}</strong>: <code>subtree</code> wählen → Wert/Präfix nach Gruppe; Einheit typfest; Präfixe = <code>allows_prefix</code>. Menge = <strong>Stück</strong>. * = Pflicht (<code>config.required</code>).`;
  }

  const mengeColIdx = cols.findIndex((c) => c.name.trim().toLowerCase() === "menge");
  const showFooter =
    node.config?.footer?.enabled !== false &&
    (isBomPartDriven || mengeColIdx >= 0);

  const tbody = document.createElement("tbody");
  for (let r = 0; r < TABLE_BODY_ROWS; r++) {
    const tr = document.createElement("tr");
    const tdNum = document.createElement("td");
    tdNum.className = "row-num";
    tdNum.textContent = String(r + 1);
    tr.append(tdNum);
    const partId = partColIdx >= 0 ? String(grid[r]?.[partColIdx] ?? "") : "";
    const fixedUnit = partId ? bauteilFixedUnit(partId) : null;
    for (let c = 0; c < cols.length; c++) {
      const td = document.createElement("td");
      const col = cols[c];
      const tn = typeNodeOf(col.id);
      const colKey = typeKey(tn);
      /** @type {Record<string, unknown>} */
      let cellOpts = { slotId: col.id };
      if (colKey === "quantity" && isBomPartDriven) {
        cellOpts = { slotId: col.id, fixedUnit, requirePart: true };
      }
      const control = createTypedCellControl(
        tn,
        grid[r]?.[c] ?? "",
        (v) => {
          setCellValue(store, node.id, r, c, v);
          if (colKey === "subtree" && c === partColIdx) {
            // Sync Einheit in Wert-Zelle when subtree (Bauteil Wahl) changes
            const wertIdx = cols.findIndex(
              (x) => typeKey(typeNodeOf(x.id)) === "quantity"
            );
            if (wertIdx >= 0) {
              const unit = v ? bauteilFixedUnit(v) : null;
              const prev = parseQuantityCell(grid[r]?.[wertIdx] ?? "");
              setCellValue(
                store,
                node.id,
                r,
                wertIdx,
                formatQuantityCell({
                  v: prev.v,
                  p: unit && allowedPrefixNamesForUnit(unit.id).includes(prev.p)
                    ? prev.p
                    : "",
                  u: unit ? unit.name : "",
                })
              );
            }
            renderRight();
            return;
          }
          // Live-update Fußzeile (gleiche Spaltenzahl, Ops pro Spalte) without losing focus
          if (showFooter) {
            const cells = table.querySelectorAll("tfoot td[data-footer-col]");
            cells.forEach((td) => {
              const idx = Number(td.getAttribute("data-footer-col"));
              if (!Number.isFinite(idx) || !cols[idx]) return;
              const op = columnFooterOp(cols[idx]);
              td.textContent = computeFooterCell(grid, idx, cols[idx]);
              td.className = op === "sum" || op === "avg" ? "footer-agg" : "muted";
              td.title = `${cols[idx].name}: ${op}`;
            });
          }
        },
        `${title}: ${col.name}, Zeile ${r + 1}`,
        cellOpts
      );
      td.append(control);
      tr.append(td);
    }
    tbody.append(tr);
  }

  table.append(thead, tbody);

  // Q57: Fußzeile — same column count; per-cell footer_op (sum/avg/…)
  if (showFooter) {
    const tfoot = document.createElement("tfoot");
    const fr = document.createElement("tr");
    const lab = document.createElement("th");
    lab.scope = "row";
    lab.className = "row-num";
    lab.textContent = "Σ";
    lab.title = "Fußzeile — gleiche Spaltenzahl; Aggregation pro Spalte (footer_op)";
    fr.append(lab);
    for (let c = 0; c < cols.length; c++) {
      const col = cols[c];
      const op = columnFooterOp(col);
      const td = document.createElement("td");
      td.setAttribute("data-footer-col", String(c));
      td.textContent = computeFooterCell(grid, c, col);
      td.className = op === "none" || op === "label" ? "muted" : "footer-agg";
      td.title = `${col.name}: ${op}`;
      fr.append(td);
    }
    tfoot.append(fr);
    table.append(tfoot);
  }

  tableWrap.append(table);
  wrap.append(tableWrap);

  // Q61: Titel unter der Tabelle — „BOM als Bauteilliste – {name}“
  if (bomLike && bomTitleEl) {
    bomTitleEl.textContent = bomDisplayTitle(nodes.get(node.id) || node);
    bomTitleEl.title =
      "Titel unter der Tabelle = „BOM als Bauteilliste – {Projektname}“ (Instanzwert)";
    wrap.append(bomTitleEl);
  }
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
  lead.innerHTML = `<strong>Feld</strong> (Spielwiese, für später) — Kontext <strong>${escapeHtml(node.name)}</strong>. Auswahlfelder nutzen die <strong>${kids.length}</strong> Kindknoten als Optionen.`;
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


/** Collection kinds under Typ-Ast (list / table / enum) — Q62 block art picker. */
function collectionArtCandidates() {
  /** @type {ProtoNode[]} */
  const out = [];
  for (const n of dataTypeNodes()) {
    if (n.name.trim().toLowerCase() !== "collection") continue;
    for (const kind of childrenOf(n.id)) {
      out.push(kind);
      out.push(...childrenOf(kind.id));
    }
  }
  return out;
}

/**
 * WP-Block chrome (Q62): pick Collection art + hint.
 * @param {ProtoNode} node
 */
function buildBlockArtPanel(node) {
  const artBlock = document.createElement("div");
  artBlock.className = "order-block block-art-panel";
  const artLab = document.createElement("div");
  artLab.className = "field-label";
  artLab.textContent = "WordPress-Block — Art der Tabelle";
  const artSel = document.createElement("select");
  artSel.className = "form-control";
  artSel.disabled = !isProjectEditable();
  const artEmpty = document.createElement("option");
  artEmpty.value = "";
  artEmpty.textContent = "— Collection-Knoten wählen —";
  artSel.append(artEmpty);
  if (!node.config) node.config = {};
  const bound = typeNodeOf(node.id);
  const stored = node.config.block_art || bound?.id || "";
  for (const c of collectionArtCandidates()) {
    const o = document.createElement("option");
    o.value = c.id;
    o.textContent = nodePath(c.id);
    artSel.append(o);
  }
  if (stored && [...artSel.options].some((o) => o.value === stored)) {
    artSel.value = stored;
  }
  artSel.addEventListener("change", () => {
    if (!node.config) node.config = {};
    node.config.block_art = artSel.value || undefined;
    persist();
  });
  const artHint = document.createElement("p");
  artHint.className = "muted";
  artHint.style.fontSize = "0.8rem";
  artHint.style.margin = "0.35rem 0 0";
  artHint.textContent =
    "Auswahl = Knoten unter Collection (list / table / enum …). Darunter: Bauteile/Zeilen wie im Backend.";
  artBlock.append(artLab, artSel, artHint);
  return artBlock;
}

/**
 * Tab „Block“ (Q62): Collection-Art wählen, dann dieselbe Tabelle wie Backend + Titel darunter.
 */
function renderFrontend() {
  const mount = document.getElementById("detail");
  if (!mount) return;
  const node = nodes.get(selectedId);
  if (!node) {
    mount.innerHTML = `<p class="muted">Knoten auswählen.</p>`;
    return;
  }

  // BOM: volle Block-Skizze = Art wählen + Backend-Tabelle + Titel
  if (isBomComposition(node)) {
    renderTableView(tableCells, "Block");
    const wrap = mount.querySelector(".table-view");
    if (!wrap) return;
    const chrome = document.createElement("div");
    chrome.className = "block-chrome";
    const lead = document.createElement("p");
    lead.className = "lead";
    lead.innerHTML =
      "<strong>Block</strong> — WP-Seite: Art der Tabelle (Collection) → <em>Projektname</em> (Instanz) → Zeilen. Baum behält Strukturname „BOM“.";
    chrome.append(lead, buildBlockArtPanel(node));
    wrap.insertBefore(chrome, wrap.firstChild);
    return;
  }

  // Non-BOM compositions: light preview
  const cols = childrenOf(node.id);
  const wrap = document.createElement("div");
  wrap.className = "frontend-view";

  const lead = document.createElement("p");
  lead.className = "lead";
  lead.innerHTML = `<strong>Block</strong> — Vorschau für <strong>${escapeHtml(node.name)}</strong>. BOM wählen für volle Block-Skizze (Collection + Tabelle).`;
  wrap.append(lead, buildBlockArtPanel(node));

  if (cols.length === 0) {
    const empty = document.createElement("p");
    empty.className = "muted";
    empty.textContent = "Keine Spalten — Composition mit Spalten wählen.";
    wrap.append(empty);
    mount.replaceChildren(wrap);
    return;
  }

  const note = document.createElement("p");
  note.className = "muted frontend-note";
  note.textContent =
    "Für die BOM-Ansicht: links „BOM“ wählen — Projektname (Instanz) + Tabelle + Titel.";
  wrap.append(note);
  mount.replaceChildren(wrap);
}

function escapeHtml(s) {
  return String(s)
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;");
}

function renderRight() {
  if (activeTab === "backend") renderTableView(tableCells, "Backend");
  else if (activeTab === "frontend") renderFrontend();
  else if (activeTab === "form") renderForm();
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
