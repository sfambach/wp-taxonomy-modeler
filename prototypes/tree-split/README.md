# Tree split prototype

Static throwaway UI to explore the taxonomy tree screen shape.

**Not** WordPress plugin code. Open `index.html` in a browser (or `python3 -m http.server` in this folder).

## What it does

- Split layout: tree left, multi-tab right
- **Project switcher**:
  - **Template (nur lesen)** — simples, `quantity`, **Collection** (`list` / `table` / `enum`), Präfix, Standard-Basiseinheiten
  - **BOM Testprojekt (editierbar)** — Kern-Kopie + concrete Collections + Spalten / Stückliste / Bauteile
- **Collection (Q52):** enum is created **like list** — one typed column; enum adds closed options under that column
- State: `localStorage` key `wtt-proto-tree-split-v14` — **Reset** after upgrade

## Seed trees

```text
Template (read-only)
├── Datentypen
│   ├── int · double · string · char · bool
│   ├── quantity
│   └── Collection
│       ├── list
│       ├── table
│       └── enum
├── Präfix (p…M + c)
└── Basiseinheit
    └── Meter · Liter · Kilogramm · Sekunde · Kelvin · Ampere

BOM Testprojekt (editable)
├── Datentypen → Collection
│   ├── list
│   │   └── RefDes
│   │       └── Element ─[has_type]→ string
│   ├── table
│   └── enum
│       └── Bauart
│           └── Option ─[has_type]→ string
│               └── 0201 · 0402 · 0603 · 0805 · axial
├── Basiseinheit + Ohm · Farad · Watt · Volt
├── Spalten (BOM-Zeile) ─[has_type]→ table
│   ├── Reference ─[has_type]→ RefDes
│   ├── Value     ─[has_type]→ quantity
│   ├── Footprint ─[has_type]→ Bauart
│   ├── Menge     ─[has_type]→ int
│   ├── LCSC      ─[has_type]→ string
│   └── Stock     ─[has_type]→ bool
├── Stückliste
└── Bauteile
```

## Edges

```text
Edge { id, from, to, label, props? }
labels: has_type | allows_prefix | multiplikator | …  (base_type legacy only)
```
