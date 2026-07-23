# Tree split prototype

Static throwaway UI to explore the taxonomy tree screen shape.

**Not** WordPress plugin code. Open `index.html` in a browser (or `python3 -m http.server` in this folder).

## What it does

- Split layout: tree left, multi-tab right
- Demo seed: **BOM Demo** with **Datentypen** (int/double/string/char/bool), Spalten, Stückliste, Bauteile
- Right pane **tabs**:
  - **Knoten** — rename, sibling order, **Datentyp** via Relation `has_type`
  - **Tabelle** / **Tabelle 2** — children = columns; cell widgets from column `has_type`
  - **Formular** — controls from selected node; choice options from children
- **+** / **×** / **↑↓** / **Alt+↑↓** (`position` order, Q13)
- State in `localStorage` (`wtt-proto-tree-split-v5`)

## Typed columns (Q48)

1. Types live under **Datentypen** (editable tree Nodes).
2. On a schema column node, assign **Datentyp** (`has_type`).
3. Table headers show a type badge; cells render:
   - `int` → number (step 1)
   - `double` → number (step any)
   - `string` → text
   - `char` → text maxlength 1
   - `bool` → checkbox

## Model

```text
Node { id, parentId, name, position }
typeRelations: slotId → typeNodeId   // prototype of Relation has_type
```

## Extend later

- Full Relation objects (not only has_type map)
- enum / measure / string_list widgets
- Drag-and-drop reorder
