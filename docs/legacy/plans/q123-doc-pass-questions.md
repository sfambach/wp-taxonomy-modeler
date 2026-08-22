---
name: Q123 documentation pass — open questions
overview: "Doc review after Settings + recursive walk. OQ-W1…W16 decided 2026-08-09. Canonical developer summary: docs/DEVELOPER-ATTRIBUTE-MODEL.md"
status: closed
version: "1.0.0"
last_updated: "2026-08-09"
related_docs:
  - docs/DEVELOPER-ATTRIBUTE-MODEL.md
  - docs/plans/relation-vs-object-concept.md
  - docs/plans/project-plan.md
  - docs/OPEN-QUESTIONS.md
  - docs/ARCHITECTURE.md
---

> ## ⚠️ FROZEN — LEGACY DOCUMENT
>
> This file belongs to the **pre-2026-08-22 planning round** and is **no longer maintained**.
>
> - Do **not** edit it. Do **not** treat it as source of truth. Do **not** implement from it.
> - It is kept as a **quarry**: content reaches the new concept only through a reviewed
>   harvest sheet (see [`../../NewConcept/README.md`](../../NewConcept/README.md)).
> - Version numbers, `Q<n>` question ids, status flags and decision-log entries in here
>   describe the **old** model. They carry no authority over the new one.


# Q123 doc pass — questions (closed)

After sharpening (**Settings**, **Relation.name**, **type = Relation target**, **recursive Settings & Render walk**, RelationTypes reduced, `node_ref`/`ref_scope`/… deprecated), tensions were listed and **decided with the user (OQ-W1…W16)**.

**Canonical write-up + diagrams:** [`docs/DEVELOPER-ATTRIBUTE-MODEL.md`](../DEVELOPER-ATTRIBUTE-MODEL.md)

Locked for this pass:

- Product RelationTypes: `child_of` | `besteht_aus` | `aggregation`
- Attributes = Relations only (no slots); Settings.`data` + Settings.`view`; recursive walk
- No `attribute_typeof`; no product `composition` alias; `ref_scope` / `node_embed` / `node_ref` / `node_pick` deprecated

---

## OQ-W1 — Attribute storage without aux node (vs Q87 slots)

**Decided (2026-08-09):** Attributes are **Relation only** (`besteht_aus` / `aggregation`: `name` + target + Settings capsule). **No slot terms** (`_wtt_attribute_slot` dropped from product model). Scaffold slots = debt to migrate/remove.

---

## OQ-W2 — Live walk vs Q71 snapshot presets

**Decided (2026-08-09): Hybrid (C).**  
Type / subtree Settings are **live**. A value from below applies **above only if not overwritten** on the Relation (or closer edge) on the walk. Explicit capsule overrides win; everything else follows the live target tree (Q123 walk + Q122 inherit).

Q71 pure snapshot is **not** product SoT (scaffold may still snapshot until migrate).

---

## OQ-W3 — Where nested overrides are stored

**Decided (2026-08-09):** Overrides live on the **current** Relation/node **Settings**.

**Storage rule (clarified):** Persist **only overrides** (the delta). Everything else is **not** copied — **display** by walking other nodes and showing their Settings live (OQ-W2 hybrid).  

**No separate `overridden?` boolean needed** in the normal case: **key present in the override map** ⇒ overridden; **absent** ⇒ live from below. Optional later: explicit “reset to inherit” (delete key) or a sentinel only if you must store “explicit empty” vs “inherit.”

Nested paths (Unit.allowedUnits, …) may sit **inside** that Relation’s Settings override map — still on the current Relation.

---

## OQ-W4 — Which fields are on Relation vs inside Settings

**Decided (2026-08-09)** after scaffold check (Attributes row today: Name, Type, Mult, Bindung, Default, RO, Hide, Inherited, Options/`typeExtras`):

### Always on the Relation (edge) — not type-node Settings

| Field | Notes |
|-------|--------|
| **name** | Attribute label |
| **target** | Type node |
| **relationType / Bindung** | `besteht_aus` \| `aggregation` |
| **multiplicity** | Mult |
| **readOnly** | Host/attribute lock (Q115) — **not** a setting of `size` |
| **hide / BO** | Background-only / hide inherited (Q105) — **not** type-node Settings |
| **default** (Festwert seed) | Attribute seed template (Q106) — edge/attribute level; type may still expose Default in walk as live type default + override delta |

### Type Settings (live walk + override **deltas** only)

Preferred R/C/V, validators, allowlists/`choiceFilter`, dateMode, unit/prefix knobs, compute, … — whatever the **target tree** defines. Display from nodes below; store only overrides on the current Relation.

**Inherited** column = host `child_of` inherit status (OQ-W5), not a stored edge field of its own.

---

## OQ-W5 — Inherit attribute definitions along `child_of` (Q66)

**Decided (already Q66; confirmed 2026-08-09 for Relation-only):** Child hosts **inherit** father’s attribute Relations (`besteht_aus` / `aggregation`). Merge by **name** (child own wins / shadows). Child may **hide** inherited attrs (Q105 BO/cover-up). RO may be switched on at heir (OQ-A3). Same concept — not a new fork; only storage is Relation-not-slot (OQ-W1).

---

## OQ-W6 — Q114 Attribute Options vs Q123 walk

**Decided (2026-08-09):** **One job, same surface.** Opening a **node** (e.g. `size`) **or** an **attribute** (e.g. Widerstand.Wert) both show Settings via the **same recursive walk** — all Settings of the node **and its subnodes** (composition/aggregation targets below). Not “node = one level” vs “attribute = walk.” Q114 single-node-only Options chrome is **superseded** by this uniform walk UI.

---

## OQ-W7 — Shared type edit vs override-only

**Decided lean (2026-08-09):** **Same concept everywhere — override logic, not “setup subnodes.”**

- Each node keeps its **own default Settings**.
- The walk **shows** subtree Settings (live).
- **Writes** from a parent/attribute/child context are **overrides on the current** Relation/node only (OQ-W2/W3) — **not** pushing configuration into shared subnodes.
- Subnode defaults stay on those nodes; the **child** (heir host / attribute Relation) **may override**.
- Editing a node “as itself” (e.g. open catalog `size` to change **size’s** defaults) = that node’s own Settings — still not a license to rewrite Unit/`With prefix` defaults from inside size’s walk as “setup.”

---

## OQ-W8 — Walk depth, cycles, stopping rules

**Decided (2026-08-09):** Walk **to the leaf** (structures are generally flat — go to the bottom). **Avoid cycles** (“round strips”): if a node is already on the current walk path, **stop** (do not recurse again). No product need for a small fixed max-depth; optional safety cap later if needed.

---

## OQ-W9 — Same walk for aggregation and composition?

**Decided (2026-08-09):** **Same** Settings + Render walk for **`besteht_aus` and `aggregation`**. Instance storage differences stay **Q111** (embedded vs linked) — orthogonal to the schema walk.

---

## OQ-W10 — `size` / `quantity` / Value+Unit vocabulary

**Decided (2026-08-09):** Keep **both**. **`quantity`** = general measure type (Value + Prefix? + Unit / rules). **`size`** = **inheriting child** of quantity with **additional** settings (specialization via `child_of`, Q122). Leave Fallstudie as-is.

**Caution (not a veto):** Do **not** hard-code product logic by display name `size` — treat it as one specialization of quantity; walk/Settings stay dynamic. Scaffold name-heuristics (`isBasiseinheitUnit`, …) remain debt toward bindings/ids.

---

## OQ-W11 — Praefix / Kuerzel: Relations or Settings-only?

**Decided (2026-08-09): Variant A — composed of.**  
**`With prefix`** is **composed** via `besteht_aus` Relations (e.g. **Praefix**, **Kuerzel**/Symbol) — real attribute Relations, not Settings-only knobs pretending to be members. **Then** Settings (allowlists, Preferred, …) apply on that graph via the usual walk + override deltas.

**Still open (narrow):** restricting which concrete unit (Ohm vs Farad) — change **Relation.target** to a specialization child under the father, and/or **Choices/allowlist** overrides — both fit A; pick when implementing if needed.

---

## OQ-W12 — Instance value keys (Model_Data)

**Decided (2026-08-09):** Instance field keys = **Relation id** (stable). Rename of attribute `name` must **not** break stored values.

**Scaffold today (check):** Model_Data `values` are keyed by **attribute/slot term id** (numeric) — already id-based, not by display name. **Q98:** attribute **rename is cosmetic** → **no** model-version bump; values keep working because the id stays. **Delete** attribute → orphan keys retained; resolver/map UI still TODO (`orphans` bag). Festwert host map is still partly **name**-keyed and remaps on rename (`rename_fixed_values_key`) — debt toward id keys with Relation-only model.

Product lean aligns with today’s instance SoT: **id key** (slot id → Relation id after migrate).

---

## OQ-W13 — Trash / delete cascade (Q89)

**Decided (2026-08-09):** Cascade depends on **Bindung** (same spirit as Q97/Q111) — not “always kill slots.”

When a **host** is deleted (e.g. Widerstand):

| Bindung | Target / related data | Relation edge |
|---------|----------------------|---------------|
| **`besteht_aus` (composition)** | Owned composed stuff **dies with** the host (soft-trash together; restore together) | removed / trashed with host |
| **`aggregation`** | Related objects **remain** | **Relation is deleted** (link gone; targets orphaned from this host only) |

**Unchanged:** Catalog type targets (`size`, `With prefix`, …) are **not** deleted because a host that pointed at them goes away.  
**Schema:** attribute Relations on the host go away with the host; Bindung decides fate of **owned instance/composition payload**, not the catalog type node.

**Same law as instance storage (Q111) — remmarked here:** Composition → data **inline** on the host; Aggregation → data in the **related object’s** Model_Data; delete/cascade follows that ownership.

---

## OQ-W14 — Attributes panel vs Relations panel

**Decided (2026-08-09):** **Keep both panels.**

- **Relations** — general Relation UI (all relation kinds / von–an as today).
- **Attributes** — **wizard** over `besteht_aus` / `aggregation` (named attribute Relations): easier create/edit of name, target, Mult, Bindung, Defaults, Settings walk — not a second graph. Same underlying Relations; Attributes = convenience surface for users.

---

## OQ-W15 — Rules / Cursor rules still teaching `node_ref` / `ref_scope`

**Decided (2026-08-09):** **`node_ref`**, **`ref_scope`**, and related **`node_embed` / `node_pick`** stay **deprecated** — **do not use** in product until a real use case reopens them. Scaffold may linger as debt; docs/rules marked deprecated (Q72/Q73/Q84).

---

## OQ-W16 — Presentation / Preferred storage (refined thinking)

**Decided (2026-08-09):** **Two namespaces, one Settings family.**

```text
Settings
  data:  { validators, allowlists, default, dateMode, compute, choiceFilter, … }
  view:  { preferredRenderer, preferredConverter, … }
Presentation (Q117)  — locale texts / icon — separate store (labels); not data Settings
```

- **Same** walk / hybrid / override-delta law for **both** `data` and `view` (OQ-W2/W3/W6).
- Preferred R/C ∈ **`view`** — not a third meta system, not mixed unlabeled with `data`.
- Validators / allowlists / value constraints ∈ **`data`**.
- Scaffold `_wtt_preferred_*` and flat typeExtras = migrate into these namespaces.

---

## Doc-pass status

| ID | Status |
|----|--------|
| W1 | decided — Relation only, no slots |
| W2 | decided — hybrid live + overrides |
| W3 | decided — deltas only; presence = override |
| W4 | decided — edge vs Settings split |
| W5 | decided — inherit + hide (Q66) |
| W6 | decided — one walk UI node = attribute |
| W7 | decided — write override only, no push to subnodes |
| W8 | decided — to leaf; break cycles |
| W9 | decided — same walk comp/agg |
| W10 | decided — quantity + size child |
| W11 | decided — With prefix composed of Praefix/Kuerzel |
| W12 | decided — instance key = Relation id |
| W13 | decided — delete by Bindung; Q111 storage |
| W14 | decided — Relations panel + Attributes wizard |
| W15 | decided — node_ref/ref_scope deprecated |
| W16 | **decided** — Settings.`data` + Settings.`view`; Preferred ∈ view; Presentation Q117 separate |

All OQ-W1…W16 closed for this pass. Implementation / scaffold migrate is separate.

---

## OQ-W4 — Which fields are on Relation vs inside Settings

**Candidates:** `name`, Mult, Bindung (`besteht_aus`|`aggregation`), RO, Hide, Default, Preferred R/C/V, allowlists, validators, …

**Ask:** Explicit split — what is **always edge** vs **inside Settings capsule** vs **only on type node**?

---

## OQ-W5 — Inherit attribute definitions along `child_of` (Q66)

**Tension:** Child hosts inherit attribute **definitions** from father. With edge-only Relations, inheritance = copy/merge of **outgoing** composition/aggregation edges?

**Ask:** Confirm inherit mechanics for named Relations (by `name`? by id?). Override/hide on child still?

---

## OQ-W6 — Q114 Attribute Options vs Q123 walk

**Tension:** Q114 = Attribute Options show **same Node chrome** as detail Settings. Q123 = **recursive subtree** Settings surface.

**Ask:** Is Q114 **superseded** by the walk (Options = walk UI), or does Node detail Settings stay a **single-node** surface while attribute Options = walk?

---

## OQ-W7 — Shared type edit vs override-only

**Ask:** From attribute Settings walk, may the user **edit the shared type node** (`size`, `With prefix`), or **only** Relation-level overrides (never mutate catalog types from that UI)?

---

## OQ-W8 — Walk depth, cycles, stopping rules

**Ask:** Max depth? Stop at Simples with no outgoing composition/aggregation? How to treat accidental cycles?

---

## OQ-W9 — Same walk for aggregation and composition?

**Ask:** Settings + Render recursion identical for both Bindungen, or any difference (e.g. linked Model_Data only on aggregation)?

---

## OQ-W10 — `size` / `quantity` / Value+Unit vocabulary

**Tension:** Fallstudie uses composed **`size`**; docs still talk **quantity** trinity / Basiseinheit.

**Ask:** Is **`size`** the product composed measure type (Value + Unit), with **quantity** renamed/aliased — or two concepts?

---

## OQ-W11 — Praefix / Kuerzel: Relations or Settings-only?

**Tension:** Diagram shows Relations on `With prefix` → Praefix / text. Unit catalog also uses allowlists as **meta**.

**Also (user 2026-08-09):** **`With prefix` is the father knot.** Unit may target `With prefix` **or a specialization child** under it. Inherited / referenced Settings (and render) must reflect **that choice** + father defaults (Q122/Q123 walk).

**Ask:** (a) Are Praefix + Kuerzel always **composition Relations**, or often **Settings knobs** on `With prefix` without child Relations? (b) Is “pick a concrete unit under With prefix” always changing **Relation.target**, or a Choices field inside the Settings capsule?

---

## OQ-W12 — Instance value keys (Model_Data)

**Tension:** Instances key values by **attribute/slot id** today.

**Ask:** After edge-only attributes, SoT key = **Relation id**? Migration path from slot ids?

---

## OQ-W13 — Trash / delete cascade (Q89)

**Tension:** Cascade soft-deletes **attribute slots**. No slots ⇒ cascade what?

**Ask:** Deleting host soft-deletes **outgoing attribute Relations** only, or also anything else?

---

## OQ-W14 — Attributes panel vs Relations panel

**Ask:** Is **Attributes** only a **filtered view** of `besteht_aus`/`aggregation` Relations (one UI family), or keep two panels with different jobs forever?

---

## OQ-W15 — Rules / Cursor rules still teaching `node_ref` / `ref_scope`

**Found:** `.cursor/rules/node-ref-nodes.mdc`, `composition-first.mdc` (`keep ref_scope`), `node-renderers.mdc` still product-tone for `node_ref`.

**Ask:** Mark those rules **deprecated** in the same pass as Q72 hard deprecate (yes/no now)?

---

## OQ-W16 — Presentation / Preferred storage

**Ask:** Preferred render/converter/validators — stay **term meta on type nodes** only, with Relation capsule overrides; or move Preferred into **Settings** as the one bag?

---

## Doc spots updated this pass

Pointers sharpened toward Q123 walk; full Q87 rewrite **blocked** on OQ-W1. See decision-log **0.7.96**.
