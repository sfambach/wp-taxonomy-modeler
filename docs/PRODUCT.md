# Product overview

> Living product documentation. Keep this aligned with [`docs/plans/project-plan.md`](plans/project-plan.md).

**Plugin:** WP Taxonomy Tree  
**Status:** Scaffolding ≈ **`0.0.296`** (admin tree on **`wtt_fs` Fallstudie** only — **`wtt_tree` retired**; Relations Q74–Q78; set=`composition` Q75; **Q88 hierarchy datatype = parent** (root **Knoten**); **Q90** Complex `enum`/`list`/`table` **parked**; **Q91** Registry + many type renderers; **Q92** `chooser_root`/`chooser_focus` catalog bindings; **Fill Model Data** instances; Sample_Data name→type map; attribute-host Form/Table preview). Full Project/Node domain still planning (Parameter class discarded). **Fallstudie is exploratory — not model sign-off.** Docs absorb Fallstudie learnings (plan **0.7.30**); status stays scaffolding.  
**Audience:** WordPress site builders and plugin developers who need hierarchical taxonomy management

## Current mode

**Scaffolding + planning:** domain model and MVP requirements continue in docs; a **runnable early scaffold** is available for exploration. Scaffold ≠ planning sign-off. See [`docs/plans/planning-phase.md`](plans/planning-phase.md) and [`docs/plans/project-plan.md`](plans/project-plan.md).

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
- Core objects: **Project**, **Node**, plus shared **Changelog** / **Change**. (**Parameter class discarded 2026-08-02.**)
- A **tree is not a separate object**; it is defined by a **root node**.
- A **root node** is the same **Node** object with **no `child_of`** parent (not a different type).
- A **project** is practically the **taxonomy** (**Q18** strong leaning); Trees live under the Project; Nodes have no taxonomy field.
- Default Nodes (Definitionsbaum + simples + quantity / units): **lean template Project copy** (**Q50**); generate remains a fallback.
- **Pure Template** (**read-only**) = Datentypen (simples; **no** product reliance on catalog `enum`/`list`/`table` — **Q90**) + Präfix + Standard-Basiseinheiten (Meter, Liter, …); **BOM demo** belongs in an **editable BOM Testprojekt**.
- **Project** always has a **Definitionsbaum** and stores anchors for Type, Präfix, Basiseinheit, **Relationstypen**.
- A filled **quantity** (*Größe*, not Messung) is **value + prefix + unit** (e.g. `10 mm`); composite from `int`/`double` + Präfix + Basiseinheit.
- **Eigenschaften (property slots)** = **typed child Nodes** under a domain Node (`type_id` → Typ-Ast / unit).
- **Decided (Q66 / Q54):** descendants **inherit property definitions** along the **`child_of`** hierarchy.
- **Decided (Q88):** Hierarchy datatype = parent. Only **root** typed **Knoten**; every other hierarchy node’s datatype = father. Attribute members keep own catalog field types (Q87). No free type pick as primary model for hierarchy nodes.
- Emerging type model: **Bauteilarten** (Definition schema) vs **Bauteile** (Implementation MPNs, **Q83**) vs **Composition**/Zusammenstellung. Bauteile nur als **`node_embed`** column (`ref_scope` → records). Instanz: values on nodes / rows.
- **Decided (Q85):** **Composition-first** — Platine `composition`→ Eigenschaften inkl. BOM; BOM `composition`→ Bauteil-Zuordnung, Position, Menge, …. Table / Collection-grid is a **view**, not the domain SoT. Avoid relations-CRUD as the product model.
- **Decided (Q26 + Q77):** assignable **catalog** types under Type/Datentypen; **type chooser** = every effective **`is_datatype`** node (attribute/catalog + root **Knoten**); **`is_abstract`** = local folders (not selectable).
- **Decided (Q59):** **Startknoten** is set by default in **Project Setup** (`start_node`).
- **Q64 superseded:** no Parameter class — Type Nodes (`int`, `media`, …) live under Datentypen; slots are typed children.
- **Decided (Q54 / Q35 / Q74):** Hierarchy = protected Relation **`child_of`** (tree view). Other Relations (esp. **`composition`**, **`has_type`**) via **Relation picker**. RelationTypes live in the **Relationstypen** tree. Node detail: Relations **von** / **an**.
- **Decided (Q78):** Each Relation edge has **multiplicity** (`0..1` / `1` / `0..*` / `1..*`) as a **definition** constraint. Default `0..*`. **`child_of` is always `1`**.
- **Q76 superseded for hierarchy datatype** by Q88; catalog inherit+override may remain scaffold interim. **Q77:** **`is_abstract` local only**; datatype nodes **may also have** a `type_id` (hierarchy: usually parent).
- **Decided (Q79):** Node identity = **ID** (`term_id`). Instance names may repeat under different parents. **Data-type** names must be **unique** in the taxonomy.
- **Decided (Q63):** **Tree = definition**; **WP page/block = instance values**.
- **Decided (Q61 / Q70 Fallstudie):** Tree structure named **`BOM`** = `composition` of **Name** (text) + **Tabelle** — **interim scaffold** (`type=table` legacy). Under **Q85** / **Q90**, prefer BOM as an object whose **composition members** are the line slots; do not treat catalog `table` as a required core type. Band identity = **`_wtt_prop_bindings`** where still used. Validator + **Bindings → Rules → Fixes** (**Q80**).
- **Decided (Q57/Q58/Q60/Q62):** Optional Fuss band; per-Fuss-slot **`_wtt_footer_op`**; Menge = Stück; allowlists; WP block fills instance rows (instance Name field removed from block UI). Legacy `slot_scope` only where still used as a filter. **Q82** lean: Fuss labels via `text` + **`fixed`**.
- Types are **Nodes under the Type branch** — no separate `TypeKind` class.
- **Decided (Q90):** Complex catalog kinds **`enum` / `list` / `table` parked** — not active product types. Closed values → hierarchy inheritance + attributes / Festwerte. Scaffold may still show leftover Complex leaves / Enum UI / collection-table until removal. **CatalogChoice:** when a type has specialization children, flat `<select>` if max depth ≤ 1, else tree chooser; Festwert seeds the value (Preis/Währung, 2026-08-06). **Value SoT** (id only vs pick + fill when host/child have attributes) → **Q93** (open).
- **Decided (Q91):** Node-only domain ≠ one renderer — **Registry + many type-specific renderers** (simples now; more later).
- `quantity` = Größe (Zahl × Einheit); not a measurement act; not BOM Menge (Stück). Alias **`measure`** normalizes to `quantity` (catalog leaf name stays `quantity`).
- Simple **`display_node_name`**: read-only display of the host node’s name (no user input).
- Simple **`media` (Q65):** WP Media Library and/or external URL (one type). Config: `allow_upload`, optional `allow_url`, optional **`allow_url_mirror`**. **`allowed_kinds`** default none. Render by MIME.
- **Decided (Q51 + Q75):** Basiseinheit allowlist for Präfixe; unit = **`set`** whose members are **`composition`** targets (Typ / Praefix? / Kuerzel).
- Every Node has a **description** (may be empty) and optional **short_description**.
- Scaffold set UX: separator, include children in label, join units.
- **Decided (Q20 / Q35):** typed PHP DTOs for Project, Node, Relation, Changelog, … (no Parameter DTO; RelationType = Node under Relationstypen).
- Leaning: each RelationType has one **`label`** (no `inverse`); reverse = view (Q41).
- Leaning: domain structures (**BOM**, **Recipe**, …) configurable as **Nodes** (schema-as-Nodes) rather than fixed PHP classes (Q46).
- Some trees are **templates** (`Node.template`) for project-specific trees.
- Open (**Q34/Q49**): config-first proposal — simples get `capabilities.originate_relations = false`.
- Every Project and Node has a changelog (`timestamp`, `changer`, `change`, `version`).
- Secure endpoints for the tree UI.
- Extension points for host plugins (which taxonomies, extra row actions, side panels).

## Out of scope (early versions)

- Modeling Tree as its own stored entity.
- Domain-specific part catalogs / part CPT ownership (host plugins).
- Full public frontend theme redesign.
- Non-hierarchical tag clouds or flat taxonomies as primary targets.
- Treating the early scaffold or Fallstudie as the final Composition / Relations product.
- Full domain services while broader planning questions remain open (beyond allowed scaffold scope).
- Reviving a separate **Parameter** class (discarded).
- A separate **`category` data type** for type-tree folders — use **`is_abstract`** (Q77) instead.
- Treating catalog **`enum` / `list` / `table`** as required core types (**Q90** parked — do not extend; warn before revival).

## Available now (scaffold)

1. Browse a hierarchical taxonomy as a tree (expand/collapse, selection memory).
2. Create / copy / rename / describe / reorder siblings / delete (promote or cascade).
3. Assign interim **types**: hierarchy datatype = parent (Q88; root **Knoten**); attribute/catalog types via chooser; required + fixed values; `is_datatype` / `is_abstract`.
4. Gutenberg block **Taxo Collection table** (`taxo/collection-table`) — **legacy scaffold** (Q90 parks catalog `table`; block may remain until removal).
5. Explore **Basiseinheit** units as sets (composition members) with prefix allowlists.
6. Preview Form + Table; sets as one field; denser chrome; adaptive picker path/name; picker search.
7. Seed / reset **Fallstudie** (`wtt_fs`) — standard scaffold tree (`wtt_tree` retired).
8. **Relations von/an** (Q74) + multiplicity (Q78); **set** members via `composition` (Q75).
9. **Table bands** + validator + prop bindings + footer ops (Fallstudie-proven).
10. Case-study slim UI: Composition + Relations always shown; no Data type picker (Flags stay).
11. **Fill Model Data** admin page — pick a structure host (attributes) and CRUD **instance** rows (separate from the taxonomy definition; option store via `Model_Data`).

## Planned user outcomes (full product)

1. Open a project and work with its trees (each tree = a root node).
2. Create root and child **nodes** from the tree.
3. Delete a node and choose whether children are promoted or removed.
4. Work with **property slots** (typed child nodes) and **inherited definitions** along the tree.
5. Let another plugin attach its own editor pane or behavior when a node is selected.

Detailed MVP acceptance criteria: [`docs/plans/mvp-requirements.md`](plans/mvp-requirements.md).  
Data structure: [`docs/plans/data-structure.md`](plans/data-structure.md).  
Use cases: [`docs/plans/use-cases.md`](plans/use-cases.md).  
Example projects: [`docs/plans/example-projects.md`](plans/example-projects.md).  
Case study: [`docs/plans/case-study.md`](plans/case-study.md).

## Versioning

- Plugin started at **`0.0.1`**; scaffold currently ≈ **`0.0.296`** (`MAJOR` stays `0` until first official release).
- Scaffold domain tree: **`wtt_fs`** (Fallstudie); **`wtt_tree`** retired from product UI (legacy constant only). Neither is post `category`.
