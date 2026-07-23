/**
 * WTT tree-split prototype — in-memory Node model.
 * Shape mirrors planning Node: { id, parentId, name, position }
 *
 * Sibling order (Q13 leaning): explicit `position` only — not name sort.
 * Right pane: tabs — Knoten | Tabelle (children of selection = column config).
 */

const STORAGE_KEY = "wtt-proto-tree-split-v3";
const TABLE_BODY_ROWS = 5;

/** @typedef {{ id: string, parentId: string|null, name: string, position: number }} ProtoNode */
/** @typedef {'node'|'table'} RightTab */

/** @type {Map<string, ProtoNode>} */
let nodes = new Map();
let rootId = "";
let selectedId = "";
/** @type {Set<string>} */
let collapsed = new Set();
let seq = 1;
/** @type {RightTab} */
let activeTab = "node";
/**
 * Placeholder cell values: schemaNodeId → rowIndex → colNodeId → string
 * @type {Map<string, string[][]>}
 */
let tableCells = new Map();

function uid() {
  return `n_${Date.now().toString(36)}_${Math.random().toString(36).slice(2, 7)}`;
}

/**
 * Sibling list sorted by explicit position (Q13).
 * Name is only a last-resort tiebreaker if two positions collide.
 */
function childrenOf(parentId) {
  return [...nodes.values()]
    .filter((n) => n.parentId === parentId)
    .sort((a, b) => {
      if (a.position !== b.position) return a.position - b.position;
      return a.name.localeCompare(b.name, "de");
    });
}

/** Normalize sibling positions to dense 0..n-1 in current display order. */
function reindexSiblings(parentId) {
  const kids = childrenOf(parentId);
  kids.forEach((k, i) => {
    k.position = i;
  });
}

function nextPosition(parentId) {
  const kids = childrenOf(parentId);
  return kids.length;
}

function createNode(parentId, name, position) {
  const id = uid();
  nodes.set(id, { id, parentId, name, position });
  return id;
}

function createInitial() {
  nodes = new Map();
  collapsed = new Set();
  seq = 1;
  tableCells = new Map();
  activeTab = "node";

  rootId = createNode(null, "BOM Demo", 0);

  // Children = table column config when this node is selected.
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

  selectedId = schemaId;
  collapsed.clear();
}

function addChild(parentId) {
  const parent = nodes.get(parentId);
  if (!parent) return;
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
  }

  if (parentId != null && nodes.has(parentId)) {
    reindexSiblings(parentId);
  }

  if (kill.has(selectedId)) {
    selectedId = nodes.has(parentId) ? parentId : rootId;
  }
  persist();
  render();
}

/**
 * Move node among siblings by delta (-1 = up, +1 = down).
 * Swaps positions, then reindexes so order stays dense.
 */
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
  if (tab !== "node" && tab !== "table") return;
  activeTab = tab;
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

/** Ensure a TABLE_BODY_ROWS × columns grid exists for schemaNodeId. */
function ensureTableGrid(schemaNodeId, colIds) {
  let rows = tableCells.get(schemaNodeId);
  if (!rows) {
    rows = Array.from({ length: TABLE_BODY_ROWS }, () =>
      colIds.map(() => "")
    );
    tableCells.set(schemaNodeId, rows);
    return rows;
  }
  // Align width to current columns (by index; order follows children position).
  while (rows.length < TABLE_BODY_ROWS) {
    rows.push(colIds.map(() => ""));
  }
  if (rows.length > TABLE_BODY_ROWS) rows.length = TABLE_BODY_ROWS;
  for (let r = 0; r < rows.length; r++) {
    const row = rows[r] || [];
    const next = colIds.map((_, c) => (row[c] != null ? String(row[c]) : ""));
    rows[r] = next;
  }
  tableCells.set(schemaNodeId, rows);
  return rows;
}

function setCellValue(schemaNodeId, rowIndex, colIndex, value) {
  const cols = childrenOf(schemaNodeId);
  const rows = ensureTableGrid(
    schemaNodeId,
    cols.map((c) => c.id)
  );
  if (!rows[rowIndex]) return;
  rows[rowIndex][colIndex] = value;
  persist();
}

function persist() {
  const cellObj = {};
  for (const [k, rows] of tableCells) {
    cellObj[k] = rows;
  }
  const payload = {
    rootId,
    selectedId,
    seq,
    activeTab,
    collapsed: [...collapsed],
    nodes: [...nodes.values()],
    tableCells: cellObj,
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
    activeTab = data.activeTab === "table" ? "table" : "node";
    tableCells = new Map();
    if (data.tableCells && typeof data.tableCells === "object") {
      for (const [k, rows] of Object.entries(data.tableCells)) {
        if (nodes.has(k) && Array.isArray(rows)) tableCells.set(k, rows);
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
    panel.setAttribute(
      "aria-labelledby",
      activeTab === "table" ? "tab-table" : "tab-node"
    );
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
    childLab.textContent = "Kinder (= Tabellenspalten im Tab „Tabelle“)";
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
    "Tab „Tabelle“: Kinder dieses Knotens werden zur Spalten-Konfiguration. Sortierung = position (Q13). Alt+↑ / Alt+↓.";

  card.append(h2, field, orderBlock, meta, hint);
  mount.append(card);
}

function renderTable() {
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
    lead.innerHTML = `Schema: <strong>${escapeHtml(node.name)}</strong> — keine Kinder. Lege links Kindknoten an; deren Namen werden Spaltenköpfe.`;
    wrap.append(lead);
    mount.replaceChildren(wrap);
    return;
  }

  lead.innerHTML = `Schema: <strong>${escapeHtml(node.name)}</strong> — ${cols.length} Spalte${cols.length === 1 ? "" : "n"} aus Kindknoten (Reihenfolge = <code>position</code>). ${TABLE_BODY_ROWS} Datenzeilen.`;
  wrap.append(lead);

  const grid = ensureTableGrid(
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
      input.setAttribute("aria-label", `${cols[c].name}, Zeile ${r + 1}`);
      input.addEventListener("change", () => {
        setCellValue(node.id, r, c, input.value);
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

function escapeHtml(s) {
  return String(s)
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;");
}

function renderRight() {
  if (activeTab === "table") renderTable();
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
  if (tag === "INPUT" || tag === "TEXTAREA") return;
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
