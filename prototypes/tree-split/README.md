# Tree split prototype

Static throwaway UI to explore taxonomy tree + **Composition** (Zusammenstellung).

**Not** WordPress plugin code. Open `index.html` in a browser (or `python3 -m http.server` in this folder).

## Right pane tabs (left → right)

| Tab | Role |
|-----|------|
| **Knoten** | Eigenschaften + Relationen; bei Basiseinheit: **zulässige Präfixe**; bei typed Slots: **Pflicht (config.required)** |
| **Backend** | Dateneingabe nur für **Compositionen** (Tabelle) |
| **Frontend** | Vereinfachte Seitenvorschau |
| **Feld** | HTML-Spielwiese — unangetastet |


## Root layout

```text
Project root
├── Typen          (Datentypen · Präfixe · Basiseinheit)
├── Compositionen  (Rezept · BOM — Board)  ← Tabellen / Zeilen
└── Bauteile       (Katalog ≠ Composition)
    ├── Widerstand   Wert/Präfix/Einheit→Ohm
    └── Kondensator  Wert/Präfix/Einheit→Farad
```

**Bauteile** = Katalogwurzel. In der BOM heißt die Auswahlspalte **Bauteil Wahl** (`node_ref` + `ref_scope` → Bauteile) — nicht dieselbe Sache wie der Katalogknoten.

## Goal path — BOM-Zeile

1. Unter **Bauteile** Gruppe anlegen/pflegen (Widerstand / Kondensator).
2. **BOM — Board** → Backend: Spalte **Bauteil Wahl** (`node_ref` + `ref_scope`→Bauteile).
3. **Wert** = double + Präfix; **Einheit** typfest (Ohm / Farad).
4. **Beschreibung** = `textarea` (optional).
5. Erlaubte Präfixe = `allows_prefix` der Einheit (Ohm/Farad im Knoten-Tab).
6. Pflicht/Optional = `config.required` am **Slot-Knoten** (nicht an `has_type`).

## Projects

| Project | Mode |
|---------|------|
| **Demo** | editable |
| **Template** | read-only |

State: `localStorage` key `wtt-proto-tree-split-v28` — **Reset** after upgrade.

## Simple types (HTML lean)

| Type | Widget | Analog |
|------|--------|--------|
| `text` | einzeilig `<input type="text">` | DB VARCHAR / Rails `:string` |
| `textarea` | mehrzeilig `<textarea>` | DB TEXT / Rails `:text`; später Format/Interpreter |
| `char` | 1 Zeichen | — |
| `int` / `double` / `bool` | number / checkbox | — |

## Reference type

| Type | Widget | Scope |
|------|--------|-------|
| `node_ref` | `<select>` of target Nodes | Relation **`ref_scope`** → Katalogwurzel (Kinder = Optionen) |

Name lean: **`node_ref`** (generic Node pointer, cf. ACF Post Object) — not `bauteil` / `tree_part` / `ast`. Domain label „Bauteil“ stays the **column name**; the type is reusable (Zutat, GPU, …).

## Slot properties

| Concern | Where | Notes |
|---------|-------|-------|
| Type / Form | Relation **`has_type`** → type Node | Shape of the value |
| Pflicht / Optional | **`Node.config.required`** on the slot | Not on the type edge |

## Edges

```text
has_type | allows_prefix | multiplikator | ref_scope | …
```
