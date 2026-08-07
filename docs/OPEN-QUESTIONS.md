# Open questions

> Resolve or defer during planning. Keep aligned with [`docs/plans/project-plan.md`](plans/project-plan.md) and [`docs/plans/planning-phase.md`](plans/planning-phase.md).

**Mode:** scaffolding + planning — answers become decision-log entries. Early scaffold may adopt a leaning (e.g. Admin-AJAX) without freezing the final product choice.

| ID | Question | Options | Current leaning | Status |
|----|----------|---------|-----------------|--------|
| Q1 | How should the admin UI talk to WordPress? | REST API / Admin-AJAX / both | **Scaffold uses Admin-AJAX**; REST still optional for hosts | open |
| Q2 | Which JS approach for the tree UI in MVP? | Vanilla JS / `@wordpress/scripts` + React | **Scaffold uses vanilla JS**; upgrade if complexity grows | open |
| Q3 | One tree screen for many taxonomies, or one screen per taxonomy? | Switcher on one screen / submenu per taxonomy | **Scaffold today: one product taxonomy (`wtt_fs`)** — no dual switcher. Future host registration may revive multi-tax UI | open |
| Q4 | Should activating the plugin replace core term list screens by default? | Opt-in per taxonomy / replace when registered / never replace | Opt-in when a taxonomy is registered with the environment | open |
| Q5 | Exact PHP namespace and prefix? | e.g. `WTT\` / `wtt_` | TBD | open |
| Q6 | Minimum supported WordPress / PHP versions? | WP 6.x + PHP 8.x targets | PHP 8.x; modern WP — pin exact numbers at sign-off | open |
| Q7 | Is rename/reparent in-tree required for MVP? | Yes rename only / yes rename+reparent / later | **Scaffold has rename + reparent**; keep both for MVP leaning | open |
| Q8 | Placeholder right-hand panel in MVP? | Yes empty/host slot / tree-only until Phase 2 | Host slot preferred so electronic-parts can attach later | open |
| Q9 | When to integrate with `wp-electronic-parts`? | After MVP / after Phase 2 / never in-repo | After extension contract exists (Phase 2+) | open |
| Q10 | Packaging for reusable code? | Single plugin only / plugin + Composer package | Single plugin first | open |
| Q11 | How is Node stored? | Map 1:1 to WP terms / custom node table / hybrid | Map 1:1 to hierarchical WP terms | open |
| Q12 | Which optional Node fields are in MVP? | slug / description / count / position / meta / short_description | **description** required on every Node (may be empty); **short_description** decided (scaffold: labels/help/tooltips); slug + count likely; **position** strongly needed if BOM/Recipe lines are Nodes (Q13/Q46) | open |
| Q13 | How are siblings ordered? | WP default name/term order / explicit position field | **Leaning: explicit `position` (or Relation order)** — BOM/Recipe line display needs stable sequence, not name sort | open |
| Q14 | Does a property slot have exactly one owning node? | Always one owning node / can be shared / taxonomy-level / other | **Superseded with Q64:** no Parameter class. **Eigenschaft** = typed **child Node** under exactly one parent (hierarchy `child_of`). | superseded |
| Q15 | Where are property-slot definitions stored? | Term meta / custom table / host plugin storage | **Superseded with Q64:** slots are **Nodes** (same persistence as Q11 — typically WP terms). Instance values still Q16/Q63. | superseded |
| Q16 | Are instance *values* (filled data) part of this plugin? | Yes in-core / host plugins only / later phase | **Strong lean:** cell / node **instance values** on Bauteile and CompositionRows are **in-core**. Host may still own price/stock. WP mapping = Q11. (No ParameterValue class.) **Scaffold:** admin **Fill Model Data** stores instances in option `wtt_model_instances` (`Model_Data`) — separate from attribute definitions. | open |
| Q17 | How does a Project get its trees (root nodes)? | Nodes carry `project_id` / project stores root ids / other | **Decided (domain model):** Project has `root_nodes` (list of Node). Persistence details still Q19. | decided |
| Q18 | How does Project relate to WordPress taxonomies? | One project = one taxonomy / project independent of taxonomy / hybrid | **Leaning strong: Project ≈ taxonomy** (practically the same); WP taxonomy slug on Project; Node has no taxonomy field | open |
| Q19 | Where is Project stored? | CPT / custom table / option / taxonomy | TBD — if Project ≈ taxonomy, storage may collapse toward the taxonomy (+ meta) or a thin Project wrapper (Q19) | open |
| Q20 | How are domain objects represented in PHP? | Typed DTO classes / arrays only / WP objects directly / hybrid | **Decided (revised Q64):** typed DTOs for Project, Node, Relation, Changelog, Change, …; **no Parameter / ParameterValue DTO**; services for behavior; no Tree/RootNode class | decided |
| Q21 | What is stored in Change.`change` (the Änderung)? | Plain text summary / structured field diff / both | Text summary first; structured diff optional later | open |
| Q22 | What is Change.`changer` (the Änderer)? | WP user ID / login / display name / Actor value object | WP user ID (+ display resolved in UI) leaning | open |
| Q23 | What format is Change.`version`? | Semver string / integer counter / object version snapshot | Align with plugin versioning where useful; decide later | open |
| Q24 | Which types require `prefix` and/or `base_unit`? | By type-node rules / flags / convention | **quantity** (Größe) uses unit group (prefix + base_unit); scalars like text/textarea do not | open |
| Q25 | How are Units represented? | Separate Unit / single unit Node / prefix+base | **Decided (Q51/Q75):** Basiseinheit unit = **`set`**; members via **`composition`** (Typ + optional Praefix + Kuerzel). Präfix from Definition tree; display Praefix+Kuerzel. | decided |
| Q26 | Must type/prefix/base_unit Nodes be children of Type/Präfix/Basiseinheit? | Strict branch check / any node | **Decided (reconcile Q77/Q92, revised 2026-08-07):** Catalog simples/units live under Type / Präfix / Basiseinheit anchors. **Type chooser** = **nodes** scoped by **`chooser_root` / bindings (Q92)** — **not** gated by `_wtt_is_datatype`. Präfix / Basiseinheit only under their anchors — never under Compositionen or Bauteile. Select nodes by **id** (not name). | decided |
| Q27 | How are type-Nodes organized? | Dedicated type tree in a project / flat list / convention | Example: Definition → Type | open |
| Q28 | Is a quantity unit prefix+base or one node (kOhm)? | prefix+base / single node | **Decided direction:** **prefix + base_unit** (e.g. k + Ohm) | decided |
| Q29 | Can prefix exist without base_unit (or vice versa)? | Both required together / either alone / type-dependent | Type-dependent leaning | open |
| Q30 | How are template trees applied to project-specific trees? | Deep copy / link / copy-on-write | Related to **Q50** (seed defaults via template-project copy) | open |
| Q31 | Does `Node.template` apply only to the root or inherit to descendants? | Root only / inherit | Root flag leaning | open |
| Q32 | Is the Definition tree itself a template? | Always template / never / optional | May be part of a **template Project** that is copied (Q50) rather than a separate “Definition template” flag alone | open |
| Q33 | How are named attributes (e.g. Wert on Widerstände) modeled? | Parameter class on Node / other | **Superseded (Q64):** **typed child Nodes** (Eigenschaften) with `type_id` → Typ-Ast. No Parameter class. | superseded |
| Q34 | How are special node *behaviors* encoded (not “what type am I”)? | PHP subclass / hard `kind` flag / **configuration** + Relations | **Plain (not type binding):** Q34 is **not** “is this an int?” — that is Q48 / `type_id` / Q88. Q34 asks: when a **normal Node** needs *extra rules* (capabilities, allowlists, footer ops, …), do we invent a PHP subclass, a hard `kind` enum, or a **config bag** on the same Node? **Example:** catalog leaf `int` is still one Node in the tree; we may want “simples cannot originate Relations” (Q49). Options: `class SimpleNode extends Node` vs `kind=simple` vs `config.capabilities.originate_relations = false`. **Strong lean: configuration** (+ Relations where links are needed); no PHP Node subclass per behavior. Slot/catalog typing stays `type_id` / `has_type` (Q48). Still open pending user confirm (pairs with Q49). | open |
| Q35 | Do node–node links need typed edges with properties? | Plain parent/child only / kinds / full Relation + RelationType | Exploring RelationType pairs + display/inherit | open |
| Q36 | What is the core Type catalog? | Fixed list vs extensible | **Parked / superseded for Collection kinds by Q90 (2026-08-06).** Core catalog direction: **simples** + **quantity** / units + hierarchy (Q88) + attributes (Q87). Collection kinds `list` / `table` / `enum` are **not** active product types. Scaffold may still seed Complex leaves until a removal slice. | superseded |
| Q38 | Are single/multiple enum variants types or selection methods? | enum_single+enum_multiple types / one enum + selection_mode | **Parked with Q90** — catalog `enum` out of product direction; closed values via hierarchy / Festwerte / attributes instead. | superseded |
| Q37 | For `quantity`, is the numeric part `double`, `int`, or choosable per param? | Always double / always int / per-param `numeric_kind` | Per-param leaning | open |
| Q39 | Which scalar may enum option values use? | string only / any scalar / configurable | **Parked with Q90** — catalog enum out; reopen only if enum is revived. | deferred |
| Q40 | Parked: further **Node** idea from planning session | Resume when user returns to it | User asked to park mid-thought (“Knoten im Kopf”) — details TBD on resume | parked |
| Q41 | Bidirectional relations / inverse typing? | Separate inverse type / inverse field / reverse as view of same edge | **Leaning: no `inverse` field; one `label` per RelationType; reverse = view** | open |
| Q42 | How should related nodes be displayed per RelationType? | Always as tree children / type-specific (part-of as attributes, is_a as taxonomy, …) | **Leaning: part-of → attributes of parent**; is_a → taxonomy; uses → refs (`DisplayHint`) | open |
| Q43 | Can `consists_of` attributes be inherited along `is_a`? | No / copy / live inherit / merge+override | **Leaning: yes, inheritable**; mechanics TBD (related Q30) | open |
| Q44 | Does RelationType need **`directed`** (arrow vs line)? | Always directed / optional flag / derive from DisplayHint / drop | Tentative: directed → arrow `from→to`, else line; may overlap `bidirectional` — user unsure | open |
| Q45 | How is a quantity (Größe) bound when value sits on a Relation? | props `{value, prefix, unit}` / value on edge + unit **group** (prefix+unit) / Node only | **Leaning: Präfix+Einheit = group**; value often on edge; no loose value→prefix→unit chain | open |
| Q51 | How do Basiseinheit and Präfix relate, and where is the scale factor (×1000)? | Unit─[allows_prefix]→Präfix + multiplikator Relation / config on Präfix / factor only on allows_prefix edge | **Decided:** allowlist; multiplikator on Präfix (same SI exponents). **to_si** = Typ × multiplikator × `prefix_root_to_si`. Mass: SI base = **kg**, prefix root = **g** (`prefix_root_to_si=1e-3`). Scaffold: unit set = **Typ** + Praefix? + Kuerzel; metas `_wtt_multiplikator`, `_wtt_prefix_root_to_si`. | decided |
| Q46 | Are domain structures (BOM, Recipe, …) hard classes or configurable Nodes? | Always host PHP classes / schema-as-Nodes templates / hybrid DTOs | **Strong lean with Q56:** one **Composition** / UX **Zusammenstellung**; no BomList/Recipe/Build core classes | open |
| Q47 | Where do value-shape rules live (e.g. BOM **Referenz** / RefDes)? | Validator meta on the schema Node / Type (+ optional constraints) / slot payload / host-only | **UR (2026-08-07):** **UX** = compact notation (`R1`, `R1,R4,R6`, `R1-R5`, `R1-R5, R8`); **canonical store** = expanded **position list** `string[]` (e.g. `["R1"…"R5","R8"]`) for uniqueness + interactive BOM (highlight placements on board when selecting a line/part). Menge ↔ `len(positions)` (Q58). Type owns converter + validators (same pattern as `int` scaffold). Details: [`MODEL-CATALOG.md`](MODEL-CATALOG.md) § UR — Referenz. Status still open on exact type-node home. | open |
| Q48 | How are scalar data types configured and bound to slots? | Hardcoded-only catalog / **Nodes under Typ-Ast** + slot `type_id` / binding-only keys | **Plain:** Where do `int`/`text`/… live, and how does a slot say “I am an int”? **Agreed lean:** types = Typ-Ast **Nodes**; slots use **`type_id`**. **Challenge (2026-08-07):** ABC is too coarse if applied to *all* catalog kinds — split **render-only** vs **settings-bearing** vs **attribute/composed** (see detail below). Flat **C** still fine for *builtin widget keys*; settings/attrs need separate SoT. Still open pending user confirm of refined split. | open |
| Q49 | May simple data-type Nodes originate Relations, or must that be blocked? | Special Node kind that cannot build Relations / same Node + **config** that disables Relations from simples / allow Relations | **Strong lean (with Q34):** same Node + **config** `capabilities.originate_relations = false` on simples (not a hard special kind); decide with Q34 | open |
| Q50 | Where do default Nodes come from (Definitionsbaum anchors, fixed simples, …)? | **Generate** on Project create / **copy from a template Project** / hybrid | **Leaning: template Project** holds **simples** + **quantity** / units (Q90: not Collection enum/list/table); copy into new Projects (generate = fallback). Relates to Q30, Q32 | open |
| Q52 | How do **list** / **table** / **enum** relate (Collection model)? | Separate types / **Collection** super-kind / enum stays apart | **Superseded by Q90 (2026-08-06).** Prior decision (Collection → list\|table\|enum) is **parked** — not active product direction. Scaffold leftovers may remain until explicit removal. | superseded |
| Q53 | How is Collection **kind** bound for a concrete type (e.g. `my_list`, `Bauart`)? | Parent under list/table/enum / Relation `has_type` → kind / XOR / other | **Parked with Q90** — Collection kinds out of product direction; reopen only if Q90 is reversed. | deferred |
| Q54 | How do tree hierarchy and Relations relate? | Semantic `parent_id` / org-only tree / hierarchy as Relation / hybrid | **Decided (2026-08-02; clarified 2026-08-06):** Hierarchy = protected Relation **`child_of`**. **`child_of` = inheritance / specialization only** (Q86/Q88 datatype = father; Q66 slot inherit along chain). **Not** for attributes — those use `besteht_aus` / `aggregation` (Q87). No dual writable `parent_id` SoT; WP `term_parent` may implement hierarchy edges. Other Relations additive. | decided |
| Q55 | How are catalog properties defined and inherited (e.g. Wert + Bauform on Widerstand)? | Typed child Nodes / only Relations `consists_of` | **Decided (Q64 superseded + Q66):** Eigenschaften = **typed child Nodes**. Descendants **inherit** slot defs along **`child_of`**. Instances fill values (Q16/Q63). E.g. Widerstand → Wert (`quantity`), Bauform (`Bauart` enum). | decided |
| Q56 | What is a Composition vs a catalog Bauteil? | GPU/Widerstand are Compositions / **only lists are Compositions** / host-only | **Decided lean:** **Bauteil** = catalog part; **Composition** = Zusammenstellung (columns+rows). Bauteile only via column type **`node_embed`** (ex-`subtree`, Q72) + `ref_scope` (UX: Bauteil-Ref / „Bauteil Wahl“). Naming: UX Zusammenstellung / internal Composition. | decided |
| Q57 | Does a BOM / Composition have a Fußzeile (footer)? | No footer / host-only footer / **Composition has Fußzeile** | **Decided (refined):** Optional **Fuss** band on a `table`-typed node. Same field count as Zeile. Per Fuss **slot** meta `_wtt_footer_op` (`FooterAggOp`): `none` \| `text` \| `sum` \| `avg` \| `min` \| `max` \| `count`. Column type stays the Zeile value type (e.g. Menge=`int`); op is chosen on the Fuss field. Catalog: `Definition/Aggregate`. Defaults: `sum` for int/double, else `text`. Scaffold ≈ **0.0.192**. | decided |
| Q58 | How is BOM **Menge** expressed? | `quantity` with unit / free text / **Stück as `int`** | **Decided:** BOM Menge = **Stück** (piece count) via type **`int`** — not `quantity` (Größe). Display unit label „Stück“. Rezept-Menge may still be `quantity`. | decided |
| Q59 | Where is the Project **Startknoten** set? | Hardcoded root / first root_node / **Setup default** | **Decided:** **Startknoten** is set by default in **Project Setup** (`Project.start_node`). UI opens/focuses that node; may point at project root or a chosen branch (e.g. Typen, Compositionen). | decided |
| Q60 | Can a BOM restrict which types / Basiseinheiten are usable? | Always all project types / host filter / **per-Composition allowlists** | **Decided:** on a BOM/Composition one chooses **zulässige Typen** (subset under Type branch) and **zulässige Basiseinheiten** (subset under Basiseinheit). Empty allowlist = all under the project anchor. Same pattern for both. | decided |
| Q61 | How is a BOM named / titled? | Tree Node.name = project / **tree = structure name BOM**; project name at page fill | **Decided (refined — BOM composition):** Tree structure node stays **`BOM`**. **BOM** = `composition` of **Name** (text) + **Tabelle** (`type=table`). Name is **not** part of the `table` datatype. Instance Name filled on WP page/block. Title: **`BOM als Bauteilliste – {Name}`**. | decided |
| Q62 | How will frontend/editor expose a BOM table? | Hardcoded BOM UI / host-only / **Gutenberg block** | **Decided (direction):** WP **block** on a page: (1) pick table art from Nodes under **Collection**, (2) fill **composition-scoped** attrs (e.g. **Name**), (3) add rows keyed by **row-scoped** columns. Tree = definition only. | decided |
| Q63 | What lives in the **tree (definition)** vs **WP page editor (instance values)**? | Everything in tree / everything in host / **split** | **Decided (refined Fallstudie):** **Tree** = definition — structure, typed property children, table bands via **`_wtt_prop_bindings`**, Fuss `_wtt_footer_op`, allowlists; legacy `slot_scope` where still used. **WP page/block** = **instance values** (Name, rows, cells). | decided |
| Q64 | What is a **Parameter**? | Tree Node / vocab only / class with name+type | **Superseded (2026-08-02):** Parameter class **discarded**. Use typed child Nodes (Eigenschaften). See Q33 / Q66. | superseded |
| Q65 | How are files / images / links modeled as types? | Separate `url` / `file` / `image` / one **`media`** | **Decided:** one simple **`media`**. Value **MediaRef**: Library (`attachment_id`), URL-only (`url`), or **mirror** (`url` + `attachment_id`). Config: `allow_upload` (default on), `allow_url` (opt-in), **`allow_url_mirror`** (opt-in, default off). **`allowed_kinds`** (image…link): default **all off** — enable ≥1 kind. Display via MIME. No separate `image`/`file`/`url` types. | decided |
| Q66 | How do property definitions inherit along the tree? | No inherit / copy on create / **live inherit along parent_id** / merge+override | **Decided lean:** **live inherit** of slot definitions (children / type_id / required / fixed / `slot_scope`) along the **`child_of` chain** (derived parent/ancestors — Q54). Override/hide rules still open. Axis = hierarchy Relations (`is_a` later, Q43). | decided |
| Q67 | When should a mirrored remote media file be re-fetched? | Once on save only / manual refresh / scheduled / on each read | **Open** — lock with Q65 mirror. Leaning: once on save + optional manual refresh; never silent re-fetch on every read. | open |
| Q68 | How do host plugins reuse / override MediaRef display (e.g. URL + Ampel)? | Rely on WP core filters only / WTT filter `wtt_render_media_ref` / always WTT chrome / hybrid | **Open — defer.** Goal: other plugins define richer URL/attachment chrome; WTT should pick it up. Today WTT uses custom `WTTMediaRender` (not auto). Resolve later with extension contract vs WP-standard hooks. | open |
| Q69 | How to handle Collection schema drift when page instance rows already exist? | Hard delete columns / soft-delete tombstone until force / block taxonomy edits | **Open — deferred.** Lean: **add column OK**; rename/retype risky; **remove** → keep orphan cell data, soft-hide in UI; full tombstone + force-purge later. Scaffold block (slice 2) soft-hides orphans only. | open |
| Q70 | How to separate Composition-wide properties (Name, Portionen, …) from table **columns** when both are child property slots? | Inherited-only = header / column folder Node / Relations for columns / **`slot_scope`** | **Decided (refined — bands + bindings):** **BOM** = Name + Tabelle via `composition` (Q61). Table columns = fields of the **bound Zeile** child. Band identity = **`_wtt_prop_bindings`** (type prop → child id), **not** the child’s display name. Optional Kopf/Fuss same field count. Legacy **`slot_scope`** where still used. Scaffold ≈ **0.0.181**. | decided |
| Q71 | Do settings on a data-type Node do anything for slots? | Ignore / live bind / **copy-on-assign presets** | **Decided:** Type-node settings = **slot presets**. On type assign/change, **snapshot** copy onto the slot (required, fixed, `ref_scope`, footer/set/media kinds, …). Later type edits do not update existing slots. | decided |
| Q72 | What is `subtree` vs pick-and-fill? | Keep `subtree` / rename / split modes | **Decided:** Rename to **`node_embed`**: scoped pick (`ref_scope`, direct children) + **embed target fields**. **`node_ref`**: scoped descendants, **id only**. Alias `subtree` → `node_embed`. | decided |
| Q73 | Shared settings for `node_embed` / `node_ref`; which catalog children are allowed? | Duplicate settings / **parent type `node_pick`** + allowlist | **Decided:** Typ-Ast **`node_pick`** parent under Complex; children **`node_embed`** / **`node_ref`**. Shared: **`ref_scope`** + **`allowed_ref_ids`** (direct children of catalog root; **empty = all**). Embed pick = allowlisted children; ref pick = those children + their descendants (or all descendants if empty). | decided |
| Q74 | How do admins create/delete non-hierarchy Relations (esp. **`composition`**) on a Node? | Only seed edges / custom UI per type / **reusable Relation picker** | **Decided (direction):** Reusable **Relation picker** (everywhere): (1) choose **RelationType** from Relationstypen-Ast, (2) choose target **Node** via tree picker. Modes **inline / popup** (default **inline**). Node detail: add/remove/duplicate/reorder outgoing Relations (not `child_of` — that stays reparent). Child Nodes **inherit** composition membership **definitions** along `child_of` where applicable (display/merge rules with Q66). **Scaffold ≈ 0.0.140:** `_wtt_relations` + edge ids + Add/Remove/Duplicate/Move UI. | decided |
| Q75 | What are **`set`** members — tree children or Relations? | Keep children (Q51 scaffold) / **`composition` Relations** | **Decided (refine Q51):** A Node typed **`set`** takes its **members from outgoing `composition` Relations** (targets), **not** from hierarchy children. Hierarchy (`child_of`) stays taxonomy/folder structure. Unit sets (Meter…): Typ / Praefix? / Kuerzel become **composition targets**. Preview/display still composes member values. **Scaffold ≈ 0.0.140:** members from composition; auto-migrate from children when no composition edges yet. | decided |
| Q76 | Does a Node’s **data type** inherit down the tree? | Never / always copy / **live inherit + override** / **superseded by Q88 for hierarchy** | **Superseded for hierarchy datatype by Q88 (2026-08-06).** Catalog-type **inheriting (vererbend)** + child **override** remains **scaffold interim** for Typ-Ast / assigned catalog types (e.g. `set` chains) where still wired. Product model for hierarchy nodes: datatype = **parent** only — no free override to a catalog type. Orthogonal to **Q66** slot-definition inherit. Exception `table never inherits` was a Q76 scaffold rule; hierarchy datatype under Q88 is always the parent Node. | superseded |
| Q77 | What is the **type chooser**; can a Typ-Ast Node also have a type? | Flat select / **tree type chooser** + **`is_datatype`** + **`is_abstract`** | **Revised (2026-08-07):** **Type chooser = nodes** (tree), scoped by **`chooser_root` / `chooser_focus` / catalog bindings (Q92)** — **not** filtered by `_wtt_is_datatype`. Primary use: attribute / catalog field types. Flag **`is_abstract`** remains **local only** (folders appear, not selectable). Hierarchy type = parent (**Q88**); free `set_type` dropped from admin (root seed-only). **`_wtt_is_datatype`**: former chooser gate — **debt**; remaining jobs listed under Architecture / plan decision log. Scaffold still reads the flag in places. | decided |
| Q78 | Does a Relation need **multiplicity** (cardinality) in the definition tree? | None / on RelationType only / **on each Relation edge** | **Decided:** On each stored **Relation** edge (definition). Values: **`0..1`**, **`1`**, **`0..*`**, **`1..*`** (lower 0 or 1; upper 1 or *). Default **`0..*`**. **`child_of` always `1`** (exactly one parent; locked in UI / forced on write / repaired on read). `has_type` / `ref_scope` stay **`0..1`**. Not instance count — definition constraint. Distinct from Präfix **multiplikator**. **Scaffold ≈ 0.0.247.** | decided |
| Q79 | Are Node **names** unique? | Taxonomy-wide / siblings only / ID only / special rule for datatypes | **Revised (2026-08-07):** Identity / selection SoT = **`term_id` (ID)** always — **never select nodes by name**. Exceptions: **named config bindings** (constants → ids in `wtt_catalog_bindings` / similar). Instance names may collide across parents. Former rule “datatype display names unique taxonomy-wide” is **not** selection SoT (scaffold may still enforce — **debt** to drop or keep as optional UX only). WP sibling-name rules still apply. | decided |
| Q80 | How do validation **rules** relate to **bindings** and repairs? | Errors only / auto-mutate / **rules + optional fixes** | **Decided (direction):** **Bindings** (e.g. `_wtt_prop_bindings`) have **rules**. On failure: **0..n optional Fixes** (user-triggered; not mandatory). Example: Fuss count ≠ Zeile → “Create missing fields”. When adding a rule, decide/ask which fixes exist. Scaffold ≈ **0.0.188** (`fixes[]` + `wtt_fix_table_band_fields`). Rule: `.cursor/rules/bindings-rules-fixes.mdc`. | decided |
| Q81 | Must Kopf / Zeile / Fuss bindings point to **distinct** children? | Allow reuse / **unique per table** | **Deferred (UAT):** Today the same direct child can be bound to Zeile and Kopf. Unique bindings (Zeile ≠ Kopf ≠ Fuss) are a candidate rule + picker filter / unbind fix — implement only if UAT shows confusion. | deferred |
| Q82 | How are Fuss labels (“Summe”, “Gesamtpreis”) and computed aggregates made non-editable? | New type `label` / `text` + `editable` flag / **`footer_op` + existing `fixed`** | **Strong lean:** No new `label` type and no extra `editable` flag. (1) Aggregate ops (`sum`/`avg`/…) → **always read-only** (computed). (2) Static footer text → Fuss slot `footer_op=text` (or `none`) + **`fixed` literal** (e.g. “Summe”, “Gesamtpreis”) — same non-editable rule as fixed Zeile slots. (3) Editable Fuss text only if `text` and **not** fixed (rare). Example: Ref→fixed “Summe”; Menge→`sum`; Preis→`sum`; Kommentar→fixed “Gesamtpreis”. | open |
| Q83 | Bauteile: category/schema vs master data in one tree? | DigiKey-style mix / split Definition vs Implementation / **Model kinds + Implementation MPNs** | **Revised (2026-08-07):** **Kinds under Model/Bauteil** (schema nodes, address by **id** / bindings — not by name; do not rely on `_wtt_is_datatype` as chooser gate). **MPN records under Implementation/Bauteile** (`type_id` → kind id). No Lieferant / Bestellnummer / Hersteller on kinds. Dioden Arten = hierarchy under Model/Bauteil/Dioden (CatalogChoice). BOM `node_embed` → Bauteile. Scaffold ≈ **0.0.304**. | decided |
| Q84 | How should `node_ref` pick / create catalog targets in preview cells? | Select + checkbox wall / full tree picker / **catalog chooser** (+ mini-form create) | **Decided:** Catalog chooser (chips + Choose/Change). Presentation follows **`treePickerMode`** (`popup`/`inline`, default popup). List = `nodeRefOptions` only (not full taxonomy tree). Create = mini-form (Name + scalar slots) via `wtt_create_node_ref_target` under `ref_scope`. No detail-panel jump mid-edit. Scaffold ≈ **0.0.225**. | decided |
| Q85 | Primary mental model: relations/table-grid vs object composition? | Relations-CRUD + Collection-table DB feel / **composition-first objects** | **Decided (2026-08-05, refined):** Objects + **`besteht_aus`**. Composition ≈ **class**; each member ≈ **attribute = Name + Typ** (`node.name` + `type_id`/`has_type`). Platine/`BOM` examples unchanged. Table UI = view only. RelationType renamed **`besteht_aus`** (alias `composition`). Rule: `.cursor/rules/composition-first.mdc`. Plan **0.7.21**. | decided |
| Q86 | How does inheritance relate to **`child_of`**? | Separate `erbt_von` / only along `child_of` / both | **Decided (2026-08-06):** Inheritance = **`child_of` hierarchy only** (Q66 slots / Q88 hierarchy datatype). RelationType **`erbt_von` dropped**. Tree parent = single inheritance path. Plan **0.7.23**. | decided |
| Q87 | How are **Attributes** on a Node modeled (Name + Typ + Mult.)? | Typed hierarchy children / Relation props / **`besteht_aus` members** | **Decided lean (2026-08-06):** Attribute = Name + Typ (`type_id` → catalog) + Mult. via **`besteht_aus` \| `aggregation` only**. **Never `child_of` to the host** (`child_of` = inheritance only, Q54/Q86). Slot terms marked `_wtt_attribute_slot`; not shown as tree children under the host. Inherit attribute **definitions** along host `child_of` (Q66). Scaffold ≈ **0.0.254**. | decided |
| Q88 | What is the **data type of a hierarchy child**? | Own free type / inherit parent's catalog type_id (Q76) / **type_id = parent node** | **Decided (2026-08-06; clarified 2026-08-07):** **Everything via hierarchy.** Root is a node (Fallstudie). **Except on the root, `has_type` / effective type is always the father** (WP parent / `child_of`). **No Data type field/picker** in hierarchy node detail. Persist `type_id`=parent on create/reparent/repair; reads prefer derive-from-parent. **Free `set_type` dropped from admin** (including root). Root `type_id` → **Knoten** is **seed-only** (write by id in ensure/seed; Relations/settings ignore free assign). Do **not** promote parents with `_wtt_is_datatype` for parent-as-type. Attribute members keep own catalog type (Q87). Scaffold ≈ **`0.0.330`**. | decided |
| Q89 | How does **node delete** work? | Hard delete / promote children / **soft-delete Trash** | **Decided (2026-08-06):** Soft-delete via Trash. **Cascade** = mark node + descendants `_wtt_trashed` (keep parent links among them). **Promote** = reparent direct children to grandparent (`term_parent` / `child_of`), then trash the node only (UI “delete node only”; restored ≈ **0.0.297**). Special **Trash** node lists deleted roots (`_wtt_trash_item_ids` JSON); Empty Trash = permanent `wp_delete_term`. Scaffold soft-delete ≈ **0.0.239**. | decided |
| Q90 | Are Complex catalog kinds **enum** / **list** / **table** still in product? | Keep Collection model (Q52) / remove / **park** | **Decided (2026-08-06):** **Parked — out of product direction for now.** **enum** fully out: closed value sets via **hierarchy inheritance** (Q88) + attributes / Festwerte (Q87), not a Collection `enum` kind. **list** and **table** not needed (YAGNI). Scaffold may still contain Complex leaves + Enum UI + collection-table block until an explicit removal slice. Agents must **warn** before reusing these types (rule: `.cursor/rules/parked-complex-types.mdc`). Plan **0.7.26**. **CatalogChoice UI (confirmed 2026-08-06, Preis/Währung):** When an attribute’s **type node has specialization children** (hierarchy under the type — not catalog `enum`): compute **max depth** of the type’s choice subtree (direct kids only → depth `1`; any grandchild → depth `≥ 2`). **Depth ≤ 1** → flat `<select>` / simple leaf list; **depth ≥ 2** → **tree chooser** (existing node tree picker). Default/Festwert seeds the selected value when present. Scope = **typed choice under a type host** (e.g. Währung → Euro/Dollar) — not every node picker in the product (deep taxonomy / model-binding may still prefer tree). See `docs/ARCHITECTURE.md` (CatalogChoice). Plan **0.7.29**. | decided |
| Q91 | Does a node-only domain imply **one** renderer? | Single paint path / **Registry + many type renderers** | **Decided (2026-08-06):** **No.** Domain objects are **nodes** (Q90 parks Collection kinds), but presentation stays **one Registry pipeline with many type-specific renderers** (simples `int`/`text`/… now; more when a type needs custom chrome). Contexts (`tree` / `form` / `table` / …) still differ. **Form(1 instance) / Table(n instances)** are presentation surfaces over a schema + values (`WTTObjectRender`) — not catalog `table`. Q90 does **not** collapse renderers into one class. Rule: `.cursor/rules/node-renderers.mdc`. Plan **0.7.27**. | decided |
| Q92 | How to address template catalog folders (Data Types / Simple) across installs if names change? | Hard-coded names / slugs / **config bindings by term id** | **Decided (2026-08-06; strengthened 2026-08-07; #6 closed):** Option **`wtt_catalog_bindings`** per taxonomy — **named config keys → term ids**. Attribute type picker: **`chooser_root`** (branch) + **`chooser_focus`**. Resolve **by id only**. **Never select nodes by name** in product logic; name lookup as runtime fallback is **debt to remove** (seed/migrate may still use names once to write bindings). Same rule for any special branch/leaf the product needs (quantity, legacy table, Model/Bauteil, …) — put the id in settings/bindings, do not re-find by name. Legacy helper keys `data_types` / `simple` / `complex` kept until cleaned. Scaffold ≈ **0.0.264** (`Catalog_Bindings`). | decided |
| Q93 | When CatalogChoice type host and/or selected child have attributes, what is stored? | Selected **node id** only / id + **instance values** on host attrs / on child attrs / on **both** (pick + fill) | **Open (2026-08-06).** UI chrome already decided under Q90 (no Choice object; depth ≤ 1 list / ≥ 2 tree). Value **source of truth** TBD when the type host and/or the chosen specialization child carries its own attributes. Relates to Q16 instance values. See `docs/ARCHITECTURE.md` (CatalogChoice). Plan **0.7.30**. | open |
| Q94 | What is the **data safety** strategy (backup / disaster recovery vs plugin export-import)? | Rely on full site/DB backup / native WXR Tools→Export / plugin JSON export-import / mix | **Open (2026-08-07) — leaning, not decided.** (1) **Primary disaster recovery** = full site/DB backup (+ uploads), **not** a plugin Export button. (2) Native **Tools → Export (WXR)** alone is **insufficient**: standard taxonomy `wtt_fs` unbound to posts; Model_Data in option `wtt_model_instances`; ID-keyed graphs break on WXR remap. (3) Plugin **JSON export/import** = later product for “copy tree between sites” (remap by path/slug); **MVP non-goal** (bulk import/export already out in `mvp-requirements`). (4) Do **not** build admin Export now for “security.” Inventory: almost all plugin data = terms + `_wtt_*` termmeta + options (**no custom tables**). See `docs/ARCHITECTURE.md` (Backup / migrate). Plan **0.7.31**+. | open |

## `_wtt_is_datatype` — remaining jobs (after 2026-08-07)

Earlier scaffold rationale (1–12). User decisions **resolved**: (1) chooser filter = nodes / Q92 scope; (2) free `set_type` **dropped from admin** (root = seed-only); (3) `has_type` except root = father (Q88); (4) select by **id** only (config named bindings → ids OK); (5) catalog lock → **`is_template`**; **(6) leaf / special-node detection → id + settings bindings (2026-08-07)**.

| # | Job | Status |
|---|-----|--------|
| 1 | Chooser filter (`get_datatype_tree` / assignable options) | **Superseded** — chooser = nodes + Q92 |
| 2 | Attribute `add` / `set_type` requires datatype | **Superseded** — free hierarchy/root `set_type` not the model; attrs keep catalog type assign |
| 3 | `has_type` picker same gate | **Superseded** — non-root type = father; root seed-only |
| 4 | Q79 datatype name uniqueness as selection aid | **Superseded** — id SoT; uniqueness = optional UX debt |
| 5 | Seed / catalog **deletable lock** tied to flag | **Resolved → `is_template`** — lock uses `_wtt_is_template` + `_wtt_deletable`; editable only in **Development mode** (`wtt_development_mode`). One-time migrate from former `is_datatype` lock in `lock_seeded_catalog_deletable`. |
| 6 | Table / quantity / catalog-leaf detection via flag (+ name) | **Decided (2026-08-07)** — **never by name**; always by **`term_id`**. Special branches/nodes the product needs (Data Types, Simple, quantity, table legacy, Model/Bauteil, …) live in **settings / catalog bindings** (`wtt_catalog_bindings`, Q92-style named keys → ids). Seed/migrate may resolve names **once** to write those ids; runtime product logic must not re-lookup by name. |
| 7 | Q88 `promote_class_datatype` so parent is assignable | **Superseded** — parent-as-type needs no flag; promote no longer sets `is_datatype` |
| 8 | Renderer `typeKey` fallback from node name when `isDatatype` | **Superseded** — no name selection (Q79/Q92) |
| 9 | Seed / `ensure_datatype_flags` writing the flag | **Debt** — only needed while survivors keep the meta |
| 10 | Admin checkbox “Is data type” | **Debt** — UI can go independently of meta |
| 11 | Flag inherit along parent chain (Q77) | **Debt** — moot if flag removed |
| 12 | Orthogonality: flag = “I am a type role” vs `type_id` = “my type is X” | **Open** — conceptual leftover of `_wtt_is_datatype`; see expanded notes below. Do **not** remove `is_abstract` or `_wtt_is_datatype` in docs-only passes. |

**Scaffold debt:** code still reads/writes `_wtt_is_datatype` in many paths — remove in a focused slice after **#12** is decided (and after #6 code paths migrate fully to bindings); do not large-rewrite in docs-only passes. Catalog lock (#5) and leaf addressing (#6) no longer *conceptually* depend on the flag.

### #12 explained — `is_datatype` vs `type_id` / has_type vs `is_abstract`

Three different jobs got tangled in the old “type role” story:

| Concept | Meta / field | Question it answers | Fallstudie example |
|---------|--------------|---------------------|--------------------|
| **`type_id` / `has_type`** | `_wtt_type_id` (+ Relation mirror) | “**What is my type?**” — this node’s datatype binding | Hierarchy: **Definition** → type = father **Fallstudie** (Q88). Attribute: **Wert** on Widerstand → type = catalog **quantity** / unit node (Q87). |
| **`_wtt_is_datatype`** (debt) | term meta flag | Old “**I am a type role**” — this node may appear as an *assignable catalog type* / participate in datatype forests | Seed sets it on **Simple**, **int**, **media**, **set**, **Knoten**, … Historically gated the type chooser (`get_datatype_tree`). **Product chooser no longer uses it** (Q77/Q92). |
| **`_wtt_is_abstract`** | local term meta (does **not** inherit) | “**Folder / not selectable**” in a chooser — node may show for navigation but must not be picked as a value | **Simple** and **Complex** folders: `is_abstract=true` so the admin can expand them but cannot assign “Simple” as a field type; children **int** / **text** are `is_abstract=false` and selectable. |

**Orthogonal, not synonyms**

- A node can have a **`type_id`** without being a “datatype role.” Example: **Implementation/BOM** has hierarchy type = father; it is *not* something you pick as an attribute’s catalog type.
- A catalog leaf can be a “type role” (historically `is_datatype`) and still have its *own* hierarchy `type_id` = parent folder (e.g. **int** under **Simple** → father Simple / Definition chain per Q88).
- **`is_abstract`** does **not** mean “has no type.” It only means “do not select me in the type chooser.” Abstract folders still have hierarchy datatype = parent.

**What “type role” meant (#12)**

Before Q77/Q92, `_wtt_is_datatype` answered: “Is this node *in the pool of things I can bind as a field type*?” That is **orthogonal** to `type_id` (“what type does *this* node have?”). After #6 + Q92, “pool membership” should come from **which branch/ids settings expose** (e.g. under `chooser_focus` → Data Types), not from a boolean flag. #12 stays open only to confirm we need **no** leftover conceptual flag once chooser scope + bindings cover it — or whether some other marker remains.

**`is_abstract` today — keep until decided**

User suspicion (“maybe we don’t need `is_abstract` either”) is fair but **not decided**. Current jobs:

1. Type chooser: abstract nodes **visible**, **not selectable** (Q77).
2. Seed: folder chrome under Definition/Simple/Complex, Präfixe groups, etc. (`Case_Data` / `Demo_Data`).
3. Admin Flags checkbox + DTO `isAbstract` / `isAbstractLocal`.
4. PRODUCT non-goal: no separate `category` data type — folders use **`is_abstract`** instead.

**If `is_abstract` were dropped**, replace with an explicit rule, e.g.:

| Option | Idea | Risk |
|--------|------|------|
| A | **Leaves only** selectable (has no children) | Breaks selectable parents that intentionally have kids (CatalogChoice hosts, `set` members, deep type trees). |
| B | **Binding / branch rule** only (anything under `chooser_focus` except listed folder ids) | Needs maintained folder-id list in settings — overlaps Q92; doable. |
| C | **Keep `is_abstract`** as the explicit non-selectable marker | Status quo; clear UX; small meta. |

No code removal in this docs pass. Decide #12 (and whether `is_abstract` stays) in a later user turn.

### “Last point” pointer (Q34 / Q48)

If a summary listed open rows after the datatype-slim-down work, the **third** open product row is often **Q34** (with **Q48** / **Q49** nearby): not the same as datatype job **#12**.

#### Q34 — special *behavior*, not type identity

| | |
|--|--|
| **Asks** | How does a Node get *extra rules* beyond being a normal tree node? |
| **Does not ask** | “What is my datatype?” → that is **`type_id` / Q48 / Q88**. |
| **Example** | Node `int` under Simple: still a Node. Rule “simples may not start Relations” (Q49) — encode as PHP subclass, hard `kind`, or **`config.capabilities…`**? |
| **Lean** | **Config bag** on the Node (+ Relations when the link itself is the structure). No `class IntNode`. Confirm with user; decide with Q49. |

#### Q48 — types as Nodes + visible builtin key (ABC challenged against datatypes)

| | |
|--|--|
| **Asks** | Where do scalars / catalog types live, and how does a slot bind to one? |
| **Agreed lean** | Types = **Nodes** in the Typ-Ast hierarchy; slots store **`type_id`** (correct). |
| **Extra ask** | Hardcoded Registry behavior must be **visible** — not guessed from display name. |
| **Scaffold today** | `Case_Data::simple_datatype_leaves` / `complex_datatype_leaves`; Registry in `wtt-node-render.js` (`SIMPLE_SCALAR_KEYS`, `STRUCTURED_TYPE_KEYS`); media via `WTTMediaRender`; object hosts via `WTTObjectRender`. DTO/`typeKey` often = **name** — debt vs Q79/Q92. |

##### Inventory → three families

Sources: seed (`class-case-data.php`), Registry, media/set/quantity/table metas (`Node_Type`), Model hosts (out of “datatype” scope for ABC).

| Family | Members (scaffold) | What differs |
|--------|--------------------|--------------|
| **Render-only** | `int`, `double`, `text`, `textarea`, `char`, `bool`, `email`; borderline `display_node_name` | Same Node shape; **widget/chrome** only (dedicated renderer). **`int` (≈ 0.0.345):** one `IntRenderer` + Converter/validators; Number format UI on type + attribute override; format ids `arabic` (default) / `roman`/`binary`/`octal`/`hex` (reserved converters). |
| **Settings-bearing** | `media` (`allowed_kinds`, upload/url); `date` (`date_mode`); `set` (separator / joinUnits / labelChildren); `quantity` (+ unit allowlists / multiplikator on Basiseinheit); `node_ref` / `node_embed` (`ref_scope`, allowed ids); legacy `table` (prop_bindings zeile/kopf/fuss, footer ops — **Q90 parked**) | Needs **typed config meta** (and sometimes structural bindings) **beyond** “which widget”. One string key is not the settings SoT. |
| **Attribute-bearing / composed** | Unit **set** = Typ+Praefix+Kuerzel (Q51/Q25); CatalogChoice hosts (Währung, Bauformen, Dioden Arten — **hierarchy**, Q90 depth); **Model hosts** (Kontakt, Platine, Bauteil kinds — attributes via Q87) | Fixed **slots / composition / specialization tree**, not a single renderer swap. Model hosts are **schema nodes**, not catalog simples — **out of scope** for “datatype ABC”. |

Parked Complex leaves still seeded (`list` / `enum` / `table`) — do not design ABC around extending them (Q90).

##### ABC vs families

| Option | Render-only | Settings-bearing | Attribute / composed |
|--------|-------------|------------------|----------------------|
| **A** — `implementationKey` meta only | **Works** — Registry dispatch + Meta chip; no name SoT for paint. | **Partial** — key picks renderer (`media`), but **settings stay separate metas**; alone cannot express allowlists/bands. Finding “the media catalog node” across installs without bindings → name debt unless every consumer already has `type_id`. | **Wrong tool** — composition/attrs are not an implementation key. |
| **B** — catalog bindings only (`builtin.text` → id) | **Awkward** — slot has `type_id`; paint needs key via **reverse** binding lookup (id→key) or hidden name. Visible “Builtin: text” needs that reverse map. | **Wrong layer** — bindings address **anchors**, not `allowed_kinds` / separators. Table `prop_bindings` are a *different* binding kind (instance structure), not `wtt_catalog_bindings`. | Anchors (Model, Data Types) already **Q92** — orthogonal to type widget identity. |
| **C** — binding + `implementationKey` | **Strong** for builtins — id SoT (Q92) + visible key + fast Registry resolve. Dual-write sync is the cost. | **Still incomplete** — C only answers “which builtin”; settings need **NodeConfig / type meta** regardless. Applying C as *the* answer for media/set/quantity **overclaims**. | Same as A/B: use composition + `type_id` + Q92 for hosts; not C. |

**Verdict:** Flat A/B/C is **too coarse**. C (or A + sparse `builtin.*` bindings) fits **render-only**. Settings-bearing need **implementationKey (renderer) + structured config SoT**. Attribute/composed need **edges / children**, not ABC.

##### Refined model (proposed — confirm with user)

```text
Slot ──type_id──► Type Node
                     │
                     ├─ implementationKey?  → Registry renderer (render-only + which chrome for settings types)
                     ├─ NodeConfig / type metas → media kinds, set separators, date_mode, ref_scope, …
                     ├─ composition / attributes (Q87) → set members, quantity usage, Model hosts
                     └─ catalog bindings (Q92) → anchors + optional builtin.* leaf ids (seed/migrate/product find)
```

| Layer | Job | SoT |
|-------|-----|-----|
| `type_id` on slot | “I am this type node” | Term meta / has_type |
| `implementationKey` | “Paint with this builtin renderer” | Term meta on **type** node (Dev-mode chip) |
| Type / NodeConfig metas | Settings-bearing extras | Existing `_wtt_*` (media, set, date, …) — do **not** encode as binding keys |
| Composition / attributes | Fixed slots / trinity / hosts | Children + `besteht_aus` / aggregation |
| `wtt_catalog_bindings` | Rename-safe **anchors** (+ optional `builtin.text` → id) | Option map key → term_id |
| Instance `prop_bindings` | Structural anchors inside one typed node (legacy table bands) | `_wtt_prop_bindings` — Rules/Fixes; not catalog ABC |

**Recommended lean (refined):** For **render-only builtins**, keep **C** (or A first, add `builtin.*` bindings when product must resolve leaves without a slot `type_id`). Do **not** pretend C replaces media/set/quantity config or Model attribute schemas.

##### Still open (user)

1. Confirm **split model** vs flat C-for-everything?
2. Ship **A first** (chip + Registry), add `builtin.*` bindings only where seed/tooling needs leaf ids?
3. Meta key name: `_wtt_implementation_key` vs reuse?
4. Abstract folders (`Simple`, `node_pick`): empty key?
5. Is **`date`** render-only + tiny `date_mode`, or full settings family?
6. Is **`quantity`** a builtin key, or always “composed object” (Preis-shaped) with no scalar renderer key?
7. Dual-write policy if C: binding wins on conflict / Dev-mode only edits key?

- **Q49** pairs with Q34 (may simples originate Relations?).

Datatype job **#12** is the orthogonality leftover above — not Q34/Q48.

## How to close a question

1. Record the choice in this table (`Status: decided` or `deferred`).
2. Add a dated entry to the project plan decision log.
3. Update `docs/ARCHITECTURE.md` / `docs/PRODUCT.md` / `docs/ROADMAP.md` if the answer changes scope or shape.
4. Do **not** implement code as part of closing the question while planning mode is active.
