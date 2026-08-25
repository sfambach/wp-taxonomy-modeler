---
title: Roadmap
status: draft
round: R1 (in progress)
last_updated: 2026-08-23
---

# Roadmap

> **Status: `draft`.** Started 2026-08-23 with its first real content — the things the owner has
> explicitly placed in a later release. The rest is still to be written, and deliberately so: a
> phase plan invented before the concept holds is a wish list.

## Purpose

What ships in which order.

## How this document is filled

**Only what has been placed.** A line appears here when a decision put it in a release, not because
it seemed like a later problem. Everything the concept describes and that is **not** listed under a
later release belongs to the first one.

That rule exists because the previous round's phase plan grew by speculation until nobody could say
what phase one actually contained.

## Release 2

| What | Why it is not in Release 1 | Decision |
|---|---|---|
| **Views** — a named, reusable calculation belonging to no node | Every figure so far has a natural home as a computed attribute on a node. A view before that is a second place where calculations live. | [D-200](90-decision-log.md), [D-203](90-decision-log.md) |
| **Reports** — prepared output: an exported parts list, an invoice | Computes at output time and **joins** across unrelated records, so it is not a descent and needs machinery of its own. | [D-201](90-decision-log.md), [D-202](90-decision-log.md), [D-203](90-decision-log.md) |
| **Renderer resolution per row template** | Thirty rows by ten columns is three hundred in-memory lookups. Not measurable. The optimisation earns its place at thousands of rows. | [D-203](90-decision-log.md) |
| **Converting between peer units** — showing a value given in inches as millimetres, °C as °F | The **shape** is decided and in Release 1 ([D-274](90-decision-log.md)): a unit carries its factor, and where needed its offset, to the reference unit of its parent. What waits is the **doing** — a converter and a renderer that show a value in a unit other than the one it was entered in. | [D-274](90-decision-log.md), [D-275](90-decision-log.md) |
| **A page per record** — a template plus a route, `/bauteil/bc547b` rendering a record through a page designed once | The owner's pattern is data embedded in **his own posts**, which [D-206](90-decision-log.md) already provides. A template matters the day a catalogue of five hundred parts wants addresses. | [D-309](90-decision-log.md) |

### One requirement that does not wait for Release 2

The owner, on the row-template optimisation: *if errors occur, then that is a conceptual error on
our side, and it has to be visible so we can react.*

So even before the optimisation exists: a precomputed row template meeting a value that needs a
different renderer must **fail loudly**, never draw quietly wrong. Silent wrongness in a table is
the hardest class of fault to notice and the cheapest to prevent.

## Not placed in a release yet

These are deferred by decision ([D-200](90-decision-log.md)) but wait on an **event**, not on a
release. They reopen when the event happens, whichever release is current.

| What | Reopens when |
|---|---|
| **What the importer is told** ([OQ-072](91-open-questions.md)) | the domain core is locked |
| **An enum filled at runtime** ([OQ-074](91-open-questions.md)) | working with the project shows it missing |


## Parking lot — nice to have

Things that are wanted but not needed. **Parking is not a promise:** an entry may be struck without
anyone explaining why, and nothing here is owed to anybody.

**What an entry must carry**, or it turns into a graveyard: *what* it is, and *what would make us
want it*. Not a trigger that fires by itself — that is a deferral with a criterion and belongs in
the section above — but the situation in which it becomes attractive.

**When it is read: at every release planning.** The lot is walked, and for each entry the second
column is put to the test — *is that true now?* That is where scope for the next release comes
from, and it is also the natural moment to **strike** an entry: something looked at three times and
not wanted three times is probably not wanted.

| What | What would make us want it | Raised |
|---|---|---|
| **Number circle** — a type that hands out numbers by a rule: prefix, start, step, width, for article numbers, EANs, order numbers ([D-268](90-decision-log.md)) | the first model that needs an identifier the system assigns rather than the author types. An invoice number would force it; an article number can wait, because the author can type one | 2026-08-23, from the legacy code sweep |
| **Reader-supplied parameters** — *four portions instead of two*; an attribute declared **scalable**, and a block offering a control ([D-309](90-decision-log.md)) | the first time a recipe is read by someone cooking for a different number of people | 2026-08-23, from the scenario check |
| **Showing things on a map** — an address or a coordinate drawn on a map ([D-334](90-decision-log.md)) | the first model where a place must be **shown**, not merely written down. No new type is needed: `address` exists, and a renderer resolves it | 2026-08-24, from the type review |
| **Reading `JSON`** ([D-334](90-decision-log.md)) | ⚠️ only ever as **reading a foreign format on import**, never as a place to keep things — arbitrary structure in a field is searchable, checkable and migratable by nothing | 2026-08-24, from the type review |
| **Connectors to foreign systems — Google Calendar, Spotify, eBay.** The owner's three examples, and ⚠️ **they are three different things, which is the reason to keep them in one entry:** *let a calendar entry be created* is an **action that leaves the building**; *list the eBay sales under an article* is **foreign data hung on a node**; *a Spotify link shows extra data* is **enrichment from a key we already store**. **The third is nearly free and the first is genuinely new.** A foreign key is storable today — [D-171](90-decision-log.md) and P4d already say an opaque key of a foreign system is stored as text and the core knows nothing of the system behind it, which is exactly what a Spotify id or an eBay item number is. ⚠️ **And the enrichment case has the same shape as a problem already on the table:** a renderer reaches out to nothing ([D-159](90-decision-log.md)), so fetched data has to arrive **in** the context — the identical question the reference renderer raises about a target's label. Solve one and the other follows. ⚠️ **The action case fits nothing yet.** Creating a calendar entry is neither storage nor rendering; it is a side effect on somebody else's system, with authorisation, failure and retry behind it, and the concept has no place for any of that. ⚠️ *One trap worth recording now: a block renders server-side on **every** request ([D-254](90-decision-log.md)), so a connector called while rendering makes every page wait on somebody else's server. Whatever is built, the fetching does not belong in the render path* | the first model whose subject genuinely lives elsewhere — a collection priced on eBay, a schedule that has to appear in a calendar the owner already keeps. ⚠️ Not before **one** of them is a real need: three half-connectors are worse than none, because each is somebody else's API changing underneath us | 2026-08-25, from the owner |
| **Doing the loading, or the rendering, several things at a time.** ⚠️ **The architecture happens to be shaped for the loading half already**, which is the useful part of writing this down: [D-159](90-decision-log.md) makes *everything is loaded before the first renderer is called* a rule, so loading is a **separate stage** with a clear boundary — the place where concurrency could go without touching a renderer. And it could not go **inside** a renderer even in principle: one is handed its value and its settings and reaches out to nothing, which is what keeps the core testable without a WordPress bootstrap (`CD-1`). ⚠️ **But it is the second lever, not the first, and the order matters.** The concept's own answer to a slow page is *fewer queries*, not *parallel ones* — `CD-7` forbids the query-per-field loop, and the boundary run counts queries for exactly this reason. A page that is slow because it asks twenty-five times what it could ask four times is not fixed by asking twenty-five times at once. **So this is worth reaching for only once a page has been measured and is slow with the queries already minimal** | a front-end page that is genuinely slow **after** its query count has been brought down — most likely a report ([D-202](90-decision-log.md)), which computes at output time and joins, or a page rendering many records server-side on every request ([D-254](90-decision-log.md)) | 2026-08-25, from the owner |
| **Three more ways to lay records out on a page — tiles, a timeline, and a plain list that comes from data.** ⚠️ **All three are drawings, not blocks, and [R76e](30-renderer.md#r76e--the-block-selects-and-hides-the-renderer-draws) is the line that says so:** *the block selects and hides; the renderer draws.* So *which* records appear stays the **list block**'s job ([D-208](90-decision-log.md), [D-234](90-decision-log.md)) and is already decided — what is new is three arrangements of the same selection, which is exactly the split [R51c](30-renderer.md#r51c--set-and-table-are-retired-as-constructs-and-three-renderers-remain) made when it retired `set` and `table`: *the thing* and *the drawing* are two things. **The plain list is the smallest and probably needs no new renderer at all** — a table renderer with one column is a list. ⚠️ **The timeline is the one with a real question in it:** it needs an **axis**, which means one attribute of the record is nominated as the date it is placed by. That is a setting naming an attribute, and nothing in the model does that yet | the first page where a reader has to take in twenty records at once. A handful reads fine as a table, and tiles are worth building when the table stops being readable | 2026-08-25, from the owner |
| **Interchangeable parts — variants that may stand in for one another, and the conditions under which they may not.** The owner's example: `7400` as the umbrella term over `74HC00`, `74LS00` and the rest — *they **can** be interchangeable but need not be, because of timing.* ⚠️ **That sentence rules out the obvious shortcut, which is why it is written here rather than assumed.** The tree already says `74HC00` **is a** `7400` ([D-041](90-decision-log.md)), so the tempting rule is *siblings under one parent are interchangeable* — and his example is a counter-example to it. **Substitutability is its own statement between two things, not a consequence of a shared ancestor**, and it is neither automatic nor symmetric in general: a faster part may replace a slower one where the reverse fails. So it wants an ordinary aggregation attribute at `0..*` — *may be replaced by* — which the model can already express. ⚠️ **What is genuinely unsolved is the qualification.** *Interchangeable except where timing matters* is a condition, and [D-243](90-decision-log.md) is explicit that the expression language does **not** grow. Whether the condition is a free text a person reads, a reference to the attribute that decides it, or nothing at all is the open part. *And comparing two of them side by side under `7400` is already the comparison block ([R58b](30-renderer.md#r58b--a-comparison-block-resolves-to-the-nearest-common-ancestor)) — the same mechanism the conclusions above want* | the first parts list where a part is out of stock and somebody has to answer *what else fits* from the model instead of from memory | 2026-08-25, from the owner |
| **Sources kept in one place, and gathered into groups a block drops onto a page** — one entry per source, edited once, used everywhere; a group is a named selection of them; a block puts the group on a page as a list. ⚠️ **Almost nothing new is needed, and that is the point of writing it down now**: a source is a thing that stands on its own, so it is an ordinary node under `Model`; a group is a node with one aggregation attribute at `0..*` pointing at sources; putting it on a page is the **list block** ([D-208](90-decision-log.md)), and *which* group is **selection**, which is the block's job ([D-234](90-decision-log.md)). What is genuinely open is the **join to what cites them** — [D-211](90-decision-log.md) already shows a source *beside a medium*, and whether an arbitrary node may cite one is a different question from whether a medium carries one | the first page whose sources appear a second time somewhere else — because that is the moment editing them in two places starts producing two versions of the truth. A single page with three footnotes does not need it | 2026-08-25, from the owner |
| **A pocket calculator on a numeric field** — type `47*1000` or `220/3` into the field and the figure appears. ⚠️ **What is stored is the number, never the expression.** An expression that were kept would be a *computed value* ([60 Calculation](60-calculation.md)), which recomputes, has a staleness state ([D-147](90-decision-log.md)) and belongs to a different mechanism entirely. This is a keypad, and it hands over a plain number — so it is a **control inside an existing renderer** (R15's circumstance), not a renderer of its own and not a new type. It is also **not** the `4k7` notation, which is the converter's job ([R35a](30-renderer.md#r35a--notation-is-not-structure)) | the first model where the author is doing arithmetic on paper before typing — dimensions in mixed units, a price built from a rate and a count. Until then a calculator sits beside the keyboard already | 2026-08-25, from the owner |
| **A type that remembers which page a record belongs to** — the node into which Gutenberg's post or page name arrives, so a record can say *this belongs to that page*. Shaped like `user_ref` ([D-171](90-decision-log.md), P4d): an **opaque key of a foreign system**, stored as text, the core knowing nothing of WordPress. ⚠️ **The direction has to be decided before it is built, not after.** [D-234](90-decision-log.md) already puts the other direction in place — the block is *selection*, and *which node this page shows* is stored **in the post**. This entry points the other way, from the record at the post. **Both facts in both places would be the same fact twice**, which the code standard forbids outright, so the question is which of the two is the truth and which derives | ⚠️ **The owner's own example, and it is not a small one:** *on every page I have a conclusion — one could collect those and also set them side by side.* **Both halves already exist as mechanisms**: collecting is the **list block** ([D-208](90-decision-log.md)) and setting side by side is the **comparison block** ([D-207](90-decision-log.md), [R58b](30-renderer.md#r58b--a-comparison-block-resolves-to-the-nearest-common-ancestor)). What is missing is only the **link**, which is this entry. ⚠️ **And the example tilts the direction question**: *collect every conclusion* is one indexed query when the **record** carries the page, and a walk through every post's stored configuration when the post carries the node. That is an argument, not a decision | 2026-08-25, from the owner |

## Still to write

- ✅ *What `locked` means was settled by [D-314](90-decision-log.md): a person must be able to
  **check** what was built, which is what [the core on one page](10-domain-core.md#the-core-on-one-page)
  is for.*
- Phases beyond the first cut of six ([97 Implementation plan](97-implementation-plan.md)) — the
  seventh package is decided when the sixth is done.

## Harvest candidates

| Source | What is in it |
|---|---|
| [`../legacy/ROADMAP.md`](../legacy/ROADMAP.md) | Old phase plan. |
| [`../legacy/plans/project-plan.md`](../legacy/plans/project-plan.md) | Section *Delivery phases* (Phase 0 / 0b / 1 / 2 / 3). |
