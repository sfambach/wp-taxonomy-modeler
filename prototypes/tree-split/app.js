/**
 * WTT tree-split prototype — in-memory Node model.
 * Shape mirrors planning Node: { id, parentId, name, position }
 * Extend later: relations, promote-on-delete, reorder, etc.
 */

const STORAGE_KEY = "wtt-proto-tree-split";

/** @typedef {{ id: string, parentId: string|null, name: string, position: number }} ProtoNode */

/** @type {Map<string, ProtoNode>} */
let nodes = new Map();
let rootId = "";
let selectedId = "";
/** @type {Set<string>} */
let collapsed = new Set();
let seq = 1;

function uid() {
  return `n_${Date.now().toString(36)}_${Math.random().toString(36).slice(2, 7)}`;
}

function createInitial() {
  nodes = new Map();
  collapsed = new Set();
  seq = 1;
  const id = uid();
  nodes.set(id, { id, parentId: null, name: "Root", position: 0 });
  rootId = id;
  selectedId = id;
}

function childrenOf(parentId) {
  return [...nodes.values()]
    .filter((n) => n.parentId === parentId)
    .sort((a, b) => a.position - b.position || a.name.localeCompare(b.name));
}

function nextPosition(parentId) {
  const kids = childrenOf(parentId);
  return kids.length === 0 ? 0 : Math.max(...kids.map((k) => k.position)) + 1;
}

function addChild(parentId) {
  const parent = nodes.get(parentId);
  if (!parent) return;
  const id = uid();
  const name = `Knoten ${seq++}`;
  nodes.set(id, {
    id,
    parentId,
    name,
    position: nextPosition(parentId),
  });
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

  const kill = new Set([id, ...descendants(id)]);
  for (const k of kill) nodes.delete(k);

  if (kill.has(selectedId)) {
    selectedId = nodes.has(node.parentId) ? node.parentId : rootId;
  }
  persist();
  render();
}

function selectNode(id) {
  if (!nodes.has(id)) return;
  selectedId = id;
  persist();
  render();
}

function renameSelected(name) {
  const n = nodes.get(selectedId);
  if (!n) return;
  n.name = name.trim() || n.name;
  persist();
  renderTree();
  renderDetail();
}

function toggleCollapse(id, event) {
  event.stopPropagation();
  if (collapsed.has(id)) collapsed.delete(id);
  else collapsed.add(id);
  persist();
  renderTree();
}

function persist() {
  const payload = {
    rootId,
    selectedId,
    seq,
    collapsed: [...collapsed],
    nodes: [...nodes.values()],
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
    return true;
  } catch {
    return false;
  }
}

function resetAll() {
  if (!window.confirm("Baum zurücksetzen?")) return;
  localStorage.removeItem(STORAGE_KEY);
  createInitial();
  persist();
  render();
}

function renderTreeRow(node, depth) {
  const kids = childrenOf(node.id);
  const hasKids = kids.length > 0;
  const isCollapsed = collapsed.has(node.id);
  const isSelected = node.id === selectedId;

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

  const label = document.createElement("span");
  label.className = "label";
  label.textContent = node.name;

  const actions = document.createElement("div");
  actions.className = "actions";

  const addBtn = document.createElement("button");
  addBtn.type = "button";
  addBtn.className = "btn icon";
  addBtn.title = "Kind hinzufügen";
  addBtn.setAttribute("aria-label", "Kind hinzufügen");
  addBtn.textContent = "+";
  addBtn.addEventListener("click", (e) => {
    e.stopPropagation();
    addChild(node.id);
  });

  const delBtn = document.createElement("button");
  delBtn.type = "button";
  delBtn.className = "btn icon danger";
  delBtn.title = "Löschen";
  delBtn.setAttribute("aria-label", "Löschen");
  delBtn.textContent = "🗑";
  delBtn.disabled = node.id === rootId;
  if (node.id === rootId) delBtn.style.visibility = "hidden";
  delBtn.addEventListener("click", (e) => {
    e.stopPropagation();
    deleteNode(node.id);
  });

  actions.append(addBtn, delBtn);
  row.append(twist, label, actions);
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

  const meta = document.createElement("div");
  meta.className = "meta";
  meta.innerHTML = `
    <div>id: <span>${node.id}</span></div>
    <div>parent: <span>${parent ? parent.name : "— (root)"}</span></div>
    <div>position: <span>${node.position}</span></div>
    <div>children: <span>${kids.length}</span></div>
  `;

  const hint = document.createElement("p");
  hint.className = "muted";
  hint.style.marginTop = "1.5rem";
  hint.textContent =
    "Rechts später: Attribute, Relations, BOM-Zeile, … — Modell in app.js erweiterbar.";

  card.append(h2, field, meta, hint);
  mount.append(card);
}

function render() {
  renderTree();
  renderDetail();
}

function init() {
  if (!restore()) createInitial();
  document.getElementById("btn-reset")?.addEventListener("click", resetAll);
  persist();
  render();
}

init();
