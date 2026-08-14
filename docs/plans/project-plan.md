---
name: WP Taxonomy Tree — Project Plan
overview: Build a reusable WordPress plugin that provides a hierarchical taxonomy tree environment (admin UI, APIs, and extension points) usable by other plugins such as wp-electronic-parts.
status: scaffolding
version: "0.7.143-plan"
last_updated: "2026-08-14"
related_docs:
  - README.md
  - docs/PRODUCT.md
  - docs/ARCHITECTURE.md
  - docs/ROADMAP.md
  - docs/OPEN-QUESTIONS.md
  - docs/DEVELOPER-ATTRIBUTE-MODEL.md
  - .cursor/rules/versioning.mdc
  - .cursor/rules/planning-only.mdc
  - .cursor/rules/clean-model-guidelines.mdc
  - .cursor/rules/block-naming.mdc
  - .cursor/rules/agent-lanes.mdc
  - .cursor/rules/node-renderers.mdc
  - .cursor/rules/config-renderers.mdc
  - .cursor/rules/settings-ui-parity.mdc
  - .cursor/rules/praefixe-catalog-lock.mdc
  - .cursor/rules/composition-first.mdc
  - .cursor/rules/parked-complex-types.mdc
  - .cursor/rules/child-of-inheritance-only.mdc
related_plans:
  - docs/plans/planning-phase.md
  - docs/plans/mvp-requirements.md
  - docs/plans/data-structure.md
  - docs/plans/use-cases.md
  - docs/plans/example-projects.md
  - docs/plans/user-constellation-recipes.md
  - docs/plans/part-identity-layers.md
  - docs/plans/case-study.md
  - docs/plans/blocks-lane.md
  - docs/plans/relation-vs-object-concept.md
  - docs/plans/q123-doc-pass-questions.md
  - docs/plans/q123-migrate-handoff.md
  - docs/plans/attribute-choice-inheritance.md
  - docs/plans/settings-ui-parity.md
  - docs/DEVELOPER-ATTRIBUTE-MODEL.md
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
  - id: user-constellation-recipes
    content: "Backlog: end-user how-to recipes for SI / calc / CatalogChoice / Money / own attrs (docs/plans/user-constellation-recipes.md)"
    status: pending
  - id: part-identity-layers
    content: "Keep part identity layers note aligned when catalog modeling evolves"
    status: in_progress
  - id: docs-sync
    content: "Keep PRODUCT, ARCHITECTURE, ROADMAP, and OPEN-QUESTIONS aligned with this plan on every plan change"
    status: in_progress
  - id: multi-agent-lanes
    content: "agent-lanes.mdc + blocks-lane.md — parallel agents own zones; plan SoT; no stomp (absorbed architecturally in 0.7.49)"
    status: completed
  - id: presentation-parity
    content: "Q63+Q91: one object paint path admin ↔ block editor ↔ frontend; blocks are views (Q85); slice docs/plans/blocks-lane.md"
    status: completed
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
    content: "Interim type/set/fixed/allowlist meta + Basiseinheit unit=set; Case_Data Fallstudie seed (wtt_fs)"
    status: completed
  - id: scaffold-fallstudie-only
    content: "Single product taxonomy wtt_fs; retire wtt_tree from UI/seeds/pickers (Demo_Data helpers kept)"
    status: completed
  - id: scaffold-settings-preview
    content: "Plugin settings (test mode, tree labels, set child props, save-via-button, tree icon allowlist) + unified Form/Table preview"
    status: completed
  - id: scaffold-tree-icons-q95
    content: "Q95: optional per-node tree icon (_wtt_icon); Settings allowlist; create standard-by-name else parent copy; renderTreeNode before name; Simple seed marker"
    status: completed
  - id: scaffold-preferred-render-validators
    content: "Preferred render/converter + Validators 0..n as per-node meta; Registry mirrors; ensure_* create-time seed; Q96 leaf-name interim bind"
    status: completed
  - id: scaffold-set-preview-ux
    content: "Set = one field; separator/join-units/label-children; short_description; dropdown unify"
    status: completed
  - id: scaffold-relations-q74
    content: "Q74 scaffold: Relationstypen seed; _wtt_relations CRUD; Add/Remove UI (not child_of); merge synthetic von/an"
    status: completed
  - id: scaffold-type-inherit-q76-q77
    content: "Q76 catalog inherit interim; Q77 chooser=nodes + Q92 only; Q88 hierarchy datatype=father (root Knoten; _wtt_type_id only)"
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

## Catch-up desk — 2026-08-09 (~14:00)

Short board after a long chat. **Locked design** vs **open UX / next detail**. Revisit bullets with the user only where marked *discuss*.

### Locked (do not re-litigate unless asked)

| ID | One-liner |
|----|-----------|
| **Q119** | Money: store precise **major unit** (EUR); entry scale per attribute (Euro vs Cent); display via Preferred converters (adaptive digits default). Not hard-coded on Preis. |
| **Q110** | FX Euro↔Dollar: **parked** (not R1). |
| **Q120** | Quantity = **Value + Prefix? + Unit**. Catalog folders = browse knots. Difference = **rules** on the unit (prefix allowlist, dimension, conversion engine). |
| **Q121** | Money: canonical **EUR** + foreign-entry **snapshot** `{amount, currency, rate, date}`. Physical canonical later. |
| **Q122** | Type properties + inheritance override **everywhere**. Composed types expose **component attribute** settings (same surfaces as on those type nodes) — **dynamic** from the model graph, never hard-coded. |
| **Q123** | Attributes = **Relation only**; **`Settings.data` + `Settings.view`**; recursive walk; hybrid overrides. Diagrams: [`DEVELOPER-ATTRIBUTE-MODEL.md`](../DEVELOPER-ATTRIBUTE-MODEL.md). OQ-W1…W16 closed. |
| **Tree ≈ 0.0.540** | **Konstanten**: **Präfixe** (ChildList; Presentation+multiplikator; pico…Tera Compact; Centi Choices-exclude) + Basiseinheiten + Bauformen + Währung. **Data Types**: Simple / Complex / Unit type. Parked Complex soft-trashed. |
| **Q74 lazy ≈ 0.0.405** | Relations panel **collapsed by default**; RelationType catalog loaded on expand (`wtt_get_relation_types`), not on every `get_node`. |

### Open design / construction sites (*discuss when needed*)

1. ~~**Unit↔prefix UX for non-power users**~~ — **Lean A done ≈ `0.0.406`:** panel **Allowed prefixes** on unit settings (Fallstudie-visible). Quantity prefix empty option = **—** (not `(none)`); tighter quantity gaps. Folder ID bindings (rename-safe) still optional later.
2. ~~**Attributes panel** — earlier lean: **collapsed + lazy** by default (same family as Relations).~~ **Done ≈ `0.0.429`:** collapsed by default (`attributesPanelOpen`); rows already on `get_node` (no second fetch — unlike Relations catalog lazy-load).
3. **Money converters / entry-scale** — Q119 lean locked; scaffold wiring (Preferred + entry scale on attribute) still to build when we pick that slice.
4. **Q121 snapshot persistence** — lean locked; Model_Data shape / paint not implemented.
5. **Physical canonical store** (m↔inch) — same story as money, lower urgency.
6. ~~**Q122 scaffold debt — Default / component settings:**~~ **Done ≈ `0.0.414`:** Default dialog = type paint (`paintFieldContent`); CatalogChoice only for true catalog `fixedMode`.

### Scaffold shipped today (plugin ≈ `0.0.406`)

- Unit catalog reshape + migrate/dedupe/currency-flat cleanup.
- Relations fold + lazy RelationType catalog (≈ `0.0.405`).
- Allowed prefixes wizard on unit detail + quantity chrome polish (≈ `0.0.406`).
- Presentation foldable (Q117/Q118) already in tree Display.

---

## Current mode: scaffolding (+ planning)

**Status: `scaffolding`.** Full Project / Node domain is still planned (**Parameter class discarded** — Eigenschaften = typed child Nodes); the user asked for a **runnable admin preview** over WordPress terms. That early scaffold is allowed (see [`.cursor/rules/planning-only.mdc`](../../.cursor/rules/planning-only.mdc)).

Still in parallel:

- refining this plan and related plan slices
- living documentation / open questions / MVP requirements
- exploring UX in the scaffold (may reverse preview experiments — see `.cursor/rules/preview-checkpoints.mdc`)

**Not yet:** treating the scaffold as planning sign-off; real Relation edge table (still term-meta); Composition instance rows beyond block attrs; host extension API without an explicit ask.

## Gold scaffold shape (2026-08-07)

**Single product taxonomy = Fallstudie (`wtt_fs`).** `Case_Data` is the reference seed. **`wtt_tree` / BOM Testprojekt is retired** from UI, auto-seed, and block pickers (`Taxonomy::TREE` + `Demo_Data` helpers remain for legacy scripts / cleanup only). **Status stays `scaffolding`** — not Phase-1 / domain sign-off.

## Fallstudie → planning absorb (2026-08-05)

Fallstudie **`wtt_fs`** proved the working model below (now the only standard scaffold tree). **Status stays `scaffolding`** — absorb into docs only; do **not** treat this as Phase-1 / domain sign-off.

| Proven in Fallstudie | Planning consequence |
|----------------------|----------------------|
| **BOM** = `composition` → Name + Tabelle | Overwrites older “Collection Parameter Projektname” / flat children-as-columns |
| **`table`** = Zeile (+ optional Kopf/Fuss); band id = **`_wtt_prop_bindings`** | Primary band SoT; legacy `slot_scope` demoted to filter where still used |
| Table validator + **Bindings → Rules → Fixes** (Q80) | Living architecture must describe rules/fixes, not “errors only” |
| Fuss **`_wtt_footer_op`** + Aggregate catalog (Q57) | Op on Fuss **slot**; column type stays Zeile value type |
| **`set` members = `composition`** (Q75) | Overwrites “scaffold still uses children until Q75” |
| Hierarchy = **`child_of`** (Q54); no Parameter class (Q64) | Overwrite open/lean rows that still say `parent_id` / Parameter |
| Type chooser = **nodes** + Q92 scope only (Q77); catalog lock = **`is_template`** | Overwrite “chooser = type-role forest” / folder-gate meta |
| Select nodes by **id** only; named config bindings → ids (Q79/Q92) | Kill name-based selection SoT; special branches in settings |
| Still open / lean | **Q53** kind binding; **Q82** Fuss label via `fixed`; **Q81** unique band bindings (UAT) |
| **Q83** Bauteile catalog | **Model only** (kinds + records); **no Implementation/** SoT (OQ-B2) |
| **Q85 composition-first** | Platine→BOM→Zeilen-Teile via **`composition`**; table UI = view — escape relations/table-DB prison |
| **Q88 hierarchy datatype** | Non-root: datatype = **father** (`_wtt_type_id` = WP parent). Root: **seed-only** `type_id` → **Knoten** (no admin free `set_type`). Attribute members keep own field types (Q87) |
| **Q95 optional tree icons** | Per-node `_wtt_icon`; Settings Dashicon allowlist; create **standard-by-name else parent copy**; no later cascade; Simple seed **`marker`**; Identity vs Display UI; `renderTreeNode` before name |
| **Preferred render / converter / validators** | **Product (Q123):** Preferred ∈ `Settings.view`, validators ∈ `Settings.data`; hybrid live + Relation override deltas; same Render walk as Settings. **Scaffold debt:** `_wtt_preferred_*` / `_wtt_validators` create-time seed. Registry (JS+PHP); Q96 id bind |
| **Presentation parity (Q63 + Q91)** | Same object chrome (**Registry** + `WTTObjectRender` / `Object_Render`) for **admin preview ↔ block editor ↔ frontend**. Blocks are **views** over definition + instances (Q85) — not a second UI stack |
| **Parallel work lanes** | Process only: agents own zones (blocks / tree / shared-render / model / planning); **append** plan decisions; do not stomp — [`.cursor/rules/agent-lanes.mdc`](../../.cursor/rules/agent-lanes.mdc) |

Details: [`docs/plans/case-study.md`](case-study.md), [`docs/OPEN-QUESTIONS.md`](../OPEN-QUESTIONS.md).

## Presentation surfaces (architecture)

This is **product architecture**, not a lane tip. It unifies already-decided threads:

| Layer | Job | SoT questions |
|-------|-----|----------------|
| **Definition tree** (admin) | Structure, types, attributes, relations | Q54 / Q87 / Q88 |
| **Instances** | Filled values (Fill Model Data / page) | Q16 / Q63 |
| **Presentation** | How schema + values look and edit | **Q91** Registry + many type renderers; Preferred render/converter/validators |

**Surfaces that must share one paint path** (parity):

1. Admin attribute-host / object preview  
2. Gutenberg **`taxo/object-view`** (editor + dynamic frontend)  
3. Multi-instance **Taxo Table view** (`taxo/collection-table`) where it reuses object/table chrome  

**Complements, does not replace:**

- **Q63** — tree = definition; page/block = instance values (blocks bind structure + optional instance).  
- **Q85** — composition-first; blocks are **views**, not the domain SoT.  
- **Q62** — Gutenberg exposes instances; Object View = one host; Table view = all instances for a host (catalog `table` kind remains **Q90** parked).  
- **Q90** — do not grow Collection `enum`/`list`/`table` product features via blocks.

Implementation backlog for the Gutenberg zone: [`docs/plans/blocks-lane.md`](blocks-lane.md). Shared renderer rules: [`.cursor/rules/node-renderers.mdc`](../../.cursor/rules/node-renderers.mdc), [`.cursor/rules/reuse-renderers.mdc`](../../.cursor/rules/reuse-renderers.mdc).

### Settings cascade → paint (locked ≈ 0.0.558 / plan 0.7.140)

**Defaults cascade detail → model** (illustration of inheritance depth, not a hard-coded path):

`Präfix → Basiseinheit (BU) → Unit type → Model`

| Rule | Meaning |
|------|---------|
| **Presets drive paint** | Preferred, Q117 Presentation, Hide, Mult, Bindung, and walk/Relation overrides must reach Model / admin Preview / nested cells. Good presets ⇒ good final view — **only if paint follows settings**. |
| **Host surface** | Admin Preview chrome = **host Preferred only** (Editable + Display for that one surface). |
| **Nested attribute cell** | Uses the **attribute type’s Preferred** (walk / Relation) + that type’s Presentation / Hide / Mult — e.g. Compact when Compact is set; **never invent Form** for the nested layer. |
| **Q117 host for nested structure** | `node_presentation` inside a structure embed resolves against the **type node** (`typePresentation` / type name), not the outer host. |
| **No hardcoding** | Never by display name, path, or special-case nodes. Hardcode **only inside a renderer** for that renderer’s own chrome (Form/Table/Compact/Multistep layout mechanics), and **only for the current layer**. Nested content still resolves from the nested type’s settings. |

Agent rules: [`.cursor/rules/preview-checkpoints.mdc`](../../.cursor/rules/preview-checkpoints.mdc), [`.cursor/rules/reuse-renderers.mdc`](../../.cursor/rules/reuse-renderers.mdc), [`.cursor/rules/settings-ui-parity.mdc`](../../.cursor/rules/settings-ui-parity.mdc), [`.cursor/rules/node-renderers.mdc`](../../.cursor/rules/node-renderers.mdc).

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

### Phase 0b — Early scaffold (in progress, plugin ≈ `0.0.369`)

Runnable preview — **not** full domain sign-off. Thin UI over hierarchical WP terms + term meta. **Only** Fallstudie **`wtt_fs`** (`Case_Data`); slim Definition / Implementation UI.

| Area | Scaffold status |
|------|-----------------|
| Bootstrap | PHP 8.x OOP plugin (`WTT_VERSION`); text domain `wp-taxonomy-tree`; **standard taxonomy `wtt_fs` only** (`wtt_tree` legacy constant / Demo_Data helpers) |
| Tree model | Nest / walk / create / rename (**slug sync** from name) / description / short_description / copy sibling / move ↑↓ / delete (promote \| cascade) |
| Transport | **Admin-AJAX** + nonce + taxonomy caps (Q1 leaning for admin MVP) |
| Admin UI | Split tree + detail; expand/collapse + selection persistence; toolbar Add child / Copy / Save / Undo / Delete; detail **Meta** / **Flags**; Fallstudie slim mode (no dual taxonomy switcher) |
| Types (interim) | **Q88:** hierarchy datatype = father (`_wtt_type_id` = WP parent); root `type_id` → **Knoten** is **seed-only** (no admin free `set_type`). Attribute / catalog field types via **node chooser** (Q92); **Q76** inherit+override = scaffold interim for catalog types; **Q77** chooser = Q92 only; **`is_template`** = protected catalog lock; `set` / simples; required; fixed |
| Q51 / Q75 | Basiseinheit unit = **set**; members = outgoing **`composition`** (Typ + optional Praefix + fixed Kuerzel); allowlist; display compose (mm / kΩ) |
| Q74 Relations | `class-relation.php`; `_wtt_relations` JSON (edge ids + **multiplicity Q78**); Relationstypen seed; AJAX CRUD; UI von/an |
| Table / BOM | **BOM** under Implementation = Name + Tabelle via composition; bands Zeile/Kopf?/Fuss? via **`_wtt_prop_bindings`**; validator; Fuss **`_wtt_footer_op`** + Aggregate catalog (**Q57**); Bindings→Rules→Fixes (**Q80**) — **Q90** table kind legacy |
| Set options | Term meta: `setSeparator`, `setJoinUnits`, `setLabelChildren`; Form/Table treat multi-member set as **one field** |
| short_description | `_wtt_short_description`; labels, help, tooltips, dropdowns |
| Demo seed | **`Case_Data`** on `wtt_fs` (reference); `Demo_Data` helpers kept for legacy scripts; `scripts/retire-wtt-tree.php` clears live `wtt_tree` |
| Settings | Test mode; show type in tree; show set child properties; save-via-button; warn before structural model changes (UR-S1) |
| Preview | Attribute hosts: **Form(1)+Table(n)** × edit/readonly via `WTTObjectRender`; samples name→type map; units/media legacy paths remain |
| Presentation parity | **Same** `WTTObjectRender` / Registry path for admin preview, **`taxo/object-view`** editor, and frontend SSR (`Object_Render`) — gaps = bugs, not block-only features |
| Fill Model Data | Working page before Settings; instances in option `wtt_model_instances` |
| Catalog bindings (Q92) | `chooser_root` + `chooser_focus` (term ids); legacy `data_types`/`simple`/`complex` |
| Blocks (views) | **`taxo/object-view`** = primary single-host / optional instance; **`taxo/collection-table`** = all instances for host (**Taxo Table view**; catalog `table` kind Q90-parked); pickers `scaffold_slugs()` → FS only |
| Collaboration | Multi-agent **lanes** (process): blocks / tree-admin / shared-render / model / planning — append plan, no stomp |
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
| 2026-08-03 | **Scaffold catch-up ≈ `0.0.123`:** **Q74** Relation CRUD (term-meta edges + Relationstypen seed + UI); **Q76/Q77** inherit+override + `is_datatype` (later removed); slug sync on rename; detail Meta/Flags form-row trial; set-preview primary+static inline. **Q75** still pending. Plan **0.7.7**. |
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
| 2026-08-06 | **CatalogChoice UI (Q90 note):** Attribute type with specialization children — max choice-subtree depth ≤ 1 → flat `<select>`; depth ≥ 2 → tree chooser; Festwert seeds selection. Typed choice under type host only (e.g. Währung), not every product picker. Docs + admin-ux rule; no new Q id. Plan **0.7.29**. |
| 2026-08-06 | **Q93 opened:** CatalogChoice **value SoT** when type host and/or selected child has attributes — node id only vs id + instance values (host / child / both). UI chrome stays Q90; no Choice object. Plan **0.7.30**. |
| 2026-08-07 | **Q94 opened:** Data safety — lean full site/DB backup for DR; WXR insufficient for unbound taxonomies + options + ID graphs; plugin JSON export/import later (copy tree); no admin Export now. Plan **0.7.31**. |
| 2026-08-07 | **Gold scaffold = Fallstudie only:** product taxonomy **`wtt_fs`**; **`wtt_tree` / BOM Testprojekt retired** from UI, seeds, pickers; `Case_Data` = reference seed; `Demo_Data` helpers kept; live `wtt_tree` terms deleted via `retire-wtt-tree.php`. Scaffold ≈ **`0.0.297`**. Plan **0.7.32**. |
| 2026-08-07 | **Q83 revised:** Kinds under **Model/Bauteil**; MPNs under **Implementation/Bauteile**; strip Hersteller/Lieferant/Bestellnummer on kinds. Scaffold ≈ **`0.0.304`**. Plan **0.7.33**. |
| 2026-08-07 | **`_wtt_is_datatype` slim-down (user):** (1) type chooser = **nodes** / Q92 — not gated by flag; (2) free **`set_type`** only root (may drop); (3) **`has_type` except root = father** (Q88); (4) **never select by name** — always id (config named bindings → ids OK). Q26/Q77/Q79/Q83/Q88/Q92 revised; remaining flag jobs = catalog lock, leaf detection, conceptual role. Scaffold code still reads flag = **debt**. Plan **0.7.34**. |
| 2026-08-07 | **#5 catalog lock → `is_template`:** term meta `_wtt_is_template`; DTO `isTemplate`; editable only in **Development mode** (`wtt_development_mode`); Meta readout always; `lock_seeded_catalog_deletable` keys on `is_template` (+ migrate from `is_datatype`). Scaffold ≈ **`0.0.315`**. Plan **0.7.35**. |
| 2026-08-07 | **Q88 root `set_type` dropped from admin:** free type assign locked for hierarchy + root (`freeTypeLocked`); root `type_id` → **Knoten** remains **seed-only** (`set_type_id(…, allow_seed)`); no `is_datatype` promote on parent-as-type; Relations `has_type` protected/read-only for hierarchy/root. Scaffold ≈ **`0.0.330`**. Plan **0.7.36**. |
| 2026-08-07 | **`_wtt_is_datatype` job #6 decided:** node binding / special branch-leaf addressing **never by name** — always **`term_id`**; needed branches/nodes stored in **settings** (`wtt_catalog_bindings` / Q92). **Q34/Q48** plain-language clarifiers. Docs-only; no flag removal. Plan **0.7.37**. |
| 2026-08-07 | **Q34 clarified** (behavior/config ≠ type identity). **Q48 lean:** types-as-Nodes OK; hardcoding must be **visible** — recommend **C** (catalog `builtin.*` binding + node `implementationKey` Meta chip; Registry off key, not name). Docs-only. Plan **0.7.38**. |
| 2026-08-07 | **Q48 challenged against real datatypes:** inventory Simple/Complex/Registry/media/set/quantity/table + Model hosts; classify **render-only** vs **settings-bearing** vs **attribute/composed**. Flat A/B/C too coarse — refined: `implementationKey` (+ optional `builtin.*`) for render; NodeConfig metas for settings; composition/Q87 for attrs; Q92 for anchors. Docs-only. Plan **0.7.39**. |
| 2026-08-07 | **`int` value slice:** one Registry renderer (edit+display) + Converter (`WTTIntValue` / `Int_Value`) + validators 1..n (`integer_shape`); prevent non-integer input; canonical decimal string; format default **arabic** (roman/binary/octal/hex reserved). Scaffold ≈ **`0.0.339`**. Plan **0.7.40**. |
| 2026-08-07 | **`int` Number format UI:** type default `_wtt_int_display_format` + **Int settings** panel; attribute override in Options (`displayFormat` type extras). Preferred render remains Form/Table only. Scaffold ≈ **`0.0.345`**. Plan **0.7.41**. |
| 2026-08-08 | **Typing vocabulary closed:** product language drops RelationType **`has_type`** and flag **`_wtt_is_datatype` / `is_datatype`**. Typing SoT = **`_wtt_type_id` only**; hierarchy effective type = father (Q88 / WP parent). Chooser pool = **Q92** scope (no type-role flag). Catalog lock remains **`is_template`**. Docs-only. Plan **0.7.42**. |
| 2026-08-08 | **Preferred render + typing teardown (scaffold ≈ 0.0.352):** Remove RelationType `has_type` + `_wtt_is_datatype` from code/seeds/UI. Preferred render on nodes; attribute slots may **copy** type preferred once (`canRender`-filtered) — later refined to create-time seed / no live cascade (**0.7.48**). Plan **0.7.43**. Next: BOM top-down after tree Go. |
| 2026-08-08 | **`is_abstract` removed from product language:** no `_wtt_is_abstract` / folder-gate on the type chooser. Chooser = **Q92 only** (`chooser_root` / `chooser_focus` / catalog bindings). Docs rewrite (Q77, Node diagram, living docs). Scaffold ≈ **`0.0.353`**. Plan **0.7.44**. |
| 2026-08-08 | **Q95 optional tree icons:** Every node may have optional `_wtt_icon` (Dashicon key). Settings = allowlist (`wtt_tree_icon_keys`). Node Properties picker. **Copy-on-create** from parent when set; later parent edits do **not** cascade; **no** live father-walk at render. Tree paint via `Registry.renderTreeNode` (icon before name). Standard seed: Simple → `marker`; scalars via name map. Scaffold ≈ **`0.0.358`**. Plan **0.7.45**. |
| 2026-08-08 | **Q95 icon catalog cleanup:** Dropped CSS example keys `circle` / `dot` (Marker already covers the circle glyph). Simple standard/seed → **`marker`**. Empty chooser chrome bug fixed by removal. Scaffold ≈ **`0.0.366`**. Plan **0.7.46**. |
| 2026-08-08 | **Validators 0..n on nodes:** `_wtt_validators` + `WTTValidator.Registry` (defaults per basic type; Expression; error text; optional fix labels). Int edit uses Registry.validateAll. Parallel to Preferred render/converter. Scaffold ≈ **`0.0.359`**. |
| 2026-08-08 | **Q96 opened (Registry↔node bind):** After removing type-role flags, Q88 father ≠ field key. Scaffold: simples match by **leaf name** ↔ Registry id; complex hosts still open. Converter `typeKeyOf` fixed for int under Simple. Scaffold ≈ **`0.0.362`**. |
| 2026-08-08 | **Multi-agent lanes:** Parallel Cursor agents own zones (blocks / tree-admin / shared-render / model / planning). Plan remains SoT for design; lanes **append** decisions, do not stomp each other’s files. Blocks focus slice: [`docs/plans/blocks-lane.md`](blocks-lane.md). Rule: [`.cursor/rules/agent-lanes.mdc`](../../.cursor/rules/agent-lanes.mdc). Docs-only. Plan **0.7.47**. |
| 2026-08-08 | **Scaffold absorb — presentation meta (≈ 0.0.369):** Preferred render / converter / validators = **per-node meta** (create-time `ensure_*` when empty; later parent/type edits do **not** cascade — same pattern as Q95 icons / Q71 presets). Registry for Render + Converter + Validator (JS + PHP). Simple defaults: int→`integer_shape`, double→`number_shape`, email→`email_shape`, char→`char_shape`, date→`date_shape` (flexible parse), media→`media_shape`; text/bool none. **Bool** default switch render. Shape validators = Binding→Rule→Fix mindset; message-only unless optional fixes added (never auto-run). **Q95** create priority = standard-by-name first, else parent copy; Identity vs Display admin UI. **Q96** remains open (simples: leaf name ↔ Registry id interim). Docs merge; preserves lanes / Q90–Q94 / composition-first. Plan **0.7.48**. |
| 2026-08-08 | **Presentation surfaces absorbed (architecture):** Elevate **admin ↔ block editor ↔ frontend parity** and Gutenberg blocks as **views** into the primary plan (complements **Q63** / **Q91** / **Q85** / **Q62**; does not reopen Q90). Multi-agent lanes stay **process** (own zones, append decisions). Blocks slice remains [`blocks-lane.md`](blocks-lane.md). Docs-only. Plan **0.7.49**. |
| 2026-08-08 | **Q97 decided (BOM storage & cascade):** Instance data under the corresponding **Model_Data** bag; parent reads children via **`links[]`** (`besteht_aus` \| `aggregation`) — not inline `lines[]`. Composition soft-trash cascades; aggregation does not. Schema: Bauteilliste→Position = composition. Trash also cascades attribute slots. Q93 lean = id-only on host. Scaffold ≈ **0.0.370**. Release-1 goal = BOM end-to-end. Plan **0.7.50**. |
| 2026-08-08 | **Recursive boxed paint (canonical):** `render(node)` → preferred for father → foreach attr: Mult≤1 recurse unit / Mult>1 collection frame (Table) then recurse items. Mult>1 = list (data); Table = presentation when items have attrs. Preferred not overwritten. Heterogeneous nested Bauteil kinds parked. Living doc: ARCHITECTURE. Plan **0.7.51**. |
| 2026-08-08 | **Docs sync (session laws):** Q72 → preferred **`embed`** (catalog `node_embed` debt); Q83 → **Model-only** (no Implementation SoT); Q93 **decided** id-only; **Q98** = UR-S1 model versioning concept. Living docs + OPEN-QUESTIONS. Plan **0.7.52**. |
| 2026-08-08 | **OQ-A3 / Read-only vs Fixed-lock:** Attribute **Read-only** is SoT for “user cannot edit”; syncs to slot `_wtt_readonly`. **Default value** (`_wtt_attribute_fixed_values`) stays seed-only. Fixed-as-lock UI deprecated on attribute slots / model hosts; legacy `fixedEnabled` on slots treated as RO for paint (meta kept). **Q104 Mandatory** not in this slice. Scaffold ≈ **`0.0.381`**. Plan **0.7.53**. |
| 2026-08-08 | **Q107 decided (warning severity):** Envelope `{ ok, errors[], warnings[], fixes[] }`; save-with-warnings always; save-with-errors by context (data entry allow + badge; schema admin block). Settings **Confirm dialogs** + `wtt_dialog_on_validation_warnings` (default OFF). Scaffold ≈ **`0.0.382`**. Plan **0.7.54**. |
| 2026-08-08 | **Q106 decided (attribute defaults):** Defaults = schema **templates** (list by Mult), not live Model_Data. Scalars = value list; related Mult = nested value maps (e.g. default BOM lines). Materialize on instance create; delete with attribute; no Q98 bump when only defaults change. Slot SoT preferred; host `_wtt_attribute_fixed_values` interim. Plan **0.7.55**. |
| 2026-08-08 | **Q102 decided (composition bottom-up):** Create via parent (`create_linked`) happy path. Orphan composition child = invalid + red **!** (no silent parent create). Aggregation without Platine stays valid. Fixes later (link / discard). Plan **0.7.56**. |
| 2026-08-08 | **Q103 decided (Gutenberg save):** **Public frontend display-only** (no visitor Model_Data entry in R1). Block editor **Save mode** = `autosave` \| `button` (default **button**). Admin Fill Model Data unchanged. **Parked later:** community BOM suggest/review. Plan **0.7.57**; refined **0.7.59**. |
| 2026-08-08 | **R1 B6 chrome (UR-B6):** Line **Wert** → type **Model/Bauteil**; paint via **preferred/default renderer `embed`** (pick part → fill Model data; id on line — Q93). Not catalog `node_ref`/`node_embed` as the product mechanism (OQ-R1/R3). Plan **0.7.60**. |
| 2026-08-08 | **UR-B6 UX locked:** Embed popup — (A) TreeChooser kind under branch root only; (B) attr Form as filter + Model_Data list; create = same form if no match. Plan **0.7.61**. |
| 2026-08-08 | **UR-B6 Wert Mult:** **`1`** (required; empty draft = error + save OK per Q107). Pick or create in embed popup. No known counterexample to filter+create — revisit if one appears. Plan **0.7.62**. |
| 2026-08-08 | **Q108 opened (lean):** Uniform nodes (Simple = Node); tree **need not show all `child_of` children**; typing SoT (`child_of` / implements / type_id) still open. Plan **0.7.63**. |
| 2026-08-08 | **Q108 lean + name:** RelationType **`attribute_typeof`**; **Attributes-wizard only**; invariants (Mult `1`, one edge, no chains/misuse). Scaffold `_wtt_type_id` interim until migrate. Plan **0.7.64**. |
| 2026-08-08 | **Batch decided (scaffold = product):** **Q1** AJAX+REST; **Q5** `WTT\`/`wtt_`; **Q7** rename+reparent; **Q11** WP terms; **Q16** instances in-core; **Q46** no BOM PHP classes; **Q47** validators on node; **Q94** site backup DR, no R1 export button. Plan **0.7.65**. |
| 2026-08-08 | **Q96 decided:** Registry bind via catalog **`builtin.<id>` → term id** (Q92 family); rename-safe; name match = debt. Plan **0.7.66**. |
| 2026-08-08 | **Q34 decided:** special behavior = **config** (+ Relations); no PHP subclass / hard kind. Q49 enforcement separate. Plan **0.7.67**. |
| 2026-08-08 | **Q49 decided:** Builtin Simples — **no** hierarchy kids, **no** attributes as host, **no** outgoing Relations; may be **`attribute_typeof` target**. Constraints via validators/Options/composed types. Plan **0.7.68**. |
| 2026-08-08 | **Q49 revised:** Simples **may** have specialization children (reusable Config presets: validators, Roman converter, …). Soft lean: still no attrs-as-host / no outgoing Relations. Plan **0.7.69**. |
| 2026-08-08 | Confirmed Q49 soft lean (no attrs on Simple leaves; percent = validator specialty). Opened **Q109** — measure/quantity + unit/prefix switch recalculation (next). Plan **0.7.70**. |
| 2026-08-08 | **Q109 decided:** quantity = display triple; same-Basiseinheit Präfix switch → **rescale Typ** (physical constant); no silent cross-unit. Plan **0.7.71**. |
| 2026-08-08 | **Q110 opened:** currency/money ≠ measure — EUR→USD needs **FX rates**, not Präfix multiplikator. Plan **0.7.72**. |
| 2026-08-09 | **Q111 decided:** value storage **from type** — Simples + `quantity` always **inline** on host; identity/structure → linked Model_Data (Q97). No `is_datatype` flag; picker `inline` ≠ storage. Plan **0.7.73**. |
| 2026-08-09 | **Q111 revised:** **Bindung** = storage SoT — Composition → embedded in parent; Aggregation → linked Model. Amends Q97 BOM-lines-as-linked. **Q112** opened (rename Preferred `embed`). Plan **0.7.74**. |
| 2026-08-09 | **Q112 decided:** Preferred key stays `embed`; UI label **Embedded renderer**. Plan **0.7.75**. |
| 2026-08-09 | **Q113 parked:** Preferred/renderer assignment as type config; one Registry lean; gray-out if only one renderer — think later. Plan **0.7.76**. |
| 2026-08-09 | **Q113 shape locked:** `enum Renderer: string` with values `IntRenderer`, `FormRenderer`, `EmbeddedRenderer`, … — build still parked. Plan **0.7.77**. |
| 2026-08-09 | **Q111 seed:** Model DEFAULT Bindung = aggregation; Position = composition. **Q113 scaffold:** enum + object layout wire ids ≈ **0.0.388**. Plan **0.7.78**. |
| 2026-08-09 | **Q114:** Attribute Options = same Node R/C/V chrome; Preferred render+converter **side by side** ≈ **0.0.389**. Plan **0.7.79**. |
| 2026-08-09 | **Q115:** Settings Fixed → **Read-only** + **Default value** (node `_wtt_fixed_*`); gray RO outside slots; gray Default on builtin Simples; specializations editable ≈ **0.0.390**. Plan **0.7.80**. |
| 2026-08-09 | **Q116:** Required list-select with **one** choice → auto-select + gray; optional (zero-lower Mult) stays open; Preferred + CatalogChoice + `renderOptionsSelect` ≈ **0.0.392**. Plan **0.7.81**. |
| 2026-08-09 | **Q117/Q118:** Node presentation store (texts + locale-invariant icon) + admin list; detail Display regroup. Store+list ≈ **0.0.393**. Plan **0.7.82**. |
| 2026-08-09 | **Q119 decided lean:** money = precise major-unit store; ISO 4217 minor for entry scale; display via Preferred converters (adaptive digits default; not Preis hard-code); FX stays **Q110** parked. Same conceptual family as Q109 unit rescale, different math. Plan **0.7.83**. |
| 2026-08-09 | **Q120 decided lean:** Quantity = fixed triple Value+Prefix?+Unit; unit **kind** + **dimension**; conversion engines separated (prefix rescale / same-dimension UoM / FX / money minor) — SAP-like, not hard-coded on Preis/Ohm; Q24 closed. Plan **0.7.84**. |
| 2026-08-09 | **Q120 refined:** Basiseinheiten/Währung folders = browse knots; product = **unit rule profiles** (prefix on/off, dimension, conversion engine). Meter↔inch same story as EUR↔GBP, different engine. **Q121** opened (canonical vs entry store / rounding). Plan **0.7.85**. |
| 2026-08-09 | **Q121 decided lean:** money store **canonical EUR** + foreign-entry **snapshot** (amount, currency, rate, date). Physical m↔inch canonical later. Next focus: **unit↔prefix marriage** (catalog) before field/input UX. Plan **0.7.86**. |
| 2026-08-09 | **Q120 tree reshape ≈ 0.0.402:** Drop **Konstanten**; under **Data Types**: Präfixe + **Unit**/{With prefix, Without prefix} + Bauformen. Quantity marries a selectable unit. Plan **0.7.87**. |
| 2026-08-09 | **Catch-up desk** after long afternoon chat: locked Q119–Q121 + tree reshape; open UX = unit/prefix non-power surface, Attributes lazy fold, money converter/snapshot scaffold. Relations lazy fold ≈ **0.0.405**. Plan **0.7.88**. |
| 2026-08-09 | **Allowed prefixes wizard ≈ 0.0.406** on unit detail (Fallstudie); quantity empty-prefix = **—**; tighter quantity margins. Catch-up item 1 closed. |
| 2026-08-09 | **Fix Praefix/Einheit Default picker ≈ 0.0.407:** attribute type_ids still pointed at empty Konstanten folders after Unit catalog move — remap to Data Types Präfixe/Unit + resolve empty catalog roots in `fixed_options`. |
| 2026-08-09 | **Attribute shadow warning ≈ 0.0.408:** own attr with same name as ancestor hides inheritance — flag `shadowsInherited` + amber UI (banner + !). Keep local only when specialization-specific (e.g. Nennspannung on Kondensator). |
| 2026-08-09 | **Attribute rule RO→default ≈ 0.0.409:** read-only without default (non-computed) is illegal; fixes clear RO or set default (`Attribute_Validator` + banner). Plan **0.7.89**. |
| 2026-08-09 | **Q91/OQ-R8/Q120 debt close ≈ 0.0.411:** **UnitRenderer** (Prefix?+Symbol); **QuantityRenderer** = one-row Value+Unit compositor (no tree-admin trinity hardcode). Presentation edit **Back to node** jumpback. Plan **0.7.90**. |
| 2026-08-09 | **Q122 decided:** Type properties set on the type; override along inheritance — **same law everywhere**. Composed types expose **component attribute** property surfaces (Default, Preferred, Choices/allowlists, …) as on those nodes; visibility **dynamic** from the attribute/type graph (not hard-coded). Scaffold debt: Festwert dialogs still catalog\|scalar — next cleanup toward Registry Quantity/Unit defaults. Plan **0.7.91**. |
| 2026-08-09 | **Default picker = type paint ≈ `0.0.414`:** Festwert dialog mounts the attribute’s type via `paintFieldContent` (Preferred + settings); CatalogChoice only for true `fixedMode=catalog`. Plan **0.7.92**. |
| 2026-08-09 | **Relation vs Object whiteboard** saved: [`relation-vs-object-concept.md`](relation-vs-object-concept.md). Clean lean: Attribute panel settings (name, type, default, RO, hide, Mult, Bindung) belong on the **Relation** when modeling relation-first; Attribute UI = projection. Does not override Q87 scaffold yet. Plan **0.7.93**. |
| 2026-08-09 | **Settings capsule lean:** forget aux Attribute node for now. **Settings on Relation** only for **`besteht_aus` + `aggregation`**. Inventory other RelationTypes for reduction (`composition` alias, `ref_scope`, `attribute_typeof`). |
| 2026-08-09 | **RelationTypes reduce:** product **`composition`** alias dropped (migrate/read-only legacy → `besteht_aus`). **`attribute_typeof` superseded** — type = Relation target; Settings of type on composition/aggregation capsule. **`ref_scope` park/likely drop** — was `node_ref`/`node_embed` catalog root (Q73); job → Settings/bindings. Keep: `child_of`, `besteht_aus`, `aggregation`. Plan **0.7.94**. |
| 2026-08-09 | **Deprecated hard:** `ref_scope`, `node_embed`, `node_ref`, `node_pick` (Q72/Q73/Q84). Pick+fill = preferred `embed` + composition/aggregation. Plan **0.7.95**. |
| 2026-08-09 | **Q123:** composition/aggregation Relation needs **`name`** (e.g. Wert) — no aux node to hold the label. |
| 2026-08-09 | **Q123 crux:** attribute Settings UI + host render = **recursive walk** down composition/aggregation targets (collect Settings / Preferred paint at each level). |
| 2026-08-09 | **Doc pass after Q123 sharpen:** living docs pointed at Settings capsule + walk; **open tensions** listed as **OQ-W1…W16** in [`q123-doc-pass-questions.md`](q123-doc-pass-questions.md) — do not invent answers. Plan **0.7.96**. |
| 2026-08-09 | **Note:** `With prefix` = **father knot**; Unit may target it or a **specialization child** — Settings/render walk must reflect choice + inherited father settings (OQ-W11). |
| 2026-08-09 | **OQ-W1 decided:** attributes = **Relation only** — no slot terms. OQ-W2 still open (live walk vs snapshot — explain to user). |
| 2026-08-09 | **OQ-W2 decided: hybrid** — live below; used above only if not overwritten on Relation. Next: OQ-W3 nested override storage. |
| 2026-08-09 | **OQ-W3 decided:** overrides on **current** Relation/node Settings; need **overridden?** marker per field/path for hybrid resolve. Next: OQ-W4 field split. |
| 2026-08-09 | **OQ-W3 clarify:** store **only override deltas**; display other Settings via live walk. Presence in override map = overwritten (marker). |
| 2026-08-09 | **OQ-W3:** no separate overwritten flag — **key in delta map** is enough; reset = delete key. |
| 2026-08-09 | **OQ-W4 decided:** Relation edge = name, target, Bindung, Mult, RO, Hide/BO, Default seed; type Settings = walk + override deltas only (Preferred, allowlists, …). Next: OQ-W5 inherit. |
| 2026-08-09 | **OQ-W5 confirmed:** inherit attrs along `child_of` (Q66) + hide; Relation-only storage. Next: OQ-W6. |
| 2026-08-09 | **OQ-W6 decided:** node Settings and attribute Settings = **same recursive walk** (subnodes included). Next: OQ-W7 write target. |
| 2026-08-09 | **OQ-W7 lean:** write = **override on current** only; do not setup/push defaults into subnodes; child may override. Next: OQ-W8 walk limits. |
| 2026-08-09 | **OQ-W8 decided:** walk to **leaf**; **break cycles** (node already on path). Next: OQ-W9. |
| 2026-08-09 | **OQ-W9 decided:** same Settings/Render walk for composition and aggregation. Next: OQ-W10 size vs quantity. |
| 2026-08-09 | **OQ-W10 decided:** keep **quantity** + **size** as inheriting child with extra settings; no hard-code by name `size`. Next: OQ-W11. |
| 2026-08-09 | **OQ-W11 decided:** `With prefix` **composed of** Praefix + Kuerzel Relations (A); then Settings. Unit restrict target vs Choices = impl detail. Next: OQ-W12. |
| 2026-08-09 | **OQ-W12 decided:** instance keys = **Relation id**; rename cosmetic (Q98) already id-safe in Model_Data. Next: OQ-W13. |
| 2026-08-09 | **OQ-W13 decided:** delete host → composition **dies with** host; aggregation **targets remain**, **Relation removed**. Catalog types untouched. Next: OQ-W14. |
| 2026-08-09 | **OQ-W13+Q111 remark:** composition = inline data; aggregation = data on related object. **OQ-W14:** keep Relations panel + Attributes **wizard** (same Relations). Next: OQ-W15. |
| 2026-08-09 | **OQ-W15 decided:** `node_ref` / `ref_scope` (/`node_embed`/`node_pick`) **deprecated — do not use** until a use case. Next: OQ-W16 Preferred storage. |
| 2026-08-09 | **OQ-W16 lean:** Preferred R/C/V ∈ **Settings** (same override law); challenges noted (paint cache, validator lists, Q117 split, meta migrate). **OQ-W1…W16 doc pass closed.** Plan **0.7.96**. |
| 2026-08-09 | **OQ-W16 refined:** two categories under same walk/override law — **Data Settings** (validators, allowlists, …) vs **View Settings** (Preferred R/C, output chrome); Presentation (Q117) stays labels/icon. Preferred ∈ view, not mixed unlabeled with data. |
| 2026-08-09 | **OQ-W16 locked:** `Settings.data` + `Settings.view`; same walk/override; Preferred ∈ view; Q117 Presentation separate. |
| 2026-08-09 | **Q123 docs sync:** living docs + [`DEVELOPER-ATTRIBUTE-MODEL.md`](../DEVELOPER-ATTRIBUTE-MODEL.md) (diagrams for developers / later user docs). Plan **0.7.97**. |
| 2026-08-09 | **Q123 scaffold slice ≈ `0.0.416`:** Relation-only attributes (named `besteht_aus`/`aggregation` → type); `Attribute.id` = edge id; `Attribute_Q123_Migrate` remaps slots + Model_Data keys; Preferred override in Relation `settings.view` (camelCase key fix); Object_Render/Blocks UUID value keys. Full Settings walk UI still debt. |
| 2026-08-09 | **Q123 ≈ `0.0.417`:** `Settings_Walk` recursive gather (live + hybrid deltas, cycle break); Preferred (+ view converter / data validators / dateMode when on edge) via `decorate_row`; `settingsResolved` + `settingsWalkMeta` on attribute rows. Full Options walk UI still debt. |
| 2026-08-09 | **Q123 ≈ `0.0.418`:** typeExtras bridge — read prefers Relation `settings.data`/`view`, host map fallback; `set_type_extras` dual-writes edge deltas for own edges; one-shot fold `wtt_q123_type_extras_folded`. Options walk UI still debt. |
| 2026-08-09 | **Q123 ≈ `0.0.419`:** Attributes Options paint prefers edge Settings deltas (+ typeExtras fallback); optional `settingsWalkMeta.nodeCount` hint; saves still dual-write AJAX. Full walk UI still debt. |
| 2026-08-09 | **Q123 ≈ `0.0.420`:** Trash cascade safety — leftover slots via edge `toId`+`is_slot` only (never edge UUID→term); Model_Data soft-trash/restore/purge on host structures (Q111 composition children); catalog filters still hide slots. Host typeExtras dual-write kept. |
| 2026-08-09 | **Q123 ≈ `0.0.421`:** `Composition::get_attribute_columns` / `normalize_rows` keep Relation edge ids (`Attribute::normalize_attr_id`); no `(int)` UUID prefix wipe for model table columns. |
| 2026-08-09 | **Q123 ≈ `0.0.422`:** Stop typeExtras dual-write — own attrs write Relation `settings.data`/`view` only (clear host key); host map read-fallback + inherited overrides kept; one-shot prune `wtt_q123_type_extras_pruned_v1`. Hide/readonly host maps untouched. |
| 2026-08-09 | **Q123 ≈ `0.0.423`:** Preferred override via `Attribute::set_preferred_render` → Relation `settings.view.preferredRenderer` only (no slot meta / no `is_slot` gate on edge ids); clear deletes delta key + leftover slot meta; `settingsWalkMeta.preferredSource` / `hasPreferredOverride`. |
| 2026-08-09 | **Q123 ≈ `0.0.424`:** Own-attr RO/Hide → Relation edge fields `readOnly`/`hidden` (OQ-W4); host maps kept for inherited hide/RO overrides; read edge-first + host fallback; Q105 BO⇒Mult `0..1`; one-shot fold `wtt_q123_edge_flags_folded_v1`. |
| 2026-08-09 | **Q123 ≈ `0.0.425`:** Own-attr Default seed → Relation edge field `default` (OQ-W4 / Q106; not Settings); inherited overrides stay host `_wtt_attribute_fixed_values` by name; read edge-first + host fallback; one-shot fold `wtt_q123_defaults_folded_v1`. |
| 2026-08-09 | **Q105 ≈ `0.0.426`:** Attribute_Validator rule `background_only_needs_mult` — own Hide/BO with Mult ≠ `0..1`; fixes `set_mult_01` / `clear_hide` via `wtt_fix_attribute_rule`; admin banner; write gate already ≈ 0.0.424. |
| 2026-08-09 | **Q123 ≈ `0.0.427`:** Bounded Settings walk summary on `decorate_row` (`settingsWalk`: names + preferred; max 24; nested only) + compact read-only list in Attributes Options fold. No full walk wizard / no second Form UI. |
| 2026-08-09 | **Q123 ≈ `0.0.428`:** Safe one-shot host-map prune `wtt_q123_host_maps_pruned_v1` — own RO/Hide/default/typeExtras host keys dropped only when already on edge; empty typeExtras maps deleted; inherited overrides kept. |
| 2026-08-09 | **Q123 ≈ `0.0.429`:** Orphan slot purge `wtt_q123_orphan_slots_purged_v1` (true orphans only; keep Q90 Zeile/Kopf/Fuss; any edge `toId` / catalog protected) + Attributes panel collapsed by default (catch-up desk). Live `wtt_fs` had 0 true orphans / 3 parked bands. |
| 2026-08-09 | **Q123 ≈ `0.0.430`:** Combined invariants smoke `scripts/_smoke-q123-invariants.php` (edge-id shape; no-slot add; Preferred/Default/RO on edge; Wert `settingsWalkMeta.nodeCount`). Core Relation-only migrate marked **ready for user UAT**; remaining = polish (walk wizard, host read fallback, Q90 bands, commit). |
| 2026-08-09 | **Q123 ≈ `0.0.431`:** Own-attr reads edge-only for RO/Hide/default/typeExtras/preferred (no host-map fallback); inherited keep host-map overrides. One-shot `wtt_q123_own_edge_read_sot_v1` folds remaining own host Hide (incl. Q105 Mult≠0..1 debt) + leftover own RO/default/typeExtras onto edge. Plan **0.7.98**. |
| 2026-08-09 | **Q123 ≈ `0.0.432`:** Settings walk Options levels include `nodeId`; click navigates via `selectNode` (read-only summary; no delta edit / no wizard). Plan **0.7.99**. |
| 2026-08-09 | **Q123 ≈ `0.0.433`:** Relations admin shows/edits `Relation.name` for `besteht_aus`/`aggregation` (same SoT as Attributes → Name); `hydrate`/`list_outgoing`/`list_incoming`/`relationsStored` expose name; AJAX `wtt_update_relation_name` + add `name`; `child_of` stays nameless. `mark_as_slot` documented legacy-only. Plan **0.7.100**. |
| 2026-08-09 | **Q123 ≈ `0.0.434`:** UUID attr-id audit — `effective_list` `shadowedAttrId` uses `normalize_attr_id` (was `(int)` → `0` for edge UUIDs). Remaining `(int)`/`parseInt` on term/catalog/band ids left intentional. Plan **0.7.101**. |
| 2026-08-09 | **Q123 ≈ `0.0.435`:** Settings walk Options UX — per-level Preferred (read-only) + explicit **Edit type settings** (`selectNode`); hint that nested Preferred is edited on the type node; attribute Relation Preferred override stays above (edge `preferredRenderer`). Full Walk wizard / in-list delta edit **deferred post-UAT**. Plan **0.7.102**. |
| 2026-08-10 | **Q123 ≈ `0.0.436`:** Inherited host-map naming clarity — API aliases (`get_inherited_*`), decorate `inheritedHostOverride` flags, Attributes Inherited column override badge + help text; invariants assert own attrs stay off host maps. Walk wizard still deferred. Plan **0.7.103**. |
| 2026-08-10 | **Q123 ≈ `0.0.437`:** First Walk delta-edit slice — Attributes Options labels Preferred/converter/dateMode/validators as **Relation overrides** (hybrid); Reset deletes edge Settings delta key; nested walk levels stay navigate / Edit type settings only. Invariants `smoke=ok`. Plan **0.7.104**. |
| 2026-08-10 | **Q123 ≈ `0.0.438`:** Q90 parked table bands (Zeile/Kopf/Fuss) — still hidden from Attributes; Relations mark as **Q90 parked** (locked, badge); ensure edge names; one-shot `wtt_q123_parked_band_names_v1`. Do not revive Collection `table`. Invariants + parked-bands `smoke=ok`. Plan **0.7.105**. |
| 2026-08-10 | **Q123 ≈ `0.0.439`:** Attribute duplicate / reorder / move audited for edge UUID ids — `move_to_node` preserves OQ-W4 edge fields + order meta; `duplicate` copies extras/default/RO/Hide; no slot create. Laragon `_smoke-q123-dup-move.php` + invariants `smoke=ok`. Plan **0.7.106**. |
| 2026-08-10 | **Q123 ≈ `0.0.441`:** Walk-Wizard — per-level Settings.view + Settings.data overrides as path-keyed deltas on the attribute Relation (`settings.nested[<edgeUuid[/…]>]`; depth 0 top-level). Preferred / converter / validators / dateMode + Reset; no nested type writes. Attributes panel default open ≈ **0.0.440**. Laragon `_smoke-q123-walk-nested-overrides.php` `smoke=ok`. Plan **0.7.107**. |
| 2026-08-10 | **Q124 ≈ `0.0.442`:** RelationType **`defaultvalue_from`** — From consumer host → To provider host; name = attr; create/empty seed from provider instance/default (after local `edge.default`). BOM seed Bauteilliste.Bauart → Position.Bauart; `create_linked` passes providers. Live cascade open. Plan **0.7.108**. |
| 2026-08-10 | **Q123 ≈ `0.0.443` / reconcile `0.0.444`:** Parallel agents both claimed 0.0.443 — (1) Walk `Settings.data.allowedPrefixIds` on Unit/With-prefix (+ paint intersect); (2) admin Preview = host Preferred only. Working tree **`0.0.444`** keeps both; no feature expansion. Plan **0.7.109**. |
| 2026-08-10 | **OQ-W11 unit structure ≈ `0.0.445`:** Idempotent Case_Data — `With prefix` Praefix→Präfixe + Kuerzel→text; `size` child_of `quantity` (Value→double, Unit→With prefix); Passiv Wert→size; unit leaves stay leaf (no fake slots). Display synthesize Typ/Praefix/Kuerzel deferred. Plan **0.7.110**. |
| 2026-08-10 | **Q123 ≈ `0.0.448`:** Walk Default override per level (depth 0 → `edge.default`; nested → `settings.nested[path].data.default`) + compact one-row Composition walk UI; tree layout deferred; parallel Model versions stack ≈ 0.0.447. Plan **0.7.111**. |
| 2026-08-11 | **Q66 heir Choices:** Abstract CatalogChoice+inheritance diagram + **Application** (Bauart, Währung, Unit candidate) in [`attribute-choice-inheritance.md`](attribute-choice-inheritance.md); inherited **`choiceFilter`** host override UI ≈ **0.0.455**. Unit remap parked. Plan **0.7.112**. |
| 2026-08-11 | **ISO 4217 money vocab:** Währung leaves use `currencyCode` / `currencyNumber` / `currencyExponent`; keep Q119 store-major, entry scale, converters, Q110/Q121 FX snapshot. Measure profiles (SI / length / temp / money / packaging) in same doc. Not an ISO conversion engine. Plan **0.7.113**. |
| 2026-08-11 | **BIPM SI + ISO/IEC 80000 vocab:** Praefix/unit names, symbols, SI prefix factors align SI Brochure + ISO 80000; Ki/Mi = IEC 80000-13 only. Keep allowlists, Q109, inch/affine/packaging. Same “vocab not engine” pattern as 4217. Plan **0.7.114**. |
| 2026-08-11 | **Q125 `fn` Relation:** Generic function-bearing RelationType (`op` + optional props); Q124 `defaultvalue_from` → first op `default_from`; later scale_factor / scale_ref / contains. SI Präfix not via `fn`. Plan **0.7.115**. |
| 2026-08-11 | **Q125 rename:** RelationType key **`calc`** (UI Berechnung); was draft `fn`. Plan **0.7.116**. |
| 2026-08-11 | **Q125 + SI Feinschliff scaffold ≈ `0.0.456`:** Seed `calc`, alias `defaultvalue_from`, `settings.data.op=default_from`, UI label Calculation; BOM Bauart seed via calc; SI engine docs (Q109 leaf, not calc). Plan **0.7.117**. |
| 2026-08-11 | **Doc backlog:** end-user **constellation recipes** (how to set up SI / `calc` / CatalogChoice / Money / own attrs) — stub [`user-constellation-recipes.md`](user-constellation-recipes.md); write full steps later. Plan **0.7.118**. |
| 2026-08-11 | **Unit type seed ≈ `0.0.457`:** Data Types / **Unit type** + heir **C1**; attrs Menge / Base unit (→ With prefix leaves) / Praefix (→ Präfixe). Full Base-unit folder remap still parked. Plan **0.7.119**. |
| 2026-08-11 | **Konstanten restored ≈ `0.0.458`:** Definition/**Konstanten** again holds Präfixe, Basiseinheiten (With/Without prefix), Bauformen, Währung; Data Types keeps Simple/Complex/Unit type. Migrate empties interim DT catalog shells. Plan **0.7.120**. |
| 2026-08-11 | **Heir Settings overrides ≈ `0.0.462`:** Child hosts can override Preferred / Walk Settings for inherited attrs via `_wtt_attribute_settings_overrides` (father edge untouched; Q66 / OQ-W5). Plan **0.7.121**. |
| 2026-08-11 | **Q90 Complex purge ≈ `0.0.463`:** Soft-trash parked `enum`/`list`/`node_pick`(+embed/ref) from Fallstudie tree; stop seeding/restoring them. Keep `quantity`/`set` + legacy `table` (BOM). Bauart → direct child of Complex. Plan **0.7.122**. |
| 2026-08-12 | **Q126 ConfigPage ≈ `0.0.481`:** Vertical box stack `WTTConfigRender.renderPage` (Actions → MetaSettings → Bools → Display → Attributes → Preview); one page everywhere; RO in Bools strip. Rule `config-renderers.mdc`. Plan **0.7.123**. |
| 2026-08-12 | **Settings UI parity:** Soll vs Ist matrix [`settings-ui-parity.md`](settings-ui-parity.md) — concept (Knoten-/Attribut-Walk) locked; GUI debt = legacy Options chrome beside Walk. First slice: dedupe Attribute Options when walk covers Settings. Agent rule: model lock ≠ GUI done. Plan **0.7.124**. |
| 2026-08-14 | **Settings walk UX ≈ `0.0.531`:** Nested walk table; deferred Choices; C1 demo retired (heirs ≠ CatalogChoice); EmbeddedRenderer for aggregation hosts without kind children; Preferred = type-node default (no display-name). Docs: parity plan + ARCHITECTURE sync. Plan **0.7.125**. |
| 2026-08-14 | **ChildList ConfigPage ≈ `0.0.532`:** Drop Q126 `childNodes` box; Choices UI only when Preferred = `ChildListRenderer` (Währung/Praefix/Konstanten same); default Preferred for hosts with children → ChildList. Tag **Walk01** = pre-change checkpoint. Plan **0.7.126**. |
| 2026-08-14 | **Retire Implementation ≈ `0.0.533`:** Remove Fallstudie/Implementation from Case_Data blueprint; `ensure_bom_implementation` / `ensure_bauteile_catalog` permanent no-ops; one-shot soft-trash `maybe_retire_implementation_branch`. Artifact source was seed + install/reset. Plan **0.7.127**. |
| 2026-08-14 | **Display Preferred form stack ≈ `0.0.536`:** Drop outer Preferred row; Render / Converter / Validators as normal form rows (label left, select right); ChildList options under Render; converter options hook; validators table only when set. Plan **0.7.128**. |
| 2026-08-14 | **Preferred options in control column ≈ `0.0.537`:** Renderer/converter/validator settings mount in the same form control column as the select (right of label), not full-width under the row. |
| 2026-08-14 | **Präfixe template snapshot ≈ `0.0.540`:** Capture live Konstanten/Präfixe into Case_Data (`prefix_catalog_leaves` + `ensure_praefixe_catalog`): ChildList + Centi Choices exclude; Presentation + multiplikator attrs; Compact leaves; Giga/Tera SI factors. |
| 2026-08-14 | **Präfixe docs lock:** ARCHITECTURE / PRODUCT / case-study / attribute-choice-inheritance / OPEN-QUESTIONS Q51 / ROADMAP describe the locked catalog; rule `praefixe-catalog-lock.mdc`. Plan **0.7.130**. |
| 2026-08-14 | **ChildList Choices/Default Q90 depth ≈ `0.0.541`:** ConfigPage Choices paint full choice subtree; Default = ListChooser (depth ≤ 1) or TreeChooser (depth ≥ 2) — same as CatalogChoice (Basiseinheiten tree, Präfixe flat). Plan **0.7.131**. |
| 2026-08-14 | **Properties scroll lock ≈ `0.0.542`:** Generation-guarded pane/window scroll hold (~180ms) + form rows `align-items: start` + `overflow-anchor: none` on detail/tree/form — stop recurring jumps on Preferred/Choices re-render. Plan **0.7.132**. |
| 2026-08-14 | **Basiseinheit leaves Compact ≈ `0.0.543`:** With + Without prefix unit leaves Preferred = `CompactRenderer` (definition = inherited Praefix?/Kuerzel); Without prefix gets Kuerzel composition; UnitRenderer reserved for quantity usage fields. Plan **0.7.133**. |
| 2026-08-14 | **EmbeddedRenderer tree-admin ≈ `0.0.544`:** Wire Model_Data list/create via `wtt_model_data_*` + `modelDataNonce`; fix preview onChange (`renderKeepingPreviewChrome`); pass rootId/label; load object-render CSS. Plan **0.7.134**. |
| 2026-08-14 | **MultistepRenderer ≈ `0.0.546`:** Rename Preferred wire Embedded→**Multistep** (aliases keep embed/EmbeddedRenderer); meta `_wtt_multistep_mode` dialog\|inline (renderer-local ≠ Q74); ConfigPage mode under Render; object-render inline strip. Plan **0.7.135**. |
| 2026-08-14 | **Multistep × Bindung (Q111/Q112 ≈ `0.0.552`):** Composition Multistep = kind → fill attrs (attribute order; values in context) — no Matches / Create-and-bind / instructional chrome. Aggregation Multistep = kind → filter/search Model_Data + create + bind id (UR-B6 chooser). Plan **0.7.136**. |
| 2026-08-14 | **Multistep Phase B = kind Preferred ≈ `0.0.554`:** Composition (and Aggregation filter) paint with the **selected kind node’s Preferred** (Meter/Gramm → Compact horizontal Praefix\|Kuerzel) — not hard-coded Form. Plan **0.7.137**. |
| 2026-08-14 | **Compact Display + Multistep composition Display ≈ `0.0.555`:** Admin Preferred Compact paints via `renderCompact` first (draft `compactShowLabels` → `showLabels`); ConfigPage **Show field labels**; Multistep Composition Display paints kind Preferred with filled values (unwrap `presentation.values`); soft Display refresh without Editable remount-loop; mount `onFieldInput` for Form. Plan **0.7.138**. |
| 2026-08-14 | **Nested structure Preferred + Q117 ≈ `0.0.558`:** Nested attribute cells paint **type Preferred** with **typePresentation** as Q117 host — never outer host / typeKey slug; Hide omits nested attrs. Plan **0.7.139**. |
| 2026-08-14 | **Settings cascade → paint (locked):** Defaults cascade detail→model (Präfix→BU→Unit type→Model). Preview/nested cells **must** follow Preferred / Q117 / Hide / Mult / Bindung / walk overrides — **no** display-name or special-case hardcoding; renderer-local chrome only for the current layer. Plan **0.7.140**. |
| 2026-08-14 | **Compact Hide edge-match + walk Preferred stack ≈ `0.0.559`:** Walk Hide/RO/Default apply to `typeProperties` by **edge id** only (never shared Simple `typeId` — Name was dropped when another text field was hidden). Walk Preferred R/C/V = stacked like node Display (`renderPreferredStack`). Plan **0.7.141**. |
| 2026-08-14 | **Hide remount shell pin ≈ `0.0.560`:** Walk Hide AJAX remount must match cold-load layout. Cause: document scroll restored/held while `body` overflow hidden → ~⅓ UI + gray void until reload. Fix: never restore window scroll; blur before wipe; `syncAppShellLayout` measures live `#wtt-app` top→viewport; pin document scroll. |
| 2026-08-14 | **Aggregation Preferred paint ≈ `0.0.561`:** Structure cells with Bindung=aggregation paint **bound Model_Data** via type Preferred (`paintAggregationBound`) — not Composition `paintStructureEmbed` host JSON. Hide edge-id match + walk R/C/V stack remain. |
| 2026-08-14 | **Hide remount shell flex ≈ `0.0.562`:** 0.0.560/561 still collapsed — `syncAppShellLayout` wrote too-small inline `#wtt-app` height from rects → `#wpwrap`/admin menu shrank + white void. Fix: CSS flex fill `#wpwrap`→`#wtt-app`; JS only clears inline sizes + pins admin scrollers; never measure/write pixel heights. Plan **0.7.142**. |
| 2026-08-14 | **Aggregation chooser paint ≈ `0.0.563`:** Bindung=aggregation structure cells must **not** composition-embed type schema (Name/E-Mail inputs). Unbound Editable → Multistep Aggregation pick/search/create/bind (concrete type skips Phase A); bound → type Preferred Compact/Form readonly + Change. Drift from ≈0.0.561 sample Compact fixed. Plan **0.7.143**. |
| 2026-08-08 | **UR-B6 scaffold ≈ `0.0.384`:** Seed Wert Mult=`1` + Model/Bauteil preferred `embed`; object-render popup Phase A (TreeChooser branch) + Phase B (Form AND-filter + instance list/create → id bind); Q107 server envelope TODO. Plan **0.7.63**. |
| 2026-08-08 | **Q106 scaffold seed (≈ `0.0.383`):** Mult-many scalars seed **all** defaults on create / open-new / fill-samples (JSON array store when >1); Mult-1 stays single. Nested maps normalize + `create_linked` on parent create; related default-row admin UI TODO. Plan **0.7.58**. |

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
