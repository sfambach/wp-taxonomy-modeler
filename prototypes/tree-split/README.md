# Tree split prototype

Static throwaway UI to explore the taxonomy tree screen shape.

**Not** WordPress plugin code. Open `index.html` in a browser (or `python3 -m http.server` in this folder).

## What it does

- Split layout: tree left, multi-tab right
- **Project switcher** — two Projects in one seed:
  - **Template (nur lesen)** — Datentypen (enum ohne Werte), Präfix, Standard-Basiseinheiten — **nicht änderbar**
  - **BOM Testprojekt (editierbar)** — Template-Kern-Kopie + Bauart, Ohm/Farad/Watt/Volt, Spalten / Stückliste / Bauteile
- Every node has **name** + **description**
- Right pane **tabs**:
  - **Knoten** — name, description, sibling order (no relation editors here)
  - **Relationen** — list/add/remove edges; edit `multiplikator` value
  - **Tabelle** / **Tabelle 2** — typed columns via `has_type`
  - **Formular** — demo controls
  - **Umrechnung** — convert within a Basiseinheit family (forward & back)
- State: `localStorage` key `wtt-proto-tree-split-v13` — **Reset** after upgrade

## Seed trees

```text
Template (read-only)
├── Datentypen
│   ├── int · double · string · char · bool
│   ├── enum                         ← keine konkreten Werte
│   └── quantity
├── Präfix (p…M + c; multiplikator)
└── Basiseinheit
    ├── Meter · Liter · Kilogramm · Sekunde · Kelvin · Ampere

BOM Testprojekt (editable)
├── Datentypen / Präfix / Basiseinheit   ← Kopie + Erweiterungen
│   └── enum
│       └── Bauart ─[base_type]→ string
│           └── 0201 · 0402 · 0603 · 0805 · axial
│   └── Basiseinheit + Ohm · Farad · Watt · Volt
├── Spalten (BOM-Zeile)   Footprint ─[has_type]→ Bauart
├── Stückliste
└── Bauteile
```

## Q51 model in the seed

```text
Präfix k ─[multiplikator]→ int   props.value = 1000
Basiseinheit Ohm ─[allows_prefix]→ m, k, M, µ, n, p   # BOM only
Basiseinheit Farad ─[allows_prefix]→ p, n, µ, m       # BOM only
Basiseinheit Meter ─[allows_prefix]→ µ, m, c, k       # template + copy
```

Umrechnung: `out = in × left.multiplikator / right.multiplikator`.

## Edges

```text
Edge { id, from, to, label, props? }
labels: has_type | base_type | allows_prefix | multiplikator | …
```
