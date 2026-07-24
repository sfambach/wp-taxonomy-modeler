# Tree split prototype

Static throwaway UI to explore taxonomy tree + **Composition** (Zusammenstellung).

**Not** WordPress plugin code. Open `index.html` in a browser (or `python3 -m http.server` in this folder).

## Root layout (all projects)

Every project root has exactly two children:

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

State: `localStorage` key `wtt-proto-tree-split-v17` — **Reset** after upgrade.

## Walkthrough — Phase 1 (Simples only)

1. Open the prototype → **Reset**.
2. Project **Composition Simples**.
3. Tree: `Compositionen` → **`Rezept — Backzutaten`** → tab **Tabelle**.
4. Seeded rows:

| Bezeichnung | Anzahl | Aktiv | Code | Faktor |
|-------------|--------|-------|------|--------|
| Mehl | 200 | ✓ | M | 1 |
| Zucker | 50 | ✓ | Z | 0.5 |
| Salz | 5 | | S | 0.1 |

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
