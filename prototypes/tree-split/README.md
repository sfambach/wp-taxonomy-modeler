# Tree split prototype

Static throwaway UI to explore taxonomy tree + **Composition** (Zusammenstellung).

**Not** WordPress plugin code. Open `index.html` in a browser (or `python3 -m http.server` in this folder).

## Projects

| Project | Mode | Purpose |
|---------|------|---------|
| **Composition Simples** | editable | **Phase 1** — Composition with **only simple** column types |
| **Template** | read-only | Datentypen (simples + quantity + Collection), Präfix, Basiseinheit |
| **BOM Testprojekt** | editable | Later phases: enum / quantity / Spalten / Bauteile |

State: `localStorage` key `wtt-proto-tree-split-v15` — **Reset** after upgrade.

## Walkthrough — Phase 1 (Simples only)

1. Open the prototype (defaults to **Composition Simples**).
2. In the tree select **`Zusammenstellung — nur Simples`**.
3. Open tab **Tabelle**.
4. See **Definition**: column headers + type badges (`string`, `int`, `bool`, `char`, `double`) from `has_type`.
5. See **Instanz**: 5 rows (CompositionRows); first two seeded — edit cells (typed widgets).
6. Optional: add a child under the Zusammenstellung = new column; set Relation `has_type` → a simple type on Relationen tab; reload Tabelle.

```text
Composition Simples
├── Datentypen (int · double · string · char · bool · …)
└── Zusammenstellung — nur Simples     ← select → Tabelle
    ├── Bezeichnung ─[has_type]→ string
    ├── Anzahl      ─[has_type]→ int
    ├── Aktiv       ─[has_type]→ bool
    ├── Code        ─[has_type]→ char
    └── Faktor      ─[has_type]→ double
```

Maps to planning: Composition **Definition** = children + `has_type`; **Instanz** = `tableCells` grid (ParameterValues / rows).

## Later phases (not in this project yet)

| Phase | Add column types |
|-------|------------------|
| 2 | `quantity` |
| 3 | Collection `enum` / `list` |
| 4 | **Bauteil-Ref** → Katalog-Bauteil (Widerstand is Bauteil, not Composition) |

Use **BOM Testprojekt** as a richer sketch for Spalten/Bauart; keep Phase 1 project for the clean Composition story.

## Edges

```text
Edge { id, from, to, label, props? }
labels: has_type | allows_prefix | multiplikator | …
```
