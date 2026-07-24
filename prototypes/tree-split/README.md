# Tree split prototype

Static throwaway UI to explore the taxonomy tree screen shape.

**Not** WordPress plugin code. Open `index.html` in a browser (or `python3 -m http.server` in this folder).

## What it does

- Split layout: tree left, multi-tab right
- **Project switcher** — two Projects in one seed:
  - **Template (rein)** — only Datentypen, Präfix, Basiseinheit
  - **BOM Testprojekt** — copied template core + Spalten / Stückliste / Bauteile (demo data)
- Every node has **name** + **description**
- Right pane **tabs**:
  - **Knoten** — name, description, sibling order (no relation editors here)
  - **Relationen** — list/add/remove edges; edit `multiplikator` value
  - **Tabelle** / **Tabelle 2** — typed columns via `has_type`
  - **Formular** — demo controls
  - **Umrechnung** — convert within a Basiseinheit family (forward & back)
- State: `localStorage` key `wtt-proto-tree-split-v12` — **Reset** after upgrade

## Seed trees

```text
Template
├── Datentypen (int…bool, enum, quantity)
├── Präfix (p…M + multiplikator)
└── Basiseinheit (Ohm, Farad, … + allows_prefix)

BOM Testprojekt
├── Datentypen / Präfix / Basiseinheit   ← copy of template core (Q50 lean)
├── Spalten (BOM-Zeile)                   ← schema demo
├── Stückliste                            ← instance demo
└── Bauteile                              ← catalog demo
```

## Q51 model in the seed

```text
Präfix k ─[multiplikator]→ int   props.value = 1000
Basiseinheit Ohm ─[allows_prefix]→ m, k, M, µ, n, p
Basiseinheit Farad ─[allows_prefix]→ p, n, µ, m   # no k/M (kein Mega-Farad)
```

Umrechnung: `out = in × left.multiplikator / right.multiplikator`.

## Edges

```text
Edge { id, from, to, label, props? }
labels: has_type | base_type | allows_prefix | multiplikator | …
```
