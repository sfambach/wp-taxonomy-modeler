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
│   ├── Datentypen
│   │   ├── Simple (int, double, string, char, bool)
│   │   ├── Zusammengesetzt
│   │   │   └── quantity
│   │   │       ├── Menge ─[has_type]→ int
│   │   │       ├── Präfix ─[has_type]→ Präfixe
│   │   │       └── Einheit ─[has_type]→ Basiseinheit
│   │   └── Collections (list · table · enum)
│   ├── Präfixe
│   └── Basiseinheit
└── Compositionen
    └── … Zusammenstellungen / Katalog-Ast …
```

## Projects

| Project | Mode | Under Compositionen |
|---------|------|---------------------|
| **Demo** | editable | **Rezept — Backzutaten**, **BOM — Board**, Bauteile |
| **Template** | read-only | (leer — nur Typen) |

State: `localStorage` key `wtt-proto-tree-split-v21` — **Reset** after upgrade. Tree starts with Compositionen + Datentypen sichtbar.

## Walkthrough

1. Open the prototype → **Reset**.
2. Project **Demo** (default).
3. Under `Typen` → `Datentypen`: Simple / Zusammengesetzt / Collections.
4. Under `Compositionen`: Rezept, **BOM — Board**, Bauteile.
5. Tab **Backend** — Zeilen; Tab **Frontend** — Seitenvorschau.

```text
Demo
├── Typen
│   └── Datentypen · Simple / Zusammengesetzt / Collections
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
