# Tree split prototype

Static throwaway UI to explore the taxonomy tree screen shape.

**Not** WordPress plugin code. Open `index.html` in a browser (or `python3 -m http.server` in this folder).

## What it does

- Split layout: tree left, detail/table right
- Demo seed: **BOM Demo** with **Spalten (BOM-Zeile)**, Stückliste, Bauteile
- Right pane **tabs**: **Knoten** | **Tabelle**
- **Tabelle**: children of the selected node = column headers (`position` order); 1 header + 5 body rows
- **+** adds a child (appended at end of sibling order)
- **×** deletes a node (cascade; root protected); sibling `position` values are reindexed
- **↑ / ↓** (tree row + detail pane) move among siblings — explicit `position` (Q13)
- Keyboard: **Alt+↑** / **Alt+↓** on the selected node
- Display order = `position` only (name changes do **not** reorder)
- State in `localStorage` (`wtt-proto-tree-split-v3`)

## Model

```text
Node { id, parentId, name, position }
```

`childrenOf(parent)` sorts by `position`, then reindexes to dense `0..n-1` after move/delete/add.

Table cells are prototype placeholders keyed by the schema (selected) node id.

## Extend later

- Drag-and-drop reorder
- Promote vs cascade on delete
- Relations / consists_of in the right pane
- Persist typed cell values / row count
- Directed edge chrome
