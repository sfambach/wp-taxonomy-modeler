---
name: WP Taxonomy Tree — Project Plan
overview: Build a reusable WordPress plugin that provides a hierarchical taxonomy tree environment (admin UI, APIs, and extension points) usable by other plugins such as wp-electronic-parts.
status: scaffolding
version: "0.6.87-plan"
last_updated: "2026-07-25"
related_docs:
  - README.md
  - docs/PRODUCT.md
  - docs/ARCHITECTURE.md
  - docs/ROADMAP.md
  - docs/OPEN-QUESTIONS.md
  - .cursor/rules/versioning.mdc
  - .cursor/rules/planning-only.mdc
  - .cursor/rules/clean-model-guidelines.mdc
related_plans:
  - docs/plans/planning-phase.md
  - docs/plans/mvp-requirements.md
  - docs/plans/data-structure.md
  - docs/plans/use-cases.md
  - docs/plans/example-projects.md
  - docs/plans/part-identity-layers.md
todos:
  - id: planning-phase
    content: "Complete planning-phase checklist (scope, questions, MVP requirements, sign-off) — no implementation"
    status: in_progress
  - id: define-data-structure
    content: "Define Project, Node, Parameter, Changelog/Change; tree is derived from root node; settle storage mapping"
    status: in_progress
  - id: draft-use-cases
    content: "Describe planning use cases (format + cards in docs/plans/use-cases.md); open questions stay open"
    status: in_progress
  - id: example-projects
    content: "Validate model with concrete example projects (BOM, Hardware, Rezepte)"
    status: in_progress
  - id: part-identity-layers
    content: "Keep part identity layers note aligned when catalog modeling evolves"
    status: in_progress
  - id: docs-sync
    content: "Keep PRODUCT, ARCHITECTURE, ROADMAP, and OPEN-QUESTIONS aligned with this plan on every plan change"
    status: in_progress
  - id: scaffold-plugin
    content: "Scaffold modern PHP 8.x plugin bootstrap 0.0.1 (wp-taxonomy-tree.php + includes)"
    status: completed
  - id: core-tree-model
    content: "Taxonomy-agnostic tree model over WP_Term (nest/walk/delete); Domain Node DTO still planning"
    status: completed
  - id: admin-tree-ui
    content: "Admin tree UI for hierarchical taxonomies (expand/collapse, select, create, delete promote/cascade)"
    status: completed
  - id: rest-or-ajax-api
    content: "Secure Admin-AJAX endpoints (capability + nonce) for the tree UI"
    status: completed
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
| 2026-07-23 | Second core object is **Parameter** (distinct from Node). One Node can have several Parameters (or none); each Parameter has one owning Node (**Q14** / **Q64**). |
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
| 2026-07-23 | A **Parameter** has a **type** and an **optional unit**. |
| 2026-07-23 | A **unit is a Node**; the **unit values** are that node’s **child nodes** (no separate Unit class). |
| 2026-07-23 | Parameter **type is also a Node** (no separate ParameterType class). |
| 2026-07-23 | Example thinking tree: root **Definition** with children **Type**, **Basiseinheit**, **Präfix**. |
| 2026-07-23 | A **Parameter** uses Nodes from **Type** (required), **Präfix** (optional), and **Basiseinheit** (optional) — e.g. measure + k + Ohm. |
| 2026-07-23 | **Project** always stores Definition anchors: `definition_root`, `type_node`, `prefix_node`, `base_unit_node`. |
| 2026-07-23 | **Template trees**: `Node.template` flag; templates can seed project-specific trees. |
| 2026-07-23 | Example catalog tree: Root → Bauteile → Widerstände → Wert / Bauform / Leistungsaufnahme / Größe → Länge / Breite / Höhe. |
| 2026-07-23 | Filled **measure** = **value + prefix + Einheit (base_unit)**; dimensions e.g. `10 mm × 5 mm × 2 mm` (`mm` = Präfix `m` + Basiseinheit `Meter`). |
| 2026-07-23 | Merged into one **Definitionsbaum**: root **Definition**; **Bauteile** hangs under it (no separate Root); **Maße** → Länge / Breite / Höhe (replaces Größe). |
| 2026-07-23 | Explore **typed edges** (`ist-ein` / `besteht-aus`) via expanded **Bauteile** example tree (Q35) — orthogonal to Parameter class. |
| 2026-07-23 | Core Type catalog leaning: string, number, integer, boolean, url, file, enum, measure. |
| 2026-07-23 | **measure** = composite (number\|integer + Präfix + Basiseinheit), not a separate scalar; Widerstand A vs B worked example. |
| 2026-07-23 | **enum** = composite (scalar option values); **single/multiple** are selection methods, not types (Q38). |
| 2026-07-23 | Parked mid-session Node thought as **Q40** (resume later); switch topic away from type/A–B fork for now. |
| 2026-07-23 | **RelationType**: one **`label`** only; no `inverse` field (`consists_of` reverse wording = view). |
| 2026-07-23 | Tentative **`directed`** on RelationType: arrow `from→to` vs undirected line — unsure (Q44); distinct from DisplayHint. |
| 2026-07-23 | Display by RelationType: part-of nodes as **attributes** of parent; `consists_of` attrs inheritable along `is_a` (Q42/Q43). |
| 2026-07-23 | Start **use-case cards** in `docs/plans/use-cases.md`; leave open questions open for later. |
| 2026-07-23 | Example project **BOM**: tree+part properties in taxonomy-tree; lists/price/stock/compare/CSV in host — model still fits. |
| 2026-07-23 | Example project **Hardware** (compare, tests, PC builds, stats): same split; Relations optional for builds — model still fits (A+B cross-check). |
| 2026-07-23 | Example project **Rezepte**: trees + measures + optional Relation.props for amounts; steps/scaling/shopping/stats = host — model still fits (A+B+C). |
| 2026-07-23 | Design spin: measure value on **Relation**; **Präfix+Basiseinheit = unit group** (not a loose chain) — Q45. |
| 2026-07-23 | Part identity **layers** (kind → subtype → specs → package → catalog part → board usage); same pattern for R/C/Diode/IC. |
| 2026-07-23 | Concrete BOM sample (JLCPCB board): host BomList/BomLine class diagram + Bauteile tree for C/R/LED/IC/connectors. |
| 2026-07-23 | Gap fill: **BOM/Recipe as configurable Nodes** (schema-as-Nodes) — fewer hard domain classes; Q46. |
| 2026-07-23 | Schema-as-Nodes needs **explicit line/step order** (BOM Zeilen, recipe steps) — strengthens Q13 `position`. |
| 2026-07-23 | Static UI prototype `prototypes/tree-split` (split tree/detail, add/delete); not WP plugin code. |
| 2026-07-23 | Prototype: sibling order by explicit `position` (↑↓ / Alt+arrow); BOM-demo seed; name does not sort. |
| 2026-07-23 | Prototype: right-pane tabs (Knoten / Tabelle); children of selection = table column config (header + 5 rows). |
| 2026-07-23 | Prototype: second editable table tab + form tab (dropdown/radio/switch/… from selected node + children). |
| 2026-07-23 | Insight: BOM **Reference** = open RefDes list (`R1,R2`); validation ≠ Node meta — Type/Parameter (Q47). |
| 2026-07-23 | Datentypen as tree Nodes (int/double/string/char/bool); bind via Relation `has_type`; UI widget from type (Q48). |
| 2026-07-23 | Fixed **simple data-type Nodes** in the template; further types **derived or composed** from those simples (**Q36**). |
| 2026-07-23 | **Q14:** each Parameter has exactly one owning Node. **Q34 leaning: configuration** (not PHP subclass of Node). **Q49:** simples may not originate Relations — config vs special kind. |
| 2026-07-23 | Use cases synced (`docs/plans/use-cases.md`): UC-04–UC-06; **UC-10**, **UC-14–UC-16**. |
| 2026-07-23 | **Taxonomy on Project, not Node** (Q18 leaning) — remove `Node.taxonomy`; Project may hold WP taxonomy slug. |
| 2026-07-23 | **Project ≈ taxonomy** (Q18 strengthened). Default Nodes: **generate** vs **copy template Project** — new **Q50** (relates Q30/Q32). |
| 2026-07-23 | Template holds **simple types** + derived **enum** (exactly one **base_type** + **value list**). Q50 leans template-copy; Q36/Q38/Q39 aligned. |
| 2026-07-23 | Derived type **`quantity`** (Größe = value + Präfix + Basiseinheit) in the template — renamed from informal `measure` (not a Messung / measurement act; not BOM Menge). Q36/Q37/Q45/Q50 synced; UC-05/UC-17. |
| 2026-07-23 | Spin **Q51:** Basiseinheit ─[allows_prefix]→ Präfix (allowed set); scale **factor** primarily on Präfix Node (kilo=1000); edge factor only as override. |
| 2026-07-23 | **Q51 agreed direction** — fits Nodes + Relations + Node.config; does not change `quantity` composition or add a Unit class. |
| 2026-07-23 | Q51 UI: pass Basiseinheit Node to a select → **derive** unit choices (Vater + linked Präfixe); labels like `kOhm`; store `{prefix, base_unit}`, not atomic unit Nodes. |
| 2026-07-23 | Prototype tab **Umrechnung** (`tree-split` v10): pick Basiseinheit in tree; convert Menge between derived units via Präfix.factor; non-base selection grays out fields. |
| 2026-07-23 | Q51 refine: scale = Relation **multiplikator** → int + value (not config); Farad allows only p/n/µ/m; Node.**description**; Relationen tab (not on Knoten); proto v11. |
| 2026-07-24 | **Q51 decided:** Basiseinheit ─[allows_prefix]→ Präfix; Präfix ─[multiplikator]→ int (`props.value`); UI derives unit labels; forward+back convert. |
| 2026-07-24 | **Q20 decided:** typed PHP DTO classes for Project, Node, **Parameter**, Changelog, Change, …; services for behavior. |
| 2026-07-24 | Node.**description** confirmed on every Node (may be empty); Q12 updated. |
| 2026-07-24 | **Q34/Q49 proposal:** config-first — `Node.config.capabilities.originate_relations` (false on simples); type binding via Relation `has_type`; no hard special kind. Still open pending user confirm. |
| 2026-07-24 | **Template vs BOM test:** pure **Template** Project = Datentypen + Präfix + Basiseinheit only; **Stückliste / Bauteile / Spalten** live in a separate **BOM Testprojekt** (demo), not in the template. Proto v12 project switcher. |
| 2026-07-24 | Template refinement: **enum** has no concrete values in template; BOM adds **Bauart** under enum. Template **Basiseinheit** = Meter/Liter/Kilogramm/Sekunde/Kelvin/Ampere; Ohm/Farad/Watt/Volt = BOM only. Template **read-only**, BOM Test **editable**. Proto v13. |
| 2026-07-24 | Spin **Collection** (Q52/Q53): list = 1-col table; enum = closed list; kind binding XOR (parent under kind **or** `has_type`→kind); concrete type needs kind + column type(s). |
| 2026-07-24 | Collection refine: **enum is created like list** (one column + `has_type`); closed options hang under that column; dedicated `base_type` Relation becomes redundant in this spin. |
| 2026-07-24 | Proto **v14:** Template has Collection(list/table/enum); BOM adds Bauart (enum), RefDes (list), Spalten ─[has_type]→ table. |
| 2026-07-24 | **Q52 decided:** Collection → `list` \| `table` \| `enum`; list = 1-col table; enum = list + closed options under typed column. **Q36** catalog aligned (no separate `string_list`). Q53 XOR kind-binding remains open. |
| 2026-07-24 | **Q53/Q54 spin:** tree `parent_id` vs Relations mixup — hierarchy already has meaning; prefer semantic graph (cloud + edges). Q53 lean: kind only via `has_type`; parent under Collection = org only. Q54: explore hierarchy as RelationType `contains` (tree = view). |
| 2026-07-24 | **Q53/Q54 decided:** Collection kind only via `has_type`. Hierarchy uses the **same Edge/Relation table** (rename optional); RelationType e.g. `contains`; tree UI = projection; `parent_id` if kept = denormalized cache only. |
| 2026-07-24 | **Q53/Q54 thought experiment closed (not adopted).** Hierarchy-as-edges + `parent_id` cache hybrid **excluded** from that branch. Q53/Q54 **restart fresh**; Q52 Collection shape kept. Baseline tree remains `parent_id` until a new decision. Plan **0.6.60**. |
| 2026-07-24 | **Design guidelines for clean restart:** (1) clear structures — one job / one truth / named shapes / visible invariants; (2) do not refuse objects where a named object is better — drop classes only with a positive reason. Cursor rule `clean-model-guidelines.mdc`. Plan **0.6.61**. |
| 2026-07-24 | Guideline add-on: **proactively flag** designs that look performance-hostile or conceptually nonsense (hot paths, dual writes, overloaded types). Plan **0.6.62**. |
| 2026-07-24 | Guideline add-on: **modern design paradigms / best practice** — composition over inheritance, typed models, ubiquitous language, SoC (persist ≠ domain ≠ UI), illegal states hard, established patterns first, cite or contrast. Plan **0.6.63**. |
| 2026-07-24 | **Q54 lean (new):** tree hierarchy only for **categorizing Bestandteile** of domain lists (BOM / Hardware / Rezept) and **inheriting hierarchical properties** — not Collection schema nesting. Plan **0.6.64**. |
| 2026-07-24 | **Q55:** **Parameter** definitions on a catalog Node (children inherit; leaves fill ParameterValue). Bauform = Parameter typed `Bauart` (enum). Examples BOM/Hardware/Rezept. Plan **0.6.65**. |
| 2026-07-24 | **Q56 lean:** BOM, hardware build, and cooking recipe are the **same concept** — a **Rezept** (composition: which Bestandteile belong together). Distinct from Katalog. Property-compare ≠ Rezept. Aligns Q46. Plan **0.6.66**. |
| 2026-07-24 | **Q56 refined:** GPU-Ausprägung *is* a Composition (filled params; refs Vorlage). Compare = Composition vs Composition. BOM/Build nest Compositions. Katalog agreed. UX lean **Zusammenstellung**; drop Rezept (kitchen) and Composition (too technical) as primary UI terms. Plan **0.6.67**. |
| 2026-07-24 | **Q56 naming decided:** UX **Zusammenstellung**, internal **Composition**; rename later allowed if a better word appears. Plan **0.6.68**. |
| 2026-07-24 | **Goal path:** create one Composition — ordered blockers; proposed defaults: Composition=Node, Vorlage=Node+Parameter defs, Parameter=definition object; BOM members = milestone 2. Plan **0.6.69**. |
| 2026-07-24 | Composition has **two viewpoints**: Definition (columns+types) and Instanz (filled values/rows). Worked schemas: BOM, Rezept, GPU (draft), Widerstand. Gap: **Composition-Ref** type for member columns. Plan **0.6.70**. |
| 2026-07-24 | **Instance content lean:** on create, store **ParameterValue**s on the Composition Node (Level A); Level B adds **CompositionRow**s with cell ParameterValues (incl. Composition-Ref). Not config blobs / not catalog children. Q16 strengthened in-core. Plan **0.6.71**. |
| 2026-07-24 | **Q56 correction:** **Widerstand is a Bauteil**, not a Composition; used in Composition **only as Bauteil-Ref column**. Composition = Stückliste/Rezept/Build. GPU-Karte = Bauteil too. Plan **0.6.72**. |
| 2026-07-24 | Proto **v15:** project **Composition Simples** — Phase 1 Zusammenstellung with only simple column types; Tabelle = instance rows. Extend later to quantity/enum/Bauteil-Ref. Plan **0.6.73**. |
| 2026-07-24 | **Simple types rename:** `string` → **`text`** (einzeilig, HTML input) + **`textarea`** (mehrzeilig; Format/Interpreter later). Aligns HTML/DB/Rails (`string`/`text`). Proto v26. Plan **0.6.74**. |
| 2026-07-24 | **`node_ref`** type (generic Node pointer) + Relation **`ref_scope`** → catalog root; replaces hardcoded Bauteil picker. Slot **Pflicht/Optional** = **`Node.config.required`** (not on `has_type`). BOM column **Beschreibung** → `textarea`. Proto v27. Plan **0.6.75**. |
| 2026-07-24 | BOM column rename **Bauteil** → **Bauteil Wahl** (vs catalog root Bauteile). Proto v28. |
| 2026-07-24 | Datentypen → **Simple** / **Complex**. Scoped catalog pick renamed **`subtree`** (`ref_scope`); new Simple **`node_ref`** = free Absprung to any Node. Proto v29. Plan **0.6.76**. |
| 2026-07-24 | Class diagram refreshed with **methods** + `NodeConfig` / `subtree` invariants. Plan **0.6.77**. |
| 2026-07-24 | Architecture **layers**: DTO + Domain Service + Repository (+ WP adapter); not classic MVC. Review notes (Parameter/Q55, naming). Plan **0.6.78**. |
| 2026-07-25 | **Q26 decided:** type of a Node is resolved **only under the Type branch** (`type_node` / Datentypen); Präfix/Basiseinheit only under their anchors. |
| 2026-07-25 | **Q57 decided:** a **BOM has a Fußzeile** (Composition table footer; e.g. Summe Menge in Stück). |
| 2026-07-25 | **Q57 refined:** Fußzeile has the **same column count**; each cell may run a simple aggregate (`sum` / `avg` / `min` / `max` / `count` / none|label) over that column’s rows. Plan **0.6.81**. |
| 2026-07-25 | **Q58 decided:** BOM **Menge** = **Stück** (`int`), not `quantity`. |
| 2026-07-25 | **Q59 decided:** **Startknoten** defaults from **Project Setup** (`Project.start_node`). |
| 2026-07-25 | **Q60 decided:** per BOM/Composition — **zulässige Typen** and **zulässige Basiseinheiten** (allowlists under the matching Definition branches). Plan **0.6.80**. Proto v30. |
| 2026-07-25 | **Q61 decided:** BOM **name required** (user); title under table = `BOM als Bauteilliste – {name}`. |
| 2026-07-25 | **Q62 decided (direction):** later WP **block** — pick table art from **Collection** nodes, then fill Bauteile like Backend. |
| 2026-07-25 | **Drop `TypeKind`:** types are simply Nodes under the Type branch (`type_node`) — no parallel enum/class. Plan **0.6.82**. |
| 2026-07-25 | Proto **v31:** BOM name field + title under table + Block tab (Collection art + Backend table). Simplified class diagram (classes only). Plan **0.6.83**. |
| 2026-07-25 | **Q61 corrected:** Tree structure name stays **`BOM`**; **`Projektname`** = Collection **Parameter** (inherited); filled on WP page/block. Title uses Projektname value. |
| 2026-07-25 | **Q63 decided:** Tree = **definition**; WP page/block = **instance values**. Proto v32. Plan **0.6.84**. |
| 2026-07-25 | **Q64 decided:** **Parameter class** — every Node may have Parameters; each has **`name`** (user text) + **`type`** (Node from Typ-Ast). Not a tree Node. Values = ParameterValue. Inheritance of defs along `parent_id` (Q55). BOM columns / Collection.Projektname = Parameters. Proto v33. Plan **0.6.85**. |
| 2026-07-25 | Docs: remove discarded anti-Parameter paths; concept is Parameter-only (**Q64**). Plan **0.6.86**. |

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
