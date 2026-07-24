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

Entfernt: Umrechnung, Tabelle 2, separater Relationen-Tab (Relationen → Knoten).

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
| **Composition Simples** | editable | **Rezept — Backzutaten** (Phase 1 · nur Simples) |
| **Template** | read-only | (leer — nur Typen) |
| **BOM Testprojekt** | editable | Spalten, Stückliste, Bauteile |

State: `localStorage` key `wtt-proto-tree-split-v18` — **Reset** after upgrade.

## Walkthrough — Phase 1 (Simples)

1. Open the prototype → **Reset**.
2. Project **Composition Simples**.
3. Tree: `Compositionen` → **`Rezept — Backzutaten`**.
4. Tab **Backend** — Zeilen Mehl / Zucker / Salz bearbeiten.
5. Tab **Frontend** — Block-ähnliche Seitenvorschau derselben Daten.

```text
Composition Simples
├── Typen
│   ├── Datentypen · Präfix · Basiseinheit
└── Compositionen
    └── Rezept — Backzutaten
        ├── Bezeichnung ─[has_type]→ string
        ├── Anzahl      ─[has_type]→ int
        ├── Aktiv       ─[has_type]→ bool
        ├── Code        ─[has_type]→ char
        └── Faktor      ─[has_type]→ double
```

## Later phases

| Phase | Add column types |
|-------|------------------|
| 2 | `quantity` |
| 3 | Collection `enum` / `list` |
| 4 | **Bauteil-Ref** → Katalog-Bauteil |

## Edges

```text
Edge { id, from, to, label, props? }
labels: has_type | allows_prefix | multiplikator | …
```
