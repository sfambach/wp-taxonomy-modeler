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
├── Compositionen  (Rezept · BOM „Demo-Platine A“)
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
2. BOM anlegen — **Name Pflicht** (z. B. Platinenname); Titel unter Tabelle: **`BOM als Bauteilliste – {name}`**.
3. Backend: Spalte **Bauteil Wahl** (`subtree` + `ref_scope`→Bauteile).
4. **Wert** = double + Präfix; **Einheit** typfest (Ohm / Farad).
5. **Menge** = **Stück** (`int`) — nicht `quantity`.
6. **Beschreibung** = `textarea` (optional).
7. Erlaubte Präfixe = `allows_prefix` der Einheit.
8. Pflicht/Optional = `config.required` am Slot-Knoten.
9. **Fußzeile** = gleiche Spaltenzahl; pro Spalte `footer_op` (`sum` / `avg` / …).
10. Am BOM-Knoten: **zulässige Typen** + **zulässige Basiseinheiten**.
11. Später WP-**Block**: Art der Tabelle = Knoten unter **Collection**; Zeilen wie Backend.
12. Typen = nur der **Typ-Ast** (kein separates TypeKind).

## Projects

| Project | Mode |
|---------|------|
| **Demo** | editable |
| **Template** | read-only |

State: `localStorage` key `wtt-proto-tree-split-v31` — **Reset** after upgrade.

## How to view (Demo)

1. Open `index.html` (or local server) — Demo startet auf **Backend** mit BOM „Demo-Platine A“.
2. **BOM-Name** oben ändern → Titel unter der Tabelle folgt (`BOM als Bauteilliste – …`).
3. Bauteile in der Tabelle wählen (Spalte Bauteil Wahl); Fußzeile aggregiert.
4. Tab **Block** — Art der Tabelle (Collection) wählen, gleiche Tabelle + Titel.

## BOM / Setup (v31)

| Concern | Rule |
|---------|------|
| Typ-Suche | Nur im **Typ-Ast** (`Typen` → Datentypen …) |
| Startknoten | Pro Projekt in Setup (`startNodeId`) |
| Menge | **Stück** (`int`) |
| Fußzeile | Gleiche Spaltenzahl; pro Spalte `footer_op` (sum/avg/min/max/count/none) |
| BOM-Name | Pflicht (`Node.name`); Titel unter Tabelle: `BOM als Bauteilliste – {name}` |
| WP-Block | Später: Collection-Knoten als Tabellenart, dann Zeilen wie Backend |
| Typen | Nur Ast unter `Typen` / `type_node` — kein `TypeKind` |
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
