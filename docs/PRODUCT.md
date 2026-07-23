# Product overview

> Living product documentation. Keep this aligned with [`docs/plans/project-plan.md`](plans/project-plan.md).

**Plugin:** WP Taxonomy Tree  
**Status:** Planned (not implemented yet)  
**Audience:** WordPress site builders and plugin developers who need hierarchical taxonomy management

## What it is

WP Taxonomy Tree is a WordPress plugin that provides a **taxonomy tree environment**: an admin-focused way to browse and manage hierarchical taxonomies as a real tree, plus APIs so other plugins can plug into that environment.

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

## In scope

- Hierarchical taxonomy tree management in wp-admin.
- Taxonomy-agnostic PHP model for nested terms.
- Secure endpoints for the tree UI.
- Extension points for host plugins (which taxonomies, extra row actions, side panels).

## Out of scope (early versions)

- Domain-specific term properties (parts parameters, units, measures).
- Full public frontend theme redesign.
- Non-hierarchical tag clouds or flat taxonomies as primary targets.

## User outcomes

1. Open a Catalog/Tree admin screen for a registered hierarchical taxonomy.
2. Create root and child terms from the tree.
3. Delete a node and choose whether children are promoted or removed.
4. Let another plugin attach its own editor pane or behavior when a node is selected.

## Versioning

- Plugin versions start at **`0.0.1`**.
- The first digit changes only for official releases (first release: **`1.0.0`**).

## Related documents

- Plan (source of truth): [`docs/plans/project-plan.md`](plans/project-plan.md)
- Architecture: [`docs/ARCHITECTURE.md`](ARCHITECTURE.md)
- Roadmap: [`docs/ROADMAP.md`](ROADMAP.md)
- Versioning rule: [`.cursor/rules/versioning.mdc`](../.cursor/rules/versioning.mdc)
