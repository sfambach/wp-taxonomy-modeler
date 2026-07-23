/**
 * WTT tree-split prototype — in-memory Node model.
 * Shape mirrors planning Node: { id, parentId, name, position }
 *
 * Sibling order (Q13): explicit `position`.
 * Right pane tabs:
 *   - Knoten
 *   - Tabelle / Tabelle 2 — children = column schema (header + 5 rows)
 *   - Formular — selected node = field context; children = choice options
 */

const STORAGE_KEY = "wtt-proto-tree-split-v4";
const TABLE_BODY_ROWS = 5;
const RIGHT_TABS = ["node", "table", "table2", "form"];
const TAB_ARIA = {
  node: "tab-node",
  table: "tab-table",
  table2: "tab-table2",
  form: "tab-form",
};

/** @typedef {{ id: string, parentId: string|null, name: string, position: number }} ProtoNode */
/** @typedef {'node'|'table'|'table2'|'form'} RightTab */
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

function createNode(parentId, name, position) {
  const id = uid();
  nodes.set(id, { id, parentId, name, position });
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

function createInitial() {
  nodes = new Map();
  collapsed = new Set();
  seq = 1;
  tableCells = new Map();
  tableCells2 = new Map();
  formStates = new Map();
  activeTab = "node";

  rootId = createNode(null, "BOM Demo", 0);

  const schemaId = createNode(rootId, "Spalten (BOM-Zeile)", 0);
  createNode(schemaId, "Designator", 0);
  createNode(schemaId, "Value", 1);
  createNode(schemaId, "Footprint", 2);
  createNode(schemaId, "Menge", 3);
  createNode(schemaId, "LCSC", 4);

  const listId = createNode(rootId, "Stückliste", 1);
  createNode(listId, "C1 — 100 nF 0603", 0);
  createNode(listId, "R1 — 10 kΩ 0603", 1);
  createNode(listId, "U1 — ESP32-WROOM-32", 2);
  createNode(listId, "D1 — LED green 0805", 3);

  const partsId = createNode(rootId, "Bauteile", 2);
  createNode(partsId, "Kondensator", 0);
  createNode(partsId, "Widerstand", 1);
  createNode(partsId, "IC", 2);
  createNode(partsId, "Diode / LED", 3);

  selectedId = schemaId;
  collapsed.clear();
}

function addChild(parentId) {
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
  if (id === rootId) {
    window.alert("Root kann nicht gelöscht werden.");
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
  }

  if (parentId != null && nodes.has(parentId)) reindexSiblings(parentId);

  if (kill.has(selectedId)) {
    selectedId = nodes.has(parentId) ? parentId : rootId;
  }
  persist();
  render();
}

function moveSibling(id, delta) {
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
    rootId,
    selectedId,
    seq,
    activeTab,
    collapsed: [...collapsed],
    nodes: [...nodes.values()],
    tableCells: mapToObject(tableCells),
    tableCells2: mapToObject(tableCells2),
    formStates: mapToObject(formStates),
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
    rootId = data.rootId;
    selectedId = nodes.has(data.selectedId) ? data.selectedId : rootId;
    seq = Number(data.seq) || nodes.size;
    collapsed = new Set(
      (data.collapsed || []).filter((id) => nodes.has(id))
    );
    activeTab = RIGHT_TABS.includes(data.activeTab) ? data.activeTab : "node";
    tableCells = new Map();
    tableCells2 = new Map();
    formStates = new Map();
    restoreStringGridMap(data.tableCells, tableCells);
    restoreStringGridMap(data.tableCells2, tableCells2);
    if (data.formStates && typeof data.formStates === "object") {
      for (const [k, st] of Object.entries(data.formStates)) {
        if (!nodes.has(k) || !st || typeof st !== "object") continue;
        formStates.set(k, { ...defaultFormState(), ...st });
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
  if (!window.confirm("Baum zurücksetzen (BOM-Demo inkl. Reihenfolge)?")) return;
  localStorage.removeItem(STORAGE_KEY);
  try {
    localStorage.removeItem("wtt-proto-tree-split");
    localStorage.removeItem("wtt-proto-tree-split-v2");
    localStorage.removeItem("wtt-proto-tree-split-v3");
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

  if (canMove) {
    actions.append(
      makeIconButton("↑", "Nach oben (Position −1)", () => moveSibling(node.id, -1), {
        disabled: index <= 0,
      }),
      makeIconButton("↓", "Nach unten (Position +1)", () => moveSibling(node.id, 1), {
        disabled: index >= total - 1,
      })
    );
  }

  actions.append(
    makeIconButton("+", "Kind hinzufügen", () => addChild(node.id)),
    makeIconButton("×", "Löschen", () => deleteNode(node.id), {
      danger: true,
      disabled: node.id === rootId,
    })
  );
  if (node.id === rootId) {
    const del = actions.querySelector(".danger");
    if (del) del.style.visibility = "hidden";
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

function renderTree() {
  const mount = document.getElementById("tree-root");
  if (!mount) return;
  mount.replaceChildren();
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

  mount.replaceChildren();
  const card = document.createElement("div");
  card.className = "detail-card";

  const h2 = document.createElement("h2");
  h2.textContent = node.name;

  const field = document.createElement("div");
  field.className = "field";
  const lab = document.createElement("label");
  lab.htmlFor = "node-name";
  lab.textContent = "Name";
  const input = document.createElement("input");
  input.id = "node-name";
  input.type = "text";
  input.value = node.name;
  input.addEventListener("change", () => renameSelected(input.value));
  input.addEventListener("keydown", (e) => {
    if (e.key === "Enter") {
      e.preventDefault();
      input.blur();
    }
  });
  field.append(lab, input);

  const orderBlock = document.createElement("div");
  orderBlock.className = "order-block";
  const orderTitle = document.createElement("div");
  orderTitle.className = "field-label";
  orderTitle.textContent = "Geschwister-Reihenfolge";
  const orderRow = document.createElement("div");
  orderRow.className = "order-controls";

  if (canMove) {
    const up = document.createElement("button");
    up.type = "button";
    up.className = "btn";
    up.textContent = "↑ Nach oben";
    up.disabled = index <= 0;
    up.addEventListener("click", () => moveSibling(node.id, -1));

    const down = document.createElement("button");
    down.type = "button";
    down.className = "btn";
    down.textContent = "↓ Nach unten";
    down.disabled = index >= total - 1;
    down.addEventListener("click", () => moveSibling(node.id, 1));

    const status = document.createElement("span");
    status.className = "order-status";
    status.textContent = `${index + 1} von ${total}`;
    orderRow.append(up, down, status);
  } else {
    const status = document.createElement("span");
    status.className = "order-status muted";
    status.textContent = "Root — keine Geschwister-Position";
    orderRow.append(status);
  }
  orderBlock.append(orderTitle, orderRow);

  if (kids.length > 0) {
    const childList = document.createElement("ol");
    childList.className = "child-order";
    childList.start = 1;
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
  `;

  const hint = document.createElement("p");
  hint.className = "muted";
  hint.style.marginTop = "1.5rem";
  hint.textContent =
    "Tabelle(n): Kinder → Spalten. Formular: Knoten = Kontext, Kinder → Dropdown/Radio/Checkbox-Optionen. Alt+↑ / Alt+↓.";

  card.append(h2, field, orderBlock, meta, hint);
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

  lead.innerHTML = `<strong>${escapeHtml(title)}</strong> — Schema: <strong>${escapeHtml(node.name)}</strong> · ${cols.length} Spalte${cols.length === 1 ? "" : "n"} · ${TABLE_BODY_ROWS} Zeilen (eigene Zellen-Daten).`;
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
    th.textContent = col.name;
    th.title = `Spalte aus Kind „${col.name}“ (position ${col.position})`;
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
      const input = document.createElement("input");
      input.type = "text";
      input.value = grid[r]?.[c] ?? "";
      input.placeholder = "—";
      input.setAttribute("aria-label", `${title}: ${cols[c].name}, Zeile ${r + 1}`);
      input.addEventListener("change", () => {
        setCellValue(store, node.id, r, c, input.value);
      });
      td.append(input);
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
  switchInput.role = "switch";
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

function renderRight() {
  if (activeTab === "table") renderTableView(tableCells, "Tabelle");
  else if (activeTab === "table2") renderTableView(tableCells2, "Tabelle 2");
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
  document.querySelectorAll(".tab[data-tab]").forEach((el) => {
    el.addEventListener("click", () => setActiveTab(el.dataset.tab));
  });
  document.addEventListener("keydown", onKeyDown);
  persist();
  render();
}

init();
