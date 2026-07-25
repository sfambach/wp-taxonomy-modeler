# Tree split prototype

Static throwaway UI to explore taxonomy tree + **Composition** (Zusammenstellung).

**Not** WordPress plugin code. Open `index.html` in a browser (or `python3 -m http.server` in this folder).

## Right pane tabs (left → right)

| Tab | Role |
|-----|------|
| **Knoten** | Definition im Baum (Strukturname, Spalten, Typ-Bindung, Allowlists) |
| **Backend** | **Instanz** wie WP-Seite: Projektname + Tabelle + Titel darunter |
| **Block** | WP-Block-Skizze: Collection-Art wählen + gleiche Instanz-Tabelle |
| **Feld** | HTML-Spielwiese — unangetastet |

## Definition vs Instanz (Q63)

| | Baum (Definition) | WP-Seite / Backend-Tab (Instanz) |
|--|-------------------|----------------------------------|
| Name | Strukturknoten heißt **`BOM`** | — |
| Projektname | Slot unter **Collection** (vererbt) | Pflicht-**Wert** eingeben |
| Spalten / Typen / Fußzeile-Ops | Schema | — |
| Zeilen / Bauteile | — | CompositionRows füllen |
| Titel unter Tabelle | — | `BOM als Bauteilliste – {Projektname}` |

## Root layout

```text
Project root
├── Typen
│   ├── Datentypen
│   │   ├── Simple
│   │   └── Complex
│   │       └── Collection
│   │           ├── Projektname   ← Attribut (Definition), vererbt
│   │           ├── list
│   │           ├── table
│   │           └── enum
│   ├── Präfixe
│   └── Basiseinheit
├── Compositionen
│   ├── Rezept — …
│   └── BOM                 ← Strukturname bleibt BOM
└── Bauteile
```

## How to view (Demo)

1. Open `index.html` → **Reset** (v32).
2. Tree: **Compositionen → BOM** (structure). Under **Typen → … → Collection** see **Projektname**.
3. Tab **Backend**: edit **Projektname (Instanz)** → title under table updates; tree name stays BOM.
4. Tab **Block**: pick Collection art + same instance fields/table.

State: `localStorage` key `wtt-proto-tree-split-v32` — **Reset** after upgrade.
