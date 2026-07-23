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
| Q14 | Is a parameter always assigned to exactly one node? | Always one owning node / can be shared / taxonomy-level / other | Unsure — mark **?** and decide later. (Agreed separately: one node can have several parameters.) | open |
| Q15 | Where are Parameters stored? | Term meta / custom table / host plugin storage | TBD | open |
| Q16 | Are parameter *values* (filled data) part of this plugin? | Yes in-core / host plugins only / later phase | TBD — definitions may be in-core first | open |
| Q17 | How does a Project get its trees (root nodes)? | Nodes carry `project_id` / project stores root ids / other | **Decided (domain model):** Project has `root_nodes` (list of Node). Persistence details still Q19. | decided |
| Q18 | How does Project relate to WordPress taxonomies? | One project = one taxonomy / project independent of taxonomy / hybrid | TBD | open |
| Q19 | Where is Project stored? | CPT / custom table / option / taxonomy | TBD | open |
| Q20 | How are domain objects represented in PHP? | Typed DTO classes / arrays only / WP objects directly / hybrid | **Typed classes/DTOs** for Project, Node, Parameter; services for behavior; no Tree/RootNode class | open |

## How to close a question

1. Record the choice in this table (`Status: decided` or `deferred`).
2. Add a dated entry to the project plan decision log.
3. Update `docs/ARCHITECTURE.md` / `docs/PRODUCT.md` / `docs/ROADMAP.md` if the answer changes scope or shape.
4. Do **not** implement code as part of closing the question while planning mode is active.
