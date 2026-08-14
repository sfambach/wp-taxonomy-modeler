# Product overview

> Living product documentation. Keep this aligned with [`docs/plans/project-plan.md`](plans/project-plan.md).

**Plugin:** WP Taxonomy Tree  
**Status:** Scaffolding â‰ˆ **`0.0.562`** on **`wtt_fs` Fallstudie** only (`wtt_tree` retired). **Q123 locked** â€” attributes = `besteht_aus`/`aggregation` Relations (`Settings.data`/`view`; own-attr edge SoT); see [`DEVELOPER-ATTRIBUTE-MODEL.md`](DEVELOPER-ATTRIBUTE-MODEL.md). **Settings UI parity** ([`plans/settings-ui-parity.md`](plans/settings-ui-parity.md)): Knoten-/Attribut-Walk = one Settings surface; walk Preferred R/C/V stacked like node Display (â‰ˆ **0.0.559**); legacy Options chrome being removed. **Settings cascade â†’ paint (locked):** presets (Preferred, Q117, Hide, Mult, Bindung, walk) drive Model/Preview/nested cells â€” no display-name hardcoding; nested cell = type Preferred; **Aggregation** paints bound Model_Data via type Preferred (â‰ˆ **0.0.561**); Hide by edge id. **Hide remount shell** = CSS flex fill (â‰ˆ **0.0.562**; supersedes measure-height pin â‰ˆ 0.0.560). Modeling UAT still open. Also: **Q88**/`Q90`/`Q91`/`Q97`/`Q98`/`Q115`; preferred **`MultistepRenderer`** (Composition = step fill with **kind Preferred** + Display sync; Aggregation = list/create/bind â€” Q112); Compact **`compactShowLabels`**. Full domain still planning. **Fallstudie = gold scaffold â€” not Phase-1 sign-off.** Plan **0.7.143**; status stays scaffolding.  
**Audience:** WordPress site builders and plugin developers who need hierarchical taxonomy management

## Current mode

**Scaffolding + planning:** domain model and MVP requirements continue in docs; a **runnable early scaffold** is available for exploration. Scaffold â‰  planning sign-off. See [`docs/plans/planning-phase.md`](plans/planning-phase.md) and [`docs/plans/project-plan.md`](plans/project-plan.md).

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
- **Pure Template** (**read-only**) = Datentypen (simples; **no** product reliance on catalog `enum`/`list`/`table` â€” **Q90**; **no** product catalog `node_embed` â€” **Q72**) + **Definition/Konstanten** (**PrÃ¤fixe**, **Basiseinheiten**/{With prefix, Without prefix}, **Bauformen**, **WÃ¤hrung**) + **Data Types** hosts such as **Unit type** â€” editable product examples live under Fallstudie **Model** (BOM / Bauteile) â€” **not** an Implementation SoT and not a parallel `wtt_tree` project.
- **Project** always has a **Definitionsbaum** and stores anchors for Type, PrÃ¤fix, Basiseinheit, **Relationstypen**.
- A filled **quantity** (*GrÃ¶ÃŸe*, not Messung) is **value + prefix + unit** (e.g. `10 mm`); composite from `int`/`double` + PrÃ¤fix + Basiseinheit.
- **Eigenschaften (attributes)** = **`besteht_aus` / `aggregation` Relations** with `name` + type target (**Q123**) â€” not hierarchy children; scaffold slots = debt.
- **Decided (Q66 / Q54):** descendants **inherit property definitions** along the **`child_of`** hierarchy.
- **Decided (Q88):** Hierarchy datatype = parent. Root `type_id` â†’ **Knoten** is **seed-only** (no admin free `set_type`). Every other hierarchy nodeâ€™s datatype = father (`has_type` except root = father). Attribute members keep own catalog field types (Q87).
- **Decided (Q83 superseded / OQ-B2):** **Model/Bauteil** = kinds **and** part records (by id). **No Implementation/ branch** as product SoT. Line refs â†’ Model only. Pick+fill = preferred **`MultistepRenderer`** (dialog|inline; legacy `embed`). Catalog **`node_ref` / `node_embed` / `ref_scope` deprecated** (do not use until a use case).
- **Decided (Q97):** Instance data under the **model object**; parent reads children via **composition/aggregation links** â€” not inline line blobs. Composition delete â†’ Trash with restore; aggregation leaves children. BOM lines = composition.
- **Decided (Q123 / OQ-W1â€¦W16):** Attributes = **composition/aggregation Relations** (`name` + target) â€” no slot terms. **`Settings.data`** + **`Settings.view`**; recursive Settings/Render walk; hybrid overrides. Instance keys = Relation id. Attributes panel = wizard. Deprecated: `node_ref` / `ref_scope` / `node_embed`. See [`docs/DEVELOPER-ATTRIBUTE-MODEL.md`](DEVELOPER-ATTRIBUTE-MODEL.md).
- **Decided (Q122):** Type **properties** live on the type; **override along inheritance** â€” same law everywhere. **Composed** types expose component settings dynamically (feeds the Q123 walk).
- **Decided (Q111 revised):** **Bindung** drives storage â€” **Composition** â†’ embedded in parent; **Aggregation** â†’ linked Model_Data. Delete cascade matches (composition dies with host; aggregation keeps targets, drops Relation).
- **Decided (Q114):** Attribute Settings UI = **same recursive walk** as node Settings (OQ-W6), not a thinner Options row.
- **Decided (Q126):** Node configuration page = one vertical **`ConfigPageRender`** box stack (Actions â†’ MetaSettings â†’ Bools â†’ Display â†’ Attributes â†’ Preview); same entry everywhere.
- **Decided (Q93):** Host stores **ids only**; filled values on referenced Model instance.
- **Decided (Q98 / UR-S1):** Model **generation** stamp + instance `modelVersion`; bump on structural edits; conflict badge â†’ Model versions; orphan retention + resolver (map/discard); Undo parked.
- **Decided (paint):** **Recursive boxed render** â€” Mult>1 = list; Table frames lists of attribute-bearing objects; Preferred paints each unit; nest freely. Same path admin â†” blocks â†” frontend.
- **Decided (Q85):** **Composition-first** â€” Platine `composition`â†’ Eigenschaften inkl. BOM; BOM `composition`â†’ Bauteil-Zuordnung, Position, Menge, â€¦. Table / Collection-grid is a **view**, not the domain SoT. Avoid relations-CRUD as the product model.
- **Decided (Q26 + Q77 + Q92 + datatype job #6, revised 2026-08-07):** catalog types under Type/Datentypen anchors; **type chooser = nodes** scoped by **`chooser_root` / bindings** â€” **not** gated by `_wtt_is_datatype`; **`is_abstract`** = local folders (not selectable â€” keep until #12 decide). Select by **id** only; special branches/nodes â†’ **settings** (`wtt_catalog_bindings`), never name lookup at runtime.
- **Decided (Q59):** **Startknoten** is set by default in **Project Setup** (`start_node`).
- **Q64 superseded:** no Parameter class â€” Type Nodes (`int`, `media`, â€¦) live under Datentypen; slots are typed children.
- **Decided (Q54 / Q35 / Q74):** Hierarchy = protected Relation **`child_of`** (tree view). Other Relations (esp. **`composition`**, **`has_type`**) via **Relation picker**. RelationTypes live in the **Relationstypen** tree. Node detail: Relations **von** / **an**. Product: non-root `has_type` = father (Q88).
- **Decided (Q78):** Each Relation edge has **multiplicity** (`0..1` / `1` / `0..*` / `1..*`) as a **definition** constraint. Default `0..*`. **`child_of` is always `1`**.
- **Q76 superseded for hierarchy datatype** by Q88; catalog inherit+override may remain scaffold interim. **Q77:** **`is_abstract` local only**; `_wtt_is_datatype` = scaffold debt (**#6** â†’ id+bindings decided; **#12** type-role vs `type_id` still open â€” see OPEN-QUESTIONS). Catalog lock = **`_wtt_is_template`** (Development-mode editable).
- **Decided (Q79, revised 2026-08-07):** Node identity / selection = **ID** (`term_id`) â€” never by name (config bindings exception). Instance names may repeat. Former datatype-name uniqueness = optional UX debt.
- **Decided (Q63):** **Tree = definition**; **WP page/block = instance values**.
- **Presentation parity (plan 0.7.49):** Admin object/preview, Gutenberg **`taxo/object-view`** (editor + frontend), and Taxo Table view rows that reuse object chrome share **one** paint path (`WTTObjectRender` / `Object_Render` / Registry). Blocks are **views** (Q85), not a second UI. Complements Q63 + Q91; does not reopen Q90.
- **Decided (Q61 / Q70 Fallstudie):** Tree structure named **`BOM`** = `composition` of **Name** (text) + **Tabelle** â€” **interim scaffold** (`type=table` legacy). Under **Q85** / **Q90**, prefer BOM as an object whose **composition members** are the line slots; do not treat catalog `table` as a required core type. Band identity = **`_wtt_prop_bindings`** where still used. Validator + **Bindings â†’ Rules â†’ Fixes** (**Q80**).
- **Decided (Q57/Q58/Q60/Q62):** Optional Fuss band; per-Fuss-slot **`_wtt_footer_op`**; Menge = StÃ¼ck; allowlists; Gutenberg exposes instances â€” Object View = one host (+ optional instance); Taxo Table view = all instances for a host. Instance Name field removed from block UI. Legacy `slot_scope` only where still used as a filter. **Q82** lean: Fuss labels via `text` + **`fixed`**.
- Types are **Nodes under the Type branch** â€” no separate `TypeKind` class.
- **Decided (Q90):** Complex catalog kinds **`enum` / `list` / `table` parked** â€” not active product types. Closed values â†’ hierarchy inheritance + attributes / Festwerte. **â‰ˆ 0.0.463:** Fallstudie soft-trashes `enum`/`list`/`node_*` from the tree; stops seeding them. Legacy **`table`** leaf kept only while BOM::Tabelle binds it. Bauart CatalogChoice under Complex. **CatalogChoice:** flat `<select>` if max depth â‰¤ 1, else tree chooser; Festwert seeds the value. **Value SoT** â†’ **Q93 decided** (id only on host).
- **Decided (Q91 + Q123):** Node-only domain â‰  one renderer â€” **Registry + many type-specific renderers**. Preferred R/C live in **`Settings.view`** (hybrid live + Relation override deltas); validators in **`Settings.data`**. Same recursive paint walk as Settings. Same Registry / object surfaces across admin â†” block â†” frontend (parity). Scaffold `_wtt_preferred_*` seed = debt.
- **Decided (Q95):** Optional **tree icon** per node (`_wtt_icon`; default none). Settings allowlist of Dashicons. Admin **Identity** (name/descriptions) vs **Display** (icon). **Create:** standard icon by name when mapped, else copy parent; no later cascade / father-walk at render. Shown before the name via `renderTreeNode`. Simple seed uses **`marker`**.
- **Decided (Q96, scaffold â‰ˆ `0.0.385`):** Registry bind via catalog **`builtin.<id>` â†’ term id**; resolve by id (name match = debt).
- **Open (Q47):** Value-shape validators lean on the type (slot inherits via create-time seed). Scaffold defaults for Simple shapes; Bindingâ†’Ruleâ†’Fix; fixes never auto-run.
- `quantity` = GrÃ¶ÃŸe (Zahl Ã— Einheit); not a measurement act; not BOM Menge (StÃ¼ck). Alias **`measure`** normalizes to `quantity` (catalog leaf name stays `quantity`).
- Simple **`node_presentation`** (alias `display_node_name`): read-only display of one host Node presentation field (form/table/select/symbol/help/icon).
- Simple **`media` (Q65):** WP Media Library and/or external URL (one type). Config: `allow_upload`, optional `allow_url`, optional **`allow_url_mirror`**. **`allowed_kinds`** default none. Render by MIME.
- **Decided (Q51 + Q75):** Basiseinheit allowlist for PrÃ¤fixe; unit = **`set`** whose members are **`composition`** targets (Typ / Praefix? / Kuerzel).
- **Decided (PrÃ¤fixe catalog â‰ˆ 0.0.540):** Under **Konstanten/PrÃ¤fixe** â€” host **ChildList** + Choices exclude Centi; attributes **Presentation** + **multiplikator**; leaves picoâ€¦Tera with **Compact** Preferred and SI factors (incl. Giga/Tera). See [`plans/attribute-choice-inheritance.md`](plans/attribute-choice-inheritance.md#konstantenprÃ¤fixe-catalog-locked).
- **Decided (ChildList depth â‰ˆ 0.0.541):** ChildList Choices/Default follow CatalogChoice **Q90** â€” depth â‰¤ 1 flat (PrÃ¤fixe), depth â‰¥ 2 tree (Basiseinheiten).
- **Decided (BU leaves Compact â‰ˆ 0.0.543):** With/Without-prefix Basiseinheit leaves Preferred = **Compact** (Praefix?/Kuerzel attrs); UnitRenderer for quantity **usage**, not catalog definition.
- **Decided (Q109):** Measure/quantity = **display triple** (Typ + PrÃ¤fix? + Basiseinheit). PrÃ¤fix-Wechsel **innerhalb derselben Basiseinheit** â†’ **Typ umrechnen** (physikalische GrÃ¶ÃŸe bleibt; multiplikator/`to_si`). Kein stiller Wechsel zwischen verschiedenen Basiseinheiten.
- **Parked (Q110):** **WÃ¤hrung/Geld** separat â€” WÃ¤hrungswechsel (EURâ†’USD) braucht **Wechselkurs-Umrechnung**, nicht SI-PrÃ¤fix-Faktoren. Form und Kursquelle spÃ¤ter; Display-Locale bei **Q99**.
- Every Node has a **description** (may be empty) and optional **short_description**.
- Every Node may have an optional **icon** (Q95).
- Scaffold set UX: separator, include children in label, join units.
- **Decided (Q20 / Q35):** typed PHP DTOs for Project, Node, Relation, Changelog, â€¦ (no Parameter DTO; RelationType = Node under Relationstypen).
- Leaning: each RelationType has one **`label`** (no `inverse`); reverse = view (Q41).
- Leaning: domain structures (**BOM**, **Recipe**, â€¦) configurable as **Nodes** (schema-as-Nodes) rather than fixed PHP classes (Q46).
- Some trees are **templates** (`Node.template`) for project-specific trees.
- **Decided (Q34):** special *behavior* via **config** (+ Relations when the link is the structure); no PHP Node subclass / hard `kind`.
- **Decided (Q49 revised):** Builtin Simples **may** have specialization children â€” reusable named Config presets (validators, preferred converter e.g. Roman `int`, â€¦). Soft lean: no attributes as host; no outgoing Relations. One-off tweaks stay on attribute Options; repeated presets â†’ child type under the Simple.
- **Q48 lean:** scalars = Typ-Ast Nodes + type link (slot `type_id` interim / Q108 `attribute_typeof`); visible hardcoding via `implementationKey` + NodeConfig; composition for attribute hosts. **`int`:** renderer + Preferred converter (type default + attribute override).
- Every Project and Node has a changelog (`timestamp`, `changer`, `change`, `version`).
- Secure endpoints for the tree UI.
- Extension points for host plugins (which taxonomies, extra row actions, side panels).

## Out of scope (early versions)

- Modeling Tree as its own stored entity.
- Domain-specific part catalogs / part CPT ownership (host plugins).
- Full public frontend theme redesign.
- **Public frontend Model_Data entry** / visitor BOM suggestâ€“review flows (**Q103** â€” R1 display-only; later release).
- Plugin **Export** button as DR substitute (**Q94** â€” site/DB backup is primary; JSON export later).
- Non-hierarchical tag clouds or flat taxonomies as primary targets.
- Treating the early scaffold or Fallstudie as the final Composition / Relations product.
- Full domain services while broader planning questions remain open (beyond allowed scaffold scope).
- Reviving a separate **Parameter** class (discarded).
- A separate **`category` data type** for type-tree folders â€” use **`is_abstract`** (Q77) instead.
- Treating catalog **`enum` / `list` / `table`** as required core types (**Q90** parked â€” do not extend; warn before revival).

## Available now (scaffold)

1. Browse a hierarchical taxonomy as a tree (expand/collapse, selection memory).
2. Create / copy / rename / describe / optional **icon** (Q95) / reorder siblings / delete (promote or cascade).
3. Assign interim **types**: hierarchy datatype = parent (Q88; root **Knoten**); attribute/catalog types via node chooser (Q92 scope); required + fixed values; `is_abstract` ( `_wtt_is_datatype` = scaffold debt).
4. Gutenberg **`taxo/object-view`** (single host / optional instance) + **Taxo Table view** (`taxo/collection-table`, all instances) â€” views over definition + instances (parity with admin object chrome); catalog `table` kind still Q90-parked.
5. Explore **Basiseinheit** units as sets (composition members) with prefix allowlists.
6. Preview Form + Table; sets as one field; denser chrome; adaptive picker path/name; picker search.
7. Seed / reset **Fallstudie** (`wtt_fs`) â€” standard scaffold tree (`wtt_tree` retired).
8. **Relations von/an** (Q74) + multiplicity (Q78); **set** members via `composition` (Q75).
9. **Table bands** + validator + prop bindings + footer ops (Fallstudie-proven).
10. Case-study slim UI: Composition + Relations always shown; no Data type picker (Flags stay).
11. **Fill Model Data** admin page â€” pick a structure host (attributes) and CRUD **instance** rows (separate from the taxonomy definition; option store via `Model_Data`).
12. **Tree icons** â€” Settings allowlist; Identity vs Display UI; create standard-by-name else parent copy; Simple seeded with **`marker`**.
13. **Preferred render / converter / validators** â€” per-node meta; create-time type defaults; Registry; Bindingâ†’Ruleâ†’Fix for validators.

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

- Plugin started at **`0.0.1`**; scaffold currently â‰ˆ **`0.0.369`** (`MAJOR` stays `0` until first official release).
- Scaffold domain tree: **`wtt_fs`** (Fallstudie); **`wtt_tree`** retired from product UI (legacy constant only). Neither is post `category`.
