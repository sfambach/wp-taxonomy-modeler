# Tree split prototype

Static throwaway UI to explore taxonomy tree + **Composition** (Zusammenstellung).

**Not** WordPress plugin code. Open `index.html` in a browser (or `python3 -m http.server` in this folder).

## Right pane tabs (left → right)

| Tab | Role |
|-----|------|
| **Knoten** | Eigenschaften; bei **Slots** Typ-Bindung + Pflicht; Basiseinheit: Präfixe; Relationen unter „Erweitert“ |
| **Backend** | Dateneingabe nur für **Compositionen** (Tabelle) |
| **Frontend** | Vereinfachte Seitenvorschau |
| **Feld** | HTML-Spielwiese — unangetastet |


## Root layout

```text
Project root
├── Typen
│   ├── Datentypen
│   │   ├── Simple    int · double · text · textarea · char · bool · node_ref
│   │   └── Complex   quantity · subtree · Collection(list/table/enum)
│   ├── Präfixe
│   └── Basiseinheit
├── Compositionen  (Rezept · BOM — Board)
└── Bauteile       (Katalog ≠ Composition)
    ├── Widerstand
    └── Kondensator
```

**Bauteile** = Katalogwurzel. BOM-Spalte **Bauteil Wahl** = **`subtree`** + `ref_scope` → Bauteile.

## Reference types

| Type | Gruppe | Bedeutung |
|------|--------|-----------|
| **`node_ref`** | Simple | Freier Absprung zu **beliebigem** Node (Wert = id); kein Scope |
| **`subtree`** | Complex | Auswahl unter einer Katalogwurzel via **`ref_scope`** (Kinder = Optionen) |

## Goal path — BOM-Zeile

1. Unter **Bauteile** Gruppe anlegen/pflegen (Widerstand / Kondensator).
2. **BOM — Board** → Backend: Spalte **Bauteil Wahl** (`subtree` + `ref_scope`→Bauteile).
3. **Wert** = double + Präfix; **Einheit** typfest (Ohm / Farad).
4. **Menge** = **Stück** (`int`) — nicht `quantity`.
5. **Beschreibung** = `textarea` (optional).
6. Erlaubte Präfixe = `allows_prefix` der Einheit.
7. Pflicht/Optional = `config.required` am Slot-Knoten.
8. **Fußzeile** = gleiche Spaltenzahl; pro Spalte `footer_op` (`sum` / `avg` / …); Menge typisch `sum` → Stück.
9. Am BOM-Knoten: **zulässige Typen** + **zulässige Basiseinheiten** (Allowlists).
10. Projekt-**Startknoten** in Setup (Standard-Fokus beim Öffnen).

## Projects

| Project | Mode |
|---------|------|
| **Demo** | editable |
| **Template** | read-only |

State: `localStorage` key `wtt-proto-tree-split-v30` — **Reset** after upgrade.

## BOM / Setup (v30)

| Concern | Rule |
|---------|------|
| Typ-Suche | Nur im **Typ-Ast** (`Typen` → Datentypen …) |
| Startknoten | Pro Projekt in Setup (`startNodeId`) |
| Menge | **Stück** (`int`) |
| Fußzeile | Gleiche Spaltenzahl; pro Spalte `footer_op` (sum/avg/min/max/count/none) |
| Allowlists | `config.allowed_types` / `config.allowed_base_units` am Composition-Knoten |

## Simple types (HTML lean)

| Type | Widget | Analog |
|------|--------|--------|
| `text` | einzeilig `<input type="text">` | DB VARCHAR / Rails `:string` |
| `textarea` | mehrzeilig `<textarea>` | DB TEXT / Rails `:text` |
| `char` | 1 Zeichen | — |
| `int` / `double` / `bool` | number / checkbox | — |
| `node_ref` | select + → Absprung | freie Node-id |

## Slot properties

| Concern | Where | Notes |
|---------|-------|-------|
| Type / Form | Relation **`has_type`** → type Node | Shape of the value |
| Pflicht / Optional | **`Node.config.required`** on the slot | Not on the type edge |

## Edges

```text
has_type | allows_prefix | multiplikator | ref_scope | …
```
