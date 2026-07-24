# Open questions

> Resolve or defer during planning. Keep aligned with [`docs/plans/project-plan.md`](plans/project-plan.md) and [`docs/plans/planning-phase.md`](plans/planning-phase.md).

**Mode:** planning only — answers here become decision-log entries; they do not trigger implementation by themselves.

| ID | Question | Options | Current leaning | Status |
|----|----------|---------|-----------------|--------|
| Q1 | How should the admin UI talk to WordPress? | REST API / Admin-AJAX / both | REST if straightforward; Admin-AJAX OK for MVP | open |
| Q2 | Which JS approach for the tree UI in MVP? | Vanilla JS / `@wordpress/scripts` + React | Vanilla for MVP | open |
| Q3 | One tree screen for many taxonomies, or one screen per taxonomy? | Switcher on one screen / submenu per taxonomy | Switcher or filter-registered screens | open |
| Q4 | Should activating the plugin replace core term list screens by default? | Opt-in per taxonomy / replace when registered / never replace | Opt-in when a taxonomy is registered with the environment | open |
| Q5 | Exact PHP namespace and prefix? | e.g. `WTT\` / `wtt_` | TBD | open |
| Q6 | Minimum supported WordPress / PHP versions? | WP 6.x + PHP 8.x targets | PHP 8.x; modern WP — pin exact numbers at sign-off | open |
| Q7 | Is rename/reparent in-tree required for MVP? | Yes rename only / yes rename+reparent / later | Rename likely MVP; reparent maybe later | open |
| Q8 | Placeholder right-hand panel in MVP? | Yes empty/host slot / tree-only until Phase 2 | Host slot preferred so electronic-parts can attach later | open |
| Q9 | When to integrate with `wp-electronic-parts`? | After MVP / after Phase 2 / never in-repo | After extension contract exists (Phase 2+) | open |
| Q10 | Packaging for reusable code? | Single plugin only / plugin + Composer package | Single plugin first | open |
| Q11 | How is Node stored? | Map 1:1 to WP terms / custom node table / hybrid | Map 1:1 to hierarchical WP terms | open |
| Q12 | Which optional Node fields are in MVP? | slug / description / count / position / meta | **description** required on every Node (may be empty); slug + count likely; **position** strongly needed if BOM/Recipe lines are Nodes (Q13/Q46) | open |
| Q13 | How are siblings ordered? | WP default name/term order / explicit position field | **Leaning: explicit `position` (or Relation order)** — BOM/Recipe line display needs stable sequence, not name sort | open |
| Q14 | Is a parameter always assigned to exactly one node? | Always one owning node / can be shared / taxonomy-level / other | **Dropped (entfällt):** Parameter *is* a Node (Q33); placement via `parent_id` and/or Relations — no separate `node_id` | decided |
| Q15 | Where are Parameters stored? | Term meta / custom table / host plugin storage | **Same as Nodes (Q11)** — Parameter is a Node; no separate Parameter store | open |
| Q16 | Are parameter *values* (filled data) part of this plugin? | Yes in-core / host plugins only / later phase | Leaning: **quantity** (Größe) needs a filled **value** beside prefix + unit (e.g. `10 mm`); storage owner TBD | open |
| Q17 | How does a Project get its trees (root nodes)? | Nodes carry `project_id` / project stores root ids / other | **Decided (domain model):** Project has `root_nodes` (list of Node). Persistence details still Q19. | decided |
| Q18 | How does Project relate to WordPress taxonomies? | One project = one taxonomy / project independent of taxonomy / hybrid | **Leaning strong: Project ≈ taxonomy** (practically the same); WP taxonomy slug on Project; Node has no taxonomy field | open |
| Q19 | Where is Project stored? | CPT / custom table / option / taxonomy | TBD — if Project ≈ taxonomy, storage may collapse toward the taxonomy (+ meta) or a thin Project wrapper (Q19) | open |
| Q20 | How are domain objects represented in PHP? | Typed DTO classes / arrays only / WP objects directly / hybrid | **Decided:** typed classes/DTOs for Project, Node, Changelog, Change; **no Parameter class**; services for behavior; no Tree/RootNode class; arrays only at API edges | decided |
| Q21 | What is stored in Change.`change` (the Änderung)? | Plain text summary / structured field diff / both | Text summary first; structured diff optional later | open |
| Q22 | What is Change.`changer` (the Änderer)? | WP user ID / login / display name / Actor value object | WP user ID (+ display resolved in UI) leaning | open |
| Q23 | What format is Change.`version`? | Semver string / integer counter / object version snapshot | Align with plugin versioning where useful; decide later | open |
| Q24 | Which types require `prefix` and/or `base_unit`? | By type-node rules / flags / convention | **quantity** (Größe) uses unit group (prefix + base_unit); scalars like url/string do not | open |
| Q25 | How are Units represented? | Separate Unit / single unit Node / prefix+base | **Decided:** Parameter uses **prefix** + **base_unit** Nodes from Definition tree | decided |
| Q26 | Must type/prefix/base_unit Nodes be children of Type/Präfix/Basiseinheit? | Strict branch check / any node | Leaning: must live under the matching Definition branch | open |
| Q27 | How are type-Nodes organized? | Dedicated type tree in a project / flat list / convention | Example: Definition → Type | open |
| Q28 | Is a quantity unit prefix+base or one node (kOhm)? | prefix+base / single node | **Decided direction:** **prefix + base_unit** (e.g. k + Ohm) | decided |
| Q29 | Can prefix exist without base_unit (or vice versa)? | Both required together / either alone / type-dependent | Type-dependent leaning | open |
| Q30 | How are template trees applied to project-specific trees? | Deep copy / link / copy-on-write | Related to **Q50** (seed defaults via template-project copy) | open |
| Q31 | Does `Node.template` apply only to the root or inherit to descendants? | Root only / inherit | Root flag leaning | open |
| Q32 | Is the Definition tree itself a template? | Always template / never / optional | May be part of a **template Project** that is copied (Q50) rather than a separate “Definition template” flag alone | open |
| Q33 | In the Definitionsbaum (e.g. Widerstände → Wert), are leaves Nodes that *own* Parameters, or are Parameter names themselves tree nodes? | Node + attached Parameter / Parameter-as-node / both | **Decided: no Parameter class.** Attribute names are ordinary tree **Nodes** with type binding; **ParameterRole also dropped** | decided |
| Q34 | How are Node specializations / type bindings modeled? | PHP subclass / `kind` flag / formal ParameterRole / **configuration** + Relations | **Strong lean: configuration** + Relations — no Parameter class, no ParameterRole; proposed shape: `Node.config.capabilities` (+ type via `has_type`); see data-structure | open |
| Q35 | Do node–node links need typed edges with properties? | Plain parent/child only / kinds / full Relation + RelationType | Exploring RelationType pairs + display/inherit | open |
| Q36 | What is the core Type catalog? | Fixed list vs extensible | **Decided (with Q52):** template holds simples + **quantity** + **Collection** (`list` / `table` / `enum`); no separate `string_list` type | decided |
| Q37 | For `quantity`, is the numeric part `double`, `int`, or choosable per param? | Always double / always int / per-param `numeric_kind` | Per-param leaning | open |
| Q38 | Are single/multiple enum variants types or selection methods? | enum_single+enum_multiple types / one enum + selection_mode | **Agreed direction:** one **enum** derived type; single\|multiple = selection method | open |
| Q39 | Which scalar may enum option values use? | string only / any scalar / configurable | **Agreed direction:** exactly one element type via the single column’s `has_type` (Collection spin; was `base_type`) — typically a simple | open |
| Q40 | Parked: further **Node** idea from planning session | Resume when user returns to it | User asked to park mid-thought (“Knoten im Kopf”) — details TBD on resume | parked |
| Q41 | Bidirectional relations / inverse typing? | Separate inverse type / inverse field / reverse as view of same edge | **Leaning: no `inverse` field; one `label` per RelationType; reverse = view** | open |
| Q42 | How should related nodes be displayed per RelationType? | Always as tree children / type-specific (part-of as attributes, is_a as taxonomy, …) | **Leaning: part-of → attributes of parent**; is_a → taxonomy; uses → refs (`DisplayHint`) | open |
| Q43 | Can `consists_of` attributes be inherited along `is_a`? | No / copy / live inherit / merge+override | **Leaning: yes, inheritable**; mechanics TBD (related Q30) | open |
| Q44 | Does RelationType need **`directed`** (arrow vs line)? | Always directed / optional flag / derive from DisplayHint / drop | Tentative: directed → arrow `from→to`, else line; may overlap `bidirectional` — user unsure | open |
| Q45 | How is a quantity (Größe) bound when value sits on a Relation? | props `{value, prefix, unit}` / value on edge + unit **group** (prefix+unit) / Node only | **Leaning: Präfix+Einheit = group**; value often on edge; no loose value→prefix→unit chain | open |
| Q51 | How do Basiseinheit and Präfix relate, and where is the scale factor (×1000)? | Unit─[allows_prefix]→Präfix + multiplikator Relation / config on Präfix / factor only on allows_prefix edge | **Decided:** `allows_prefix` = allowed set (per unit; Farad without k/M); **Präfix ─[multiplikator]→ int** with `props.value`; UI derives Ohm/kOhm/…; forward+back convert via same factor | decided |
| Q46 | Are domain structures (BOM, Recipe, …) hard classes or configurable Nodes? | Always host PHP classes / schema-as-Nodes templates / hybrid DTOs | **Strong lean with Q56:** one **Composition** / UX **Zusammenstellung**; no BomList/Recipe/Build core classes | open |
| Q47 | Where do value-shape rules live (e.g. BOM **Reference** = comma-separated RefDes list `R1,R2` / `C1…Cn`)? | Validator meta on the schema Node / Type (+ optional constraints) / Parameter payload / host-only | **Leaning: not on bare Node** — schema Node = slot; **type/Parameter** owns list-vs-scalar + validation; `,` is serialization | open |
| Q48 | How are scalar data types configured and bound to slots? | Hardcoded catalog / **Nodes under Datentypen/Type** + Relation `has_type` / Parameter.type only | **Aligned with Q33:** simple types = fixed Nodes per project; Parameter/slot ─[has_type]→ type Node; derived/composed types allowed; UI widget from type | open |
| Q49 | May simple data-type Nodes originate Relations, or must that be blocked? | Special Node kind that cannot build Relations / same Node + **config** that disables Relations from simples / allow Relations | **Strong lean (with Q34):** same Node + **config** `capabilities.originate_relations = false` on simples (not a hard special kind); decide with Q34 | open |
| Q50 | Where do default Nodes come from (Definitionsbaum anchors, fixed simples, …)? | **Generate** on Project create / **copy from a template Project** / hybrid | **Leaning: template Project** holds simples + **enum** + **quantity**; copy into new Projects (generate = fallback). Relates to Q30/Q32 | open |
| Q52 | How do **list** / **table** / **enum** relate (Collection model)? | Separate types / **Collection** super-kind (list=1 col, table=n cols, enum=closed list) / enum stays apart | **Decided:** Collection → `list` \| `table` \| `enum`. list ≡ 1-column table; enum created like list (1 typed column + closed option children under that column). Kind binding still **Q53**. | decided |
| Q53 | How is Collection **kind** bound for a concrete type (e.g. `my_list`, `Bauart`)? | Parent under list/table/enum / Relation `has_type` → kind / XOR / other | **Fresh start** under design guidelines (clear structures; named objects; flag perf/nonsense; modern paradigms). Prior TE closed — no carry-over. | open |
| Q54 | How do tree hierarchy and Relations relate? | Semantic `parent_id` / org-only tree / hierarchy as Relation / hybrid | **Strong lean:** `parent_id` tree = **categorize Bestandteile** of domain lists (BOM / Hardware / Rezept) **+ inherit hierarchical properties**. Not Collection schema nesting. Closed TE hybrid still excluded. | open |
| Q55 | How are catalog **Parameters** defined and inherited (e.g. Wert + Bauform on Widerstand)? | Parameter object on Node / Parameter as Node role (Q33) / only Relations `consists_of` | **Spin:** Node **defines** Parameters (name + type); children **inherit** definitions; leaves **fill** values. Bauform lean: Parameter typed as enum `Bauart`, concrete leaf fills Wert+Bauform. Simple + composed types from template. | open |
| Q56 | One concept for GPU cards, BOM, builds, cooking recipes? | Separate classes / **Composition** (params and/or member refs) / host-only | **Concept lean:** Ausprägung *is* a Composition; compare Compositions; BOM/Build nest them; Katalog holds Vorlagen+Ausprägungen. **Naming decided:** UX **Zusammenstellung**, internal **Composition** (rename later OK). | open |

## How to close a question

1. Record the choice in this table (`Status: decided` or `deferred`).
2. Add a dated entry to the project plan decision log.
3. Update `docs/ARCHITECTURE.md` / `docs/PRODUCT.md` / `docs/ROADMAP.md` if the answer changes scope or shape.
4. Do **not** implement code as part of closing the question while planning mode is active.
