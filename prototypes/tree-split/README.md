# Tree split prototype

Static throwaway UI to explore the taxonomy tree screen shape.

**Not** WordPress plugin code. Open `index.html` in a browser (or `python3 -m http.server` in this folder).

## What it does

- Split layout: tree left, detail right
- Demo seed: **BOM Demo** with ordered Stückliste lines + Bauteile
- **+** adds a child (appended at end of sibling order)
- **×** deletes a node (cascade; root protected); sibling `position` values are reindexed
- **↑ / ↓** (tree row + detail pane) move among siblings — explicit `position` (Q13)
- Keyboard: **Alt+↑** / **Alt+↓** on the selected node
- Display order = `position` only (name changes do **not** reorder)
- Positions shown as 1-based index in the tree; raw `position` in the detail meta
- State in `localStorage` (`wtt-proto-tree-split-v2`)

## Model

```text
Node { id, parentId, name, position }
```

`childrenOf(parent)` sorts by `position`, then reindexes to dense `0..n-1` after move/delete/add.

## Extend later

- Drag-and-drop reorder
- Promote vs cascade on delete
- Relations / consists_of in the right pane
- Directed edge chrome
