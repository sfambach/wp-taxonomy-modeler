---
name: Case study (wtt_fs)
overview: Gold standard scaffold taxonomy — Fallstudie Definition / Implementation tree. Not Phase-1 domain sign-off.
status: scaffolding
version: "0.2.0-slice"
last_updated: "2026-08-07"
related_docs:
  - docs/plans/project-plan.md
  - docs/ROADMAP.md
  - README.md
todos:
  - id: tax-wtt-fs
    content: "Register taxonomy wtt_fs as sole scaffold (default_slug / scaffold_slugs)"
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
  - id: retire-wtt-tree
    content: "Retire wtt_tree from UI/seeds/pickers; Demo_Data helpers remain; retire-wtt-tree.php"
    status: completed
---

# Case study — `wtt_fs` (gold scaffold)

**Standard product scaffold tree.** `Case_Data` is the reference seed. **`wtt_tree` / BOM Testprojekt is retired** as a parallel product tree (legacy constant + `Demo_Data` helpers only). Still **not** planning sign-off for the full domain model.

## Locked decisions

| ID | Choice |
|----|--------|
| Taxonomy | **`wtt_fs` only** in `scaffold_slugs` / admin UI (renamed from interim `wtt_case`). `Taxonomy::TREE` kept for legacy helpers. |
| UI | Slim detail pane (`cfg.caseStudyMode`); **no Data type picker** (Flags stay); **Composition + Relations list always shown** (Q74/Q75); no dual taxonomy switcher |
| Seed | `Case_Data` auto-install when empty; **Reset case tree** → `Case_Data::reset` |
| Hierarchy datatype (Q88) | Root **Fallstudie** typed **Knoten**; each hierarchy child datatype = parent. Attribute members keep catalog field types. |

## Seed outline

```text
Fallstudie                    type → Knoten (root only; Q88)
├── Definition                type → Fallstudie
│   ├── Simple          (is_datatype + is_abstract)   type → Definition
│   │   ├── int, double, text, textarea, char, bool
│   │   ├── display_node_name
│   │   └── media
│   ├── Complex         (is_datatype + is_abstract)  ← Q90 parked kinds may still seed
│   │   ├── list, table, enum (Bauart options), set
│   │   └── node_pick → node_embed / node_ref
│   ├── Konstanten
│   │   ├── Präfixe (pico, nano, Micro, Milli, Centi, Kilo, Mega; short_description = p/n/u/m/c/k)
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

**Q88 example chain:** Fallstudie→Knoten; Definition→Fallstudie; further children inherit father. Attribute members (`besteht_aus`) keep own field types (Q87).

**Q83:** Categories/schemas = **Definition/Bauteilarten**; master data = **Implementation/Bauteile**.

## Implementation map

| Piece | Location |
|-------|----------|
| Taxonomy | `Taxonomy::FS`, `default_slug()`, `is_scaffold()`, `scaffold_slugs()` = `[FS]` |
| Seed / reset | `includes/class-case-data.php` |
| Table validator | `includes/class-table-validator.php` + `assets/js/wtt-table-validator.js` |
| Admin boot | `Tree_Admin::build_config()` — `Case_Data::maybe_install` only |
| Slim UI | `assets/js/tree-admin.js` — `caseStudyMode()` |
| Retire BOM | `scripts/retire-wtt-tree.php`; `Demo_Data` class retained |

## Local DB note

Terms under the old slug `wtt_case` are not migrated. Empty `wtt_fs` auto-seeds on open, or use **Reset case tree**. Live `wtt_tree` terms may be deleted with `retire-wtt-tree.php`.

## Out of scope

- Reviving `wtt_tree` as a second product tree
- Treating Fallstudie as Phase-1 domain sign-off
- Full removal of parked Complex enum/list/table seed leaves (Q90) until an explicit removal slice
