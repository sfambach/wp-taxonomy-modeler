# Roadmap

> Living delivery roadmap. Keep this aligned with [`docs/plans/project-plan.md`](plans/project-plan.md).

Last synced from plan version **0.7.72-plan** (2026-08-08).

**Current mode: scaffolding** — early runnable admin tree on **`wtt_fs` (Fallstudie) only**; **`wtt_tree` / BOM retired** from product UI (`Case_Data` = reference seed). Taxonomy = structures; **Fill Model Data** = instances (**Q97** links + composition soft-trash). Gutenberg **`taxo/object-view`** / **Taxo Table view**; **Q98 / UR-S1 Model versions** (concept locked; stamp + structural warn/bump + conflict badge); **Cleanup v1**; plugin ≈ **`0.0.376`**. Release-1 target = BOM end-to-end (**`1.0.0`**). Product: **Model-only refs** (no Implementation SoT); preferred **`embed`** ≠ catalog `node_embed`. Status stays scaffolding — not Phase-1 green light.

- Node presentation: **one Registry pipeline**, many type-specific renderers (Q91); Form(1)/Table(n) object surfaces (`WTTObjectRender`); Preferred render/converter/validators = **per-node meta** (not live father-walk); samples = **name→then type** map (not methods on nodes) — map keys via bindings/ids where possible (**debt**: kill name SoT; **Q96** for Registry bind).
- **Parity:** same object chrome for admin preview, `taxo/object-view`, and frontend SSR — gaps are bugs (plan **0.7.49**).
- Attribute type chooser: **`chooser_root` + `chooser_focus`** (full branch + focus), **not** gated by `_wtt_is_datatype`.
- Multiplicity: many → multi-select; required `1` / `1..*` → **swap only** (no clear).

## Phase 0 — Foundation & planning (active)

| Item | Status |
|------|--------|
| Coding rules (English, practices, WP standards, DB practices) | In progress |
| Versioning rule (start `0.0.1`; major only on release) | Done |
| Planning + early-scaffold rule | Done (updated) |
| Project plan + living docs + sync rule | In progress |
| **Multi-agent lanes** (blocks / tree / shared / model / planning) | Done (process; plan **0.7.47**; absorbed with surfaces in **0.7.49**) |
| **Presentation surfaces / parity** (admin ↔ block ↔ frontend; blocks as views) | Done (docs; plan **0.7.49**; scaffold parity work continues in [`blocks-lane.md`](plans/blocks-lane.md)) |
| Planning checklist + MVP requirements + open questions | In progress |
| Data structure: Node / Project / Definition anchors; Eigenschaften = typed children | Done (planning) |
| **Docs absorb Fallstudie** (overwrite Parameter / `parent_id` lean / slot_scope-primary) | Done (plan **0.7.17**) |
| **Q51 + Q75:** unit=`set`; members via **`composition`** | Done (planning); scaffold ≈ `0.0.140` |
| **Q64 superseded / Q66:** Parameter class dropped; inherit along `child_of` (Q54) | Done (planning) |
| **Q54 / Q35 / Q74:** hierarchy = `child_of`; RelationTypes-Ast; Relation picker CRUD | Done (planning); scaffold ≈ `0.0.140` |
| **Q76 / Q77:** catalog type inherit interim; `_wtt_is_datatype` debt; local `is_abstract`; chooser = nodes (Q92) | Done (planning); **Q76 superseded for hierarchy by Q88**; chooser gate revised **0.7.34**; catalog lock → **`is_template`** (**0.7.35**) |
| **Q88:** hierarchy datatype = parent (root **Knoten** seed-only); `has_type` except root = father; attrs keep own types | Done (planning); scaffold ≈ `0.0.358` |
| **Q90:** Complex `enum` / `list` / `table` parked | Done (docs); CatalogChoice depth UI noted; scaffold leftovers until removal |
| **Q91:** Registry + many type renderers (node ≠ one renderer) | Done (docs) |
| **Q92:** Catalog bindings (`chooser_root` + `chooser_focus`); resolve by **id** only; **#6** special leaves/branches in settings | Done (≈ `0.0.264`); name fallback = debt; plan **0.7.37** |
| **Recursive boxed paint** (Mult → list/collection frame; Preferred = unit; recurse) | Done (docs; plan **0.7.51**; Object View Mult>1 related ≈ `0.0.372`) |
| **Q97 UX:** Bauteilliste related Position table (Fill Model Data + Object View) | Done admin + block edit create/save line (≈ `0.0.372`) |
| **Q93:** CatalogChoice / ref value SoT | **Decided** — id only on host; values on Model (OQ-R2b) |
| **Q98 / UR-S1:** Model versions ↔ instance data | **Concept locked** — scaffolds ≈ `0.0.376`; mapping / Revision UI TODO |
| **Q94:** Data safety (site/DB backup vs WXR vs plugin JSON export) | Open |
| **Q95:** Optional tree icons (`_wtt_icon`; Settings allowlist; create standard-by-name else parent copy; Identity vs Display) | Done (≈ `0.0.366`+) |
| **Q47 lean / Preferred R+C+V:** per-node meta; create-time `ensure_*`; shape validators + optional fixes (never auto-run) | Scaffold-proven (≈ `0.0.369`); product SoT nuance still open |
| **Q96:** Registry↔node bind (`builtin.*` → term id) | Done (scaffold ≈ `0.0.385`; name fallback = debt) |
| **Q34 / Q49:** config; Simples may specialize via children (presets); soft lean no attrs-as-host / no outgoing Relations | Done (planning; soft lean) |
| **Q61 / Q70 / Q80:** BOM = Name + Tabelle; bands via **`prop_bindings`**; rules + optional fixes | Done (planning); scaffold ≈ `0.0.181`–`0.0.188` |
| **Q57:** Fuss `_wtt_footer_op` + Aggregate catalog | Done (planning); scaffold ≈ `0.0.192` |
| **Q78 / Q79:** Relation multiplicity; identity=ID; never select by name | Done; Q79 uniqueness-for-datatypes demoted **0.7.34** |
| **Q62** collection-table block | Done (≈ `0.0.87`); **Taxo Table view** = all instances (≈ `0.0.336`); catalog `table` kind Q90-parked |
| **Q65 url_mirror** + **Q67** re-fetch | Docs locked / open |
| **Q68** host MediaRef display reuse | Open (deferred) |
| **Q69** Collection schema drift / soft-delete | Deferred with Q90 (parked Collection kinds) |
| **Q81** unique Kopf/Zeile/Fuss bindings | Deferred (UAT) |
| **Q83** Bauteile catalog (Model-only; no Implementation SoT) | **Decided** (OQ-B2); scaffold Implementation/ = debt |
| **Q85** composition-first (objects over relations/table prison) | Done (planning); scaffold reshape pending |
| **Q82** Fuss labels via `fixed` + footer_op | Open (strong lean) |
| **Q109** Measure/quantity + unit/prefix switch recalculation | **Decided** — rescale Typ on Präfix change; display triple SoT |
| **Q110** Currency/money switch (FX, e.g. EUR→USD) | Parked (hold; ≠ Q109; with Q99 later) |
| **Q53** Collection kind binding | Deferred (Q90) |
| Open questions remaining | In progress |
| Local WordPress development environment | In progress (Windows + Cloud notes) |

**Exit criteria (planning):** MVP requirements accepted; open questions decided or deferred; user sign-off for broader domain implementation beyond scaffold. Fallstudie alone is **not** exit.

## Phase 0b — Early scaffold (active)

| Item | Status |
|------|--------|
| Plugin bootstrap PHP 8.x OOP (`WTT_VERSION`, text domain) | Done |
| Dedicated taxonomy `wtt_fs` Fallstudie (no post categories; gold scaffold) | Done (≈ `0.0.297`) |
| Legacy `wtt_tree` retired from UI / seeds / pickers (`Demo_Data` helpers kept) | Done (≈ `0.0.297`) |
| Tree model over `WP_Term` (nest, create, rename+slug sync, description, short_description, copy, move, delete) | Done |
| Admin-AJAX + caps + nonce | Done |
| Admin split UI (tree + detail + toolbar; Fallstudie slim mode) | Done |
| Interim types: assign type, set members, table footer, required, fixed value | Done |
| Q51 / Q75: unit set + composition members + prefix allowlist | Done |
| Set options: separator, join units, label children; set = one Form/Table field | Done |
| Case_Data Fallstudie seed + reset (`retire-wtt-tree.php` for legacy cleanup) | Done |
| Settings + denser chrome + picker search / adaptive path | Done (UX may reverse) |
| **Q95** optional tree icons (Settings allowlist; create standard-by-name else parent; Identity vs Display; `renderTreeNode`) | Done (≈ `0.0.366`+) |
| Preferred render / converter / validators 0..n (per-node meta; Registry; create-time seed) | Done (≈ `0.0.369`) |
| Gutenberg `taxo/object-view` | Done (wiring); **parity** with admin object chrome = in progress ([`blocks-lane.md`](plans/blocks-lane.md)) |
| Gutenberg `taxo/collection-table` (**Taxo Table view**) | Done — all Model_Data instances for bound node (≈ `0.0.336`); reuse object/table paint where applicable |
| **Fill Model Data** (instances vs structures) | Done (≈ `0.0.267`) |
| **UR-S1 / Q98 Model versions** (schema meta + instance stamp + admin shell; structural warn/bump ≈ `0.0.374`; red-badge → `host_id` ≈ `0.0.376`) | Concept locked; scaffold ≈ `0.0.376` — mapping DSL / Revision `G.e` / change log TODO |
| **Cleanup v1** (admin health: hosts with model version conflicts → link to Model versions) | Scaffold (≈ `0.0.375`) — no purge / mapping yet |
| **Sample_Data** name→type map + attribute Form/Table preview | Done (≈ `0.0.265`–`0.0.270`) |
| **Q92** `chooser_root` + `chooser_focus` | Done (≈ `0.0.264`) |
| **BOM / table bands** + **`_wtt_prop_bindings`** + validator | Done (≈ `0.0.171`–`0.0.181`) — **Q90 legacy path** |
| **Bindings → Rules → Fixes** (Q80) | Done (≈ `0.0.188`) — table-band scaffold |
| **Fuss `_wtt_footer_op`** + Aggregate catalog | Done (≈ `0.0.192`) |
| **Q74 / Q78:** Relation list CRUD + multiplicity | Done |
| **Q76 / Q77:** catalog type inherit interim; `_wtt_is_datatype` debt; local `is_abstract` | Done (chooser no longer product-gated by flag) |
| **Q88:** hierarchy datatype = parent; root **Knoten** seed-only; no admin free `set_type` | Done (≈ `0.0.358`) |
| **Q79:** identity = ID; never select by name (bindings → ids OK) | Done (uniqueness-for-datatypes = optional UX debt) |
| **Q84:** `node_ref` catalog chooser + mini-form create | Done (≈ `0.0.225`–`0.0.227`) |
| **Q85:** composition-first language / reshape (block ≠ DB table) | Planning decided; implementation pending |
| Legacy `_wtt_slot_scope` / block header Name follow-up | Pending (legacy filter; block instance Name UI removed) |
| **Q54 follow-up:** map hierarchy to `child_of` as sole persistence | Pending |
| **Q82** fixed Fuss labels in renderer | Not started |
| Property inheritance UI (Q66) / real Relation edge table / Composition services / REST / host hooks | Not started |
| Explicit removal slice for parked enum/list/table scaffold | Not started |

**Exit criteria:** User can exercise the taxonomy tree + type/unit/table preview locally; scaffold remains interim until domain sign-off.

## Phase 1 — MVP (blocked on planning sign-off for domain slice)

| Item | Status |
|------|--------|
| Formal Domain DTOs / services (beyond term-meta interim) | Blocked |
| Property-slot inherit (Q66) + instance values (no Parameter class) | Blocked |
| Harden MVP FR acceptance vs scaffold | Blocked |

**Exit criteria:** Activate plugin, manage hierarchical taxonomy as primary tree workflow; accepted MVP requirements met.

## Phase 2 — Extensions (later)

| Item | Status |
|------|--------|
| Filters to register taxonomies into the environment | Pending |
| Side-panel / row-action extension hooks | Pending |
| Documented public PHP + HTTP API | Pending |
| Automated tests for nesting and delete policies | Pending |

**Exit criteria:** A second plugin can enable the tree for its taxonomy with glue code only (no forks of this plugin).

## Phase 3 — Integration and polish (later)

| Item | Status |
|------|--------|
| Optional integration with `wp-electronic-parts` | Pending |
| Drag-and-drop reparent/reorder (if still required) | Pending |
| Large-tree performance (batch queries, caching) | Pending |
| Optional read-only frontend tree | Pending |

**Exit criteria:** Host catalog plugins can rely on a stable tree environment without forking tree UI code.
