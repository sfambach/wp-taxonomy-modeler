# Tree split prototype

Static throwaway UI to explore the taxonomy tree screen shape.

**Not** WordPress plugin code. Open `index.html` in a browser (or `python3 -m http.server` in this folder).

## What it does

- Split layout: tree left, multi-tab right
- Demo seed: **Template / BOM Demo** with **Datentypen**:
  - simples: `int`, `double`, `string`, `char`, `bool` (in template)
  - derived **enum**: exactly one `base_type` (→ `string`) + value list as children (`0201`…`axial`)
- Right pane **tabs**:
  - **Knoten** — rename, sibling order, **Datentyp** via `has_type`; on enum → **base_type**
  - **Tabelle** / **Tabelle 2** — children = columns; cell widgets from column `has_type` (enum → select)
  - **Formular** — controls from selected node; choice options from children
- **+** / **×** / **↑↓** / **Alt+↑↓** (`position` order, Q13)
- State in `localStorage` (`wtt-proto-tree-split-v8`) — Reset once after upgrade

## Typed columns (Q48)

1. Types live under **Datentypen** (template branch).
2. On a schema column node, assign **Datentyp** (`has_type`).
3. Table headers show a type badge; cells render:
   - `int` → number (step 1)
   - `double` → number (step any)
   - `string` → text
   - `char` → text maxlength 1
   - `bool` → checkbox
   - `enum` → select from child value list (base_type = one simple)

## Model

```text
Node { id, parentId, name, position, template? }
typeRelations:     slotId → typeNodeId     // Relation has_type
baseTypeRelations: enumId → simpleTypeId   // Relation base_type (exactly one)
```

## Extend later

- Full Relation objects (not only maps)
- measure / string_list widgets
- Drag-and-drop reorder
