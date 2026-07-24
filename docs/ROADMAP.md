# Roadmap

> Living delivery roadmap. Keep this aligned with [`docs/plans/project-plan.md`](plans/project-plan.md).

Last synced from plan version **0.6.71-plan** (2026-07-24).

**Current mode: planning only — no plugin implementation.**

## Phase 0 — Foundation & planning (active)

| Item | Status |
|------|--------|
| Coding rules (English, practices, WP standards, DB practices) | In progress (separate PR) |
| Versioning rule (start `0.0.1`; major only on release) | In progress |
| Planning-only rule (no implementation yet) | In progress |
| Project plan + living docs + sync rule | In progress |
| Planning checklist + MVP requirements + open questions | In progress |
| Data structure: Node (root = same Node with parent null; tree = that root) | Done (planning) |
| Data structure: **no Parameter / ParameterRole** — ordinary Nodes + type binding (Q33/Q34) | Done (planning) |
| Data structure: **Project ≈ taxonomy** (Q18); **Q50** default Nodes = generate vs template copy | In progress |
| Data structure: Project (`name`, `description`, `root_nodes`) | Done (planning) |
| Data structure: Project Definition anchors + Node.template | Done (planning) |
| Example tree: single **Definitionsbaum** (Definition + Bauteile + Maße) | In progress |
| Example tree: **Bauteile** with typed edges (`ist-ein` / `besteht-aus`) | In progress |
| **Q49:** simples originate Relations? lean config `originate_relations=false` (with Q34) | Open (strong lean) |
| **Q50:** lean template-copy for defaults (simples + enum + quantity) | In progress |
| **Template vs BOM Testprojekt** (Bauart/Ohm… = demo; Template read-only) | Done (planning) |
| UI prototype `prototypes/tree-split` v14 (Collection; Bauart/RefDes/Spalten) | In progress |
| **Q52:** Collection → list / table / enum (enum = list + closed options) | Done (planning) |
| **Q53:** Collection kind binding | Open (restart; guidelines) |
| **Q54:** tree hierarchy vs Relations | Open — **lean:** catalog Bestandteile + property inheritance (BOM/Hardware/Rezept) |
| **Q55:** Parameter define/inherit on catalog Nodes (Bauform etc.) | Open (spin — Bauform as Parameter lean) |
| **Q56:** Composition / UX Zusammenstellung (GPU card, BOM, Build, …) | Open (concept lean); **naming decided** |
| Goal path: create one Composition (blockers #1–3) | In progress |
| Composition Definition vs Instanz + worked column types (BOM/Rezept/GPU) | In progress |
| Composition instance storage: ParameterValue + CompositionRow | Open (strong lean) |
| Closed TE: hierarchy-as-edges + `parent_id` cache | Closed (not adopted) |
| Design guidelines: clear structures; named objects when better | Done (planning) |
| Guideline: proactively flag performance risk / nonsense | Done (planning) |
| Guideline: modern design paradigms / best practice | Done (planning) |
| Core types: template has **simples** + **quantity** + **Collection** (Q36/Q52) | Done (planning) |
| **Q51:** Basiseinheit→Präfix; multiplikator→int; select derives Ohm/kOhm/… | Done (planning) |
| **Q20:** typed PHP DTOs; no Parameter class | Done (planning) |
| Parked: further Node idea (Q40) | Parked |
| RelationType pairs + display/inherit (Q35, Q41–Q43) | In progress |
| Use-case cards (`docs/plans/use-cases.md`) — synced to Q33/Q14; UC-10, UC-14–UC-16 | In progress |
| Example project A — BOM (fit/gap) | Done (planning) |
| Example project B — Hardware / tests / builds | Done (planning) |
| Example project C — Rezepte | Done (planning) |
| Cross-check A+B+C — model boundary holds | Done (planning) |
| Part identity layers (R/C/Diode/IC) | In progress |
| Schema-as-Nodes (BOM/Recipe without hard classes) Q46 | In progress |
| Prototype: Umrechnung tab (Q51 unit family convert) | In progress |
| Datentypen tree + `has_type` → typed table widgets (Q48) | In progress |
| Open questions (leave open; resolve later in batches) | In progress |
| Widerstand worked example: Approach A rejected; B (Parameter-Nodes) survives | Done (planning) |
| Local WordPress development environment | In progress (separate PR; env only) |

**Exit criteria:** MVP requirements accepted; Node data structure agreed; open questions decided or deferred; user sign-off to leave planning mode.

## Phase 1 — MVP (blocked on planning sign-off)

| Item | Status |
|------|--------|
| Plugin bootstrap at version `0.0.1` (PHP 8.x, OOP, text domain) | Blocked |
| Node tree model (taxonomy-agnostic) | Blocked |
| Admin tree UI (create / select / delete promote\|cascade) | Blocked |
| Secure mutation/read endpoints | Blocked |

**Exit criteria:** Activate plugin, register at least one hierarchical taxonomy, manage its tree in admin without using the default list as the primary workflow.

## Phase 2 — Extensions (later)

| Item | Status |
|------|--------|
| Filters to register taxonomies into the environment | Pending |
| Side-panel / row-action extension hooks | Pending |
| Documented public PHP + HTTP API | Pending |
| Automated tests for nesting and delete policies | Pending |

**Exit criteria:** A second plugin can enable the tree for its taxonomy with glue code only (no forks of this plugin).

## Phase 3 — Integration and polish (later)

| Item | Status |
|------|--------|
| Optional integration with `wp-electronic-parts` | Pending |
| Drag-and-drop reparent/reorder (if still required) | Pending |
| Large-tree performance (batch queries, caching) | Pending |
| Optional read-only frontend tree | Pending |

**Exit criteria:** Host catalog plugins can rely on this environment for tree UX; docs describe the supported extension contract.

## Maintenance rule

Whenever phases, priorities, or exit criteria change in the project plan, update this roadmap in the same change.
