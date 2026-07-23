# Tree split prototype

Static throwaway UI to explore the taxonomy tree screen shape.

**Not** WordPress plugin code. Open `index.html` in a browser.

## What it does (v1)

- Split layout: tree left, detail right
- One initial root node
- **+** adds a child under the selected/hovered row’s node
- Trash deletes a node (cascade to descendants; root cannot be deleted)
- Click a node to select it; right pane shows / edits the name
- State persisted in `localStorage` (key `wtt-proto-tree-split`)

## Extend later

Ideas already shaped by planning docs:

- Explicit `position` / reorder siblings
- Promote vs cascade on delete
- Relations / consists_of attributes in the right pane
- BOM-schema instance as the tree content
- Directed edge chrome

Keep the in-memory model in `app.js` (`nodes` map + `rootId`) aligned with conceptual `Node { id, parentId, name, position }`.
