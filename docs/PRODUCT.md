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
- Core objects: **Project** (`name`, `description`, `root_nodes`, `changelog`), **Node**, plus shared **Changelog** / **Change**. **No Parameter class.**
- A **tree is not a separate object**; it is defined by a **root node**.
- A **root node** is the same **Node** object with parent `null` (not a different type).
- A **project** is practically the **taxonomy** (**Q18** strong leaning); Trees live under the Project; Nodes have no taxonomy field.
- Default Nodes (Definitionsbaum + simples + enum + quantity): **lean template Project copy** (**Q50**); generate remains a fallback.
- **Pure Template** (**read-only**) = Datentypen (enum ohne konkrete Werte) + Präfix + Standard-Basiseinheiten (Meter, Liter, …); **BOM demo** (Bauart, Ohm/Farad/…, Stückliste, Bauteile, Spalten) belongs in an **editable BOM Testprojekt**.
- **Project** always has a **Definitionsbaum** and stores anchors for Type, Präfix, Basiseinheit.
- Attribute **Nodes** bind **Type** (via `has_type` / config), optional **Präfix**, and optional **Basiseinheit**.
- A filled **quantity** (*Größe*, not Messung) is **value + prefix + unit** (e.g. `10 mm`); composite from `int`/`double` + Präfix + Basiseinheit.
- Emerging type model: Composition = **Definition** + **Instanz**. Instanz lean: **ParameterValue** (+ **CompositionRow** for BOM/Rezept). UX Zusammenstellung / internal Composition.
- `enum` = closed value list over one simple base; `single`/`multiple` = selection methods (not types).
- `quantity` = Größe (Zahl × Einheit); not a measurement act; not BOM Menge.
- **Decided (Q51):** Basiseinheit links to allowed Präfixe; scale via Relation **multiplikator** → int (value on edge); unit select fed e.g. `Ohm` derives `Ohm`/`kOhm`/… — no `kOhm` Nodes.
- Every Node has a **description** (may be empty).
- **Decided (Q33):** **no Parameter class** and **no ParameterRole** — attributes like `Wert` are ordinary **Nodes** with type binding via config / `has_type`. Typed edges remain exploratory (**Q35**, Q41–Q43) — not hierarchy store.
- **Decided (Q20):** typed PHP DTOs for Project / Node / Changelog / Change.
- Leaning: each RelationType has one **`label`** (no `inverse`); `consists_of` targets shown as **attributes**, inheritable along `is_a`.
- Leaning: domain structures (**BOM**, **Recipe**, …) configurable as **Nodes** (schema-as-Nodes) rather than fixed PHP classes (Q46).
- Some trees are **templates** (`Node.template`) for project-specific trees.
- Attribute-node placement uses `parent_id` and/or Relations (**Q14 dropped / entfällt**); baseline tree = `parent_id` until fresh Q54.
- Open (**Q34/Q49**): config-first proposal — simples get `capabilities.originate_relations = false` (not a hard special kind).
- Every Project and Node has a changelog (`timestamp`, `changer`, `change`, `version`).
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
4. Work with attribute **Nodes** under categories (configuration shape and values still being planned).
5. Let another plugin attach its own editor pane or behavior when a node is selected.

Detailed MVP acceptance criteria: [`docs/plans/mvp-requirements.md`](plans/mvp-requirements.md).  
Data structure: [`docs/plans/data-structure.md`](plans/data-structure.md) (Project + Node; no Parameter; tree = root).  
Use cases: [`docs/plans/use-cases.md`](plans/use-cases.md).  
Example projects: [`docs/plans/example-projects.md`](plans/example-projects.md) (BOM + Hardware + Rezepte validate tree vs host split).  
Part identity layers: [`docs/plans/part-identity-layers.md`](plans/part-identity-layers.md) (100 Ω SMD vs THT vs Shunt, etc.).
Planning examples: **Definitionsbaum**; separate **Bauteile** tree with typed edges (`ist-ein` / `besteht-aus`).

## Versioning

- Plugin versions start at **`0.0.1`** when implementation begins.
- The first digit changes only for official releases (first release: **`1.0.0`**).

## Related documents

- Plan (source of truth): [`docs/plans/project-plan.md`](plans/project-plan.md)
- Planning checklist: [`docs/plans/planning-phase.md`](plans/planning-phase.md)
- MVP requirements: [`docs/plans/mvp-requirements.md`](plans/mvp-requirements.md)
- Data structure (Nodes): [`docs/plans/data-structure.md`](plans/data-structure.md)
- Use cases: [`docs/plans/use-cases.md`](plans/use-cases.md)
- Open questions: [`docs/OPEN-QUESTIONS.md`](OPEN-QUESTIONS.md)
- Architecture: [`docs/ARCHITECTURE.md`](ARCHITECTURE.md)
- Roadmap: [`docs/ROADMAP.md`](ROADMAP.md)
- Versioning rule: [`.cursor/rules/versioning.mdc`](../.cursor/rules/versioning.mdc)
