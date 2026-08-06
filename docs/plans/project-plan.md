---
name: WP Taxonomy Tree — Project Plan
overview: Build a reusable WordPress plugin that provides a hierarchical taxonomy tree environment (admin UI, APIs, and extension points) usable by other plugins such as wp-electronic-parts.
status: scaffolding
version: "0.7.28-plan"
last_updated: "2026-08-06"
related_docs:
  - README.md
  - docs/PRODUCT.md
  - docs/ARCHITECTURE.md
  - docs/ROADMAP.md
  - docs/OPEN-QUESTIONS.md
  - .cursor/rules/versioning.mdc
  - .cursor/rules/planning-only.mdc
  - .cursor/rules/clean-model-guidelines.mdc
  - .cursor/rules/block-naming.mdc
  - .cursor/rules/node-renderers.mdc
  - .cursor/rules/composition-first.mdc
  - .cursor/rules/parked-complex-types.mdc
  - .cursor/rules/child-of-inheritance-only.mdc
related_plans:
  - docs/plans/planning-phase.md
  - docs/plans/mvp-requirements.md
  - docs/plans/data-structure.md
  - docs/plans/use-cases.md
  - docs/plans/example-projects.md
  - docs/plans/part-identity-layers.md
  - docs/plans/case-study.md
todos:
  - id: planning-phase
    content: "Complete planning-phase checklist (scope, questions, MVP requirements, sign-off) — no implementation"
    status: in_progress
  - id: define-data-structure
    content: "Define Project, Node, Relation (child_of hierarchy Q54), RelationTypes-Ast, Changelog; Q66 inherit along child_of"
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
  - id: docs-absorb-fallstudie
    content: "Absorb Fallstudie (wtt_fs) learnings into living docs — overwrite Parameter/parent_id/slot_scope-primary assumptions; status stays scaffolding"
    status: completed
  - id: scaffold-plugin
    content: "Scaffold modern PHP 8.x plugin bootstrap (wp-taxonomy-tree.php + includes); version tracked in plugin header"
    status: completed
  - id: core-tree-model
    content: "Taxonomy-agnostic tree model over WP_Term (nest/walk/move/delete/copy); Domain Node DTO still planning"
    status: completed
  - id: admin-tree-ui
    content: "Admin tree UI (expand/collapse, select, create, copy, move, delete, detail pane, preview)"
    status: completed
  - id: rest-or-ajax-api
    content: "Secure Admin-AJAX endpoints (capability + nonce) for the tree UI"
    status: completed
  - id: scaffold-types-units
    content: "Interim type/set/fixed/allowlist meta + Basiseinheit unit=set; demo BOM Testprojekt seed"
    status: completed
  - id: scaffold-settings-preview
    content: "Plugin settings (test mode, tree labels, set child props, save-via-button) + unified Form/Table preview"
    status: completed
  - id: scaffold-set-preview-ux
    content: "Set = one field; separator/join-units/label-children; short_description; dropdown unify"
    status: completed
  - id: scaffold-relations-q74
    content: "Q74 scaffold: Relationstypen seed; _wtt_relations CRUD; Add/Remove UI (not child_of); merge synthetic von/an"
    status: completed
  - id: scaffold-type-inherit-q76-q77
    content: "Q76 catalog inherit+override interim; Q77 is_datatype + local is_abstract; Q88 hierarchy datatype=parent (root Knoten)"
    status: completed
  - id: scaffold-set-composition-q75
    content: "Q75: set members from composition Relation targets (migrate off hierarchy children)"
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

## Current mode: scaffolding (+ planning)

**Status: `scaffolding`.** Full Project / Node domain is still planned (**Parameter class discarded** — Eigenschaften = typed child Nodes); the user asked for a **runnable admin preview** over WordPress terms. That early scaffold is allowed (see [`.cursor/rules/planning-only.mdc`](../../.cursor/rules/planning-only.mdc)).

Still in parallel:

- refining this plan and related plan slices
- living documentation / open questions / MVP requirements
- exploring UX in the scaffold (may reverse preview experiments — see `.cursor/rules/preview-checkpoints.mdc`)

**Not yet:** treating the scaffold or Fallstudie as planning sign-off; real Relation edge table (still term-meta); Composition instance rows beyond block attrs; host extension API; returning to “main” BOM domain implementation without an explicit ask.

## Fallstudie → planning absorb (2026-08-05)

Parallel taxonomy **`wtt_fs`** proved the working model below. **Status stays `scaffolding`** — absorb into docs only; do **not** treat this as Phase-1 / domain sign-off.

| Proven in Fallstudie | Planning consequence |
|----------------------|----------------------|
| **BOM** = `composition` → Name + Tabelle | Overwrites older “Collection Parameter Projektname” / flat children-as-columns |
| **`table`** = Zeile (+ optional Kopf/Fuss); band id = **`_wtt_prop_bindings`** | Primary band SoT; legacy `slot_scope` demoted to filter where still used |
| Table validator + **Bindings → Rules → Fixes** (Q80) | Living architecture must describe rules/fixes, not “errors only” |
| Fuss **`_wtt_footer_op`** + Aggregate catalog (Q57) | Op on Fuss **slot**; column type stays Zeile value type |
| **`set` members = `composition`** (Q75) | Overwrites “scaffold still uses children until Q75” |
| Hierarchy = **`child_of`** (Q54); no Parameter class (Q64) | Overwrite open/lean rows that still say `parent_id` / Parameter |
| Type chooser = **`is_datatype`** + local **`is_abstract`** (Q77); datatype may have `type_id` | Overwrite “datatype ⇒ no type_id” |
| Still open / lean | **Q53** kind binding; **Q82** Fuss label via `fixed`; **Q81** unique band bindings (UAT) |
| **Q83** Bauteilarten vs Bauteile | **Definition** = category/schema; **Implementation** = MPN records (`type` → kind) |
| **Q85 composition-first** | Platine→BOM→Zeilen-Teile via **`composition`**; table UI = view — escape relations/table-DB prison |
| **Q88 hierarchy datatype** | Only **root** typed **Knoten**; every hierarchy child’s datatype = **parent** (no free type pick on hierarchy nodes). Attribute members keep own field types (Q87) |

Details: [`docs/plans/case-study.md`](case-study.md), [`docs/OPEN-QUESTIONS.md`](../OPEN-QUESTIONS.md).

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
- Broader domain implementation beyond the allowed early scaffold while status is `scaffolding` / planning incomplete.
- Reviving a separate **Parameter** class (discarded 2026-08-02; slots = typed children).
- Treating scaffold UX experiments as frozen product decisions.

## Relationship to `wp-electronic-parts`

`wp-electronic-parts` already contains a catalog split-view and category tree tightly coupled to `part_category` / `electronic_part`.

**Direction:** extract and generalize the taxonomy-tree concerns into this plugin, then optionally have electronic parts consume it. Until integration exists, this repo evolves independently with a clean public API. Integration coding is out of scope until after planning and after an extension contract is drafted.

## Delivery phases

### Phase 0 — Foundation & planning (active)

- Repository rules (English code/docs, WordPress standards, DB practices, versioning, planning + early-scaffold gate).
- Project plan + living documentation + sync rule.
- Planning checklist, MVP requirements, open questions, and data structure (**Project**, **Node**; tree = root node; Eigenschaften = typed children).
- Local WordPress development environment (Windows Laragon + Cloud VM notes).

### Phase 0b — Early scaffold (in progress, plugin ≈ `0.0.270`)

Runnable preview — **not** full domain sign-off. Thin UI over hierarchical WP terms + term meta. Parallel Fallstudie **`wtt_fs`** (slim UI) explores Definition / Implementation without replacing `wtt_tree`.

| Area | Scaffold status |
|------|-----------------|
| Bootstrap | PHP 8.x OOP plugin (`WTT_VERSION`); text domain `wp-taxonomy-tree`; taxonomies **`wtt_tree`** + **`wtt_fs`** |
| Tree model | Nest / walk / create / rename (**slug sync** from name) / description / short_description / copy sibling / move ↑↓ / delete (promote \| cascade) |
| Transport | **Admin-AJAX** + nonce + taxonomy caps (Q1 leaning for admin MVP) |
| Admin UI | Split tree + detail; expand/collapse + selection persistence; toolbar Add child / Copy / Save / Undo / Delete; detail **Meta** / **Flags** form-row trial (`flagsAsFormRow`); case-study slim mode |
| Types (interim) | **Q88:** hierarchy datatype = parent (root = **Knoten**; type UI read-only for typed-as-parent). Attribute / catalog field types still chooser (`is_datatype`); **Q76** inherit+override = scaffold interim for catalog types; **Q77** local `is_abstract`; `set` / `table` / simples; required; fixed |
| Q51 / Q75 | Basiseinheit unit = **set**; members = outgoing **`composition`** (Typ + optional Praefix + fixed Kuerzel); allowlist; display compose (mm / kΩ) |
| Q74 Relations | `class-relation.php`; `_wtt_relations` JSON (edge ids + **multiplicity Q78**); Relationstypen seed; AJAX CRUD; UI von/an |
| Table / BOM | **BOM** = Name + Tabelle via composition; bands Zeile/Kopf?/Fuss? via **`_wtt_prop_bindings`**; validator; Fuss **`_wtt_footer_op`** + Aggregate catalog (**Q57**); Bindings→Rules→Fixes (**Q80**) |
| Set options | Term meta: `setSeparator`, `setJoinUnits`, `setLabelChildren`; Form/Table treat multi-member set as **one field** |
| short_description | `_wtt_short_description`; labels, help, tooltips, dropdowns |
| Demo seed | BOM Testprojekt (`wtt_tree`) + Fallstudie seed (`wtt_fs` / `Case_Data`); sync/reset scripts |
| Settings | Test mode; show type in tree; show set child properties; save-via-button |
| Preview | Attribute hosts: **Form(1)+Table(n)** × edit/readonly via `WTTObjectRender`; samples name→type map; units/media legacy paths remain |
| Fill Model Data | Working page before Settings; instances in option `wtt_model_instances` |
| Catalog bindings (Q92) | `chooser_root` + `chooser_focus` (term ids); legacy `data_types`/`simple`/`complex` |
| Block | **`taxo/object-view`** (current); **`taxo/collection-table`** = **Q90 legacy** |
| Dropdowns / pickers | Shared selects; tree picker + search; multiplicity: required `1`/`1..*` → swap only (no clear) |
| Not in scaffold | Real Relation edge table (still term-meta); Q66 inherit UI; unique band bindings (**Q81** UAT); Q82 fixed-Fuss labels; Composition instance services beyond block attrs; REST; host hooks; `child_of` as sole hierarchy persistence (term_parent still used); removal of parked enum/list/table scaffold |

Details: living [`docs/ARCHITECTURE.md`](../ARCHITECTURE.md) “Implemented scaffold”.

### Phase 1 — MVP plugin (after planning sign-off)

- Formalize domain DTOs / services beyond term-meta interim.
- Property slots as typed children + inheritance rules (**Q66**); instance values (Q16/Q63).
- Harden delete / create / rename against accepted MVP requirements.
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

- Planning produces agreed MVP requirements and closed/deferred open questions before full domain coding beyond the scaffold.
- Early scaffold: a site admin can manage a hierarchical taxonomy as a tree and explore interim types/units/preview.
- After full implementation is allowed: primary tree workflow without relying on the default tags list.
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
| 2026-07-25 | Q51 refine: **empty allowlist = L1 (no prefixes)**; scaffold interim `_wtt_allowed_prefix_ids` on Basiseinheit units; Praefix UI filtered by fixed sibling Einheit; Kondensator local disable removed in favour of Farad allowlist. |
| 2026-07-25 | Basiseinheit units as **set**: Wert + optional Praefix + fixed **Kuerzel** string; display Praefix+Kuerzel (mm); add Celsius + Stück. |
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
| 2026-07-25 | Simple type **`display_node_name`**: read-only host `Node.name` (no input / no fixed value). Scaffold + plan **0.6.88**. |
| 2026-07-25 | Plan mode **`scaffolding`**: early admin preview allowed; domain planning continues in parallel. Plan **0.6.91**. |
| 2026-07-25 | Scaffold inventory synced: Admin-AJAX tree UI; type/set/fixed/footer; Q51 unit=set + allowlist meta; demo seed; settings; Form/Table preview; unit Definition vs usage (P1). Plugin ≈ **`0.0.40`**. |
| 2026-07-25 | Unit set member **Wert → Typ**; Praefix `_wtt_multiplikator`; Kilogramm SI base kg with prefix root **g** (`prefix_root_to_si=1e-3`). to_si = Typ × multiplikator × prefix_root_to_si. |
| 2026-07-25 | Preview UX closure: set = one field; separator / join-units / label-children; **short_description**; dropdown unify; Kuerzel≠Praefix `m` clarified. Plugin ≈ **`0.0.74`**. Plan **0.6.93**. |
| 2026-07-30 | **Q65 decided:** one simple type **`media`** (no separate `url` / `file` / `image`). Value = MediaRef (attachment \| url). Default WP Media Library; optional type config enables URL-only / external URL. MIME-based render (WP). Plan **0.6.94**. |
| 2026-07-30 | Clarify **Type Node vs Parameter:** `int`/`media` are Type Nodes under Typ-Ast; Parameter is the slot (`name` + `type` → that Node), not a Node. Scaffold child terms still stand in for Parameters. Plan **0.6.95**. |
| 2026-08-02 | Basiseinheit=set plan closed: all catalog units (Meter…Stück + Celsius) as Typ+Praefix?+Kuerzel; display compose; docs table; sync + assert script green. |
| 2026-08-02 | **Q64 superseded:** **Parameter class discarded**. Eigenschaften = **typed child Nodes**. **Q66:** inherit property-slot definitions along `parent_id` (override rules open). Q14/Q33/Q55/Q20 revised. Plan **0.6.96**. |
| 2026-08-02 | **Q65:** `MediaTypeConfig.allowed_kinds` — default **none**; user must enable MIME kinds (e.g. image only). Scaffold **0.0.83**. Plan **0.6.97**. |
| 2026-08-02 | **Q65:** third ingest **`allow_url_mirror`** — paste URL, sideload into WP Media, keep origin URL; MediaRef may hold **both** `url` + `attachment_id`. Reader: original link **or** local download. Not a new MIME kind. Re-fetch policy → **Q67**. Plan **0.6.98**. |
| 2026-08-02 | **Q68 opened (deferred):** host-plugin MediaRef/URL display (e.g. Ampel) vs WTT custom renderer / WP hooks. Decide later. Plan **0.6.99**. |
| 2026-08-02 | **Q62 scaffold slice 2:** Gutenberg block `wtt/collection-table` — pick table Collection, columns from taxonomy, row add/remove, instance in block attrs. **Q69** schema-drift soft-delete deferred. Plan **0.7.0**. |
| 2026-08-02 | **Q70 decided:** property slots have **`slot_scope`**: `composition` \| `row`. Collection slot **Name** (string, composition-scoped) replaces vocabulary **Projektname**; inherited (Q66). Table columns = row-scoped only; Rezept may add local composition slots (e.g. Portionen). Q61–Q63 refined. Plan **0.7.1**. |
| 2026-08-02 | **Q54/Q35 decided:** hierarchy = protected Relation **`child_of`** (reparent only; no Unassigned bucket; no dual `parent_id` SoT). RelationTypes = Nodes under **`relation_type_node`** (seed `child_of`, `composition`); Node UI Relations von/an. **Q66** inherit along `child_of`. **Q70/Q61:** **Name** on **Compositionen** (not Collection type). Plan **0.7.2**. |
| 2026-08-02 | **Q71/Q72:** Type settings = slot presets (copy-on-assign). **`subtree` → `node_embed`** (pick + embed fields); **`node_ref`** = scoped id-only. Unified admin tree picker. Scaffold **0.0.93**. Plan **0.7.3**. |
| 2026-08-03 | **Q73:** Parent type **`node_pick`** (Complex) with children **`node_embed`** / **`node_ref`**. Shared **`ref_scope`** + **`allowed_ref_ids`** (direct children; empty = all). Scaffold **0.0.100**. Plan **0.7.4**. |
| 2026-08-03 | **Block naming:** Gutenberg namespace **`taxo/`**; titles start with **Taxo** (e.g. `taxo/collection-table` → **Taxo Collection table**). Renamed from `wtt/collection-table`. Rule: `.cursor/rules/block-naming.mdc`. Scaffold **0.0.102**. Plan **0.7.5**. |
| 2026-08-03 | **Q74–Q77:** Reusable **Relation picker** (type → node; inline default); **`set` members = `composition` Relations** (refine Q51); **type inherit + override**; **type chooser** + **`is_datatype`** (Typen-Ast; no type_id on datatype nodes). Plan **0.7.6**. |
| 2026-08-03 | **Scaffold catch-up ≈ `0.0.123`:** **Q74** Relation CRUD (term-meta edges + Relationstypen seed + UI); **Q76/Q77** inherit+override + `is_datatype` / local `is_abstract`; slug sync on rename; detail Meta/Flags form-row trial; set-preview primary+static inline. **Q75** still pending. Plan **0.7.7**. |
| 2026-08-04 | **Q77 revise:** datatype nodes **may have a `type_id`** (unlocked in UI/PHP). Self-assignment forbidden. Scaffold **0.0.128**. Plan **0.7.8**. |
| 2026-08-04 | **Node renderers:** data/view split; dispatcher picks context renderer (tree / list / form / table / …); recursive children; **preview = render current node** in that context (no separate preview path). Rule `.cursor/rules/node-renderers.mdc`. Plan **0.7.10**. |
| 2026-08-04 | **Q74/Q75 scaffold ≈ `0.0.140`:** generic Relations list on every node — add / remove / duplicate / reorder (edge ids); **set members** from outgoing **`composition`** Relations; migrate children → composition when empty. Plan **0.7.11**. |
| 2026-08-04 | **Q78 decided:** Relation **multiplicity** on each edge — `0..1` \| `1` \| `0..*` \| `1..*` (definition; default `0..*`). Scaffold ≈ **`0.0.153`**. Plan **0.7.12**. |
| 2026-08-04 | **BOM composition model:** **BOM** = Name + Tabelle (`composition`); datatype **`table`** = Zeile (+ optional Kopf/Fuss, same field count); table validator gates preview + save; Fallstudie seed under Implementation. Plan **0.7.13**. Scaffold ≈ **`0.0.171`**. |
| 2026-08-05 | **Q79 decided:** Node identity = **ID**; instance names may repeat (Bom/Rezept → Zeile); **datatype** names unique in taxonomy. Scaffold ≈ **`0.0.175`**. Plan **0.7.14**. |
| 2026-08-05 | **Q57 footer ops catalog:** `none`/`text`/`sum`/`avg`/`min`/`max`/`count` (`avg` = Durchschnitt/Mittelwert). Scaffold `Footer_Ops` + JS ≈ **`0.0.177`**. Plan **0.7.15**. |
| 2026-08-05 | **Q57 Fuss-slot op:** `_wtt_footer_op` on Fuss fields; type stays column value type; catalog `Definition/Aggregate`; picker + preview ≈ **`0.0.192`**. Plan **0.7.16**. |
| 2026-08-05 | **Q82 opened (lean):** Fuss labels via `footer_op=text` + **`fixed`**; aggregates always read-only; no new `label` type / `editable` flag. Plan **0.7.16**. |
| 2026-08-05 | **Docs absorb Fallstudie:** overwrite Parameter / `parent_id`-as-lean / “until Q75” / slot_scope-as-primary assumptions in living docs; Q14–Q16/Q20/Q25–Q26/Q33/Q54–Q56/Q63 hygiene; Phase 0b inventory ≈ **`0.0.199`**. Status stays **`scaffolding`** — not Phase-1 sign-off. Plan **0.7.17**. |
| 2026-08-05 | **Q83 decided:** Bauteile split — **Bauteilarten** (schema/kinds) under Definition; **Bauteile** (MPN records) under Implementation; `type_id` → kind; `node_embed` → records. Scaffold ≈ **`0.0.207`**. Plan **0.7.18**. |
| 2026-08-05 | **Q85 decided:** **Composition-first** — leave relations/table-DB prison. Platine `composition`→ properties incl. BOM; BOM `composition`→ Bauteil-Zuordnung, Position, Menge, …. Table UI = view only. Rule `.cursor/rules/composition-first.mdc`. Plan **0.7.19**. |
| 2026-08-05 | Seed RelationType **`erbt_von`** (additive). **Q86** open: inherit engine along `erbt_von` vs `child_of` (Q66). Plan **0.7.20**. |
| 2026-08-05 | **Q85 refine:** Composition ≈ class; members ≈ attributes (**Name + Typ**). RelationType **`besteht_aus`** (alias `composition`). Plan **0.7.21**. |
| 2026-08-06 | **Q87 trial:** Attribute = Name + Typ + Mult. via `besteht_aus` edge; Admin Attribute panel. Plan **0.7.22**. |
| 2026-08-06 | **Q86 decided:** Inheritance = **`child_of` only**; RelationType **`erbt_von` removed**. Plan **0.7.23**. |
| 2026-08-06 | Attributes: inherit along `child_of` + hide on child; Festwert on host; type editable (inherited → local override); Relationstypen abstract; root typed **Knoten**. Scaffold ≈ **`0.0.229`**. Plan **0.7.24**. |
| 2026-08-06 | **Q88 strengthened (general rule):** Hierarchy datatype is mapped **only through `child_of`**. Only the **root** has explicit base type **Knoten**. Every other hierarchy node’s datatype = its **parent** (child always inherits from father). Example: Fallstudie→Knoten; Definition→Fallstudie; Aggregation→Definition; …. Attribute members (`besteht_aus`) keep own field types (Q87) — orthogonal. Free type pick is **not** the primary model for hierarchy nodes. **Q76** catalog inherit+override demoted for hierarchy datatype (scaffold may still expose it). Plan **0.7.25**. |
| 2026-08-06 | **Q90 decided:** Complex catalog kinds **`enum` / `list` / `table` parked** — out of product direction. Enum → hierarchy inheritance + attributes/Festwerte; list/table YAGNI. Q36/Q52/Q53/Q38 superseded or deferred. Scaffold leftovers until removal slice. Rule `.cursor/rules/parked-complex-types.mdc`. Plan **0.7.26**. |
| 2026-08-06 | **Q91 decided:** Node-only domain ≠ one renderer. Presentation = **Registry + many type-specific renderers** (simples now; more later). Q90 does not collapse the pipeline. Rule `.cursor/rules/node-renderers.mdc`. Plan **0.7.27**. |
| 2026-08-06 | **Q92 decided:** Template catalog folders bound by term id in option **`wtt_catalog_bindings`**. Attribute type chooser uses **`chooser_root`** (branch, e.g. Fallstudie) + **`chooser_focus`** (e.g. Data Types). Legacy keys `data_types` / `simple` / `complex` remain helpers. Scaffold ≈ **0.0.264+**. Plan **0.7.28**. |
| 2026-08-06 | Concept sync ≈ **`0.0.270`:** Fill Model Data instances; Sample_Data name→type map; Form(1)/Table(n) attribute-host preview; multiplicity swap-vs-clear; Q90 leftovers marked legacy (no removal slice). Plan **0.7.28**. |

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
5. While status is `planning`, do not add implementation files. While `scaffolding`, only extend the **allowed early scaffold** (tree UI / interim meta) unless the user asks for the next domain slice.
