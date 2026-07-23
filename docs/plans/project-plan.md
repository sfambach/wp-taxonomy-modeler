---
name: WP Taxonomy Tree — Project Plan
overview: Build a reusable WordPress plugin that provides a hierarchical taxonomy tree environment (admin UI, APIs, and extension points) usable by other plugins such as wp-electronic-parts.
status: draft
version: "0.1.0-plan"
last_updated: "2026-07-23"
related_docs:
  - README.md
  - docs/PRODUCT.md
  - docs/ARCHITECTURE.md
  - docs/ROADMAP.md
todos:
  - id: scaffold-plugin
    content: "Scaffold modern PHP 8.x plugin bootstrap, autoload, text domain, and activation hooks"
    status: pending
  - id: core-tree-model
    content: "Implement taxonomy-agnostic tree model (load, nest, ancestors, descendants) on top of WP_Term"
    status: pending
  - id: admin-tree-ui
    content: "Admin tree UI for any hierarchical taxonomy (expand/collapse, select, create child, delete with promote/cascade)"
    status: pending
  - id: rest-or-ajax-api
    content: "Secure CRUD/tree endpoints (capability + nonce/permission callbacks) for the admin UI"
    status: pending
  - id: extension-api
    content: "Documented hooks/filters so host plugins can bind CPTs, side panes, and custom term behavior"
    status: pending
  - id: docs-sync
    content: "Keep PRODUCT, ARCHITECTURE, and ROADMAP docs aligned with this plan on every plan change"
    status: in_progress
  - id: integrate-electronic-parts
    content: "Optional later: consume this plugin from wp-electronic-parts instead of the embedded category tree"
    status: pending
---

# Project plan: WP Taxonomy Tree

> **Source of truth for intent.** When this plan changes, update the linked documentation in the same change (`docs/PRODUCT.md`, `docs/ARCHITECTURE.md`, `docs/ROADMAP.md`, and the README summary).

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
- Owning domain-specific part properties (those stay in host plugins such as `wp-electronic-parts`).
- Frontend public theme templates in MVP (may come later).

## Relationship to `wp-electronic-parts`

`wp-electronic-parts` already contains a catalog split-view and category tree tightly coupled to `part_category` / `electronic_part`.

**Direction:** extract and generalize the taxonomy-tree concerns into this plugin, then optionally have electronic parts consume it. Until integration exists, this repo evolves independently with a clean public API.

## Delivery phases

### Phase 0 — Foundation (current)

- Repository rules (English code/docs, WordPress standards, DB practices).
- Project plan + living documentation + sync rule.
- Local WordPress development environment (see sibling/dev-env work).

### Phase 1 — MVP plugin

- Plugin bootstrap (PHP 8.x, OOP, text domain `wp-taxonomy-tree`).
- Taxonomy-agnostic tree builder over `WP_Term` / `WP_Term_Query`.
- Admin page registering a tree UI for selected hierarchical taxonomies.
- Create root/child terms, rename/select, delete with promote-children or cascade.
- Capability checks, nonces, prepared `$wpdb` usage only when custom SQL is unavoidable.

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

- A site admin can manage a hierarchical taxonomy as a tree without using the default tags list as the primary UI.
- Another plugin can register a taxonomy into the environment with minimal glue code.
- Documentation always reflects the current plan and implemented architecture.
- Code and docs remain English, WPCS-oriented, and secure by default.

## Decision log

| Date | Decision |
|------|----------|
| 2026-07-23 | Project is a reusable taxonomy tree **environment**, not a parts catalog. |
| 2026-07-23 | Plan file is the intent source of truth; product/architecture/roadmap docs must update whenever the plan changes. |
| 2026-07-23 | Domain properties (measure, enums, etc.) remain outside this plugin. |

## Change protocol

1. Edit this plan (status, todos, phases, decisions).
2. Update `last_updated`.
3. In the **same commit/PR**, sync:
   - `docs/PRODUCT.md` — user-facing purpose and scope
   - `docs/ARCHITECTURE.md` — technical shape matching the plan
   - `docs/ROADMAP.md` — phased delivery matching todos/phases
   - `README.md` — short summary and links
4. Do not leave plan and docs disagreeing about goals, non-goals, or current phase.
