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
| Q12 | Which optional Node fields are in MVP? | slug / description / count / position / meta | slug + count likely; position/meta later | open |
| Q13 | How are siblings ordered? | WP default name/term order / explicit position field | WP default until proven insufficient | open |
| Q14 | Is a parameter always assigned to exactly one node? | Always one owning node / can be shared / taxonomy-level / other | May dissolve if Parameter is a Node or via `besteht-aus`; else still open | open |
| Q15 | Where are Parameters stored? | Term meta / custom table / host plugin storage | TBD | open |
| Q16 | Are parameter *values* (filled data) part of this plugin? | Yes in-core / host plugins only / later phase | Leaning: measures need a filled **value** beside prefix + unit (e.g. `10 mm`); storage owner TBD | open |
| Q17 | How does a Project get its trees (root nodes)? | Nodes carry `project_id` / project stores root ids / other | **Decided (domain model):** Project has `root_nodes` (list of Node). Persistence details still Q19. | decided |
| Q18 | How does Project relate to WordPress taxonomies? | One project = one taxonomy / project independent of taxonomy / hybrid | TBD | open |
| Q19 | Where is Project stored? | CPT / custom table / option / taxonomy | TBD | open |
| Q20 | How are domain objects represented in PHP? | Typed DTO classes / arrays only / WP objects directly / hybrid | **Typed classes/DTOs** for Project, Node, Parameter, Changelog, Change; services for behavior; no Tree/RootNode class | open |
| Q21 | What is stored in Change.`change` (the Änderung)? | Plain text summary / structured field diff / both | Text summary first; structured diff optional later | open |
| Q22 | What is Change.`changer` (the Änderer)? | WP user ID / login / display name / Actor value object | WP user ID (+ display resolved in UI) leaning | open |
| Q23 | What format is Change.`version`? | Semver string / integer counter / object version snapshot | Align with plugin versioning where useful; decide later | open |
| Q24 | Which types require `prefix` and/or `base_unit`? | By type-node rules / flags / convention | **measure** requires them (composite); scalars like url/string do not | open |
| Q25 | How are Units represented? | Separate Unit / single unit Node / prefix+base | **Decided:** Parameter uses **prefix** + **base_unit** Nodes from Definition tree | decided |
| Q26 | Must type/prefix/base_unit Nodes be children of Type/Präfix/Basiseinheit? | Strict branch check / any node | Leaning: must live under the matching Definition branch | open |
| Q27 | How are type-Nodes organized? | Dedicated type tree in a project / flat list / convention | Example: Definition → Type | open |
| Q28 | Is a measure unit prefix+base or one node (kOhm)? | prefix+base / single node | **Decided direction:** **prefix + base_unit** (e.g. k + Ohm) | decided |
| Q29 | Can prefix exist without base_unit (or vice versa)? | Both required together / either alone / type-dependent | Type-dependent leaning | open |
| Q30 | How are template trees applied to project-specific trees? | Deep copy / link / copy-on-write | TBD | open |
| Q31 | Does `Node.template` apply only to the root or inherit to descendants? | Root only / inherit | Root flag leaning | open |
| Q32 | Is the Definition tree itself a template? | Always template / never / optional | TBD | open |
| Q33 | In the Definitionsbaum (e.g. Widerstände → Wert), are leaves Nodes that *own* Parameters, or are Parameter names themselves tree nodes? | Node + attached Parameter / Parameter-as-node / both | **Paused** — compare with typed-edge Bauteile example | open |
| Q34 | If Parameter is a Node, how is specialization modeled? | PHP subclass / `kind` flag on Node / role without subclass / Node + payload | TBD — wait for Q33 | open |
| Q35 | Do node–node links need typed edges with properties? | Plain parent/child only / kinds / full Relation + RelationType | Exploring RelationType pairs + display/inherit | open |
| Q36 | What is the core Type catalog? | Fixed list vs extensible | Leaning: string, number, integer, boolean, url, file, **enum**, **measure** (composites) | open |
| Q37 | For `measure`, is the numeric part `number`, `integer`, or choosable per param? | Always number / always integer / per-param `numeric_kind` | Per-param leaning | open |
| Q38 | Are single/multiple enum variants types or selection methods? | enum_single+enum_multiple types / one enum + selection_mode | **Leaning: one `enum` type; single\|multiple = selection method** | open |
| Q39 | Which scalar may enum option values use? | string only / any scalar / configurable | string leaning | open |
| Q40 | Parked: further **Node** idea from planning session | Resume when user returns to it | User asked to park mid-thought (“Knoten im Kopf”) — details TBD on resume | parked |
| Q41 | Bidirectional relations / inverse typing? | Separate inverse type / inverse field / reverse as view of same edge | **Leaning: no `inverse` field; one `label` per RelationType; reverse = view** | open |
| Q42 | How should related nodes be displayed per RelationType? | Always as tree children / type-specific (part-of as attributes, is_a as taxonomy, …) | **Leaning: part-of → attributes of parent**; is_a → taxonomy; uses → refs (`DisplayHint`) | open |
| Q43 | Can `consists_of` attributes be inherited along `is_a`? | No / copy / live inherit / merge+override | **Leaning: yes, inheritable**; mechanics TBD (related Q30) | open |
| Q44 | Does RelationType need **`directed`** (arrow vs line)? | Always directed / optional flag / derive from DisplayHint / drop | Tentative: directed → arrow `from→to`, else line; may overlap `bidirectional` — user unsure | open |
| Q45 | How is a measure bound when value sits on a Relation? | props `{value, prefix, unit}` / value on edge + unit **group** (prefix+unit) / Parameter only | **Leaning: Präfix+Einheit = group**; value often on edge; no loose value→prefix→unit chain | open |

## How to close a question

1. Record the choice in this table (`Status: decided` or `deferred`).
2. Add a dated entry to the project plan decision log.
3. Update `docs/ARCHITECTURE.md` / `docs/PRODUCT.md` / `docs/ROADMAP.md` if the answer changes scope or shape.
4. Do **not** implement code as part of closing the question while planning mode is active.
