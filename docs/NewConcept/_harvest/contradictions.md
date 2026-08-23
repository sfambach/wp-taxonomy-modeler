---
title: Contradictions found while catching the documents up
status: for the owner to settle
round: R1
last_updated: 2026-08-23
---

# Contradictions

Places where a concept document and a decision — or two decisions with each other — say
different things. **Nothing here is resolved.** [PR-4](../../../CLAUDE.md) forbids picking one
silently, and every item below is a decision the owner has to make, not a gap an agent may fill.

Raised 2026-08-23 while folding the post-draft decisions into
[40 I18n](../40-i18n.md) and [70 Migration](../70-migration.md). Both documents point here from
the affected sections.

## Blocking

| Document | Line or section | What the text says | Which decision disagrees | What is unclear |
|---|---|---|---|---|
| [40 I18n](../40-i18n.md) | [I10](../40-i18n.md#i10--a-node-always-has-a-name), the fallback chain; and [I4a](../40-i18n.md#i4a--roles-are-nodes-seeded-and-extensible) | The chain is `<role>` → `long` → `node.name`, and only `long` is worth entering per locale. [D-151](../90-decision-log.md) reinforces it: **`long` is mandatory**, the chain "breaks without it". [D-153](../90-decision-log.md) restates the chain as `form·other` → `form·one` → `long·one` → `node.name`. | [D-196](../90-decision-log.md): the seeded roles are `form`, `table`, `select`, `symbol`, `help` — **no `long`** — and it records that "`long` … was my invention; the owner's `help` is the same thing under a better name". | Three readings, and no decision says which: (a) `long` is **renamed** `help`, so the chain ends `help` → `node.name`; (b) `long` survives as a **mandatory but unseeded** role beside `help`; (c) `long` is **retired** and the chain now ends directly on `node.name`. Reading (c) would remove the anchor [D-151](../90-decision-log.md) calls load-bearing. Also affects: [D-151](../90-decision-log.md)'s "the long description doubles as the tooltip", and [01 Glossary](../01-glossary.md), which already carries the [D-196](../90-decision-log.md) set without `long`. |
| [70 Migration](../70-migration.md) | [M14](../70-migration.md#owner-statement--2026-08-22-second-pass-export-versions-per-record-and-what-the-resolver-offers) and the [M13/M14 section](../70-migration.md#m13m14--the-version-lives-on-the-record) | "Until everything is resolved, records of **different versions coexist**. When all is resolved, only records of the **current** version remain." [D-060](../90-decision-log.md) repeats it: "after which all sit at the current version". | [D-172](../90-decision-log.md): "a record is in conflict when the model differs **in the parts that record uses** — not by distance between numbers", and a revert is a new version with an old shape, so a record stamped `v1` can be compatible with `v4`. | Whether an untouched record whose shape still fits is **carried forward anyway** (so the version stamp is normalised and M14 holds), or **left at its old stamp** (so M14 is now wrong and records at several versions is the steady state). This decides whether the stamp means *written against* or *checked against*, and whether resolving ever runs over records that have no conflict. |
| [70 Migration](../70-migration.md) | [Packs](../70-migration.md#packs--what-ships-and-how-it-gets-in) and [Provenance](../70-migration.md#provenance--two-marks-because-one-is-not-enough) | [D-119](../90-decision-log.md): "Updates **offer** new items additively and **never overwrite**, so a plugin update cannot undo an author's edit." | [D-174](../90-decision-log.md): a node marked *came from the seed* and **not** *changed since* is "**updated silently**". | Whether "never overwrite" was always meant as "never overwrite an author's edit" — in which case [D-174](../90-decision-log.md) merely makes it precise and [D-119](../90-decision-log.md)'s wording is loose — or whether it was meant literally, in which case [D-174](../90-decision-log.md) is a change of policy and [D-119](../90-decision-log.md) needs superseding. The difference is visible to the user: under the first reading an untouched shipped node changes under them without being asked. |
| [70 Migration](../70-migration.md) | [Converting between the relation kinds](../70-migration.md#converting-between-the-relation-kinds-is-a-move) | [D-137](../90-decision-log.md): aggregation → composition "needs **two** checks, not one: at **model level** only one edge may point at it, and at **data level** each target record must be referenced by **at most one** owner record." | [D-162](../90-decision-log.md): "`Model` → `Kompositionen` works **exactly when** each affected record has a single user", and where it does not, the resolver offers "**a copy per use site**" — a resolution that presupposes several edges. | Whether the model-level *one incoming edge* check still stands. If it does, "a copy per use site" can never apply, because there is only ever one use site. If it does not, [D-137](../90-decision-log.md)'s first check is superseded and should be marked so. [D-162](../90-decision-log.md) corrects [D-137](../90-decision-log.md) on the asymmetry question but says nothing about this check. |

## Minor — wording overtaken rather than a real disagreement

Listed separately because these need a sentence changed, not a decision taken. Still not changed
here, for the same reason.

| Document | Line or section | What the text says | Which decision disagrees | What is unclear |
|---|---|---|---|---|
| [40 I18n](../40-i18n.md) | [I9](../40-i18n.md#i9--labels-and-settings-are-separate-storage), the comparison table, row *Read by* | A setting is read by "validators, renderers, resolution"; a label is read by "**display only**". | [D-158](../90-decision-log.md): an author-written **validator message** is a label, addressed by `owner_id` + `path` + `role`. | Whether *display only* was meant as *never branched on* — which a validator message still satisfies, since it is shown and not tested — or as *no validator ever reads the labels table*, which is no longer true. The row is one of five used to justify keeping labels and settings apart, so it is worth a precise word. |
| [70 Migration](../70-migration.md) | [M1–M4](../70-migration.md#m1m4--rename-is-not-replace), closing paragraph | "M3 removes the one case that argued for freezing … Nothing inside the model needs to remember how it once read." | [D-141](../90-decision-log.md) brings documents *as records* into the concept — "where a document must survive, it is **not deleted** … purging an order would simply be forbidden" — and [D-064](../90-decision-log.md) / [D-065](../90-decision-log.md) freeze an agreed price's exchange rate **on the record**. | Whether the sentence is still true when read narrowly (**labels** never freeze — which [D-053](../90-decision-log.md) and [D-065](../90-decision-log.md) confirm) but false as written (something inside the data *is* remembered as it was: the rate). [M3](../70-migration.md#owner-statement--2026-08-22) itself is an owner statement about an **exported** PDF; [D-141](../90-decision-log.md) is about an order record. The two may simply be about different objects — but the paragraph currently generalises from one to the other. |

---

## Resolved 2026-08-23

| # | How it was settled |
|---|---|
| 1 · `long` vs `help` | **[D-209](../90-decision-log.md)** — `long` is gone, renamed `help`; the chain ends `help` → `node.name`. The owner: *`long` ist weg, ist als `help` definiert.* |
| 2 · version stamps | **[D-210](../90-decision-log.md)** — a record keeps its stamp; only what actually conflicted is touched. Records at several versions are a normal steady state. |
| 3 · updates overwriting | **[D-213](../90-decision-log.md)** — [D-119](../90-decision-log.md) precised to *never overwrite an author's edit*; untouched shipped content is corrected silently. |
| 4 · one edge vs a copy per use site | **[D-214](../90-decision-log.md)** — both checks stand. The apparent conflict was a term used wrongly: *use site* means a **relation**, and the resolution copies **per using record**. |
| 5 · labels read by display only | Wording fixed in [40 I18n](../40-i18n.md) — *shown, never branched on*. Both sources are satisfied by that reading. |
| 6 · nothing remembers how it once read | Wording fixed in [70 Migration](../70-migration.md) — the sentence is about **labels in the model**; a **record** may freeze what was agreed. |

Rows 5 and 6 were wording rather than decisions and were fixed directly; 1 to 4 needed the owner
and got him.
