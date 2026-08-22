> ## ⚠️ FROZEN — LEGACY DOCUMENT
>
> This file belongs to the **pre-2026-08-22 planning round** and is **no longer maintained**.
>
> - Do **not** edit it. Do **not** treat it as source of truth. Do **not** implement from it.
> - It is kept as a **quarry**: content reaches the new concept only through a reviewed
>   harvest sheet (see [`../NewConcept/README.md`](../NewConcept/README.md)).
> - Version numbers, `Q<n>` question ids, status flags and decision-log entries in here
>   describe the **old** model. They carry no authority over the new one.

# Roadmap

> Living delivery roadmap. Keep this aligned with [`docs/plans/project-plan.md`](plans/project-plan.md).

Last synced from plan version **0.7.143-plan** (2026-08-14).

**Current mode: scaffolding** â€” early runnable admin tree on **`wtt_fs` (Fallstudie) only**; **`wtt_tree` / BOM retired** from product UI. **Q123 locked** â€” see [`DEVELOPER-ATTRIBUTE-MODEL.md`](DEVELOPER-ATTRIBUTE-MODEL.md). **Settings UI parity** ([`settings-ui-parity.md`](plans/settings-ui-parity.md)): one Walk surface; Display Preferred form stack â‰ˆ **0.0.537**; walk R/C/V stack â‰ˆ **0.0.559**. **Konstanten/PrÃ¤fixe** locked â‰ˆ **0.0.540**. **ChildList Q90 depth** â‰ˆ **0.0.541**. **Properties scroll lock** â‰ˆ **0.0.542**. **BU leaves Compact Preferred** â‰ˆ **0.0.543**. **MultistepRenderer** (dialog|inline; **Composition vs Aggregation Phase B** â‰ˆ **0.0.552**; **Phase B = kind Preferred** â‰ˆ **0.0.554**; **Composition Display + Compact labels** â‰ˆ **0.0.555**) â‰ˆ **0.0.546+**. **Nested structure Preferred + Q117** â‰ˆ **0.0.558**. **Settings cascade â†’ paint** locked (plan **0.7.140**). **Hide remount shell flex** â‰ˆ **0.0.562**. **Aggregation Preferred paint** â‰ˆ **0.0.561**. Plugin â‰ˆ **`0.0.562`**. Q123 modeling UAT still open. Release-1 target = BOM end-to-end (**`1.0.0`**). Status stays scaffolding â€” not Phase-1 green light.

See plan **[Catch-up desk](plans/project-plan.md#catch-up-desk--2026-08-09-1400)** for the short locked vs open board.

- Node presentation: **one Registry pipeline**, many type-specific renderers (Q91); Form(1)/Table(n) object surfaces (`WTTObjectRender`); Preferred render/converter/validators = **per-node meta** (not live father-walk); samples = **nameâ†’then type** map (not methods on nodes) â€” map keys via bindings/ids where possible (**debt**: kill name SoT; **Q96** for Registry bind).
- **Parity:** same object chrome for admin preview, `taxo/object-view`, and frontend SSR â€” gaps are bugs (plan **0.7.49**).
- Attribute type chooser: **`chooser_root` + `chooser_focus`** (full branch + focus), **not** gated by `_wtt_is_datatype`.
- Multiplicity: many â†’ multi-select; required `1` / `1..*` â†’ **swap only** (no clear).

## Phase 0 â€” Foundation & planning (active)

| Item | Status |
|------|--------|
| Coding rules (English, practices, WP standards, DB practices) | In progress |
| Versioning rule (start `0.0.1`; major only on release) | Done |
| Planning + early-scaffold rule | Done (updated) |
| Project plan + living docs + sync rule | In progress |
| **Multi-agent lanes** (blocks / tree / shared / model / planning) | Done (process; plan **0.7.47**; absorbed with surfaces in **0.7.49**) |
| **Presentation surfaces / parity** (admin â†” block â†” frontend; blocks as views) | Done (docs; plan **0.7.49**; scaffold parity work continues in [`blocks-lane.md`](plans/blocks-lane.md)) |
| Planning checklist + MVP requirements + open questions | In progress |
| Data structure: Node / Project / Definition anchors; Eigenschaften = typed children | Done (planning) |
| **Docs absorb Fallstudie** (overwrite Parameter / `parent_id` lean / slot_scope-primary) | Done (plan **0.7.17**) |
| **Q51 + Q75:** unit=`set`; members via **`composition`** | Done (planning); scaffold â‰ˆ `0.0.140` |
| **Q64 superseded / Q66:** Parameter class dropped; inherit along `child_of` (Q54) | Done (planning) |
| **Q54 / Q35 / Q74:** hierarchy = `child_of`; RelationTypes-Ast; Relation picker CRUD | Done (planning); scaffold â‰ˆ `0.0.140` |
| **Q76 / Q77:** catalog type inherit interim; `_wtt_is_datatype` debt; local `is_abstract`; chooser = nodes (Q92) | Done (planning); **Q76 superseded for hierarchy by Q88**; chooser gate revised **0.7.34**; catalog lock â†’ **`is_template`** (**0.7.35**) |
| **Q88:** hierarchy datatype = parent (root **Knoten** seed-only); `has_type` except root = father; attrs keep own types | Done (planning); scaffold â‰ˆ `0.0.358` |
| **Q90:** Complex `enum` / `list` / `table` parked | Done; **â‰ˆ 0.0.463** Fallstudie soft-trash of `enum`/`list`/`node_*`; legacy `table` for BOM only |
| **Q91:** Registry + many type renderers (node â‰  one renderer) | Done (docs) |
| **Q92:** Catalog bindings (`chooser_root` + `chooser_focus`); resolve by **id** only; **#6** special leaves/branches in settings | Done (â‰ˆ `0.0.264`); name fallback = debt; plan **0.7.37** |
| **Recursive boxed paint** (Mult â†’ list/collection frame; Preferred = unit; recurse) | Done (docs; plan **0.7.51**; Object View Mult>1 related â‰ˆ `0.0.372`) |
| **Q97 UX:** Bauteilliste related Position table (Fill Model Data + Object View) | Done admin + block edit create/save line (â‰ˆ `0.0.372`) |
| **Q93:** CatalogChoice / ref value SoT | **Decided** â€” id only on host; values on Model (OQ-R2b) |
| **Q98 / UR-S1:** Model versions â†” instance data | **Concept locked** â€” scaffolds â‰ˆ `0.0.376`; mapping / Revision UI TODO |
| **Q94:** Data safety (site/DB backup vs WXR vs plugin JSON export) | Open |
| **Q95:** Optional tree icons (`_wtt_icon`; Settings allowlist; create standard-by-name else parent copy; Identity vs Display) | Done (â‰ˆ `0.0.366`+) |
| **Q47 lean / Preferred R+C+V:** per-node meta; create-time `ensure_*`; shape validators + optional fixes (never auto-run) | Scaffold-proven (â‰ˆ `0.0.369`); product SoT nuance still open |
| **Q96:** Registryâ†”node bind (`builtin.*` â†’ term id) | Done (scaffold â‰ˆ `0.0.385`; name fallback = debt) |
| **Q34 / Q49:** config; Simples may specialize via children (presets); soft lean no attrs-as-host / no outgoing Relations | Done (planning; soft lean) |
| **Q61 / Q70 / Q80:** BOM = Name + Tabelle; bands via **`prop_bindings`**; rules + optional fixes | Done (planning); scaffold â‰ˆ `0.0.181`â€“`0.0.188` |
| **Q57:** Fuss `_wtt_footer_op` + Aggregate catalog | Done (planning); scaffold â‰ˆ `0.0.192` |
| **Q78 / Q79:** Relation multiplicity; identity=ID; never select by name | Done; Q79 uniqueness-for-datatypes demoted **0.7.34** |
| **Q62** collection-table block | Done (â‰ˆ `0.0.87`); **Taxo Table view** = all instances (â‰ˆ `0.0.336`); catalog `table` kind Q90-parked |
| **Q65 url_mirror** + **Q67** re-fetch | Docs locked / open |
| **Q68** host MediaRef display reuse | Open (deferred) |
| **Q69** Collection schema drift / soft-delete | Deferred with Q90 (parked Collection kinds) |
| **Q81** unique Kopf/Zeile/Fuss bindings | Deferred (UAT) |
| **Q83** Bauteile catalog (Model-only; no Implementation SoT) | **Decided** (OQ-B2); scaffold Implementation/ = debt |
| **Q85** composition-first (objects over relations/table prison) | Done (planning); scaffold reshape pending |
| **Q82** Fuss labels via `fixed` + footer_op | Open (strong lean) |
| **Q109** Measure/quantity + unit/prefix switch recalculation | **Decided** â€” rescale Typ on PrÃ¤fix change; display triple SoT |
| **Q110** Currency/money switch (FX, e.g. EURâ†’USD) | Parked (hold; â‰  Q109; with Q99 later) |
| **Q111** Inline vs linked value storage (from type) | **Revised** â€” **Bindung**: Composition=embedded, Aggregation=linked |
| **Q112** Rename Preferred render `embed`? | **Decided** â€” key `embed`; UI **Embedded renderer** |
| **Q113** Unified renderer registry + Preferred storage / gray-out | Parked build; **shape:** `enum Renderer: string` â†’ `IntRenderer` / `FormRenderer` / â€¦ |
| **Q114** Attribute Options = Node Preferred R/C/V chrome | **Decided** â€” side-by-side Preferred â‰ˆ `0.0.389` |
| **Q115** Settings Fixed â†’ Read-only + Default on nodes | **Decided** â€” gray RO outside slots; gray Default on builtin Simples â‰ˆ `0.0.390` |
| **Q116** Required list-select sole option â†’ auto + gray | **Decided** â€” optional (0-lower Mult) stays open â‰ˆ `0.0.392` |
| **Q117** Presentation texts + icon off node | **Decided** â€” `wtt_node_presentation` store; admin list â‰ˆ `0.0.393` |
| **Q118** Detail: Node properties vs Display | **Decided** â€” foldable Presentation next |
| **Q126** Config page = vertical box stack | **Decided** â€” `WTTConfigRender.renderPage` â‰ˆ `0.0.481` |
| **Q120** Quantity anatomy + unit rules | **Decided** â€” Value+Prefix?+Unit; rules on unit |
| **Q121** Money/physical canonical vs entry store | **Decided lean** â€” money EUR + FX snapshot |
| **Q122** Type properties / composed component settings | **Decided** â€” inherit override everywhere; composed = dynamic component surfaces |
| **Q123** Attribute / Settings model | **Locked** â€” Relation-only; Settings.data/view; walk; see DEVELOPER-ATTRIBUTE-MODEL |
| **RelationTypes** | `child_of`, `besteht_aus`, `aggregation`; deprecated pick types |
| **Q53** Collection kind binding | Deferred (Q90) |
| Open questions remaining | In progress |
| Local WordPress development environment | In progress (Windows + Cloud notes) |

**Exit criteria (planning):** MVP requirements accepted; open questions decided or deferred; user sign-off for broader domain implementation beyond scaffold. Fallstudie alone is **not** exit.

## Phase 0b â€” Early scaffold (active)

| Item | Status |
|------|--------|
| Plugin bootstrap PHP 8.x OOP (`WTT_VERSION`, text domain) | Done |
| Dedicated taxonomy `wtt_fs` Fallstudie (no post categories; gold scaffold) | Done (â‰ˆ `0.0.297`) |
| Legacy `wtt_tree` retired from UI / seeds / pickers (`Demo_Data` helpers kept) | Done (â‰ˆ `0.0.297`) |
| Tree model over `WP_Term` (nest, create, rename+slug sync, description, short_description, copy, move, delete) | Done |
| Admin-AJAX + caps + nonce | Done |
| Admin split UI (tree + detail + toolbar; Fallstudie slim mode) | Done |
| Interim types: assign type, set members, table footer, required, fixed value | Done (Q115: Fixed UI â†’ Read-only + Default value â‰ˆ `0.0.390`) |
| Q51 / Q75: unit set + composition members + prefix allowlist | Done |
| Set options: separator, join units, label children; set = one Form/Table field | Done |
| Case_Data Fallstudie seed + reset (`retire-wtt-tree.php` for legacy cleanup) | Done |
| Settings + denser chrome + picker search / adaptive path | Done (UX may reverse) |
| **Q95** optional tree icons (Settings allowlist; create standard-by-name else parent; Identity vs Display; `renderTreeNode`) | Done (â‰ˆ `0.0.366`+) |
| Preferred render / converter / validators 0..n (per-node meta; Registry; create-time seed) | Done (â‰ˆ `0.0.369`) |
| Gutenberg `taxo/object-view` | Done (wiring); **parity** with admin object chrome = in progress ([`blocks-lane.md`](plans/blocks-lane.md)) |
| Gutenberg `taxo/collection-table` (**Taxo Table view**) | Done â€” all Model_Data instances for bound node (â‰ˆ `0.0.336`); reuse object/table paint where applicable |
| **Fill Model Data** (instances vs structures) | Done (â‰ˆ `0.0.267`) |
| **UR-S1 / Q98 Model versions** (schema meta + instance stamp + admin shell; structural warn/bump â‰ˆ `0.0.374`; red-badge â†’ `host_id` â‰ˆ `0.0.376`) | Concept locked; scaffold â‰ˆ `0.0.376` â€” mapping DSL / Revision `G.e` / change log TODO |
| **Cleanup v1** (admin health: hosts with model version conflicts â†’ link to Model versions) | Scaffold (â‰ˆ `0.0.375`) â€” no purge / mapping yet |
| **Sample_Data** nameâ†’type map + attribute Form/Table preview | Done (â‰ˆ `0.0.265`â€“`0.0.270`) |
| **Q92** `chooser_root` + `chooser_focus` | Done (â‰ˆ `0.0.264`) |
| **BOM / table bands** + **`_wtt_prop_bindings`** + validator | Done (â‰ˆ `0.0.171`â€“`0.0.181`) â€” **Q90 legacy path** |
| **Bindings â†’ Rules â†’ Fixes** (Q80) | Done (â‰ˆ `0.0.188`) â€” table-band scaffold |
| **Fuss `_wtt_footer_op`** + Aggregate catalog | Done (â‰ˆ `0.0.192`) |
| **Q74 / Q78:** Relation list CRUD + multiplicity | Done |
| **Q76 / Q77:** catalog type inherit interim; `_wtt_is_datatype` debt; local `is_abstract` | Done (chooser no longer product-gated by flag) |
| **Q88:** hierarchy datatype = parent; root **Knoten** seed-only; no admin free `set_type` | Done (â‰ˆ `0.0.358`) |
| **Q79:** identity = ID; never select by name (bindings â†’ ids OK) | Done (uniqueness-for-datatypes = optional UX debt) |
| **Q84:** `node_ref` catalog chooser + mini-form create | Done (â‰ˆ `0.0.225`â€“`0.0.227`) |
| **Q85:** composition-first language / reshape (block â‰  DB table) | Planning decided; implementation pending |
| Legacy `_wtt_slot_scope` / block header Name follow-up | Pending (legacy filter; block instance Name UI removed) |
| **Q54 follow-up:** map hierarchy to `child_of` as sole persistence | Pending |
| **Q82** fixed Fuss labels in renderer | Not started |
| Property inheritance UI (Q66) / real Relation edge table / Composition services / REST / host hooks | Not started |
| Explicit removal slice for parked enum/list/table scaffold | Not started |

**Exit criteria:** User can exercise the taxonomy tree + type/unit/table preview locally; scaffold remains interim until domain sign-off.

## Phase 1 â€” MVP (blocked on planning sign-off for domain slice)

| Item | Status |
|------|--------|
| Formal Domain DTOs / services (beyond term-meta interim) | Blocked |
| Property-slot inherit (Q66) + instance values (no Parameter class) | Blocked |
| Harden MVP FR acceptance vs scaffold | Blocked |

**Exit criteria:** Activate plugin, manage hierarchical taxonomy as primary tree workflow; accepted MVP requirements met.

## Phase 2 â€” Extensions (later)

| Item | Status |
|------|--------|
| Filters to register taxonomies into the environment | Pending |
| Side-panel / row-action extension hooks | Pending |
| Documented public PHP + HTTP API | Pending |
| Automated tests for nesting and delete policies | Pending |

**Exit criteria:** A second plugin can enable the tree for its taxonomy with glue code only (no forks of this plugin).

## Phase 3 â€” Integration and polish (later)

| Item | Status |
|------|--------|
| Optional integration with `wp-electronic-parts` | Pending |
| Drag-and-drop reparent/reorder (if still required) | Pending |
| Large-tree performance (batch queries, caching) | Pending |
| Optional read-only frontend tree | Pending |

**Exit criteria:** Host catalog plugins can rely on a stable tree environment without forking tree UI code.
