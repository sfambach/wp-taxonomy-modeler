# Roadmap

> Living delivery roadmap. Keep this aligned with [`docs/plans/project-plan.md`](plans/project-plan.md).

Last synced from plan version **0.6.93-plan** (2026-07-25).

**Current mode: scaffolding** — early runnable admin tree over WP terms; full domain model still planning. Plugin ≈ **`0.0.74`**.

## Phase 0 — Foundation & planning (active)

| Item | Status |
|------|--------|
| Coding rules (English, practices, WP standards, DB practices) | In progress |
| Versioning rule (start `0.0.1`; major only on release) | Done |
| Planning + early-scaffold rule | Done (updated) |
| Project plan + living docs + sync rule | In progress |
| Planning checklist + MVP requirements + open questions | In progress |
| Data structure: Node / Parameter / Project / Definition anchors | Done (planning) |
| **Q51:** Basiseinheit allowlist + unit=set (Typ/Praefix/Kuerzel); display compose | Done (planning + scaffold interim) |
| **Q52–Q64** and other decided items | See plan decision log / OPEN-QUESTIONS |
| Open questions remaining | In progress |
| Local WordPress development environment | In progress (Windows + Cloud notes) |

**Exit criteria (planning):** MVP requirements accepted; open questions decided or deferred; user sign-off for broader domain implementation beyond scaffold.

## Phase 0b — Early scaffold (active)

| Item | Status |
|------|--------|
| Plugin bootstrap PHP 8.x OOP (`WTT_VERSION`, text domain) | Done (≈ `0.0.74`) |
| Tree model over `WP_Term` (nest, create, rename, description, short_description, copy, move, delete) | Done |
| Admin-AJAX + caps + nonce | Done |
| Admin split UI (tree + detail + toolbar) | Done |
| Interim types: assign type, set members, table footer, required, fixed value | Done |
| Q51 interim: `_wtt_allowed_prefix_ids`, Praefix child UI, composed unit label | Done |
| Set options: separator, join units, label children; set = one Form/Table field | Done |
| short_description in labels / help / dropdowns | Done |
| Demo BOM Testprojekt seed + reset/sync scripts | Done |
| Settings (test mode, show type, show set child props, save-via-button) | Done |
| Preview Form/Table × edit/display; unit Definition vs usage; set field UX | Done (UX may reverse) |
| Dropdown unify (no empty placeholders; space indent) | Done |
| Parameter class / Relations / Composition rows / REST / host hooks | Not started |

**Exit criteria:** User can exercise the taxonomy tree + type/unit preview locally; scaffold remains interim until domain sign-off.

## Phase 1 — MVP (blocked on planning sign-off for domain slice)

| Item | Status |
|------|--------|
| Formal Domain DTOs / services (beyond term-meta interim) | Blocked |
| Parameter persistence per Q64 | Blocked |
| Harden MVP FR acceptance vs scaffold | Blocked |

**Exit criteria:** Activate plugin, manage hierarchical taxonomy as primary tree workflow; accepted MVP requirements met.

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

**Exit criteria:** Host catalog plugins can rely on a stable tree environment without forking tree UI code.
