# Tree split prototype

Static throwaway UI to explore the taxonomy tree screen shape.

**Not** WordPress plugin code. Open `index.html` in a browser (or `python3 -m http.server` in this folder).

## What it does

- Split layout: tree left, multi-tab right
- Demo seed: **BOM Demo** with Spalten, Stückliste, Bauteile
- Right pane **tabs**:
  - **Knoten** — rename, sibling order
  - **Tabelle** / **Tabelle 2** — same schema (children = columns), separate cell stores; header + 5 rows
  - **Formular** — GUI controls driven by selected node; choice options from children
- **+** / **×** / **↑↓** / **Alt+↑↓** as before (`position` order, Q13)
- State in `localStorage` (`wtt-proto-tree-split-v4`)

## Form tab (from selected node)

| Control | Source |
| --- | --- |
| Dropdown / Radio / Checkbox / Multi-select / Datalist | **Children** as options |
| Switch (boolean), text, textarea, number, range, color, date, time, email, url, file | **Selected node** as context/label |

## Model

```text
Node { id, parentId, name, position }
```

## Extend later

- Drag-and-drop reorder
- Typed parameters per column / form field
- Relations in the right pane
