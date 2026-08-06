---
name: Case study (wtt_fs)
overview: Parallel slim taxonomy tree for Definition / Implementation exploration — not a model sign-off for the full BOM domain.
status: scaffolding
version: "0.1.6-slice"
last_updated: "2026-08-06"
related_docs:
  - docs/plans/project-plan.md
  - docs/ROADMAP.md
  - README.md
todos:
  - id: tax-wtt-fs
    content: "Register taxonomy wtt_fs + admin switcher"
    status: completed
  - id: seed-case
    content: "Case_Data seed (Definition + Implementation BOM = Name + Tabelle + Zeile)"
    status: completed
  - id: slim-ui
    content: "caseStudyMode slim detail UI (hide Data type picker; keep Flags; hide BOM ballast)"
    status: completed
  - id: relations-visible
    content: "Show Composition + Relations list in case study; seed Relationstypen"
    status: completed
---

# Case study — `wtt_fs`

Parallel feature branch / taxonomy for a **schlanke Fallstudie**. Does **not** replace or rewrite `wtt_tree` / BOM Testprojekt, and is **not** planning sign-off for the overall domain model.

## Locked decisions

| ID | Choice |
|----|--------|
| Taxonomy | Own `wtt_fs` (not a second root inside `wtt_tree`; renamed from interim `wtt_case`) |
| UI | Slim detail pane when `taxonomy === wtt_fs` (`cfg.caseStudyMode`); **no Data type picker** (Flags stay); **Composition + Relations list always shown** (Q74/Q75) |
| Git | Feature branch `feature/case-study-wtt-case` (branch name kept; slug is `wtt_fs`) |
| Hosting | Inside `wp-taxonomy-tree` (no sibling host plugin for this slice) |
| Hierarchy datatype (Q88) | Root **Fallstudie** typed **Knoten**; each hierarchy child datatype = parent (e.g. Definition → Fallstudie). Attribute members keep catalog field types. |

## Seed outline

```text
Fallstudie                    type → Knoten (root only; Q88)
├── Definition                type → Fallstudie
│   ├── Simple          (is_datatype + is_abstract)   type → Definition
│   │   ├── int, double, text, textarea, char, bool
│   │   ├── display_node_name
│   │   └── media
│   ├── Complex         (is_datatype + is_abstract)
│   │   ├── list, table, enum (Bauart options), set
│   │   └── node_pick → node_embed / node_ref
│   ├── Konstanten
│   │   ├── Präfixe (p, n, u, m, c, k, Mega + multiplikator)
│   │   └── Basiseinheiten (… + Henry, Hertz, Stück)
│   ├── Eigene Datentypen
│   ├── Knoten                (base type catalog leaf for roots)
│   └── Bauteilarten    (Q83: category + schema only; is_abstract folder)
│       ├── Widerstand, Kondensator, … (set + slots; is_datatype)
│       └── (no MPN leaves here)
├── Relationstypen            type → Fallstudie
│   ├── child_of, has_type, ref_scope (system)
│   └── composition / besteht_aus / aggregation (Bindung)
└── Implementation            type → Fallstudie
    ├── BOM
          composition → Name (text)
          composition → Tabelle (type=table)
                            composition → Zeile → Reference, Wert, Menge
    ├── Bauteile        (Q83: MPN master records; type_id → Bauteilarten kind)
    │   └── RC0603…, CL10B…, … (catalog leaves)
    └── Lieferanten     (Url / Suchstring / Bewertung + supplier records)
```

**Q88 example chain (general rule):** Fallstudie→Knoten; Definition→Fallstudie; a further child under Definition (e.g. Aggregation in the product example)→Definition; and so on. Seed may still lag on promoting every folder to `is_datatype` so children can assign the parent.

**Q83:** Do not nest kinds and MPNs under one Bauteile root. Categories/schemas = **Definition/Bauteilarten**; master data = **Implementation/Bauteile**. BOM `node_embed` → Bauteile records.

## Implementation map

| Piece | Location |
|-------|----------|
| Taxonomy | `Taxonomy::FS`, `is_scaffold()`, `scaffold_taxonomies()` |
| Seed / reset | `includes/class-case-data.php` (+ `ensure_relation_types`, `ensure_konstanten`, `ensure_bom_implementation`, table bands) |
| Table validator | `includes/class-table-validator.php` + `assets/js/wtt-table-validator.js` |
| Admin boot | `Tree_Admin::build_config()` — seed case when empty; BOM ensures skipped |
| Slim UI | `assets/js/tree-admin.js` — `caseStudyMode()`; Relations always rendered |

## Local DB note

Terms under the old slug `wtt_case` are not migrated. Open the Fallstudie taxonomy once (empty `wtt_fs` auto-seeds) or use **Reset case tree**.

## Out of scope

- Reworking or deleting BOM Testprojekt
- Global UI slimming on `wtt_tree`
- Full Basiseinheit set schema (Typ/Praefix/Kuerzel composition) under Fallstudie — catalog leaves only for now
- Reworking BOM Testprojekt (`wtt_tree`) to the new Name+Tabelle shape (docs aligned; demo seed may lag)
