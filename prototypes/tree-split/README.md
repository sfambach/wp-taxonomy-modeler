# Tree split prototype

Static throwaway UI to explore taxonomy tree + **Composition** (Zusammenstellung) + **Parameter**.

**Not** WordPress plugin code. Open `index.html` in a browser (or `python3 -m http.server` in this folder).

## Right pane tabs (left → right)

| Tab | Role |
|-----|------|
| **Knoten** | Definition: Node + **Parameters** (name + Typ-Ast), Allowlists, Fußzeile |
| **Backend** | **Instanz** wie WP-Seite: Projektname + Tabelle + Titel darunter |
| **Block** | WP-Block-Skizze: Collection-Art wählen + gleiche Instanz-Tabelle |
| **Feld** | HTML-Spielwiese — unangetastet |

## Parameter (Q64)

| Field | Meaning |
|-------|---------|
| `name` | Text, vom Benutzer bei Zuweisung zum Knoten |
| `type` | Knoten aus dem **Typ-Ast** |

- Jeder Node kann Parameter haben; Parameter ≠ Baumknoten.
- BOM-Spalten = eigene Parameter am BOM-Knoten.
- `Projektname` = Parameter an **Collection** (vererbt); Instanzwert auf der WP-Seite.

## Definition vs Instanz (Q63)

| | Baum (Definition) | WP-Seite / Backend-Tab (Instanz) |
|--|-------------------|----------------------------------|
| Name | Strukturknoten heißt **`BOM`** | — |
| Projektname | Parameter an **Collection** | Pflicht-**Wert** eingeben |
| Spalten / Typen / Fußzeile-Ops | Parameter-Schema | — |
| Zeilen / Bauteile | — | CompositionRows füllen |
| Titel unter Tabelle | — | `BOM als Bauteilliste – {Projektname}` |

## Root layout

```text
Project root
├── Typen
│   ├── Datentypen
│   │   ├── Simple
│   │   └── Complex
│   │       └── Collection   ← Parameter Projektname→text (kein Kindknoten)
│   │           ├── list
│   │           ├── table
│   │           └── enum
│   ├── Präfixe
│   └── Basiseinheit
├── Compositionen
│   ├── Rezept — …          ← Spalten noch als Kindknoten (Legacy-Demo)
│   └── BOM                 ← Spalten = Parameters
└── Bauteile
```

## How to view (Demo)

1. Open `index.html` → **Reset** (v33).
2. Tree: **Compositionen → BOM**. Tab **Knoten**: Parameter-Liste (Bauteil Wahl, Menge, …).
3. **Typen → … → Collection**: Parameter **Projektname** (kein Kind „Projektname“).
4. Tab **Backend**: Instanz-**Projektname** → Titel unter der Tabelle; Baumname bleibt BOM.
5. Tab **Block**: Collection-Art + gleiche Instanz-Tabelle.

State: `localStorage` key `wtt-proto-tree-split-v33` — **Reset** after upgrade.
