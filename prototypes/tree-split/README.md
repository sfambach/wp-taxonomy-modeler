# Tree split prototype

Static throwaway UI to explore taxonomy tree + **Composition** (Zusammenstellung).

**Not** WordPress plugin code. Open `index.html` in a browser (or `python3 -m http.server` in this folder).

## Right pane tabs (left → right)

| Tab | Role |
|-----|------|
| **Knoten** | Eigenschaften + Relationen für jeden Knoten (inkl. Composition) |
| **Backend** | Dateneingabe / vereinfachte WP-Seitenerstellung (Tabelle = CompositionRows) |
| **Frontend** | Vereinfachte Seitenvorschau (später: Gutenberg-Blöcke, Vergleich, Aktionen) |
| **Feld** | HTML-Feld-Spielwiese — **unangetastet**, für spätere Zwecke |

## Root layout (all projects)

```text
Project root
├── Typen
│   ├── Datentypen (simples, quantity, Collection…)
│   ├── Präfix
│   └── Basiseinheit
└── Compositionen
    └── … Zusammenstellungen / Katalog-Ast …
```

## Projects

| Project | Mode | Under Compositionen |
|---------|------|---------------------|
| **Demo** | editable | **Rezept — Backzutaten**, **BOM — Board**, Bauteile |
| **Template** | read-only | (leer — nur Typen) |

State: `localStorage` key `wtt-proto-tree-split-v20` — **Reset** after upgrade. Tree starts collapsed; **Compositionen** is opened so both compositions are visible.

## Walkthrough

1. Open the prototype → **Reset**.
2. Project **Demo** (default).
3. Tree under `Compositionen`:
   - **Rezept — Backzutaten** — Phase 1 Simples
   - **BOM — Board** — quantity / enum / list (+ Seed-Zeilen)
   - **Bauteile** — Katalog-Ast
4. Tab **Backend** — Zeilen bearbeiten; Tab **Frontend** — Seitenvorschau.

```text
Demo
├── Typen · …
└── Compositionen
    ├── Rezept — Backzutaten
    ├── BOM — Board
    └── Bauteile
```

## Later phases

| Phase | Add column types |
|-------|------------------|
| 2 | `quantity` (BOM already demos) |
| 3 | Collection `enum` / `list` (BOM already demos) |
| 4 | **Bauteil-Ref** → Katalog-Bauteil |

## Edges

```text
Edge { id, from, to, label, props? }
labels: has_type | allows_prefix | multiplikator | …
```
