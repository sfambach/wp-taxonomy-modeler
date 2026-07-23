# Tree split prototype

Static throwaway UI to explore the taxonomy tree screen shape.

**Not** WordPress plugin code. Open `index.html` in a browser (or `python3 -m http.server` in this folder).

## What it does

- Split layout: tree left, multi-tab right
- Every node has **name** + **description**
- Demo seed: Datentypen, Präfixe (with **multiplikator**), Basiseinheiten (with **allows_prefix**)
- Right pane **tabs**:
  - **Knoten** — name, description, sibling order (no relation editors here)
  - **Relationen** — list/add/remove edges; edit `multiplikator` value
  - **Tabelle** / **Tabelle 2** — typed columns via `has_type`
  - **Formular** — demo controls
  - **Umrechnung** — convert within a Basiseinheit family (forward & back)
- State: `localStorage` key `wtt-proto-tree-split-v11` — **Reset** after upgrade

## Q51 model in the seed

```text
Präfix k ─[multiplikator]→ int   props.value = 1000
Basiseinheit Ohm ─[allows_prefix]→ m, k, M, µ, n, p
Basiseinheit Farad ─[allows_prefix]→ p, n, µ, m   # no k/M (kein Mega-Farad)
```

Umrechnung: `out = in × left.multiplikator / right.multiplikator`.

## Edges

```text
Edge { id, from, to, label, props? }
labels: has_type | base_type | allows_prefix | multiplikator | …
```
