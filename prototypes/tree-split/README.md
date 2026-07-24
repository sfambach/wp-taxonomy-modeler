# Tree split prototype

Static throwaway UI to explore taxonomy tree + **Composition** (Zusammenstellung).

**Not** WordPress plugin code. Open `index.html` in a browser (or `python3 -m http.server` in this folder).

## Right pane tabs (left → right)

| Tab | Role |
|-----|------|
| **Knoten** | Eigenschaften + Relationen; bei Basiseinheit: **zulässige Präfixe** |
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

**Bauteile sind kein Composition** und keine Tabelle — nur Katalog mit Parameter-Schema. In der BOM erscheinen sie als **Bauteil-Ref**.

## Goal path — BOM-Zeile

1. Unter **Bauteile** Gruppe anlegen/pflegen (Widerstand / Kondensator).
2. **BOM — Board** → Backend: Spalte **Bauteil** wählen.
3. **Wert** = double + Präfix; **Einheit** typfest (Ohm / Farad).
4. Erlaubte Präfixe = `allows_prefix` der Einheit (Ohm/Farad im Knoten-Tab).

## Projects

| Project | Mode |
|---------|------|
| **Demo** | editable |
| **Template** | read-only |

State: `localStorage` key `wtt-proto-tree-split-v26` — **Reset** after upgrade.

## Simple types (HTML lean)

| Type | Widget | Analog |
|------|--------|--------|
| `text` | einzeilig `<input type="text">` | DB VARCHAR / Rails `:string` |
| `textarea` | mehrzeilig `<textarea>` | DB TEXT / Rails `:text`; später Format/Interpreter |
| `char` | 1 Zeichen | — |
| `int` / `double` / `bool` | number / checkbox | — |


## Edges

```text
has_type | allows_prefix | multiplikator | …
```
