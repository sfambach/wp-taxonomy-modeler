# Tree split prototype

Static throwaway UI to explore the taxonomy tree screen shape.

**Not** WordPress plugin code. Open `index.html` in a browser (or `python3 -m http.server` in this folder).

## What it does

- Split layout: tree left, multi-tab right
- Demo seed: **Template / BOM Demo** with **Datentypen**:
  - simples: `int`, `double`, `string`, `char`, `bool` (in template)
  - derived **enum**: exactly one `base_type` (→ `string`) + value list as children (`0201`…`axial`)
  - derived **quantity** (*Größe*): value + Präfix + Basiseinheit (not a Messung; not BOM Menge)
- Seeded: **Präfix** (`m`/`k`/`M`/`µ` with `config.factor`) and **Basiseinheit** (`Ohm`, …)
- **Q51:** each Basiseinheit ─[allows_prefix]→ Präfixe
- Right pane **tabs**:
  - **Knoten** — rename, sibling order, **Datentyp** via `has_type`; on enum → **base_type**
  - **Tabelle** / **Tabelle 2** — children = columns; cell widgets from column `has_type`
  - **Formular** — controls from selected node; choice options from children
  - **Umrechnung** — pick a Basiseinheit in the tree; convert Menge between derived units (Ohm ↔ kOhm)
- **+** / **×** / **↑↓** / **Alt+↑↓** (`position` order, Q13)
- State in `localStorage` (`wtt-proto-tree-split-v10`) — Reset once after upgrade

## Umrechnung tab (Q51)

1. Select a child of **Basiseinheit** (e.g. `Ohm`). Other nodes gray out the form.
2. Left: enter value + choose derived unit (`Ohm`, `kOhm`, …).
3. Right: pick another variant of the **same** base unit; value is computed via `value × left.factor / right.factor`.
4. Labels are generated (Vater + Präfix) — no stored `kOhm` nodes.

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
   - `quantity` → number + Präfix + Basiseinheit (cell: `value|prefix|unit`)

## Model

```text
Node { id, parentId, name, position, template?, config? }
typeRelations:          slotId → typeNodeId      // has_type
baseTypeRelations:      enumId → simpleTypeId    // base_type
allowsPrefixRelations:  unitId → prefixId[]      // allows_prefix
Präfix.config.factor     e.g. k → 1000
```

## Extend later

- Full Relation objects (not only maps)
- string_list widgets
- Drag-and-drop reorder
- Edit allows_prefix / factor in Knoten tab
