---
name: Case study (wtt_fs)
overview: Gold standard scaffold taxonomy — Fallstudie Definition / Model (+ legacy Implementation debt). Not Phase-1 domain sign-off.
status: scaffolding
version: "0.2.4-slice"
last_updated: "2026-08-08"
related_docs:
  - docs/plans/project-plan.md
  - docs/ROADMAP.md
  - docs/MODEL-CATALOG.md
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
| Seed | `Case_Data` auto-install when empty; **Reset case tree** (Settings → Development mode) → `Case_Data::reset` |
| Hierarchy datatype (Q88) | Root **Fallstudie** typed **Knoten**; each hierarchy child datatype = parent. Attribute members keep catalog field types. |

## Seed outline

```text
Fallstudie                    type → Knoten (root only; Q88)
├── Definition                type → Fallstudie
│   ├── Data Types
│   │   ├── Simple            int, double, text, …, media, quantity, …
│   │   ├── Complex           ← Q90 parked kinds may still seed
│   │   ├── Präfixe           pico…Mega (multiplikator) — married to units via allowlist
│   │   ├── Unit              (Q120 unit datatype)
│   │   │   ├── With prefix   → Meter, Ohm, Farad, …
│   │   │   └── Without prefix → Kelvin, Celsius, Stück, Währung (Euro/…)
│   │   └── Bauformen
│   ├── Eigene Datentypen
│   └── Knoten                (base type catalog leaf for roots)
├── Relationstypen            type → Fallstudie
│   ├── child_of, ref_scope (system)
│   └── composition / besteht_aus / aggregation (Bindung)
└── Implementation            type → Fallstudie   ← scaffold debt (Q83/OQ-B2: product SoT = Model only)
    ├── BOM                   ← legacy Name+Tabelle bands (Q90 table debt)
          composition → Name (text)
          composition → Tabelle (type=table)
                            composition → Zeile → Reference, Wert, Menge
    ├── Bauteile        (legacy MPN seed — migrate/target Model)
    │   └── RC0603…, CL10B…, …
    ├── Lieferanten     (Url / Suchstring / Bewertung + supplier records)
└── Model
    ├── Kontakt, Platine (slim: + Bauteilliste)
    ├── Bauteilliste    (Name + Position[0..*] → Bauteillisten Position — composition Q97)
    ├── Bauteillisten Position  (Referenz, Wert→Bauteil, Menge, Beschreibung, Auf Lager)
    └── Bauteil
        ├── Passiv → Widerstand, Kondensator, Spule
        ├── Halbleiter → Dioden, Transistor, LED, IC
        │   └── Dioden → Schalt, Schottky, Zener, Gleichrichter, TVS, LDD (CatalogChoice only)
        ├── Elektromechanik → Relais, Steckverbinder, Schalter
        └── Sonstige → Quarz, Sicherung
```

**Q88 example chain:** Fallstudie→Knoten; Definition→Fallstudie; further children inherit father. Attribute members (`besteht_aus`) keep own field types (Q87).

**Q83 / OQ-B2:** Product = **Model/Bauteil** (kinds + records by id). **No Implementation/ SoT** — scaffold Implementation folder above is **debt**. Line refs → Model only.

**Q85 Model BOM:** Platine → Bauteilliste → Bauteillisten Position[…] (**composition** / Q97; not Collection `table`).

**Model/Dioden:** Hierarchy Arten under `Model/Bauteil/Halbleiter/Dioden` for CatalogChoice (Q90).

## Scaffold map (code)

| Piece | Location |
|-------|----------|
| Taxonomy | `Taxonomy::FS`, `default_slug()`, `is_scaffold()`, `scaffold_slugs()` = `[FS]` |
| Seed / reset | `includes/class-case-data.php` |
| Table validator | `includes/class-table-validator.php` + `assets/js/wtt-table-validator.js` |
| Admin boot | `Tree_Admin::build_config()` — `Case_Data::maybe_install` = **empty tree only** (no ensure/prune on every load; repair via Settings **Delete and reinstall** / `install` / CLI) |
| Slim UI | `assets/js/tree-admin.js` — `caseStudyMode()` |
| Retire BOM | `scripts/retire-wtt-tree.php`; `Demo_Data` class retained |

## Local DB note

Terms under the old slug `wtt_case` are not migrated. Empty `wtt_fs` auto-seeds **once** on first open (`maybe_install`); later loads do not re-ensure. Full rebuild: **Taxonomy Tree → Settings → Development mode → Delete and reinstall case-study tree** (or `scripts/reset-case-tree.php`). Live `wtt_tree` terms may be deleted with `retire-wtt-tree.php`.

## Out of scope

- Reviving `wtt_tree` as a second product tree
- Treating Fallstudie as Phase-1 domain sign-off
- Full removal of parked Complex enum/list/table seed leaves (Q90) until an explicit removal slice
