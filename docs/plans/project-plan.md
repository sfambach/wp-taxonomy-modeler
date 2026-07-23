---
name: WP Taxonomy Tree — Project Plan
overview: Build a reusable WordPress plugin that provides a hierarchical taxonomy tree environment (admin UI, APIs, and extension points) usable by other plugins such as wp-electronic-parts.
status: planning
version: "0.6.2-plan"
last_updated: "2026-07-23"
related_docs:
  - README.md
  - docs/PRODUCT.md
  - docs/ARCHITECTURE.md
  - docs/ROADMAP.md
  - docs/OPEN-QUESTIONS.md
  - .cursor/rules/versioning.mdc
  - .cursor/rules/planning-only.mdc
related_plans:
  - docs/plans/planning-phase.md
  - docs/plans/mvp-requirements.md
  - docs/plans/data-structure.md
todos:
  - id: planning-phase
    content: "Complete planning-phase checklist (scope, questions, MVP requirements, sign-off) — no implementation"
    status: in_progress
  - id: define-data-structure
    content: "Define Project, Node, Parameter, Changelog/Change; tree is derived from root node; settle storage mapping"
    status: in_progress
  - id: docs-sync
    content: "Keep PRODUCT, ARCHITECTURE, ROADMAP, and OPEN-QUESTIONS aligned with this plan on every plan change"
    status: in_progress
  - id: scaffold-plugin
    content: "Scaffold modern PHP 8.x plugin bootstrap, autoload, text domain, and activation hooks (blocked until planning sign-off)"
    status: pending
  - id: core-tree-model
    content: "Implement taxonomy-agnostic tree model (load, nest, ancestors, descendants) on top of Node/WP_Term (blocked until planning sign-off)"
    status: pending
  - id: admin-tree-ui
    content: "Admin tree UI for any hierarchical taxonomy (expand/collapse, select, create child, delete with promote/cascade) (blocked until planning sign-off)"
    status: pending
  - id: rest-or-ajax-api
    content: "Secure CRUD/tree endpoints (capability + nonce/permission callbacks) for the admin UI (blocked until planning sign-off)"
    status: pending
  - id: extension-api
    content: "Documented hooks/filters so host plugins can bind CPTs, side panes, and custom term behavior (blocked until planning sign-off)"
    status: pending
  - id: integrate-electronic-parts
    content: "Optional later: consume this plugin from wp-electronic-parts instead of the embedded category tree"
    status: pending
---

# Project plan: WP Taxonomy Tree

> **Source of truth for intent.** When this plan changes, update the linked documentation in the same change (`docs/PRODUCT.md`, `docs/ARCHITECTURE.md`, `docs/ROADMAP.md`, `docs/OPEN-QUESTIONS.md`, and the README summary).

## Current mode: planning only

**Status: `planning`.** Do **not** implement plugin code yet.

Work now is limited to:

- refining this plan and related plan slices
- living documentation
- open questions and MVP requirements
- repository rules that support planning/standards

Implementation todos below stay **pending/blocked** until planning sign-off and an explicit request to start coding. See [`.cursor/rules/planning-only.mdc`](../../.cursor/rules/planning-only.mdc) and [`docs/plans/planning-phase.md`](planning-phase.md).

## Problem

WordPress hierarchical taxonomies are hard to manage in the default flat/list UI. Domain plugins (for example electronic parts catalogs) repeatedly need a **tree environment**: browse, create, reparent/delete, and extend nodes with custom behavior.

## Goal

Ship **WP Taxonomy Tree** as a focused WordPress plugin that provides a reusable **taxonomy tree environment**:

1. Works with any hierarchical taxonomy (not only one hard-coded slug).
2. Offers a clear admin tree experience.
3. Exposes stable PHP and HTTP APIs for host plugins.
4. Follows current WordPress standards, solid programming practice, and safe relational/data access.

## Non-goals (for early versions)

- Replacing the full Gutenberg site editor experience.
- Becoming a general-purpose graph database.
- Owning filled part-instance catalogs (parts CPT, etc. stay in host plugins such as `wp-electronic-parts`).
- Frontend public theme templates in MVP (may come later).
- Any plugin implementation work while status remains `planning`.
- Treating Parameter as fully specified before Node↔Parameter relation and types are agreed.

## Relationship to `wp-electronic-parts`

`wp-electronic-parts` already contains a catalog split-view and category tree tightly coupled to `part_category` / `electronic_part`.

**Direction:** extract and generalize the taxonomy-tree concerns into this plugin, then optionally have electronic parts consume it. Until integration exists, this repo evolves independently with a clean public API. Integration coding is out of scope until after planning and after an extension contract is drafted.

## Delivery phases

### Phase 0 — Foundation & planning (current)

- Repository rules (English code/docs, WordPress standards, DB practices, versioning, planning-only gate).
- Project plan + living documentation + sync rule.
- Planning checklist, MVP requirements, open questions, and data structure (**Project**, **Node**, **Parameter**; tree = root node).
- Local WordPress development environment (separate PR; environment only, not product implementation).

### Phase 1 — MVP plugin (after planning sign-off)

- Plugin bootstrap (PHP 8.x, OOP, text domain `wp-taxonomy-tree`), starting at version **`0.0.1`**.
- Taxonomy-agnostic **Node** tree model (over WordPress terms unless planning decides otherwise).
- **Parameter** object model as defined in the data-structure plan (scope of MVP vs later still open).
- Admin page registering a tree UI for selected hierarchical taxonomies.
- Create root/child nodes, rename/select, delete with promote-children or cascade.
- Capability checks, nonces, prepared `$wpdb` usage only when custom SQL is unavoidable.
- Details: [`docs/plans/mvp-requirements.md`](mvp-requirements.md), [`docs/plans/data-structure.md`](data-structure.md).

### Phase 2 — Extension surface

- Filters to register which taxonomies use the tree UI.
- Actions/filters for row actions, side panel content, and delete policy.
- REST or Admin-AJAX endpoints documented for host UIs.
- Basic automated tests for tree nesting and delete behaviors.

### Phase 3 — Host integration & polish

- Integration path for `wp-electronic-parts` (replace embedded tree where practical).
- Drag-and-drop reordering / parent changes (if still needed).
- Performance pass for large trees (caching, batched queries, avoid N+1).
- Optional block or shortcode for read-only frontend tree browsing.

## Success criteria

- Planning produces agreed MVP requirements and closed/deferred open questions before coding starts.
- After implementation is allowed: a site admin can manage a hierarchical taxonomy as a tree without using the default tags list as the primary UI.
- Another plugin can register a taxonomy into the environment with minimal glue code.
- Documentation always reflects the current plan and (later) implemented architecture.
- Code and docs remain English, WPCS-oriented, and secure by default.
- Versioning starts at `0.0.1`; major digit changes only on official releases.

## Decision log

| Date | Decision |
|------|----------|
| 2026-07-23 | Project is a reusable taxonomy tree **environment**, not a parts catalog. |
| 2026-07-23 | Plan file is the intent source of truth; product/architecture/roadmap docs must update whenever the plan changes. |
| 2026-07-23 | Domain properties (measure, enums, etc.) remain outside this plugin. |
| 2026-07-23 | Versioning: always start at `0.0.1`; change the first digit (`MAJOR`) only for official releases (for example first release `1.0.0`). |
| 2026-07-23 | **Planning-only mode:** no plugin implementation until plan status leaves `planning` and the user explicitly asks to implement. |
| 2026-07-23 | Core data structure starts with **Node**: `id`, `parent_id` (`null` = root), `name`, `taxonomy`; tree is a rooted forest with no cycles. |
| 2026-07-23 | One node can have **one parent node** (or none). Parent links are used to build **trees**; multiple trees form a **forest** (e.g. per taxonomy). |
| 2026-07-23 | One node can have **several child nodes** (or none). Children are the inverse of the single-parent link. |
| 2026-07-23 | Second core object is **Parameter** (distinct from Node). Node↔Parameter relation, types, storage, and values still open. |
| 2026-07-23 | **One node can have several parameters** (or none). |
| 2026-07-23 | “One parameter is always assigned to one node” is **tentative (?)** — reopen/keep Q14 and decide later. |
| 2026-07-23 | **Project** is a core object and can consist of different trees. |
| 2026-07-23 | **Tree is not an additional object**; a tree is defined by its **root node** (plus descendants). |
| 2026-07-23 | A **root node** is a node that has **no parent**. |
| 2026-07-23 | A root node is the **same object as a Node** where parent is `null` — not a separate type. |
| 2026-07-23 | PHP representation leaning (**Q20**): typed DTO **classes** for Project/Node/Parameter; services for behavior; no Tree/RootNode class; arrays only at API edges. |
| 2026-07-23 | **Project** class fields: `name`, `description`, and `root_nodes` (list of root **Node** objects). |
| 2026-07-23 | After every data-structure change, update and **show a Mermaid class diagram** (see `docs/plans/data-structure.md`). |
| 2026-07-23 | Every Project, Node, and Parameter has a **Changelog** made of **Change** entries (`timestamp`, `changer`, `change`). |
| 2026-07-23 | Every **Change** also has a **`version`**. |
| 2026-07-23 | A **Parameter** always has a **`type`** (required). |
| 2026-07-23 | A **type can have a unit** (Einheit), but not always: e.g. URL has none, measure like 10 kOhm has a unit. |

## Change protocol

1. Edit this plan (status, todos, phases, decisions).
2. Update `last_updated` (and plan version when the change is meaningful).
3. In the **same commit/PR**, sync:
   - `docs/PRODUCT.md` — user-facing purpose and scope
   - `docs/ARCHITECTURE.md` — technical shape matching the plan
   - `docs/ROADMAP.md` — phased delivery matching todos/phases
   - `docs/OPEN-QUESTIONS.md` — when decisions answer or defer questions
   - `README.md` — short summary and links
4. Do not leave plan and docs disagreeing about goals, non-goals, current mode, or current phase.
5. While status is `planning`, do not add implementation files.
