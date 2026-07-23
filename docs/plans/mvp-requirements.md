---
name: MVP requirements
overview: Testable requirements for the first implementable plugin version (0.0.1 development line). Planning artifact only.
status: draft
version: "0.1.0-plan"
last_updated: "2026-07-23"
related_plans:
  - docs/plans/project-plan.md
  - docs/plans/planning-phase.md
---

# MVP requirements (planning)

> Requirements for the first coding milestone. **Not implemented yet.**

## Product statement

Administrators can manage one or more **hierarchical** WordPress taxonomies in a dedicated tree screen. Host plugins can later attach behavior when a term is selected. Domain-specific fields (parts, measures, etc.) are **not** part of this MVP.

## Personas

| Persona | Job |
|---------|-----|
| Site admin | Maintain a clear category/term hierarchy without fighting the default list UI |
| Host plugin developer | Reuse the tree environment for a custom hierarchical taxonomy with minimal glue |

## Functional requirements

### FR1 — Taxonomy-agnostic registration

- The environment can target hierarchical taxonomies by slug.
- Non-hierarchical taxonomies are rejected or ignored with a clear rule (document exact behavior during planning sign-off).

### FR2 — Tree browsing

- Admin can expand/collapse nodes.
- Admin can see parent/child structure for the selected taxonomy.
- Empty taxonomy shows a clear empty state and a way to create a root term.

### FR3 — Create terms

- Create a root term.
- Create a child under a selected term.
- New terms appear in the tree without a full page reload (planned UX).

### FR4 — Select term

- Clicking a term selects it and exposes a selection hook/event for host UI (even if MVP host UI is only a placeholder panel).

### FR5 — Delete term

- Deleting a leaf term removes it after confirmation.
- Deleting a term with children requires an explicit choice:
  - **promote** children to the deleted term’s parent, or
  - **cascade** delete descendants
- Unauthorized users cannot mutate terms.

### FR6 — Security & i18n

- Capability checks use the taxonomy’s term-management capabilities.
- Mutations are protected (nonce or REST permission callbacks).
- User-facing strings are translatable with text domain `wp-taxonomy-tree`.

### FR7 — Version bootstrap

- First implemented plugin version is **`0.0.1`**.
- Major version digit changes only on official releases later.

## Non-functional requirements

| ID | Requirement |
|----|-------------|
| NFR1 | Prefer WordPress term APIs over custom tables for MVP |
| NFR2 | Avoid N+1 term queries when building the tree for typical catalog sizes |
| NFR3 | English identifiers, comments, and docs |
| NFR4 | Follow current WordPress coding/security practices |

## Explicit non-requirements (MVP)

- Drag-and-drop reordering
- Frontend/public tree block
- Term property schemas / measure fields
- Bulk import/export
- Replacing all core taxonomy screens site-wide by default
- Hard dependency on `wp-electronic-parts`

## Acceptance scenarios

1. **Empty tree:** On a hierarchical taxonomy with no terms, admin creates a root term and sees it in the tree.
2. **Child create:** Admin selects a term, creates a child, and sees nesting under the parent.
3. **Promote delete:** Admin deletes a parent with children via promote; children remain under the grandparent.
4. **Cascade delete:** Admin deletes a parent with children via cascade; parent and descendants are gone.
5. **Caps:** A user without `manage_terms` (or taxonomy equivalent) cannot create/delete via the endpoints.
6. **Host hook (minimal):** Selecting a term fires a documented extension point that a host can listen to.

## Open items affecting MVP

See [`docs/OPEN-QUESTIONS.md`](../OPEN-QUESTIONS.md) — especially transport, JS stack, multi-taxonomy UI, and default screen replacement.
