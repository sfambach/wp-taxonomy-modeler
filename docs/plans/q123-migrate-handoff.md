---
name: Q123 migrate resume handoff
overview: Durable checkpoint for resuming Relation-only attribute migrate work (slots ? named edges).
status: ACTIVE
last_updated: "2026-08-10"
related_docs:
  - docs/DEVELOPER-ATTRIBUTE-MODEL.md
  - docs/plans/q123-doc-pass-questions.md
  - docs/plans/relation-vs-object-concept.md
  - docs/plans/project-plan.md
---

# Q123 migrate — resume handoff

**Audience:** next agent / human resuming after sleep.  
**Language:** English (code + this handoff). Chat with user stays German.

---

## Agent rule

**Status: ACTIVE** — Walk-Wizard ≈ **0.0.441** …; **Settings UI parity ≈ 0.0.497** ([`settings-ui-parity.md`](settings-ui-parity.md)): Attribute Options no longer double-paints legacy Choices + depth-0 Relation chrome when the Settings walk covers the type; walk label **Settings**. Node ConfigPage same widget = next. Q123 still not “DONE” until user UAT on modeling paths (Passiv/Wert, Toleranz, …).

### Settings walk performance (≈ 0.0.453)

- **Compute-on-write:** `Attribute::ensure_settings_walk_cache` runs on attribute add / type retarget / Walk-Wizard save; snapshot in host meta `_wtt_attribute_walk_cache` (keyed by edge id).
- **get_node / decorate:** depth-0 Preferred + hybrid only; deep summary **not** rebuilt — read cache when `structureFp` / deltas / default fingerprints match; else `settingsWalkLazy` and Options hydrates via `wtt_get_attribute_settings_walk`.
- **Invalidate:** type/target change, walk delta change, attribute remove, or fingerprint mismatch (type composition under target edited). Shared catalog type edits may leave other hosts stale until Options open / ensure.
- **Also:** request memos (walk/live/list/outgoing), single `Attribute::list` + `validate_rows`, deferred Preview mount, session `datatypeTree` reuse.

- Core migrate + polish through ≈ **0.0.449**. Nested Composition settings walk edits **Settings.view + Settings.data** per level as path-keyed deltas on the **attribute Relation** (OQ-W16).
- **Q124 (0.0.442):** RelationType `defaultvalue_from` — consumer host → provider host; name = attr; create/empty seed only (BOM Bauart example). Live cascade still open (OPEN-QUESTIONS Q124).
- **0.0.443 (parallel claim A):** `Settings.data.allowedPrefixIds` on Unit / With-prefix walk levels; paint intersects Unit `fixedOptions.allowedPrefixes` / quantitySchema; catalog unit meta unchanged. setSeparator / setJoinUnits / Q120 engines still deferred.
- **0.0.443 (parallel claim B) / reconciled in 0.0.444:** Admin Preview uses **host Preferred only** (Settings → Preferred; Editable + Display). Attribute Options / walk Render overrides do **not** change the host Preview surface.
- **0.0.444:** Version reconcile — both 0.0.443 features present; single consistent `WTT_VERSION` / header / `package.json`. No feature expansion.
- **0.0.445 (OQ-W11 structure):** `Case_Data::ensure_unit_quantity_structure` — `With prefix` attrs Praefix→Präfixe—fixe + Kuerzel→text; `size` child_of `quantity` (Value→double, Unit→With prefix); Passiv Wert→size (safe retype only). Unit leaves stay leaves. Display still uses `synthesize_unit_quantity_members` — next slice.
- **0.0.448 (Walk Default + compact row):** Per-level **Default** override (live type seed shown; Reset deletes key). Depth 0 → `edge.default` (Q106 SoT; clears leftover `settings.data.default`); nested → `settings.nested[path].data.default`. Nested overrides applied onto `typeProperties.fixedValues` for paint. Compact **one-row** walk UI (Preferred / Converter / Default / Val / Pref chips). **Tree layout deferred** (one-row preferred after UAT feedback).
- **Not DONE** until user confirms modeling UX. Commit only when asked — draft should cover **0.0.415–0.0.449**.
- Prefer hardening walk UX / missing keys over inventing new systems.
- Bugfixes from UAT findings are OK.
- Avoid large Case_Data edits while another agent may seed With-prefix/size.

---

## PC awake window (user constraint)

| | |
|--|--|
| **Night session** | 2026-08-09 (~21:42 UTC+2 start; paused ~23:01 for UAT) |
| **Resume** | **2026-08-10** morning — user unpaused |
| **Timer** | Cleared for day work — refresh this handoff when ending a slice |

**Do not commit** unless the user explicitly asks.

---

## Safe rollback checkpoint

| Item | Value |
|------|--------|
| **Commit** | `ed9c5eb` (`ed9c5eb03bf7bb2b09956894c93d537e47606019`) |
| **Message** | `Checkpoint before Q123 Relation-only attribute migrate (0.0.414).` |
| **Plugin version at checkpoint** | **`0.0.414`** |
| **Branch** | `main` (checkpoint = current `HEAD` at handoff write; all Q123 code is **uncommitted** working tree) |

Rollback (only if user asks): reset/checkout that commit, or discard the dirty tree and return to `ed9c5eb` / `0.0.414`. Prefer user confirmation before destructive git.

---

## Current status (snapshot 2026-08-10)

| Item | Value |
|------|--------|
| **Handoff status** | **`ACTIVE` / UAT** — Walk-Wizard ≈ **0.0.441** + Q124 ≈ **0.0.442** + prefix allowlist ≈ **0.0.443** + host Preferred Preview ≈ **0.0.444** + **OQ-W11 unit structure ≈ 0.0.445** + **Walk Default/compact ≈ 0.0.448**; not DONE until modeling UAT |
| **HEAD** | `ed9c5eb` (clean commit = 0.0.414) |
| **Working tree version** | **`0.0.449`** — plugin header, `WTT_VERSION`, `package.json` |
| **Commits ahead of checkpoint** | **None** — all Q123/Q124 work is local dirty / untracked |
| **Commit policy** | **Do not commit** until user asks — draft should cover **0.0.415–0.0.449** |
| **PHP lint** | Clean on Attribute / Relation / Settings_Walk / Tree_Ajax / Node_Type (Laragon php 8.3.30) |
| **Invariants smoke** | Laragon `smoke=ok` at **0.0.439** (re-run after UAT if needed) |
| **Walk nested overrides smoke** | Laragon `smoke=ok` at **0.0.441** (`_smoke-q123-walk-nested-overrides.php` — Passiv/Wert) |
| **Prefix allowlist Walk smoke** | Laragon `smoke=ok` at **0.0.443** / recheck **0.0.444** (`_smoke-q123-prefix-allowlist-walk.php` — Passiv/Wert Unit→With-prefix path) |
| **Unit structure smoke** | Laragon `smoke=ok` at **0.0.445** (`_smoke-q123-unit-structure.php` — With prefix 2 attrs; size Value+Unit; Passiv Wert; Meter leaf 0 attrs) |
| **defaultvalue_from smoke** | Laragon `smoke=ok` at **0.0.442** / recheck **0.0.444** (`_smoke-q123-defaultvalue-from.php`) |
| **Host Preferred Preview** | Present in `tree-admin.js` `renderAttributeHostPreview` + i18n `previewPreferredOnlyHint` (host Preferred only) |
| **Dup/move smoke** | Laragon `smoke=ok` at **0.0.439** (`_smoke-q123-dup-move.php`) |
| **Live migrate (`wtt_fs`)** | **Done earlier:** 78 typed edges rewritten, slots deleted; flag `wtt_q123_attr_migrated[wtt_fs]=1` |
| **typeExtras fold** | One-shot flag `wtt_q123_type_extras_folded_v2` — folds edge-keyed maps + drops orphan slot keys |
| **typeExtras prune** | One-shot flag `wtt_q123_type_extras_pruned_v1` — drops host map keys fully covered by edge deltas |
| **edge flags fold** | One-shot flag `wtt_q123_edge_flags_folded_v1` — own-attr RO/Hide host keys ? `edge.readOnly` / `edge.hidden` (skipped Mult?0..1 Hide) |
| **defaults fold** | One-shot flag `wtt_q123_defaults_folded_v1` — own-attr name-keyed Festwerte ? `edge.default` |
| **host maps prune** | One-shot flag `wtt_q123_host_maps_pruned_v1` — safe drop of own keys already on edge + empty typeExtras maps |
| **orphan slot purge** | One-shot flag `wtt_q123_orphan_slots_purged_v1` — true orphans only; parked bands + any edge `toId` + catalog kept |
| **own edge-read SoT** | One-shot flag **`wtt_q123_own_edge_read_sot_v1`** — fold remaining own Hide (incl. Q105 Mult?0..1) + leftover own RO/default/typeExtras ? edge; then own reads edge-only |
| **Q105 validator** | **0.0.426** — `background_only_needs_mult` + fixes `set_mult_01` / `clear_hide` (user-triggered); debt now on `edge.hidden` |
| **Settings walk Options** | **0.0.441** Walk-Wizard view+data; **0.0.443** + `allowedPrefixIds` on Unit/With-prefix levels (paint-honored) |
| **Prefix allowlist Walk** | **0.0.443** — `Settings.data.allowedPrefixIds` path delta; hybrid live from unit meta; With-prefix = attr restrict n unit marriage |
| **Host Preferred Preview** | **0.0.444** (was parallel 0.0.443) — Preview surface = host Settings → Preferred only; not attr/walk Render |
| **OQ-W11 unit structure** | **0.0.445** — With prefix Praefix+Kuerzel; size under quantity; Passiv Wert→size; smoke `_smoke-q123-unit-structure.php` |
| **Walk Default + compact UI** | **0.0.448** — Default override per walk level (edge.default / nested `data.default`); one-row compact controls; tree layout deferred |
| **Relations Name** | **0.0.433** — Name column for `besteht_aus`/`aggregation`; AJAX `wtt_update_relation_name`; payload exposes `name` |
| **Q90 parked bands** | **0.0.438** — Attributes hide; Relations `parkedTableBand` + **Q90 parked** badge/lock; edge names Zeile/Kopf/Fuss; fold `wtt_q123_parked_band_names_v1` |
| **Dup / reorder / move** | **0.0.439** — edge UUID ids; move preserves OQ-W4 fields + order; duplicate copies extras/default/RO/Hide; no slots |
| **UUID cast audit** | **0.0.434** — thorough grep; only fix: `shadowedAttrId` `(int)` ? `normalize_attr_id` (audit otherwise clean) |
| **Walk wizard / nested delta-edit** | **0.0.441 first usable slice** — path key = `/`-joined child Relation edge UUIDs from attribute target; writes only attribute edge |
| **Attributes panel** | **0.0.440** — open by default (`attributesPanelOpen: true`); rows already on `get_node` |
| **Invariants smoke** | **0.0.430** + **0.0.436** host-map asserts — `scripts/_smoke-q123-invariants.php` (Laragon `smoke=ok` at **0.0.439**) |
| **Inherited host maps** | **0.0.436** — `get_inherited_*` aliases + `inheritedHostOverride` + Inherited **override** badge; storage keys unchanged |
| **Relation override Options** | **0.0.437** — Preferred/converter/dateMode/validators labeled; Reset deletes edge Settings key |
| **Leftovers (polish)** | **3** untyped slot targets on parked **table** host (Zeile/Kopf/Fuss ids 4943——“4945 ? table 4882) — **hidden from Attributes**; Relations show **Q90 parked** (locked); edges kept until Q90 cleanup; Trash cascade still soft-deletes those slot terms via `toId`+`is_slot`. Live inventory: **0 true orphans**. |

### Critical path verify

| Check | Result |
|-------|--------|
| `Attribute::add` creates no slots | **OK** — Relation-only |
| AJAX / Model_Data value keys | **OK** — `normalize_attr_id` |
| Object_Render / Blocks instance value keys | **Fixed 0.0.416** |
| Preferred override `settings.view` | **Fixed 0.0.416** (camelCase); **encapsulated 0.0.423** |
| Compute source attr ids (admin JS) | **Fixed 0.0.416** — `wttAttrId`; pathAttrId also `wttAttrId` in **0.0.417** |
| Parked table band leftovers | **Safe hide** — `list_own_raw` skips untyped/parked bands; Relations mark **Q90 parked** (≈ 0.0.438) |
| **Settings walk helper** | **0.0.417** — `Settings_Walk` + Preferred wired in `decorate_row` |
| Blocks collection-table cell keys | **0.0.417** — `normalize_attr_id` (was `(string)(int)`) |
| **typeExtras ? edge Settings** | **0.0.418** bridge; **0.0.422** stop dual-write (edge SoT for own) |
| **Options paint edge Settings** | **0.0.419** — `attributeOptionsExtras()` prefers `attr.settings` deltas |
| **Trash / slot cascade safety** | **0.0.420** — leftover slots only (`toId`+`is_slot`); Model_Data soft-trash/restore/purge |
| **Composition attr columns** | **0.0.421** — `get_attribute_columns` / `normalize_rows` use `normalize_attr_id` |
| **typeExtras edge-only write** | **0.0.422** — own attrs ? Relation Settings only; host key cleared |
| **Preferred edge-only write** | **0.0.423** — `Attribute::set_preferred_render` ? `settings.view.preferredRenderer`; clear deletes key |
| **RO / Hide edge fields** | **0.0.424** write; **0.0.431** own read edge-only |
| **Default seed edge field** | **0.0.425** write; **0.0.431** own read edge-only |
| **Q105 BO?Mult rule+fixes** | **0.0.426** — validator + banner + `wtt_fix_attribute_rule` (`set_mult_01` / `clear_hide`) |
| **Settings walk Options summary** | **0.0.427** — `settingsWalk` levels + Options fold list (read-only; no AJAX; no second Form) |
| **Safe host-map prune** | **0.0.428** — own keys covered by edge cleared; empty typeExtras maps deleted; inherited kept |
| **Orphan slot purge** | **0.0.429** — true orphans deleted; parked bands kept; any edge `toId` / catalog protected |
| **Attributes fold** | **0.0.429** — collapsed by default (catch-up desk) |
| **Q123 invariants smoke** | **0.0.430** — combined CLI assert (edge ids / no slots / edge SoT / Wert nodeCount) |
| **Own-attr edge-only reads** | **0.0.431** — RO/Hide/default/typeExtras/preferred; fold `wtt_q123_own_edge_read_sot_v1` |
| **Walk level navigate** | **0.0.432** — `settingsWalk[].nodeId` + Options click ? `selectNode` (settings stay read-only) |
| **Relations Name UI** | **0.0.433** — show/edit `edge.name` for attribute bindings; sync via `Attribute::update` |
| **UUID cast audit** | **0.0.434** — `shadowedAttrId` edge UUID preserved; remaining casts are term/catalog/band ids |
| **Walk navigate UX** | **0.0.435** — Preferred readout + **Edit type settings** + hint; nested navigate-only |
| **Inherited host-map naming** | **0.0.436** — aliases + decorate flags + Inherited override badge; invariants own?host-map |
| **Relation override Options** | **0.0.437** — labeled hybrid chrome + Reset for Preferred/converter/date/validators |
| **Q90 parked band Relations** | **0.0.438** — `parkedTableBand` payload + badge/lock; AJAX reject; name fold |
| **Dup / reorder / move** | **0.0.439** — edge UUID paths; OQ-W4 transfer on move; RO/Hide copy on duplicate |

### Dirty / untracked summary (extra vs 0.0.438)

| Path | Extra (this resume ≈ 0.0.439) |
|------|--------------------------------|
| `includes/class-attribute.php` | `move_to_node` preserves edge fields + order; `edge_fields_from_edge` / `drop_host_edge_keys`; duplicate copies RO/Hide |
| `includes/class-relation.php` | `Relation::add` optional `$edge_fields` (readOnly/hidden/default) |
| `scripts/_smoke-q123-dup-move.php` | Duplicate + reorder + move round-trip smoke |
| `wp-taxonomy-tree.php`, `package.json`, living docs, handoff | **`0.0.439`** / plan **0.7.106** |

**Also dirty (0.0.438 parked bands + prior resumes):** Attribute Options Relation-override chrome, parked-band Relations UI, `get_inherited_*` / Inherited badge, invariants host-map asserts, Relation/Tree_Model/Tree_Ajax Relations Name + walk Edit, Composition, Trash, Attribute Q123 migrate, Settings_Walk, Model_Data*, Case_Data, Demo_Data, edge SoT, Object_Render, Blocks, Attribute_Validator Q105, Attributes fold, plan docs, prior `_smoke-q123-*` scripts.

**Untracked:**

| Path | Role |
|------|------|
| `includes/class-attribute-q123-migrate.php` | Idempotent migrate + folds/prunes + orphan slot purge + own-edge-read SoT |
| `includes/class-settings-walk.php` | Settings walk + typeExtras bridge helpers + summary flatten |
| `docs/plans/q123-migrate-handoff.md` | This handoff |
| `scripts/_smoke-q123-invariants.php` | **Primary** combined invariants smoke (UAT gate) |
| `scripts/_smoke-q123-composition-cols.php` | Composition column UUID smoke |
| `scripts/_smoke-q123-type-extras-edge.php` | Edge-only typeExtras write smoke |
| `scripts/_smoke-q123-preferred-edge.php` | Preferred edge write + clear-key smoke |
| `scripts/_smoke-q123-ro-hide-edge.php` | RO/Hide edge fold + Q105 Mult smoke |
| `scripts/_smoke-q123-default-edge.php` | Default seed edge fold + write smoke |
| `scripts/_smoke-q123-bo-mult-rule.php` | Q105 validator + fixes smoke (edge.hidden debt) |
| `scripts/_smoke-q123-settings-walk-summary.php` | Nested `settingsWalk` decorate summary smoke |
| `scripts/_smoke-q123-host-maps-prune.php` | Safe host-map prune smoke |
| `scripts/_smoke-q123-orphan-slots.php` | Orphan slot purge smoke |
| `scripts/_smoke-q123-slot-inventory.php` | Slot leftover inventory |
| `scripts/_smoke-q123-relation-name.php` | Relations Name payload + rename sync smoke |
| `scripts/_smoke-q123-parked-bands.php` | Q90 parked band hide + Relations lock smoke |
| `scripts/_smoke-q123-dup-move.php` | Duplicate / reorder / move edge-UUID smoke |
| `scripts/_smoke-q123-defaultvalue-from.php` | Q124 `defaultvalue_from` type + BOM Bauart link + create_linked seed |
| `scripts/_smoke-q123-trash.php` | Trash cascade smoke (prior; may be absent) |

---

## Product intent (locked)

Canonical: [`docs/DEVELOPER-ATTRIBUTE-MODEL.md`](../DEVELOPER-ATTRIBUTE-MODEL.md) — decisions: [`q123-doc-pass-questions.md`](q123-doc-pass-questions.md) (OQ-W1…W16).

- Attributes = **`besteht_aus` / `aggregation` Relation only** (`name` + type target).
- **No** new `_wtt_attribute_slot` terms; **`child_of` = inheritance only**.
- Instance / host map keys ? **Relation edge id**.
- Preferred override ? Relation `settings.view.preferredRenderer` (hybrid deltas) — **SoT ≈ 0.0.423** via `Attribute::set_preferred_render`.
- typeExtras (dateMode, validators, choiceFilter, compute, preferredConverter) ? Relation `settings.data` / `view` (**SoT + own read ≈ 0.0.431**); host map = **inherited overrides** only.
- **RO / Hide (OQ-W4 ≈ 0.0.424 write / 0.0.431 own read):** own attrs ? Relation edge fields `readOnly` / `hidden` only. Inherited hide cover-up + heir RO overrides ? host maps.
- **Default seed (OQ-W4 / Q106 ≈ 0.0.425 write / 0.0.431 own read):** own attrs ? Relation edge field **`default`** only. Inherited overrides ? host `_wtt_attribute_fixed_values` by **name**.
- **Q124 `defaultvalue_from` (≈ 0.0.442):** typed default-seed link (not Bindung). **From** consumer host ? **To** provider host; **name** = attribute (same name on provider). Materialize on create/empty after local `edge.default` — provider instance value else provider schema default. `Model_Data::create_linked` passes parent as `defaultProviders`. BOM seed: Position.Bauart ? Bauteilliste.Bauart. **No live cascade** (open nuance).
- **Q105:** own Hide = Background-only ? Mult **`0..1` only**; Mult change blocked while BO; inherited hide stays cover-up (no Mult gate). **Validator + fixes ≈ 0.0.426** (`set_mult_01` / `clear_hide`, never auto). Debt lives on **`edge.hidden`** after `wtt_q123_own_edge_read_sot_v1`.
- Settings walk (OQ-W16 ≈ **0.0.441**): same hybrid law for **Settings.view** + **Settings.data**. Depth 0 = top-level `settings.data`/`view`; nested = `settings.nested[<path>]` where path = `/`-joined composition Relation edge UUIDs from the attribute target. Options Walk-Wizard edits Preferred / converter / validators / dateMode per level; Reset deletes the key; never writes nested type nodes. Navigate (“Open type node”) remains secondary.
- **Relations Name (≈ 0.0.433 / Q124):** admin Relations table edits `Relation.name` for attribute bindings **and** `defaultvalue_from` (consumer attr name). `child_of` / other types leave name optional/empty.
- **Q90 parked bands (≈ 0.0.438):** Zeile/Kopf/Fuss stay hidden from Attributes; Relations show locked **Q90 parked** rows with names; AJAX mutations rejected; fold `wtt_q123_parked_band_names_v1`. Do **not** revive Collection `table`/`enum`/`list`.
- **UUID cast audit (≈ 0.0.434):** `effective_list` `shadowedAttrId` uses `normalize_attr_id` (edge UUID / legacy digit string). No other high-confidence attr-id `absint`/`(int)`/`parseInt` left in hot paths.
- **Host map cleanup (≈ 0.0.428 + 0.0.431):** prune + own-edge-read fold clear own keys; inherited overrides **kept**.
- **Orphan slot purge (≈ 0.0.429):** one-shot hard-delete of true orphan `_wtt_attribute_slot` terms (no edge `toId`, not catalog, not Zeile/Kopf/Fuss). Live `wtt_fs` had **0** orphans.
- **Attributes panel (≈ 0.0.429):** collapsed by default (catch-up desk); payload already on `get_node`.
- **Invariants smoke (≈ 0.0.430):** CLI gate for edge-id shape, no-slot create, Preferred/Default/RO on edge, Wert `nodeCount`.
- Delete host: leftover slots soft-trash; composition Model_Data dies with host; aggregation targets remain (OQ-W13 / Q111) — scaffold ≈ **0.0.420**.
- Model / table column keys for attribute hosts ? edge id — scaffold ≈ **0.0.421**.

### Schema pick — Default seed key

| Choice | Decision |
|--------|----------|
| Storage | Relation **edge field** `default` (OQ-W4 table: name, target, Bindung, Mult, RO, Hide/BO, **default**) |
| Not used | `settings.data.default` / `defaultSeed` as SoT (alias `defaultSeed` accepted on **read** only; writes normalize to `default`) |
| Shape | Always a **list** when present (Q106); omit key when empty |
| API | `Relation::update_default` — `Attribute::set_fixed_values` — `Attribute::normalize_default_seed` |

---

## Done in this working tree (≈ 0.0.415——“0.0.439)

1. Relation-only `Attribute::add` + migrate + live `wtt_fs` (**0.0.415**).
2. Value-key `absint` wipe fixed in Model_Data / Admin / Tree_Ajax / fixed maps (**0.0.415**).
3. **Object_Render / Blocks** value-key int casts fixed (**0.0.416**).
4. **Preferred override** persists (camelCase settings keys + wire Renderer ids) (**0.0.416**).
5. **Compute** admin JS keeps string edge ids (**0.0.416**); pathAttrId too (**0.0.417**).
6. **Parked table bands** hidden from Attribute list (**0.0.416**).
7. **`Settings_Walk`** recursive gather + hybrid Preferred resolve wired into `decorate_row` (**0.0.417**).
8. **typeExtras bridge** (**0.0.418**): edge Settings preferred on read; dual-write on save for own edges; one-shot fold for existing host maps.
9. **Options paint** (**0.0.419**): admin Attributes Options prefer edge `settings` deltas ? typeExtras fallback; `settingsResolved` only when override flags say so; optional walk `nodeCount` hint; saves unchanged via dual-write AJAX.
10. **Trash / slot legacy safety** (**0.0.420**):
    - `collect_owned_attribute_slot_ids` no longer `(int) $row['id']` (UUID prefix ? wrong term id — e.g. Kontakt edge `6665fb9f…` ? `6665`).
    - Collects only edge `toId` when `Attribute::is_slot` (incl. untyped parked leftovers).
    - Catalog type targets never cascaded.
    - `Model_Data::soft_trash_all_for_structure` / restore / purge wired into Trash move / restore / empty (Q111 composition children).
    - `detach_from_hierarchy_parent` no-op unless slot term.
11. **Composition attribute columns** (**0.0.421**):
    - `get_attribute_columns` uses `Attribute::normalize_attr_id` (was `(int)` — empty cols for letter-prefix UUIDs, or wrong prefix int for digit-prefix UUIDs).
    - `normalize_rows` always normalizes via `Attribute::normalize_attr_id` (dropped absint fallback).
    - `scripts/assert-collection-block.php` cell key fixed the same way.
    - Catalog / legacy table column `(int)` casts left alone (term ids; Q90 parked — do not revive).
12. **Stop typeExtras dual-write** (**0.0.422**):
    - `Attribute::set_type_extras`: own edge ? `Relation::update_settings` only; **clear host key** on success.
    - Inherited attrs (no local edge) ? host map override only (hide/ro untouched).
    - One-shot prune `wtt_q123_type_extras_pruned_v1` drops host keys covered by edge deltas.
    - Risk note: edge-only accepted — fold already copied host?edge; failure path leaves host map unchanged (no partial clear).
13. **Preferred edge encapsulate + clear semantics** (**0.0.423**):
    - `Attribute::set_preferred_render` writes `settings.view.preferredRenderer` on the Relation edge (find by edge UUID / legacy toId).
    - AJAX `wtt_set_attribute_preferred_render` no longer gates on `is_slot( $attr_id )` or slot term meta.
    - Clear (`''` / `inherit` / `default`) **deletes** the delta key (does not store empty string).
    - `resolve_preferred_render`: `hasOverride` only for non-empty edge delta or leftover slot meta; exposes `preferredSource` (`edge`|`legacy`|`walk`|`type`).
    - Leftover slot `_wtt_preferred_render` deleted on set/clear so legacy cannot keep override.
    - `settingsWalkMeta` gains `preferredSource` + `hasPreferredOverride`; Options hint shows source when override.
    - Laragon smoke: Kontakt/Titel ? edge CompactRenderer; clear removes key; `smoke=ok`.
14. **RO / Hide ? Relation edge fields** (**0.0.424**, OQ-W4):
    - Audit: host `_wtt_attribute_readonly` / `_wtt_hidden_attributes` were lists keyed by edge id; Hide was inherited-only; RO own+inherited on host map.
    - `Relation::read_edges` / `write_edges` / `hydrate_edge` now preserve `readOnly` + `hidden` (true keys only).
    - Own attrs: `Attribute::set_readonly` ? `Relation::update_read_only`; `set_hidden` ? `Relation::update_hidden` + **Q105** Mult must be `0..1` when enabling; host map key cleared.
    - Inherited: Hide cover-up + heir RO override stay on host maps (do not mutate father’s edge).
    - Mult change blocked while `edge.hidden` and new Mult ? `0..1`.
    - Admin UI: own Hide switch enabled when Mult is `0..1`.
    - One-shot fold `wtt_q123_edge_flags_folded_v1` (own host keys ? edge; Mult?`0..1` hide keys left as debt until 0.0.431).
    - Laragon smoke: `ro_hide_edge_write=yes`, BO blocked on Titel Mult=`1` (`wtt_bo_mult`), fold flag yes; `smoke=ok`.
15. **Default seed ? Relation edge field** (**0.0.425**, OQ-W4 / Q106):
    - Audit: host `_wtt_attribute_fixed_values` was **name-keyed** (not edge-id); rename remapped keys; inherited could not see father’s seed via edge.
    - Canonical key: **`edge.default`** (list). Not Settings. Alias `defaultSeed` accepted on read only.
    - `Relation::update_default` + preserve in `read_edges` / `write_edges` / `hydrate` (bug fix: `read_edges` must copy default or dirty rewrite strips it).
    - Own: `Attribute::set_fixed_values` ? edge + clear host name key.
    - Inherited: host name-map override only (no father edge mutation).
    - One-shot fold `wtt_q123_defaults_folded_v1` (own name keys ? edge; leftover inherited names stay on host).
    - Laragon smoke: `default_edge_write=yes`, fold + API + decorate; `smoke=ok`.
16. **Q105 BO?Mult Bindings?Rules?Fixes** (**0.0.426**):
    - `Attribute_Validator::RULE_BACKGROUND_ONLY_NEEDS_MULT` on **own** rows with `hidden` and Mult ? `0..1`.
    - Fixes (user-triggered, never auto): `set_mult_01` ? `Attribute::set_multiplicity(…, '0..1')`; `clear_hide` ? `Attribute::set_hidden(…, false)`.
    - Wired through existing `wtt_fix_attribute_rule` + `attributeValidation` node payload + admin attribute banner.
    - `apply_fix` attr id type fixed to **string** (UUID edge ids; was `int` — would coerce digit-prefix UUIDs).
    - Client mirror in `resolveAttributeValidation` when PHP payload absent; banner title switches by rule set.
    - Laragon smoke: `bo_mult_rule=yes` (report + both fixes); `smoke=ok`.
17. **Bounded Settings walk Options summary** (**0.0.427**):
    - Reuse the walk already done in `Settings_Walk::resolve_preferred_render` (no second recursion / no AJAX).
    - `summary_from_walk` flattens tree ? `{ depth, name, edgeName, preferred, hasDelta, cycleStopped }` (cap `SUMMARY_MAX_NODES=24`).
    - Only when nested (`nodeCount > 1` or `depth > 0`) ? `decorate_row` sets `settingsWalk`.
    - Attributes Options fold: compact read-only list (`renderSettingsWalkSummary`) — no second panel, no parallel Form UI, no delta edit.
    - Laragon smoke: Passiv/Wert ? `nodeCount=3`, `levels=3`, `walk_summary=yes`; `smoke=ok`.
18. **Safe host-map prune** (**0.0.428**):
    - One-shot `wtt_q123_host_maps_pruned_v1` after folds/prunes in `maybe_migrate`.
    - Own `_wtt_attribute_readonly` / `_wtt_hidden_attributes` edge-id keys removed **only** when `edge.readOnly` / `edge.hidden` already true (no new fold).
    - Own `_wtt_attribute_fixed_values` **name** keys removed **only** when `edge.default` already present.
    - Own typeExtras keys covered by edge deltas dropped; **empty** `_wtt_attribute_type_extras` maps deleted.
    - Inherited override host-map entries **kept** (no local own edge).
    - Laragon smoke: `host_maps_prune=yes`, `inherited_ro_kept=yes`; `smoke=ok`.
19. **Orphan slot purge + Attributes fold** (**0.0.429**):
    - Live inventory (`_smoke-q123-slot-inventory.php`): **3** parked bands (Zeile/Kopf/Fuss ? table 4882); **0** true orphans; **0** non-band referenced slots.
    - One-shot `wtt_q123_orphan_slots_purged_v1` via `maybe_purge_orphan_slots` — deletes only when `slot_safe_to_purge` (is_slot, not Zeile/Kopf/Fuss/aliases, not under type catalog, **no** incoming edge `toId` of any kind).
    - `delete_orphan_slots` / `slot_still_targeted` strengthened to any edge toId (not only attribute bindings).
    - Catch-up desk: Attributes panel `<details>` collapsed by default (`attributesPanelOpen`); table built only when open; count badge on summary.
    - Laragon smoke: `orphan_slots_purge=yes`, `bands_kept=yes`; post-purge inventory still 3 bands; `smoke=ok`.
20. **Q123 invariants smoke + UAT gate** (**0.0.430**):
    - `scripts/_smoke-q123-invariants.php` on Kontakt / Widerstand / Passiv.
    - Asserts: own attr ids = sanitize_key edge UUIDs (not pure term ints); no `legacySlotId`; `Attribute::add` ? type `toId` (no slot); Preferred / Default / RO write probes land on edge (host RO key cleared); Passiv/**Wert** `settingsWalkMeta.nodeCount=3`.
    - Note: Widerstand has **0** own attrs (inherits from Passiv) — Wert check uses Passiv.
    - Laragon: `invariants=yes`, `smoke=ok`. No product bugs found.
    - Handoff status ? **core migrate ready for user UAT**.
21. **Own-attr edge-only reads** (**0.0.431**):
    - `effective_list`: host RO/Hide maps apply **only** when `inherited`.
    - `resolve_fixed_values`: own = `edge.default` only (no host name-map fallback).
    - `decorate_row` typeExtras: own = `Settings_Walk::type_extras_from_deltas` only; inherited keeps hybrid host override.
    - Preferred already edge/walk-based for own (unchanged).
    - One-shot **`wtt_q123_own_edge_read_sot_v1`**: folds remaining own host Hide **including Mult ? 0..1** onto `edge.hidden`, plus leftover own RO/default/typeExtras gaps, then clears those own host keys. Inherited keys kept.
    - Q105 debt now surfaces via `edge.hidden` (validator unchanged). BO-mult smoke plants edge Hide, not host-map.
    - Laragon: invariants `smoke=ok`; bo-mult `smoke=ok`; fold flag `wtt_fs=1`.
22. **Settings walk level navigate** (**0.0.432**):
    - `summary_from_walk` / flatten includes **`nodeId`** (already on walk tree nodes).
    - Attributes Options list: each level with `nodeId > 0` is a button-link ? existing `selectNode` (expand ancestors + load node). Settings display stays read-only; no delta edit / no wizard.
    - Laragon: walk-summary `has_nodeIds=yes` + invariants `smoke=ok`.
23. **Relations Name for attribute bindings** (**0.0.433**):
    - `hydrate_edge` / `list_outgoing` already exposed `name`; `list_incoming` + `relationsStored` now include it.
    - Relations admin table: **Name** column after Relation type — editable for `besteht_aus` / `aggregation` / legacy `composition`; dash for other types (`child_of` stays empty/optional).
    - AJAX `wtt_update_relation_name` ? attribute bindings via `Attribute::update` (fixed_values key remap + legacy slot rename); others via `Relation::update_name`.
    - `wtt_add_relation` accepts `name` (required for attribute bindings); Add flow prompts for name; same-To allowed when names differ.
    - `Attribute::mark_as_slot` documented **legacy-only** (`@deprecated`); still used by detach/migrate leftover slots — not Attribute::add.
    - Laragon: `_smoke-q123-relation-name.php` + invariants `smoke=ok`.
24. **UUID attr-id cast audit** (**0.0.434**):
    - Grepped `includes/` + `assets/js` for `absint` / `(int)` / `parseInt` on attr ids, value keys, `attr_id`, `pathAttrId`, `shadowedAttrId`, fixed-map keys.
    - **One fix:** `Attribute::effective_list` `shadowedAttrId` was `(int)` of inherited edge id ? `0` for UUIDs; now `normalize_attr_id` (default `''`).
    - Admin UI today uses `shadowsInherited` + `shadowsDefinedOnName` only; field kept correct for payload / future consumers.
    - Left intentional: catalog/table/band/term `(int)` casts, `legacySlotId`, edge `toId`/`typeId`/`fromId`, `wttAttrId` for attrs, node `parseInt` for taxonomy terms.
    - Laragon invariants `smoke=ok` at **0.0.434**.
25. **Walk navigate UX polish** (**0.0.435**):
    - Options `settingsWalk` list already showed live Preferred per level + clickable name ? `selectNode` (0.0.432).
    - Attribute Relation Preferred **override** remains the Options Preferred select above (edge `settings.view.preferredRenderer`) — not a second control in the walk list.
    - Nested levels stay **navigate-only** (no dangerous write to nested type Settings from the walk list; no confirm dialog path).
    - Added short hint + explicit **Edit type settings** button per navigable level (same `selectNode`).
    - **Full Walk wizard / in-list delta edit deferred post-UAT** (user UAT first).
    - Laragon invariants `smoke=ok` at **0.0.435**.
26. **Inherited host-map naming clarity** (**0.0.436**):
    - META docs + public aliases `get_inherited_hidden_ids` / `readonly` / `fixed_values_map` / `type_extras_map` (storage keys unchanged).
    - `decorate_row` ? `inheritedHostOverride` `{hidden,readonly,default,typeExtras,any}` (own always false).
    - Attributes Inherited column: **override** badge + tooltip when host-local override active; help text distinguishes edge vs host maps.
    - Invariants: own attrs must not keep RO/Hide/typeExtras/default on host maps; Widerstand inherited Hide round-trip.
    - Laragon invariants `smoke=ok` at **0.0.436**.
27. **Relation override Options chrome** (**0.0.437** — first real delta-edit slice):
    - Attributes Options: intro **Relation overrides** + hint (hybrid `Settings.view`/`data` on attribute edge).
    - Preferred / converter / dateMode / validators: **Relation override** badge when delta present; **Reset override** deletes the edge Settings key (same clear path as Type default empty / existing AJAX).
    - Nested `settingsWalk` levels stay navigate / **Edit type settings** only — no nested type default writes from the attribute panel.
    - Laragon invariants `smoke=ok` at **0.0.437**.
28. **Q90 parked table bands clarity** (**0.0.438**):
    - Confirmed Attributes still hide Zeile/Kopf/Fuss (`list_own_raw` untyped + parked-name skip); table host own attrs = 0.
    - Relations: `parkedTableBand` + `protected`/`typeLocked`; Name cell **Q90 parked** badge; no edit/dup/reorder/target pick; AJAX mutations rejected.
    - Ensure edge names = term names (already Zeile/Kopf/Fuss on live `wtt_fs`); one-shot `wtt_q123_parked_band_names_v1`.
    - Public helpers `Attribute::is_parked_table_band_term` / `is_parked_table_band_edge` (migrate reuses).
    - Do **not** revive Collection `table`/`enum`/`list`.
    - Laragon parked-bands + invariants `smoke=ok` at **0.0.438**.
29. **Attribute duplicate / reorder / move edge-UUID audit** (**0.0.439**):
    - Audit: `reorder` / `duplicate` / `move_*` already used `normalize_attr_id` + `Attribute::add` / Relation edges (no slot create).
    - **Fix:** `move_to_node` was dropping OQ-W4 edge fields (`readOnly`/`hidden`/`default`) and host order meta; now transfers via `Relation::add(…, $edge_fields)` + `drop_host_edge_keys` / append order on target.
    - **Fix:** `duplicate` now copies RO/Hide onto the new own edge (Q105 `wtt_bo_mult` non-fatal for Hide).
    - `Relation::add` accepts optional `$edge_fields` for atomic OQ-W4 write.
    - Laragon `_smoke-q123-dup-move.php` + invariants `smoke=ok` at **0.0.439**.

30. **Walk-Wizard view+data overrides** (**0.0.441** / OQ-W16):
    - Storage: depth 0 ? `settings.data`/`view`; nested → `settings.nested[<path>]` with path = `/`-joined child Relation edge UUIDs from attribute target.
    - Resolve: hybrid live + type-tree child-edge bag + attribute path deltas (path wins).
    - API: `Attribute::set_walk_settings_key` + AJAX `wtt_set_attribute_walk_settings` — never `Node_Type::set_preferred` on nested types.
    - Options UI: each `settingsWalk` level edits Preferred + converter (view) and validators + dateMode (data); Reset deletes key; “Open type node” secondary.
    - Laragon `_smoke-q123-walk-nested-overrides.php` `smoke=ok` (Passiv/Wert nested Preferred + validators).

31. **Prefix allowlist Walk bridge** (**0.0.443**):
    - `Settings.data.allowedPrefixIds` on Unit / With-prefix walk levels (`supportsPrefixAllowlist` via unit leaf / prefix bucket).
    - Live: unit `_wtt_allowed_prefix_ids`; With-prefix omits live key.
    - Paint: `Attribute::apply_walk_prefix_allowlist_to_row` intersects Unit `fixedOptions.allowedPrefixes` / quantitySchema — not chrome-only.
    - UI: Walk-Wizard multi-check + Relation-override Reset; catalog unit meta never written.
    - Laragon `_smoke-q123-prefix-allowlist-walk.php` `smoke=ok` (Passiv/Wert).

32. **Host Preferred Preview + version reconcile** (**0.0.444**):
    - Parallel agents both claimed ≈ **0.0.443** (prefix allowlist + Preview Preferred).
    - Preview: `renderAttributeHostPreview` uses **host** Preferred only; hint `previewPreferredOnlyHint`; attr/walk Render does not change host Preview.
    - Bump working tree to **0.0.444** (header / `WTT_VERSION` / `package.json`) with both features present — no expansion.
    - Laragon recheck: prefix-allowlist + defaultvalue_from `smoke=ok` at **0.0.444**.

33. **OQ-W11 unit structure seed** (**0.0.445**):
    - `Case_Data::ensure_with_prefix_composition` — Praefix→Präfixe—fixe, Kuerzel→text (`besteht_aus`); wired into `ensure_unit_catalog`.
    - `Case_Data::ensure_size_datatype` — `size` child_of `quantity`; Value?double, Unit→With prefix; Preferred QuantityRenderer.
    - `Case_Data::ensure_passiv_wert_size` — Passiv Wert→size when missing / soft-typed (not Bauteil).
    - Public `ensure_unit_quantity_structure`; Demo `quantity_member_slots` ? Wert→size only.
    - Unit leaves: **no** fake attribute slots; `synthesize_unit_quantity_members` kept for display debt.
    - Laragon `_smoke-q123-unit-structure.php` `smoke=ok`; Settings_Walk size `nodeCount=5` (size/Value/Unit/Praefix/Kuerzel).

---

## Remaining polish (short)

Walk-Wizard first usable slice landed (**0.0.441**). **Not DONE** until modeling UAT. Commit only when asked.

| # | Item | Notes |
|---|------|--------|
| 1 | **UAT modeling** | Hard reload ? Passiv/Wert + Toleranz: Walk view/data + Unit→With-prefix allowlist; host Preferred for Preview; confirm type nodes unchanged |
| 2 | **Commit** | Only when user asks; draft covers **0.0.415–0.0.449** |
| 3 | **Unit / Quantity display** | Stop synthetic Typ/Praefix/Kuerzel on unit leaves; UnitRenderer compose from With-prefix Relations + leaf symbol (structure done 0.0.445) |
| 4 | **Walk tree layout** | Deferred — compact one-row (0.0.448) preferred after UAT; revisit only if one-row still too heavy |
| 4 | **Walk polish** | choiceFilter / compute path deltas; richer validator errorText per level if UAT needs it |
| 5 | **Quantity / unit Settings on walk** | **Prefix allowlist ≈ 0.0.443** done; setSeparator / setJoinUnits / Q120 still deferred |
| 6 | **Q90 table band removal** | 3 leftovers clarified in Relations (0.0.438); full delete with Q90 cleanup only (do not revive Collection `table`). |
| 7 | **Host meta key rename** | Optional — aliases cover clarity; renaming `_wtt_*` strings needs a one-shot migrate (low value now). |

### Quantity / unit Settings on Walk levels

**Ask:** After 0.0.441, do quantity/unit knobs appear as overridable `Settings.data` on walk levels?

| Knob | Today SoT | Walk-Wizard |
|------|-----------|-------------|
| Preferred / converter | `Settings.view` (+ nested path) | **Yes** (0.0.441) |
| validators / dateMode | `Settings.data` (+ nested path) | **Yes** (0.0.441) |
| **Prefix allowlist** | Unit term meta `_wtt_allowed_prefix_ids` (catalog marriage) + attr delta `Settings.data.allowedPrefixIds` | **Yes** (≈ **0.0.443**) — Unit / With-prefix levels; paint intersects Unit `fixedOptions.allowedPrefixes` / quantitySchema; catalog meta unchanged |
| choiceFilter / compute | typeExtras ? `Settings.data` bridge | **No UI** on walk (polish) |
| **setSeparator / setJoinUnits / setLabelChildren** | Set-node term meta | **No** — deferred |
| **Q120 unit rules** (dimension, conversion engine, …) | Planning / product intent | **No** |

**0.0.443 notes**

- Key: `Settings.data.allowedPrefixIds` (int prefix term ids). Presence = override; empty list = L1 (no prefixes) for this attribute.
- Live: unit leaf ? `_wtt_allowed_prefix_ids`; With-prefix bucket ? no live key (unit marriage only until override).
- Detect via `is_basiseinheit_unit_node` / `is_unit_prefix_bucket` + `prefixCatalog` — never hard-code `"size"`.
- Smoke: `_smoke-q123-prefix-allowlist-walk.php` (Passiv/Wert Unit path) Laragon `smoke=ok`.
- Still deferred: setSeparator / join-units; Q120 rule profiles.

Longer historical debt notes (legacy `mark_as_slot`, other envs, optional JS walk mirror, etc.) stay in earlier sections / DEVELOPER-ATTRIBUTE-MODEL.

### Path-key design (locked for 0.0.441)

| Choice | Decision |
|--------|----------|
| Path segments | Relation **edge UUID** along composition walk from attribute target |
| Join | `/` (e.g. `edgeA` or `edgeA/edgeB`) |
| Depth 0 | Top-level `settings.data` / `settings.view` (not under `nested`) |
| Not used | Node ids, display names, or recursive nested-in-nested trees |

### Draft commit message (when user asks — do **not** run git commit)

```text
Q123 Relation-only attribute migrate through 0.0.448: edge SoT, Settings walk view+data Walk-Wizard (path-keyed nested deltas incl. allowedPrefixIds + Default), compact one-row walk UI, host Preferred Preview, Q124 defaultvalue_from, OQ-W11 unit structure seed (With prefix / size / Passiv Wert), Relation overrides, Q90 parked bands, dup/move edge-UUID safety, invariants smokes.

Uncommitted work on checkpoint ed9c5eb (0.0.414); Walk-Wizard + prefix allowlist + Preview Preferred + unit structure usable but Q123 UAT still open; unit display synthesize still debt.
```

### Grep notes (0.0.434) — remaining `(int)` / `parseInt` on ids

**High-confidence UUID attr-id casts: audit clean** after `shadowedAttrId` fix. Prior clears: Composition columns + Model_Data / Object_Render / Blocks / Trash / Preferred / typeExtras / RO-Hide / Default / compute `pathAttrId` / validator `apply_fix`.

Left intentionally (not attr edge ids):

| Area | Why OK |
|------|--------|
| `Composition` catalog / table column `(int) $col['id']` | Term / band field ids (Q90 parked table / catalog slots) |
| `Composition::list_all_collections` `(int) $row['id']` | Host term ids |
| `Relation` / `Attribute` `(int) $edge['toId'|'typeId'|'fromId']` | Term ids on the edge, not edge UUID |
| `Attribute::unit_allowed_prefix_options` `(int) $row['id']` | Prefix term ids |
| `Object_Render::reference_display_label` `absint` | Stored **node_ref term** ids / option ids, not attr edge keys |
| `legacySlotId` `(string)(int)` | Numeric legacy only |
| Admin JS `parseInt` on `node.id` / `termId` / `propBindings` / `typeId` | Taxonomy terms / table prop bindings / type terms — not Attribute edge keys (`wttAttrId` covers attrs) |
| `wtt-node-render` `parseInt(f.id)` on node_ref create fields | Catalog scalar slot term ids |
| `wtt-object-render` `parseInt(opt.id)` | CatalogChoice / fixed option **term** ids |

Do **not** “fix” parked table band product surfaces (Q90).

### `is_slot` grep — Relation-only / Preferred / Trash

No remaining `is_slot( $attr_id )` gates that block Preferred / typeExtras / RO-Hide / Default on **edge ids**. Preferred/RO/Hide/Default saves use edge lookup; leftover slot meta cleared as side effect where applicable. Remaining `is_slot` uses are intentional (legacy slot branch, Settings_Walk resolve, catalog filters, leftover Trash cascade). Do **not** remove without a leftover-slot purge plan.

---

## How to resume

1. Read this handoff — status is **ACTIVE / UAT**. Working tree ≈ **0.0.449**. Walk-Wizard Default + compact row shipped; needs modeling confirmation before calling Q123 done. Next display slice: UnitRenderer from With-prefix attrs (stop synthesize). Tree walk layout still deferred.
2. Confirm version strings **`0.0.449`** and untracked PHP classes (`attribute-q123-migrate`, `settings-walk`).
3. **Do not** invent a second Attribute CRUD path; extend Relation-only APIs + `Settings_Walk` only when asked.
4. Harden walk UX from UAT; do not invent a parallel override store.
5. **Do not commit** unless the user asks (use draft message above). Rollback checkpoint remains `ed9c5eb` / `0.0.414`.
6. Smoke on Laragon (`http://devel.test`) — **hard reload** admin (Ctrl+F5 / empty cache).
7. CLI gate: Laragon php 8.3.30 + `wp-cli.phar` from `C:\devel\wordpress` ? `_smoke-q123-prefix-allowlist-walk.php` + `_smoke-q123-defaultvalue-from.php` (+ walk nested / invariants as needed).

### Recommended next

| Priority | Action |
|----------|--------|
| **1** | **Hard reload** ? host Preferred Preview + Passiv/Wert Walk view/data + Unit→With-prefix allowlist; Reset; type nodes unchanged |
| **2** | Mark Q123 closer to done only after user UAT |
| **3** | Commit when user asks (**0.0.415–0.0.449**) |
| — | setSeparator / join-units / Q120 still deferred; skip host meta **key rename** / full Q90 band **delete** unless asked |

### Clear UAT checklist (primary)

**Prep:** hard reload Tree admin (`http://devel.test` — Ctrl+F5). Plugin ≈ **0.0.449**, dirty on checkpoint `ed9c5eb`.

| # | Check | Pass? |
|---|--------|-------|
| 1 | **Hard reload** — no stale admin JS; Attributes panel starts **open** (0.0.440); version **0.0.449** | [ ] |
| 2 | **Host Preferred Preview** — change host Settings → Preferred; Preview shows that surface only (Editable + Display); attr/walk Render overrides do **not** change host Preview | [ ] |
| 3 | **Walk wizard view/data** — Passiv/Wert Options ? Composition walk: compact one-row; edit Preferred / converter / **Default** / validators / dateMode per level (path-keyed deltas on attr edge) | [ ] |
| 3b | **Walk Default override** — set Default on depth 0 (? `edge.default` + Attributes column) and a nested level (`settings.nested[…].data.default`); live type seed shown; Reset clears; ? marks override | [ ] |
| 4 | **Walk Unit ? With-prefix allowlist** — on a Unit / With-prefix walk level: override `allowedPrefixIds`; paint intersects; Reset clears; catalog unit meta unchanged | [ ] |
| 5 | **Q124 `defaultvalue_from` (0.0.442)** — BOM Bauart seed on create/empty still OK (Position ? Bauteilliste) | [ ] |
| 6 | **Walk Reset** — badge + Reset deletes path key; nested type Preferred unchanged; “Open type node” still navigates | [ ] |
| 7 | **Kontakt** — own attrs show string edge ids; Preferred / Default / RO / Hide behave (own ? edge SoT) | [ ] |
| 8 | **Duplicate** — duplicate an attr on Kontakt ? new edge id; no new slot term; RO/Hide/Default copy when set | [ ] |
| 9 | **Fill Model Data** / Object View — instance values still bind to edge ids | [ ] |
| 10 | **Relations Name** — `besteht_aus` / `aggregation` Name editable; rename syncs Attributes ? Name; `child_of` stays `—` | [ ] |
| 11 | **Inherited override** — on Widerstand, Hide an inherited attr ? Inherited column shows **override** badge; own attrs never show it | [ ] |
| 12 | **Q90 parked bands** — on catalog `table`: Attributes empty of Zeile/Kopf/Fuss; Relations shows three **Q90 parked** locked rows with those names | [ ] |

### Extended UAT notes (optional detail)

Use when digging into edge cases; not required for first pass:

- Own RO ? `edge.readOnly` (host map alone must not mark own attr readonly). Own Hide only when Mult `0..1` (`wtt_bo_mult` otherwise). Own Default ? `edge.default`.
- Q105: BO with Mult ? `0..1` ? banner fixes `set_mult_01` / `clear_hide` (never auto); debt on `edge.hidden`.
- Inherited Hide / Default / RO still on host maps. Options saves clear own host typeExtras keys.
- Migrate flags `[wtt_fs]=1`: type_extras fold/prune, edge_flags, defaults, host_maps, orphan_slots, own_edge_read_sot.
- Table host must **not** list Zeile/Kopf/Fuss in Attributes; Relations may show them as **Q90 parked** (locked). Soft-delete Model host still cascades composition Model_Data.
- CLI: `_smoke-q123-invariants.php` (+ `_smoke-q123-dup-move.php`, optional `_smoke-q123-parked-bands.php`, `_smoke-q123-relation-name.php`, `_smoke-q123-bo-mult-rule.php`).

### Smoke result (0.0.439, Laragon) — dup / reorder / move + invariants

```text
WTT_VERSION=0.0.439
host=Kontakt id=4961
source_id=6665fb9fdb2449f388b443bafbe04d5a
source_name=Titel
dup_id=d3fd20129d6548e6a90c1254faf5d61e
dup_toId=4871
dup_no_slot=yes
dup_name=Titel (copy)
reorder_ok=yes
child_id=5168
moved_id=bdb3641a06254bfb81dc0d0879b478c4
move_on_child=yes
move_ro_kept=yes
move_no_slot=yes
gone_from_host=yes
back_id=6f1ddbd8f3ef4ba7bc47191cb54f4058
back_ro_kept=yes
dup_move=yes
smoke=ok
```

```text
WTT_VERSION=0.0.439
host_Kontakt_attrs=9
host_Widerstand_attrs=0
host_Passiv_attrs=5
wert_nodeCount=3
wert_walk=yes
add_probe=no_slot
preferred_on_edge=yes
default_on_edge=yes
readonly_on_edge=yes
edge_write_host=Titel
inherited_host_map_flags=yes (rows=5)
inherited_hide_host_map=yes
wert_host=Passiv
invariants=yes
smoke=ok
```

(Dup/move temp child + duplicate attr cleaned up. Write probes on Kontakt/Titel + Widerstand Hide restored after invariants.)

### Smoke result (0.0.438, Laragon) — parked bands + invariants (prior)

Parked-bands + invariants `smoke=ok` at **0.0.438** (same gates as 0.0.439 minus dup/move).

### Smoke result (0.0.437, Laragon) — Q123 invariants (prior)

Same core gates as 0.0.439 (`WTT_VERSION=0.0.437`).

### Smoke result (0.0.436, Laragon) — Q123 invariants (prior)

Same core gates as 0.0.439 (`WTT_VERSION=0.0.436`).

### Smoke result (0.0.435, Laragon) — Q123 invariants (prior)

Same core gates without host-map / inherited override asserts (`WTT_VERSION=0.0.435`).

### Smoke result (0.0.434, Laragon) — Q123 invariants (prior)

Same as 0.0.435 with `WTT_VERSION=0.0.434` — UUID `shadowedAttrId` gate.

### Smoke result (0.0.433, Laragon) — Relations Name sync

```text
WTT_VERSION=0.0.433
host=Kontakt id=4961
attr_id=52b49d717389431bad680d2e3cce0b98
attr_name=Name
payload_name=yes
von_has_name=yes
edge_renamed=yes
attr_synced=yes
restored=yes
child_of_ok=yes
relation_name=yes
smoke=ok
```

### Smoke result (0.0.432, Laragon) — Settings walk summary + nodeIds

```text
WTT_VERSION=0.0.432
host=Passiv id=4981
attr_id=cc2aa43e771a4cac8890a0448e96fc43
attr_name=Wert
nodeCount=3
depth=1
settingsWalk_levels=3
has_names=yes
has_preferred=yes
has_nodeIds=yes
root_name=size
root_nodeId=5162
root_preferred=QuantityRenderer
child_edgeName=Value
child_name=double
child_nodeId=4870
walk_summary=yes
smoke=ok
```

### Smoke result (0.0.431, Laragon) — Q105 BO?Mult (edge debt)

```text
WTT_VERSION=0.0.431
host=Kontakt id=4961
attr_id=6665fb9fdb2449f388b443bafbe04d5a
attr_name=Titel
mult_before=1
report_ok=yes
fix_clear_ok=yes
fix_set_mult_ok=yes
bo_mult_rule=yes
smoke=ok
```

(Planted `edge.hidden` with Mult=`1`; both fixes exercised; prior Mult/Hide restored.)

### Smoke result (0.0.422, Laragon) — typeExtras

```text
WTT_VERSION=0.0.422
host=Kontakt id=4961
attr_id=6665fb9fdb2449f388b443bafbe04d5a
attr_name=Titel
typeKey=text
host_had_key_before=no
host_has_key_after=no
edge_preferredConverter=roman
decorate_preferredConverter=roman
prune_flag=yes
edge_only_write=yes
smoke=ok
```

(Smoke write cleared afterward via `set_type_extras(..., null)`.)

### Smoke result (0.0.423, Laragon) — Preferred

```text
WTT_VERSION=0.0.423
host=Kontakt id=4961
attr_id=6665fb9fdb2449f388b443bafbe04d5a
attr_name=Titel
marker=CompactRenderer
edge_preferredRenderer=CompactRenderer
decorate_preferredRender=CompactRenderer
hasOverride=yes
settingsWalkMeta.preferredSource=edge
legacy_slot_had_meta_before=no
legacy_slot_has_meta_after=no
clear_deleted_delta_key=yes
override_after_clear=no
preferred_edge_write=yes
smoke=ok
```

(Prior edge settings restored after smoke.)

### Smoke result (0.0.424, Laragon) — RO / Hide

```text
WTT_VERSION=0.0.424
host=Kontakt id=4961
attr_id=6665fb9fdb2449f388b443bafbe04d5a
attr_name=Titel
mult=1
fold_edge_ro=yes
fold_host_ro_cleared=yes
api_edge_readOnly=yes
api_host_ro_cleared=yes
decorate_readonly=yes
bo_path=blocked
bo_ok=yes
bo_blocked_ok=yes
hide_cleared=yes
fold_flag=yes
ro_hide_edge_write=yes
smoke=ok
```

(Prior edge RO/Hide restored after smoke. Titel Mult=`1` ? own Hide correctly rejected with `wtt_bo_mult`.)

### Smoke result (0.0.425, Laragon) — Default seed

```text
WTT_VERSION=0.0.425
host=Kontakt id=4961
attr_id=6665fb9fdb2449f388b443bafbe04d5a
attr_name=Titel
marker=wtt-smoke-default-202710
fold_edge_default=yes
fold_host_cleared=yes
api_edge_default=yes
api_host_cleared=yes
decorate_fixedValues=yes
fold_flag=yes
default_edge_write=yes
smoke=ok
```

(Prior edge `default` restored after smoke.)

### Smoke result (0.0.427, Laragon) — Settings walk summary (pre-nodeId)

```text
WTT_VERSION=0.0.427
host=Passiv id=4981
attr_id=cc2aa43e771a4cac8890a0448e96fc43
attr_name=Wert
nodeCount=3
depth=1
settingsWalk_levels=3
has_names=yes
has_preferred=yes
root_name=size
root_preferred=QuantityRenderer
child_edgeName=Value
child_name=double
walk_summary=yes
smoke=ok
```

(Superseded for nodeId assert by **0.0.432** walk-summary smoke above.)

### Smoke result (0.0.428, Laragon) — host-map prune

```text
WTT_VERSION=0.0.428
host=Kontakt id=4961
attr_id=6665fb9fdb2449f388b443bafbe04d5a
attr_name=Titel
host_ro_planted=yes
own_ro_cleared=yes
own_fv_cleared=yes
own_tx_cleared=yes
inherited_ro_kept=yes
prune_flag=yes
host_maps_prune=yes
smoke=ok
```

(Planted redundant own RO / fixed_values / typeExtras mirrors + fake inherited RO; prune cleared own only; prior edge/maps restored.)

### Smoke result (0.0.429, Laragon) — orphan slot purge

```text
WTT_VERSION=0.0.429
orphan_id=5167
orphan_gone=yes
bands_kept=yes
band_count=3
purge_flag=yes
orphan_slots_purge=yes
smoke=ok
```

### Inventory (0.0.429, Laragon) — leftover slots

```text
slot_count=3
BAND Zeile/Kopf/Fuss ? from=4882(table) (parked-band)
summary_true_orphan=0
inventory=ok
```

### Key code entry points

| Concern | Location |
|---------|----------|
| Create attr (no slot) | `Attribute::add` ? `Relation::add` |
| Relation name (attr label) | `Relation::update_name` / `Attribute::update` — AJAX `wtt_update_relation_name` |
| Relations Name UI | `tree-admin.js` `renderRelationNameCell` / `updateStoredRelationName` |
| Migrate + folds + prunes | `includes/class-attribute-q123-migrate.php` |
| Host-map prune | `Attribute_Q123_Migrate::maybe_prune_host_maps` (`wtt_q123_host_maps_pruned_v1`) |
| Own edge-read SoT fold | `Attribute_Q123_Migrate::maybe_fold_own_edge_read_sot` (`wtt_q123_own_edge_read_sot_v1`) |
| Orphan slot purge | `Attribute_Q123_Migrate::maybe_purge_orphan_slots` (`wtt_q123_orphan_slots_purged_v1`) |
| Attributes fold | `tree-admin.js` `attributesPanelOpen` + `.wtt-attributes-fold` |
| Settings walk | `includes/class-settings-walk.php` — `walk()`, `resolve_preferred_render()`, `summary_from_walk()` (+ `nodeId`), typeExtras bridge helpers |
| Settings key sanitize | `Relation::sanitize_settings_key` / `normalize_settings_deltas` |
| Preferred API | `Attribute::set_preferred_render` ? `Relation::update_settings` |
| Preferred AJAX | `Tree_Ajax::set_attribute_preferred_render` ? Attribute API |
| typeExtras AJAX | `Tree_Ajax::set_attribute_type_extras` ? `Attribute::set_type_extras` (**edge SoT for own**) |
| RO / Hide API | `Attribute::set_readonly` / `set_hidden` ? `Relation::update_read_only` / `update_hidden` (own) or host maps (inherited) |
| Default seed API | `Attribute::set_fixed_values` ? `Relation::update_default` (own) or host name-map (inherited) |
| Q105 BO?Mult rule | `Attribute_Validator` + AJAX `wtt_fix_attribute_rule` (`set_mult_01` / `clear_hide`) |
| Preferred decorate | `Attribute::decorate_row` ? `Settings_Walk::resolve_preferred_render` |
| Walk summary decorate | `Attribute::decorate_row` ? `settingsWalk` (from same resolve walk) |
| typeExtras decorate | Own: `type_extras_from_deltas`; inherited: `merge_type_extras_hybrid` |
| Default decorate | `Attribute::decorate_row` ? `resolve_fixed_values` (own edge-only / inherited host+edge) |
| Options paint extras | `assets/js/tree-admin.js` — `attributeOptionsExtras` / `typeExtrasFromEdgeSettings` |
| Options walk summary | `assets/js/tree-admin.js` — `renderSettingsWalkSummary` (Preferred readout + Edit type settings ? `selectNode`) |
| Relation override Options | `tree-admin.js` — `renderAttrRelationOverrideHead` + Preferred/converter/date/validators Reset; i18n `attributesRelationOverride*` |
| Q90 parked band helpers | `Attribute::is_parked_table_band_term` / `is_parked_table_band_edge` |
| Parked band name fold | `Attribute_Q123_Migrate::maybe_name_parked_band_edges` (`wtt_q123_parked_band_names_v1`) |
| Parked band Relations UI | `Tree_Model::get_stored_relations_payload` + `tree-admin.js` badge/lock; AJAX `reject_parked_table_band_edge` |
| Parked band smoke | `scripts/_smoke-q123-parked-bands.php` |
| Dup / reorder / move | `Attribute::duplicate` / `reorder` / `move_to_node` (+ `edge_fields_from_edge`) |
| Dup/move smoke | `scripts/_smoke-q123-dup-move.php` |
| Relation add OQ-W4 fields | `Relation::add(…, $settings, $edge_fields)` |
| Inherited host-map APIs | `Attribute::get_inherited_*` + `inheritedHostOverride` on decorate |
| Inherited override UI | `tree-admin.js` Inherited column badge; i18n `attributesInheritedOverride*` |
| Trash slot cascade | `Trash::collect_owned_attribute_slot_ids` — edge `toId` + `is_slot` only |
| Trash Model_Data | `Trash` ? `Model_Data::soft_trash_all_for_structure` / restore / purge |
| Value key normalize | `Model_Data::*`, `Object_Render::with_instance_values` |
| Composition columns | `Composition::get_attribute_columns` / `normalize_rows` |
| Admin id contract | `assets/js/tree-admin.js` `wttAttrId` |
| Invariants smoke | `scripts/_smoke-q123-invariants.php` |

### Lane note

Prefer **model / domain meta** + **tree/admin**. Do not fork Form/Table paint ([`reuse-renderers.mdc`](../../.cursor/rules/reuse-renderers.mdc)).

### Doc recovery note (2026-08-09 ~23:20)

A botched bulk version replace briefly checked out living docs to HEAD (`0.0.414`). Status/scaffold paragraphs were rebuilt to **0.0.424** via UTF-8 PHP patch, then advanced through **0.0.425**——“**0.0.435**. Older intermediate narrative in those files (0.0.415——“0.0.423 detail beyond decision-log) may be thinner than before; **this handoff + `project-plan` decision log rows 0.0.420——“0.0.435** remain the resume source of truth.

---

## Status refresh log

| When (UTC+2) | Note |
|--------------|------|
| 2026-08-09 ~21:42 | Handoff created. HEAD `ed9c5eb` = 0.0.414 safe rollback. Dirty work = 0.0.415 Relation-only CRUD + migrate uncommitted. |
| 2026-08-09 ~21:55 | Fixed absint value-key wipe; live migrate 78 edges; parked-table leftovers named. Living docs ? 0.0.415. No commit. |
| 2026-08-09 ~22:10 | **0.0.416:** Preferred override key mangling fixed (`sanitize_key` on Settings keys); Object_Render/Blocks UUID value keys; compute JS `wttAttrId`; hide untyped table-band slots from Attribute UI. No commit. |
| 2026-08-09 ~22:55 | **0.0.417:** `Settings_Walk` helper + Preferred hybrid via `decorate_row` (`settingsResolved` / `settingsWalkMeta`); int converter / validators / dateMode from edge deltas; Blocks/Composition/pathAttrId UUID key quick kills. Full Options walk UI still debt. No commit. |
| 2026-08-09 ~23:10 | **0.0.418:** typeExtras ? Relation Settings bridge (read prefer edge, dual-write on save, one-shot fold). Host map kept as debt. No Options wizard. No commit. |
| 2026-08-09 ~22:00 | **0.0.419:** Options paint prefers edge Settings deltas (+ typeExtras); `settingsResolved` override fallback; walk nodeCount hint; `is_slot` Preferred gates audited (none blocking edge ids). No commit. |
| 2026-08-09 ~22:15 | **0.0.420:** Trash cascade safety — leftover slots via `toId`+`is_slot`; Model_Data soft-trash/restore/purge on host trash; UUID `(int)` prefix hazard confirmed on Kontakt (`6665…`?`6665`). Dual-write kept. No commit. |
| 2026-08-09 ~22:20 | **0.0.421:** Composition `get_attribute_columns` / `normalize_rows` keep edge UUID keys; assert-collection-block + Laragon smoke OK (`ids_match=yes`). Catalog/table term `(int)` left. No commit. |
| 2026-08-09 ~22:35 | **0.0.422:** Stop typeExtras dual-write — own edges write Settings only + clear host key; inherited host-map overrides kept; prune flag `wtt_q123_type_extras_pruned_v1`; Laragon smoke `edge_only_write=yes`. No commit. |
| 2026-08-09 ~22:50 | **0.0.423:** Preferred via `Attribute::set_preferred_render` (edge `settings.view` only; clear deletes key + leftover slot meta); `settingsWalkMeta.preferredSource`; Laragon smoke `preferred_edge_write=yes` / `clear_deleted_delta_key=yes`. No commit. |
| 2026-08-09 ~23:20 | **0.0.424:** Own RO/Hide ? Relation `readOnly`/`hidden`; inherited host maps kept; Q105 Mult gate; fold `wtt_q123_edge_flags_folded_v1`; Laragon smoke `ro_hide_edge_write=yes`. No commit. |
| 2026-08-09 ~22:30 | **0.0.425:** Own Default seed ? Relation `edge.default` (OQ-W4); inherited host name-map kept; fold `wtt_q123_defaults_folded_v1`; `read_edges` preserves `default`; Laragon smoke `default_edge_write=yes`. No commit. |
| 2026-08-09 ~22:30 | **0.0.426:** Q105 `Attribute_Validator` BO?Mult rule + fixes `set_mult_01` / `clear_hide`; admin banner; `apply_fix` string attr id; Laragon smoke `bo_mult_rule=yes`. No commit. |
| 2026-08-09 ~22:35 | **0.0.427:** Bounded `settingsWalk` summary on decorate_row + compact read-only Options list; Laragon smoke `walk_summary=yes` (Passiv/Wert). Full wizard still debt. No commit. |
| 2026-08-09 ~22:45 | **0.0.428:** Safe host-map prune `wtt_q123_host_maps_pruned_v1` (own RO/Hide/default/typeExtras covered by edge; empty typeExtras maps; inherited kept); Laragon smoke `host_maps_prune=yes`. No commit. |
| 2026-08-09 ~22:50 | **0.0.429:** Slot inventory (3 parked bands / 0 orphans); orphan purge `wtt_q123_orphan_slots_purged_v1` + safer any-edge `toId` guard; Attributes panel collapsed by default (catch-up desk); Laragon smoke `orphan_slots_purge=yes`. No commit. |
| 2026-08-09 ~22:45 | **0.0.430:** Combined invariants smoke `_smoke-q123-invariants.php` (edge ids / no-slot add / Preferred—Default—RO on edge / Passiv Wert nodeCount=3); Laragon `smoke=ok`; no product bugs. Handoff status ? **core migrate ready for user UAT**. Remaining = polish (walk wizard, host read fallback, Q90 bands, commit). No commit. |
| 2026-08-09 ~22:50 | **0.0.431:** Own-attr reads edge-only (RO/Hide/default/typeExtras/preferred); fold `wtt_q123_own_edge_read_sot_v1` (Q105 Hide Mult debt ? `edge.hidden`); inherited host maps kept; invariants + bo-mult `smoke=ok`; plan **0.7.98**. No commit. |
| 2026-08-09 ~22:55 | **0.0.432:** `settingsWalk` levels include `nodeId`; Options list click ? `selectNode` (read-only; no wizard); walk-summary + invariants `smoke=ok`; plan **0.7.99**. No commit. |
| 2026-08-09 ~23:05 | **0.0.433:** Relations admin Name column for `besteht_aus`/`aggregation` (`edge.name`; sync via `Attribute::update`); payload/incoming expose name; add requires name for attr bindings; `mark_as_slot` `@deprecated` legacy-only; relation-name + invariants `smoke=ok`; plan **0.7.100**. No commit. |
| 2026-08-09 ~23:10 | **Docs sync (stay 0.0.433):** `DEVELOPER-ATTRIBUTE-MODEL` Scaffold status debt tightened (walk wizard / inherited host maps / Q90 bands / commit pending); living docs + handoff version lines already **0.0.433** / plan **0.7.100**. Invariants `smoke=ok`. No code bump. No commit. |
| 2026-08-09 ~23:15 | **0.0.434:** UUID attr-id audit — `shadowedAttrId` `(int)` ? `normalize_attr_id`; no other high-confidence attr-id casts; invariants `smoke=ok`; plan **0.7.101**. Walk wizard not started. No commit. |
| 2026-08-09 ~23:00 | **0.0.435:** Walk Options UX — Preferred readout + **Edit type settings** + hint; nested navigate-only; attribute Preferred override stays above; **Walk wizard deferred post-UAT**; invariants `smoke=ok`; plan **0.7.102**. No commit. |
| 2026-08-09 ~23:01 | **PAUSED FOR USER UAT** — handoff status flip; clear UAT checklist + draft commit message for 0.0.435; polish shortlist (Walk wizard / Q90 bands / inherited host-map naming / commit). **5h sleep timer still armed** (~**02:27 UTC+2**). Agents: no large features until UAT or user ask. No code bump. No commit. |
| 2026-08-10 morning | **ACTIVE** — user “you can proceed”. Slice: **0.0.436** inherited host-map naming (`get_inherited_*`, `inheritedHostOverride`, Inherited override badge); Walk wizard skipped as too large. Invariants `smoke=ok`. Plan **0.7.103**. No commit. |
| 2026-08-10 morning | **0.0.437:** First Walk delta-edit slice — Attributes Options **Relation overrides** chrome (Preferred/converter/dateMode/validators); Reset deletes edge Settings key; nested walk stays navigate-only. Invariants `smoke=ok`. Plan **0.7.104**. No commit. |
| 2026-08-10 morning | **0.0.438:** Q90 parked table bands — Attributes hide confirmed; Relations **Q90 parked** badge/lock + AJAX reject; edge names ensured (`wtt_q123_parked_band_names_v1`); parked-bands + invariants `smoke=ok`. Plan **0.7.105**. No commit. |
| 2026-08-10 morning | **0.0.439:** Attribute duplicate/reorder/move audit — move preserves OQ-W4 edge fields + order; duplicate copies RO/Hide; `Relation::add` `$edge_fields`; `_smoke-q123-dup-move.php` + invariants `smoke=ok`. Plan **0.7.106**. Handoff ? **READY FOR COMMIT** (draft 0.0.415——“0.0.439). **Do not commit** until user asks. |
| 2026-08-10 afternoon | **0.0.442 / Q124:** RelationType **`defaultvalue_from`** (consumer?provider, name=attr); create/empty seed via `Model_Data::merge_defaultvalue_from` + `create_linked` providers; BOM Bauteilliste.Bauart ? Position.Bauart; Relations UI name required; smoke `_smoke-q123-defaultvalue-from.php`. Live cascade open. Plan **0.7.108**. No commit. |
| 2026-08-10 afternoon | **0.0.443:** Walk `Settings.data.allowedPrefixIds` on Unit/With-prefix levels; paint intersects Unit `allowedPrefixes` / quantitySchema; Walk UI multi-check + Reset; `_smoke-q123-prefix-allowlist-walk.php` `smoke=ok`. setSeparator/Q120 deferred. No commit. |
| 2026-08-10 afternoon | **0.0.443 (parallel) / 0.0.444 reconcile:** Second agent claimed 0.0.443 for **host Preferred Preview** (`renderAttributeHostPreview` + hint). Both features verified present; bump to **0.0.444** (header/`WTT_VERSION`/`package.json`); living docs + handoff synced; Laragon prefix-allowlist + defaultvalue_from `smoke=ok`. Plan **0.7.109**. No commit. |
| 2026-08-10 afternoon | **0.0.445 OQ-W11 unit structure:** Case_Data ensure With prefix Praefix+Kuerzel, size under quantity, Passiv Wert→size; Demo slots ? size; unit leaves no fake attrs; synthesize display debt documented; `_smoke-q123-unit-structure.php` `smoke=ok`; Walk size `nodeCount=5`. Plan **0.7.110**. No commit. |
| 2026-08-10 afternoon | **0.0.448 Walk Default + compact one-row UI (UAT feedback):** Default override per walk level — depth 0 `edge.default`, nested `settings.nested[path].data.default`, Reset deletes key; live type seed shown; nested → `typeProperties.fixedValues` paint. Compact horizontal walk row (Preferred/Converter/Default/Val/Pref); long hint dropped. **Tree layout deferred.** Touched `Settings_Walk` / `Attribute` / `tree-admin.js`+CSS / i18n; avoided Case_Data. No commit. |

| 2026-08-10 afternoon | **0.0.449 reconcile:** Parallel agent bumped header/`package.json` while Walk Default (0.0.448) landed; working tree version = **0.0.449**. Feature ownership: Walk Default+compact = **0.0.448**; Model versions stack = **0.0.447**. No commit. |
