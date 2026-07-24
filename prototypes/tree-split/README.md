# Tree split prototype

Static throwaway UI to explore taxonomy tree + **Composition** (Zusammenstellung).

**Not** WordPress plugin code. Open `index.html` in a browser (or `python3 -m http.server` in this folder).

## Right pane tabs (left → right)

| Tab | Role |
|-----|------|
| **Knoten** | Eigenschaften + Relationen; bei Basiseinheit: **zulässige Präfixe** |
| **Backend** | Dateneingabe (BOM: Bauteil → dynamische Wert/Präfix-Felder) |
| **Frontend** | Vereinfachte Seitenvorschau |
| **Feld** | HTML-Spielwiese — unangetastet |

## Goal path — BOM-Zeile

1. Unter **Bauteile** Gruppe wählen (Widerstand / Kondensator) — Schema im Baum.
2. In **BOM — Board** → Backend: Spalte **Bauteil** setzen.
3. **Wert** = double + Präfix; **Einheit** kommt fix vom Bauteil (Ohm / Farad).
4. Erlaubte Präfixe = `allows_prefix` der Einheit (unter Typen → Basiseinheit → Ohm/Farad pflegbar).

```text
Bauteile
├── Widerstand
│   ├── Wert ─[has_type]→ double
│   ├── Präfix ─[has_type]→ Präfixe
│   └── Einheit ─[has_type]→ Ohm   (fix)
└── Kondensator
    ├── Wert ─[has_type]→ double
    ├── Präfix ─[has_type]→ Präfixe
    └── Einheit ─[has_type]→ Farad (fix)

Ohm  ─[allows_prefix]→ m, k, M, µ, n, p
Farad ─[allows_prefix]→ p, n, µ, m
```

## Root layout

```text
Demo
├── Typen (Datentypen · Präfixe · Basiseinheit inkl. Ohm/Farad)
└── Compositionen
    ├── Rezept — Backzutaten
    ├── Bauteile
    └── BOM — Board
```

## Projects

| Project | Mode |
|---------|------|
| **Demo** | editable |
| **Template** | read-only |

State: `localStorage` key `wtt-proto-tree-split-v22` — **Reset** after upgrade.

## Edges

```text
has_type | allows_prefix | multiplikator | …
```
