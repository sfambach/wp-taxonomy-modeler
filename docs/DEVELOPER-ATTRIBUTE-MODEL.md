---
name: Developer guide — Attribute / Relation / Settings model
overview: Locked Q123 product model (OQ-W1…W16) with Widerstand diagrams for developers (and later user docs).
status: agreed
version: "1.0.0"
last_updated: "2026-08-09"
related_docs:
  - docs/plans/relation-vs-object-concept.md
  - docs/plans/q123-doc-pass-questions.md
  - docs/ARCHITECTURE.md
  - docs/PRODUCT.md
  - docs/OPEN-QUESTIONS.md
---

# Developer guide — Attribute / Relation / Settings model

**Audience:** developers (scaffold migrate + future product). Diagrams here are also suitable to adapt later into **user documentation**.

**Status:** Agreed planning model (**2026-08-09**). Scaffold still uses attribute **slot terms** + `_wtt_type_id` / Preferred term meta — **debt** toward this model. Decisions: [`q123-doc-pass-questions.md`](plans/q123-doc-pass-questions.md) (OQ-W1…W16). Whiteboard history: [`relation-vs-object-concept.md`](plans/relation-vs-object-concept.md).

---

## Locked summary

| Topic | Decision |
|-------|----------|
| Attribute | **`besteht_aus` / `aggregation` Relation only** — no slot term |
| Relation fields | `name`, target, Bindung, Mult, RO, Hide/BO, Default seed |
| Type | **Relation target** (no `attribute_typeof`) |
| Settings | **`Settings.data`** + **`Settings.view`** on node; Relation stores **override deltas only** |
| Resolve | **Hybrid** — live from target tree; local delta wins if key present |
| UI | Same **recursive walk** for node detail and attribute Settings (to leaf; break cycles) |
| Write | Override on **current** context only — do not push defaults into subnodes |
| Inherit | Along host `child_of` (Q66); hide inherited; merge by name |
| Instance keys | **Relation id** |
| Delete host | Composition data/links **die with** host; aggregation **targets remain**, Relation removed (Q111) |
| UI panels | Relations = general; Attributes = **wizard** over comp/agg |
| RelationTypes | `child_of`, `besteht_aus`, `aggregation` only (product) |
| Deprecated | `node_ref`, `ref_scope`, `node_embed`, `node_pick`, product `composition` alias, `attribute_typeof` |
| Measure | `quantity` general; `size` = inheriting child with extra settings |
| `With prefix` | Father knot; **composed of** Praefix + Kuerzel Relations; then Settings |
| Presentation | Q117 texts/icon — **separate** from Settings.data/view |

```text
Settings
  data:  validators, allowlists, defaults, dateMode, compute, …
  view:  preferredRenderer, preferredConverter, …
Presentation (Q117): locale texts + icon
```

---

## Diagram — Widerstand / Wert → Unit (source → target)

Attribute **Unit** on `size` targets **`With prefix`** directly (no intermediate Unit node).

| Relation | source | target | name |
|----------|--------|--------|------|
| R1 | Widerstand | size | Wert |
| R2 | size | double | Value |
| R3 | size | With prefix | Unit |
| R4 | With prefix | Praefix | Praefix |
| R5 | With prefix | text | Kuerzel |

```mermaid
flowchart TB
  W[Widerstand]
  R1["Relation<br/>source: Widerstand → target: size<br/>name: Wert · besteht_aus<br/>override deltas Settings.data/view"]
  S[size]
  R2["Relation<br/>source: size → target: double<br/>name: Value"]
  D[double]
  R3["Relation<br/>source: size → target: With prefix<br/>name: Unit"]
  WP[With prefix]
  R4["Relation<br/>source: With prefix → target: Praefix<br/>name: Praefix"]
  R5["Relation<br/>source: With prefix → target: text<br/>name: Kuerzel"]
  Px[Praefix]
  Tx[text]

  W --- R1 --- S
  S --- R2 --- D
  S --- R3 --- WP
  WP --- R4 --- Px
  WP --- R5 --- Tx
```

---

## Diagram — Settings / Render walk

Opening **node** `size` or attribute **Wert** = **same graph walk**. Settings UI collects `Settings.data` + `Settings.view`; paint uses the same edges and resolves **Preferred** from `Settings.view` (hybrid + deltas) then **Registry**.

```mermaid
flowchart TB
  W[Widerstand]
  R1["Relation name=Wert → size"]
  S[size]
  R2["Relation name=Value → double"]
  D[double]
  R3["Relation name=Unit → With prefix"]
  WP[With prefix]
  R4["Praefix"]
  R5["Kuerzel"]

  W --- R1 --- S
  S --- R2 --- D
  S --- R3 --- WP
  WP --- R4
  WP --- R5
```

```text
Settings(node|attr) = walk to leaves + live Settings + override deltas
Render(node)        = resolve Settings.view → Preferred → Registry.paint(node)
                      then for each attr Relation: Render(target)  (Mult>1 → collection box)
Cycle guard         = node already on path → stop
Surfaces            = same path admin preview ↔ block editor ↔ frontend (parity)
```

**Not the same as Q117 Presentation** (locale texts / icon) — that store is labels/chrome identity, not Preferred renderer selection.

---

## Class sketch — Relation

```mermaid
classDiagram
  direction TB
  class Knoten {
    +Settings data
    +Settings view
  }
  class Relation {
    +source Knoten
    +target Knoten
    +relationType besteht_aus|aggregation|child_of
    +name String
    +multiplicity String
    +readOnly bool
    +hide bool
    +default seed
    +overrideDeltas Settings
  }
  class Settings {
    +data map
    +view map
  }
  Knoten --> Relation : outgoing
  Relation --> Knoten : target
  Relation --> Settings : deltas only
  Knoten --> Settings : defaults live
```

---

## Scaffold debt (do not implement in this doc)

- Remove / stop creating `_wtt_attribute_slot`; migrate values keys slot id → Relation id
- Fold `_wtt_preferred_*` / typeExtras into `Settings.data` / `Settings.view`
- Attribute Options UI → full walk surface
- Drop seeding / teaching `ref_scope` / `node_ref` as product

---

## For later user documentation

Reuse the **flowchart** (Widerstand → Wert → size → Unit) and the short tables above. Keep class diagrams and debt lists in **developer** docs.
