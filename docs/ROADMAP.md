# Roadmap

> Living delivery roadmap. Keep this aligned with [`docs/plans/project-plan.md`](plans/project-plan.md).

Last synced from plan version **0.6.20-plan** (2026-07-23).

**Current mode: planning only — no plugin implementation.**

## Phase 0 — Foundation & planning (active)

| Item | Status |
|------|--------|
| Coding rules (English, practices, WP standards, DB practices) | In progress (separate PR) |
| Versioning rule (start `0.0.1`; major only on release) | In progress |
| Planning-only rule (no implementation yet) | In progress |
| Project plan + living docs + sync rule | In progress |
| Planning checklist + MVP requirements + open questions | In progress |
| Data structure: Node (root = same Node with parent null; tree = that root) | In progress |
| Data structure: Parameter (node → several params; param → one node ?) | In progress |
| Data structure: Project (`name`, `description`, `root_nodes`) | In progress |
| Data structure: Project Definition anchors + Node.template | In progress |
| Example tree: single **Definitionsbaum** (Definition + Bauteile + Maße) | In progress |
| Example tree: **Bauteile** with typed edges (`ist-ein` / `besteht-aus`) | In progress |
| Core Type catalog: scalars + measure/enum composites; selection_mode (Q36–Q39) | In progress |
| Parked: further Node idea (Q40) | Parked |
| RelationType pairs + display/inherit (Q35, Q41–Q43) | In progress |
| Widerstand worked example: Approach A vs B | In progress |
| Data structure: Parameter-as-Node vs param defs vs typed edges (Q33–Q35) | In progress |
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
