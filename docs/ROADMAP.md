# Roadmap

> Living delivery roadmap. Keep this aligned with [`docs/plans/project-plan.md`](plans/project-plan.md).

Last synced from plan version **0.7.32-plan** (2026-08-07).

**Current mode: scaffolding** — early runnable admin tree on **`wtt_fs` (Fallstudie) only**; **`wtt_tree` / BOM retired** from product UI (`Case_Data` = reference seed). Taxonomy = structures; **Fill Model Data** = instances. Gutenberg **`taxo/object-view`** (current); **`taxo/collection-table`** = **Q90 legacy**. Plugin ≈ **`0.0.296`**. **Q83–Q92** as in OPEN-QUESTIONS / ARCHITECTURE; **CatalogChoice** depth rule under Q90; **Q93** CatalogChoice value SoT open; **Q94** data safety open; **gold Fallstudie-only** (plan **0.7.32**). Status stays scaffolding — not Phase-1 green light.

- Node presentation: **one Registry pipeline**, many type-specific renderers (Q91); Form(1)/Table(n) object surfaces (`WTTObjectRender`); samples = **name→then type** map (not methods on nodes).
- Attribute type chooser: **`chooser_root` + `chooser_focus`** (full branch + focus), not Data-Types-only.
- Multiplicity: many → multi-select; required `1` / `1..*` → **swap only** (no clear).

## Phase 0 — Foundation & planning (active)

| Item | Status |
|------|--------|
| Coding rules (English, practices, WP standards, DB practices) | In progress |
| Versioning rule (start `0.0.1`; major only on release) | Done |
| Planning + early-scaffold rule | Done (updated) |
| Project plan + living docs + sync rule | In progress |
| Planning checklist + MVP requirements + open questions | In progress |
| Data structure: Node / Project / Definition anchors; Eigenschaften = typed children | Done (planning) |
| **Docs absorb Fallstudie** (overwrite Parameter / `parent_id` lean / slot_scope-primary) | Done (plan **0.7.17**) |
| **Q51 + Q75:** unit=`set`; members via **`composition`** | Done (planning); scaffold ≈ `0.0.140` |
| **Q64 superseded / Q66:** Parameter class dropped; inherit along `child_of` (Q54) | Done (planning) |
| **Q54 / Q35 / Q74:** hierarchy = `child_of`; RelationTypes-Ast; Relation picker CRUD | Done (planning); scaffold ≈ `0.0.140` |
| **Q76 / Q77:** catalog type inherit+override interim; `is_datatype`; local `is_abstract` | Done (planning); **Q76 superseded for hierarchy by Q88**; scaffold ≈ `0.0.128` |
| **Q88:** hierarchy datatype = parent (root **Knoten**); attrs keep own types | Done (planning); scaffold ≈ `0.0.234+` |
| **Q90:** Complex `enum` / `list` / `table` parked | Done (docs); CatalogChoice depth UI noted; scaffold leftovers until removal |
| **Q91:** Registry + many type renderers (node ≠ one renderer) | Done (docs) |
| **Q92:** Catalog bindings (`chooser_root` + `chooser_focus`; legacy `data_types`/…) | Done (≈ `0.0.264`) |
| **Q93:** CatalogChoice value SoT (id only vs pick + fill) | Open |
| **Q94:** Data safety (site/DB backup vs WXR vs plugin JSON export) | Open |
| **Q61 / Q70 / Q80:** BOM = Name + Tabelle; bands via **`prop_bindings`**; rules + optional fixes | Done (planning); scaffold ≈ `0.0.181`–`0.0.188` |
| **Q57:** Fuss `_wtt_footer_op` + Aggregate catalog | Done (planning); scaffold ≈ `0.0.192` |
| **Q78 / Q79:** Relation multiplicity; identity=ID; datatype names unique | Done; scaffold ≈ `0.0.153` / `0.0.175` |
| **Q62** collection-table block | Done (≈ `0.0.87`); **Q90** — legacy until removal |
| **Q65 url_mirror** + **Q67** re-fetch | Docs locked / open |
| **Q68** host MediaRef display reuse | Open (deferred) |
| **Q69** Collection schema drift / soft-delete | Deferred with Q90 (parked Collection kinds) |
| **Q81** unique Kopf/Zeile/Fuss bindings | Deferred (UAT) |
| **Q83** Bauteilarten vs Bauteile | Done (planning); scaffold ≈ `0.0.207` |
| **Q85** composition-first (objects over relations/table prison) | Done (planning); scaffold reshape pending |
| **Q82** Fuss labels via `fixed` + footer_op | Open (strong lean) |
| **Q53** Collection kind binding | Deferred (Q90) |
| Open questions remaining | In progress |
| Local WordPress development environment | In progress (Windows + Cloud notes) |

**Exit criteria (planning):** MVP requirements accepted; open questions decided or deferred; user sign-off for broader domain implementation beyond scaffold. Fallstudie alone is **not** exit.

## Phase 0b — Early scaffold (active)

| Item | Status |
|------|--------|
| Plugin bootstrap PHP 8.x OOP (`WTT_VERSION`, text domain) | Done |
| Dedicated taxonomy `wtt_fs` Fallstudie (no post categories; gold scaffold) | Done (≈ `0.0.296`) |
| Legacy `wtt_tree` retired from UI / seeds / pickers (`Demo_Data` helpers kept) | Done (≈ `0.0.296`) |
| Tree model over `WP_Term` (nest, create, rename+slug sync, description, short_description, copy, move, delete) | Done |
| Admin-AJAX + caps + nonce | Done |
| Admin split UI (tree + detail + toolbar; Fallstudie slim mode) | Done |
| Interim types: assign type, set members, table footer, required, fixed value | Done |
| Q51 / Q75: unit set + composition members + prefix allowlist | Done |
| Set options: separator, join units, label children; set = one Form/Table field | Done |
| Case_Data Fallstudie seed + reset (`retire-wtt-tree.php` for legacy cleanup) | Done |
| Settings + denser chrome + picker search / adaptive path | Done (UX may reverse) |
| Gutenberg `taxo/object-view` | Done |
| Gutenberg `taxo/collection-table` | Done — **Q90 legacy** (do not extend) |
| **Fill Model Data** (instances vs structures) | Done (≈ `0.0.267`) |
| **Sample_Data** name→type map + attribute Form/Table preview | Done (≈ `0.0.265`–`0.0.270`) |
| **Q92** `chooser_root` + `chooser_focus` | Done (≈ `0.0.264`) |
| **BOM / table bands** + **`_wtt_prop_bindings`** + validator | Done (≈ `0.0.171`–`0.0.181`) — **Q90 legacy path** |
| **Bindings → Rules → Fixes** (Q80) | Done (≈ `0.0.188`) — table-band scaffold |
| **Fuss `_wtt_footer_op`** + Aggregate catalog | Done (≈ `0.0.192`) |
| **Q74 / Q78:** Relation list CRUD + multiplicity | Done |
| **Q76 / Q77:** catalog type inherit interim; `is_datatype`; local `is_abstract` | Done (Q76 demoted for hierarchy) |
| **Q88:** hierarchy datatype = parent; root **Knoten**; type UI read-only when typed-as-parent | Done (≈ `0.0.234+`; gaps: full seed chain / all parents `is_datatype`) |
| **Q79:** identity = ID; datatype names unique | Done |
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
