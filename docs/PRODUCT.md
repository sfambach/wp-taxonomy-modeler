# Product overview

> Living product documentation. Keep this aligned with [`docs/plans/project-plan.md`](plans/project-plan.md).

**Plugin:** WP Taxonomy Tree  
**Status:** Planning only — not implemented yet  
**Audience:** WordPress site builders and plugin developers who need hierarchical taxonomy management

## Current mode

We are defining scope, requirements, and architecture **before** writing plugin code. See [`docs/plans/planning-phase.md`](plans/planning-phase.md).

## What it is

WP Taxonomy Tree is a WordPress plugin that will provide a **taxonomy tree environment**: an admin-focused way to browse and manage hierarchical taxonomies as a real tree, plus APIs so other plugins can plug into that environment.

## Who it is for

- Administrators who outgrow the default taxonomy list screens.
- Plugin authors who need a reusable tree UI/API instead of rebuilding one per project.
- Projects such as catalogs (for example `wp-electronic-parts`) that attach domain data to taxonomy nodes.

## Core value

| Need | How this plugin helps |
|------|------------------------|
| See hierarchy clearly | Tree UI with expand/collapse and parent/child structure |
| Maintain terms safely | Create, select, and delete with explicit child handling |
| Reuse across plugins | Taxonomy-agnostic design and extension hooks |
| Stay WordPress-native | Built on terms, capabilities, and current WP APIs |

## In scope (planned)

- Hierarchical taxonomy tree management in wp-admin.
- Core objects: **Project** (`name`, `description`, `root_nodes`, `changelog`), **Node**, **Parameter**, plus shared **Changelog** / **Change**.
- A **tree is not a separate object**; it is defined by a **root node**.
- A **root node** is the same **Node** object with parent `null` (not a different type).
- A **project** holds different trees via `root_nodes`.
- **Project** always has a Definition tree and stores anchors for Type, Präfix, Basiseinheit.
- A **Parameter** uses **Type**, optional **Präfix**, and optional **Basiseinheit**.
- A filled **measure** is **value + prefix + unit** (e.g. `10 mm`); Größe dimensions compose as `10 mm × 5 mm × 2 mm`.
- Some trees are **templates** (`Node.template`) for project-specific trees.
- Parameter single-owner rule is still **?** .
- Every Project, Node, and Parameter has a changelog (`timestamp`, `changer`, `change`, `version`).
- Secure endpoints for the tree UI.
- Extension points for host plugins (which taxonomies, extra row actions, side panels).

## Out of scope (early versions)

- Modeling Tree as its own stored entity.
- Domain-specific part catalogs / part CPT ownership (host plugins).
- Full public frontend theme redesign.
- Non-hierarchical tag clouds or flat taxonomies as primary targets.
- Implementation work while the project plan status is `planning`.

## Planned user outcomes

1. Open a project and work with its trees (each tree = a root node).
2. Create root and child **nodes** from the tree.
3. Delete a node and choose whether children are promoted or removed.
4. Attach **parameters** to nodes (details still being planned).
5. Let another plugin attach its own editor pane or behavior when a node is selected.

Detailed MVP acceptance criteria: [`docs/plans/mvp-requirements.md`](plans/mvp-requirements.md).  
Data structure: [`docs/plans/data-structure.md`](plans/data-structure.md) (Project + Node + Parameter; tree = root).  
Planning examples: Definition tree + catalog tree `Root → Bauteile → Widerstände → …`.

## Versioning

- Plugin versions start at **`0.0.1`** when implementation begins.
- The first digit changes only for official releases (first release: **`1.0.0`**).

## Related documents

- Plan (source of truth): [`docs/plans/project-plan.md`](plans/project-plan.md)
- Planning checklist: [`docs/plans/planning-phase.md`](plans/planning-phase.md)
- MVP requirements: [`docs/plans/mvp-requirements.md`](plans/mvp-requirements.md)
- Data structure (Nodes): [`docs/plans/data-structure.md`](plans/data-structure.md)
- Open questions: [`docs/OPEN-QUESTIONS.md`](OPEN-QUESTIONS.md)
- Architecture: [`docs/ARCHITECTURE.md`](ARCHITECTURE.md)
- Roadmap: [`docs/ROADMAP.md`](ROADMAP.md)
- Versioning rule: [`.cursor/rules/versioning.mdc`](../.cursor/rules/versioning.mdc)
