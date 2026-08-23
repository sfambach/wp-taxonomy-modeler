---
title: Contradictions found while catching the documents up
status: for the owner to settle
round: R1
last_updated: 2026-08-23
---

# Contradictions

Places where a concept document and a decision — or two decisions with each other — say
different things. **Nothing below the *Resolved* section is settled.**
[PR-4](../../../CLAUDE.md) forbids picking one silently, and every open item here is a decision
the owner has to make, not a gap an agent may fill.

## Where things stand

| Section | Raised while catching up | Count | State |
|---|---|---|---|
| [Blocking](#blocking) · [Minor](#minor--wording-overtaken-rather-than-a-real-disagreement) | [40 I18n](../40-i18n.md), [70 Migration](../70-migration.md) | 6 | **resolved** — round one |
| [From the 30-renderer pass](#from-the-30-renderer-pass-2026-08-23) | [30 Renderer](../30-renderer.md) | 6 | **resolved** — rounds two and three |
| [From the persistence and calculation pass](#from-the-persistence-and-calculation-pass-2026-08-23) | [50 Persistence](../50-wordpress-persistence.md), [60 Calculation](../60-calculation.md) | 5 | **resolved** — round three |
| [From the renderer inventory](#from-the-renderer-inventory-2026-08-23) | [30 Renderer](../30-renderer.md), `legacy/` | 5 | **resolved** — round three |
| [From the interaction and domain-core catch-up](#from-the-interaction-and-domain-core-catch-up-2026-08-23) | [20 Interaction](../20-interaction.md), [10 Domain core](../10-domain-core.md) | 1 | **resolved** — round four |
| [From the second renderer catch-up](#from-the-second-renderer-catch-up-2026-08-23) | [30 Renderer](../30-renderer.md) | 2 | **open** |

**None open.** The last — found on 2026-08-23 while writing the specification — [D-088](../90-decision-log.md)'s
*an override may narrow **and** widen* against [D-221](../90-decision-log.md)'s *restrictions never
widen* — see the last section. The previous one — a preview *level* switch against
[D-231](../90-decision-log.md) — was settled by [D-278](../90-decision-log.md), which removed
one side rather than reconciling the two: the *level* is retired, because what it did is
purpose, depth and visibility, and all three already exist.

Twenty-two were raised and **all twenty-two are settled** — each in one of the six *Resolved*
sections below, which name the deciding entry.

| Document | Line or section | What the text says | Which decision disagrees | What is unclear |
|---|---|---|---|---|
| [30 Renderer](../30-renderer.md) | [R15](../30-renderer.md#r15--variant-and-circumstance-are-different-axes), the variant/circumstance table; and [the contract](../30-renderer.md#the-contract-revised) | *Editable / read-only* is a **circumstance** — an **option inside one renderer** — not a variant, so a read-only slider is the same renderer as an editable one. [D-018](../90-decision-log.md) is the rule behind it, and [D-096](../90-decision-log.md) has the preview call **the same renderer twice**, once editable and once not. | [D-168](../90-decision-log.md): purpose — **display / edit / search** — is *part of the registry key*, and display and edit are named as two of the three purposes. | Whether a type may now register **different renderers** for display and for edit — in which case editable/not has moved from an option to a key and the R15 table is wrong — or whether the key merely *permits* a specialised renderer while one renderer normally answers for both purposes. [D-168](../90-decision-log.md) names a fallback only for the **search** purpose ("with no search renderer registered, the edit renderer plus the type's default operators") and says nothing about a display↔edit fallback. Knock-on: [D-095](../90-decision-log.md)'s *`read_only` removes the input, not the field* — under a purpose key, does a read-only attribute inside an edit-purpose form get the display renderer, or the edit renderer with its input suppressed? |
| [30 Renderer](../30-renderer.md) | [R28–R32](../30-renderer.md#r28r32--the-rule-complete), the fourth row of the multiplicity table | *One available entry with an **optional** multiplicity keeps a real choice — take it or leave it — and **must not be greyed**.* Stated twice: in the table, and in the paragraph that warns the rule must not be over-applied. Recorded as [D-056](../90-decision-log.md) in those words. | [D-198](../90-decision-log.md): *a select holding **one** entry is greyed out — nothing is being chosen — and becomes live only when more than one option exists*, and the owner asks for this to be **checked everywhere and kept consistent**. | Which of the two is the rule. They collide on exactly one case — one entry, multiplicity `0..1` or `0..*` — where D-056 counts *nothing* as a second possible answer and D-198 does not count it at all. The difference is visible: under D-198 a user can no longer clear an optional single-entry selection. Also: D-198 is the later decision but does not mention D-056, so it is not clear it was meant to supersede it. |
| [30 Renderer](../30-renderer.md) | [R51](../30-renderer.md#r51--grouping-renderers-are-not-for-data-types) and [R51a](../30-renderer.md#r51a--what-became-of-the-declared-root) | Grouping renderers (table, form, compact row) are **not offered for data-type nodes**, which have their own; they apply to nodes that **do not inherit from a data type**. The classification was to come from a **declared root** — [D-099](../90-decision-log.md), the declared-root half marked as a proposal. | [D-188](../90-decision-log.md) and [D-193](../90-decision-log.md): the branch is `Primitives`, and it holds **two** sub-branches — **Data Types** (value in the record) and **Constants** (value is a reference to a node). [D-120](../90-decision-log.md) makes the declared root a **binding**. | What the eligibility rule keys on now: the whole `Primitives` branch, or only its `Data Types` sub-branch. A **constant** — `Gramm`, a currency — is a primitive but is not a data type, and no decision says whether *render as a table* should be offered for one. Also whether there is **one** binding or two, since [D-120](../90-decision-log.md) speaks of bindings for roots and singletons and [D-193](../90-decision-log.md) put the load-bearing distinction one level deeper. |
| [30 Renderer](../30-renderer.md) | [R22a](../30-renderer.md#r22a--the-preview-renders-a-test-data-pack) | [D-052](../90-decision-log.md) gives test data **three** sources in fallback order: a record flagged as test data → the attribute defaults assembled into a sample → **generated from the settings** (type, min, max, step). The third is what makes the decision's own claim true: *a new type therefore previews immediately with nothing entered*. | [D-160](../90-decision-log.md): the preview renders a **test data pack**, and *defaults remain the fallback* — two sources, the generated one not mentioned. [D-175](../90-decision-log.md) then makes those packs installable and **removable** data packs. | Whether the generated third source still exists. If it does, [D-160](../90-decision-log.md)'s fallback sentence is simply incomplete. If it does not, a model that no pack covers and whose attributes carry no defaults previews **empty** — which is the state [D-160](../90-decision-log.md) exists to avoid — and removing a data pack ([D-175](../90-decision-log.md)) can silently take a model's preview with it. |
| [30 Renderer](../30-renderer.md) | [R70/R71](../30-renderer.md#r70r71--the-shown-fields-are-the-searched-fields) and [R69a](../30-renderer.md#r69a--the-search-column-the-quick-search-and-the-filter) | The shown fields are the searched fields, and *the shown fields are per **use site*** — [D-112](../90-decision-log.md), which offers a type-level identifying set only as an additional declaration "where consistency genuinely matters". | [D-167](../90-decision-log.md): the search structure is *a normalised column **per record**, written **on save** from the fields that are shown* ([D-112](../90-decision-log.md)). | Which fields are written into that column, since **at save time there is no use site**. Either the type-level identifying set stops being optional and becomes what the column is built from — in which case [D-112](../90-decision-log.md)'s default is no longer the common case — or the column is built from some union of the use sites, which no decision describes. It decides what the quick search can find, and it is the same column duplicate detection shares ([D-167](../90-decision-log.md)'s one-normalisation-function rule). |
| [30 Renderer](../30-renderer.md) | [R64](../30-renderer.md#r64--the-branch-root-a-rule-with-a-setting) and [R64a](../30-renderer.md#r64a--what-may-be-picked-is-a-property-of-the-use-site) | [D-110](../90-decision-log.md): only the **branch root** is excluded from the choice by default. It explicitly **rejects deriving** the rule *a node with children is a category, a leaf is a choice*, because it "breaks for organisational units, where a department with sub-departments is still a valid answer". | [D-181](../90-decision-log.md): what may be picked is a use-site setting, and **the default is *leaves only***, because the owner "could think of no case for choosing an intermediate node". | Whether *leaves only* is really the default, given that [D-110](../90-decision-log.md) had already found the counter-case and recorded it as the reason not to derive exactly this rule. Under D-181's default a department chooser rooted at the company offers no department that has sub-departments; under D-110 it offers all of them except the company. Both decisions are about the same control, and neither cites the other. |

---

## From the persistence and calculation pass, 2026-08-23

Raised while folding the post-draft decisions into
[50 Persistence](../50-wordpress-persistence.md) and [60 Calculation](../60-calculation.md). Same
standing as everything above: **nothing here is resolved.** Both documents point here from the
affected sections.

| Document | Line or section | What the text says | Which decision disagrees | What is unclear |
|---|---|---|---|---|
| [50 Persistence](../50-wordpress-persistence.md) | [P5–P7](../50-wordpress-persistence.md#owner-statement--2026-08-22-second-pass-no-table-per-model) and *P7 is right, and it has one consequence worth naming* | **P5:** *There will not be a database table per model.* [D-066](../90-decision-log.md) gives the reason: *a table per model would mean schema changes at run time*, and that is what makes a run-time modeller possible at all. | [D-165](../90-decision-log.md): the reporting stage is **one flat projection per model** — *a table with a column per attribute, filled from the records*. | [D-165](../90-decision-log.md) answers the *duplicated fact* objection — the projection is a cache, never written to, only rebuilt — but it does not answer [D-066](../90-decision-log.md)'s objection, which was about **DDL at run time**, not about a second truth. A column per attribute means the table's own schema changes whenever the model does. Undecided: when a projection is created and rebuilt, what happens to it while a model change is in flight, whether it is per installation opt-in or automatic, and what a `1..*` attribute ([D-133](../90-decision-log.md)) becomes in a table with one column per attribute. |
| [50 Persistence](../50-wordpress-persistence.md) | [P12–P14](../50-wordpress-persistence.md#owner-statement--2026-08-22-fourth-pass-type-safety-down-to-the-column), the typed-column table; and [the data layer](../50-wordpress-persistence.md#oq-015-answered--the-data-layer) | The value columns are `value_int` · `value_decimal` · `value_text` · `value_ref` · `value_date` ([D-074](../90-decision-log.md)), and `value_ref` holds **a node id or a record id** — both from spaces this plugin allocates ([D-131](../90-decision-log.md), [D-164](../90-decision-log.md)). | [D-211](../90-decision-log.md): a medium's file lives in the **WordPress media library** and *our model holds the identifier, the URL and the attribution* — an attachment id belongs to neither of our two id spaces. | Which column carries a foreign identifier, and whether a third sort of reference is being introduced. Also whether [AR-1](../../../CLAUDE.md) / [D-007](../90-decision-log.md) — *not in `wp_posts`* — is untouched by a **data** value pointing into `wp_posts`, since it was written about the **model**. And whether *existence is checked at display time* means one check per medium per render, which nothing has costed. |
| [50 Persistence](../50-wordpress-persistence.md) | [The asymmetry](../50-wordpress-persistence.md#the-asymmetry--edge-only-settings-exist-node-only-ones-do-not), closing note | *Multiplicity still inherits and is still overridable: a subtype may narrow `0..1` to `1`.* Multiplicity is a **setting**, not a column, precisely so that it gets the resolution walk ([D-086](../90-decision-log.md)). | [D-133](../90-decision-log.md) makes multiplicity decide **where the value is stored**, and [D-134](../90-decision-log.md) makes a change from `1` to `1..*` a **storage migration**. | Which multiplicity decides the storage — the one on the base edge, or the one resolved for this use site. If it is the resolved one, one attribute can be inline for a subtype and own records for its parent, and the migration of [D-134](../90-decision-log.md) fires on an *override* rather than on a model change. If it is the base one, narrowing across the `1` / `1..*` boundary has to be refused, and no decision says so. The example in the text (`0..1` → `1`) does not cross that boundary, which is why the question has stayed invisible. |
| [50 Persistence](../50-wordpress-persistence.md) | [P11c](../50-wordpress-persistence.md#p11c--finding-text-one-normalised-column-per-record) | The search column is written on save **from the fields that are shown** ([D-167](../90-decision-log.md), [D-112](../90-decision-log.md)). | [D-112](../90-decision-log.md) itself: *shown fields are **per use site***, and a type *may additionally declare its own identifying set*. | There is **one** search column per record and **many** use sites showing it. Undecided: whether the column is filled from the type's declared identifying set (in which case the *no new setting for the common case* promise of [D-112](../90-decision-log.md) does not carry over to search), from the union of every use site's shown fields, or from something else. The two decisions were written about different objects — a chooser column and a record row — and the join between them was never made. |
| [60 Calculation](../60-calculation.md) | [K6](../60-calculation.md#k6--the-second-cut-model-or-display) — *The same expression language serves both* | Exactly **two** owners for one expression language: an **attribute** (yields a value) and a **renderer** (yields output). Taken as written by [D-043](../90-decision-log.md). | [D-202](../90-decision-log.md): a **report** computes *at the time the output is produced*, and it **joins** — *a join across unrelated records is not a descent*. [D-045](../90-decision-log.md)'s addressing is a **relative path of edge ids** from one record, which cannot express such a join. | Whether a report is a third owner of the same language, a renderer-owned display calculation with a reach nothing has yet described, or a separate mechanism. [D-202](../90-decision-log.md) says only that *the rules for a report are stored* and that the claim *a report needs no new mechanism* falls — it does not say what replaces it. Until this is settled, K6's *the same expression language serves both* is true of two owners and silent about the third. |


---

## Resolved — second round, 2026-08-23

| # | How it was settled |
|---|---|
| renderer pass · 1 · purpose vs circumstance | **[D-217](../90-decision-log.md)** — resolved against the newer decision. The owner: *a node cannot have several renderers at the same time.* Purpose leaves the registry key and is passed in the context; a renderer declares the purposes it serves through `supports()`. [D-018](../90-decision-log.md) and [D-096](../90-decision-log.md) stand untouched. The knock-on question — what a read-only field gets inside an edit form — is answered by **[D-218](../90-decision-log.md)**: the display purpose, no input at all. |

The index at the top has been renumbered for this round (2026-08-23, after the renderer-inventory
pass appended). ⚠️ The **row itself** is still in the 30-renderer table above — it is left there
deliberately rather than deleted, per the rule that a superseded entry is never removed; it is the
row on *purpose versus circumstance*.

---

## From the renderer inventory, 2026-08-23

Raised while building the inventory at the end of [30 Renderer](../30-renderer.md), which for the
first time read the **legacy per-type renderer assignment** alongside the decisions
([`ARCHITECTURE.md`](../../legacy/ARCHITECTURE.md), the `plans/` sheets, and the exported standard
tree [`test-template-wtt_fs.json`](../../../scripts/fixtures/test-template-wtt_fs.json)).

Same standing as everything above: **nothing here is resolved.** Two of the five are a *decision
against legacy material* rather than a decision against a decision — legacy is quoted, never
inherited ([PR-1](../../../CLAUDE.md)) — so the fourth column names the legacy source instead of a
`D-<nnn>`, and the question in those rows is what the harvest is allowed to take.

| Document | Line or section | What the text says | Which decision disagrees | What is unclear |
|---|---|---|---|---|
| [30 Renderer](../30-renderer.md) | [R51](../30-renderer.md#r51--grouping-renderers-are-not-for-data-types), and the *Assigned to* column of the [Inventory](../30-renderer.md#inventory--the-renderers-named-so-far) | [D-099](../90-decision-log.md): **grouping renderers are not offered for data-type nodes**, which have their own — *offering «render as a table» for `Integer` is noise in a choice list [D-056](../90-decision-log.md) says should hold only real choices.* | The legacy assignment does the opposite throughout: in the exported tree `Simple Datatypes` and `Complex Datatypes` carry **`FormRenderer`** as their own set value, `Eigene Datentypen` carries **`CompactRenderer`**, and `Konstanten › Präfixe` carries **`ChildListRenderer`** ([`ARCHITECTURE`](../../legacy/ARCHITECTURE.md) ≈ `0.0.540`) — three grouping renderers sitting on the data-type side of the tree. | Whether the eligibility rule bites on a **branch host** at all. In the old project the grouping renderer on `Präfixe` is what drew the *list of its children*, which is a real job and the concept has no renderer for it. Either [D-099](../90-decision-log.md) is about **leaves** and says nothing about the folders above them, or the concept is missing a child-list renderer and the exclusion would forbid the only sensible choice for a branch node. This compounds the still-open question of what the rule keys on after [D-193](../90-decision-log.md). |
| [30 Renderer](../30-renderer.md) | [R65](../30-renderer.md#owner-statement--2026-08-22-sixteenth-pass-the-multi-step-input) — *the **multi-step renderer**: the user first chooses a node, then has to enter data for that node* | [D-111](../90-decision-log.md) records the multi-step **input** as *step one is the chooser, step two is the ordinary editor* — existing parts in sequence, and **no renderer of its own**. | The owner's own [R65](../30-renderer.md#owner-statement--2026-08-22-sixteenth-pass-the-multi-step-input) calls it a renderer, and the legacy product had one: **`MultistepRenderer`** ([`ARCHITECTURE`](../../legacy/ARCHITECTURE.md) ≈ `0.0.546`), a selectable *Preferred render* value carrying a **`dialog` / `inline` mode option** — which is precisely the shape [D-108](../90-decision-log.md) refused when it split inline and popup into two renderers. | Whether the multi-step case needs a renderer of its own. If it does not, something still has to own the two-phase sequence and the *search existing / create new* choice of [D-111](../90-decision-log.md), and no decision says what. If it does, it arrives carrying a mode option that [D-108](../90-decision-log.md) has already ruled out once — so the question is not only *is it a renderer* but *is inline-versus-popup a setting after all, in this one place*. |
| [30 Renderer](../30-renderer.md) | [R20](../30-renderer.md#owner-statement--2026-08-22-fourth-pass-surfaces-and-preview) — *the settings side is itself a **page renderer**, and it follows special steps* | [D-091](../90-decision-log.md): *`IPageRendere` needs no interface of its own — a page is a rendered node.* [D-190](../90-decision-log.md): the detail view *is a series of attributes rendered under the edit purpose inside a frame*, and *nothing about it is special-cased*. | Each other, on what draws **the frame**. [D-190](../90-decision-log.md) fixes the frame's order — buttons · chips · name · display · attributes · preview · relations — as a decided sequence, and [D-192](../90-decision-log.md) adds three more sections to it. [R1](../30-renderer.md#consequences-of-r1) says **no other path produces output**. | What object the frame is a rendering *of*. If a page is a rendered node ([D-091](../90-decision-log.md)), the frame is a renderer registered for — which type? No node in the model says *I am a detail screen*. If the frame is instead hand-written admin chrome, that is the second way of drawing a screen [R1](../30-renderer.md#consequences-of-r1) and [D-190](../90-decision-log.md) both exist to prevent. The same question applies to the **tree row** ([R18](../30-renderer.md#owner-statement--2026-08-22-fourth-pass-surfaces-and-preview)), which no decision has ever confirmed as a renderer. |
| [30 Renderer](../30-renderer.md) | The [Inventory](../30-renderer.md#inventory--the-renderers-named-so-far) rows for the **front-end**, **comparison** and **list** blocks; and [*What does NOT belong here*](../30-renderer.md#what-does-not-belong-here) | *Concrete Gutenberg block implementations — those are **consumers** of this contract*, and [R1](../30-renderer.md#consequences-of-r1): display happens **only** through a renderer. | [D-207](../90-decision-log.md) puts real drawing logic **in the block**: walk up to the nearest common ancestor, compare the shared attributes side by side, **move what is not shared below** the comparison and optionally behind a disclosure. [D-208](../90-decision-log.md) puts the attribute restriction there too. | Whether that logic is a renderer. Cell-level drawing is clearly the ordinary renderer under the display purpose, but the ancestor walk, the column layout and the ordering rule are display decisions living in a block — and if a block may hold them, so may an admin screen, which is [R1](../30-renderer.md#consequences-of-r1) eroding by precedent rather than by decision. The reverse reading is equally available: a **comparison renderer** taking several subjects, which nothing in the contract currently allows, since [D-092](../90-decision-log.md) fixes a node renderer at **exactly one node**. |
| [30 Renderer](../30-renderer.md) | [R51/R51a](../30-renderer.md#r51--grouping-renderers-are-not-for-data-types) and the [legacy per-type table](../30-renderer.md#the-legacy-per-type-assignment-as-exported) | [D-117](../90-decision-log.md): the old `Complex Datatypes` branch held two different things, and *`set` and `table` were **container renderers** all along* — which is why neither needed to be a type. | The exported legacy tree, where `Complex Datatypes › set` and `› table` are **ordinary nodes carrying no renderer of their own** — they fall back to the inherited `FormRenderer`, and `ARCHITECTURE` records both as **parked** catalog kinds that are *not active product renderers*. | Whether the concept's container renderers have a legacy ancestor to harvest or are genuinely new. [D-117](../90-decision-log.md) reads as though the old project had implemented them and merely filed them in the wrong place; it had not — it had two parked type leaves and no set/table renderer. Nothing decided rests on this, but the *take / rework / drop* of a harvest row does, and so does any expectation that a table renderer is a small job because *it already existed*. |

---

## Resolved — third round, 2026-08-23

All fifteen are now settled. Rounds one and two are recorded above; this round closed the rest.

| # | How it was settled |
|---|---|
| projection vs *no table per model* | **[D-228](../90-decision-log.md)** — [P5](../50-wordpress-persistence.md) binds what *holds* data; a projection holds none. Opt-in and few, because fine granularity would otherwise breed hundreds of tables. |
| attachment id fits no column | **[D-229](../90-decision-log.md)** — a medium is an ordinary type under `Model`; no new table, and the duplicated attribution disappears. |
| base vs resolved multiplicity | **[D-232](../90-decision-log.md)** — the branch decides storage, not the multiplicity. Supersedes [D-133](../90-decision-log.md). |
| detail-view frame · blocks drawing · grouping eligibility | **[D-233](../90-decision-log.md)**, **[D-234](../90-decision-log.md)**, **[D-235](../90-decision-log.md)** — a page is a rendered node; the block selects and the renderer draws; eligibility keys on the `data_types` binding. |
| shown fields vs the search column | **[D-237](../90-decision-log.md)** — identifying fields belong to the type, displayed columns to the use site. |
| leaves-only default | **[D-238](../90-decision-log.md)** — reversed; [D-110](../90-decision-log.md) was right and I had reused its rule without re-reading its reason. |
| test data sources | **[D-240](../90-decision-log.md)**, **[D-241](../90-decision-log.md)** — three layers, and the sample value belongs to the type. |
| one expression language vs the report | **[D-243](../90-decision-log.md)** — a report is selection plus grouping plus expression; the language does not grow. |
| multi-step renderer | **[D-244](../90-decision-log.md)** — already dissolved by [D-111](../90-decision-log.md) into chooser plus editor; only the chooser's default moved, to the dialog. |
| `set` and `table` | **[D-245](../90-decision-log.md)** — [D-117](../90-decision-log.md) was right; the parked nodes were the conflation being cleaned up. |

---

## From the interaction and domain-core catch-up, 2026-08-23

Raised while bringing [20 Interaction](../20-interaction.md) and
[10 Domain core](../10-domain-core.md) up to [D-258](../90-decision-log.md). One item, and it is a
**document against a decision**, not two decisions against each other.

Same standing as everything above: **nothing here is resolved.**

| Document | Line or section | What the text says | Which decision disagrees | What is unclear |
|---|---|---|---|---|
| [10 Domain core](../10-domain-core.md) | [C44](../10-domain-core.md#c44--the-correction-is-the-load-bearing-part), the class diagram — `Basiseinheit` with a `+symbol` member | The unit node carries **`symbol` as a modelled attribute**, alongside `praefix`. The neighbouring diagram in [C39/C41/C42](../10-domain-core.md#c39-c41-c42--the-type-layer) reads the same way, labelling the leaves `Stueck · St` and `Euro · EUR`. | [D-252](../90-decision-log.md): **`symbol` is a label role** — a very short text, translated, stored in the labels table like any other label ([D-196](../90-decision-log.md)). [40 I18n](../40-i18n.md) already holds `Ω` and `St` exactly that way, as `role = symbol` rows per locale. | Whether a unit's short form is a **label** or an **attribute** — and if it is a label, whether `Basiseinheit` is left with only `praefix`, since the diagram was drawn to show that a base unit is a definition rather than a number. Under both readings at once the same fact has two homes, which is the duplication the code standard forbids outright, and the failure would only surface in the second locale. And the two are not obviously interchangeable, so the question underneath is *does anything ever read a unit's symbol as **data*** — restrict it, compute with it, key on it — or is it only ever displayed? |

---

## From the second renderer catch-up, 2026-08-23

Raised while bringing [30 Renderer](../30-renderer.md) up to [D-258](../90-decision-log.md) — the
pass that had to correct six superseded positions in place. Two items, and both are **a decision
against a decision** rather than a document against one.

Same standing as everything above: **nothing here is resolved.**

| Document | Line or section | What the text says | Which decision disagrees | What is unclear |
|---|---|---|---|---|
| [30 Renderer](../30-renderer.md) | [R33b](../30-renderer.md#r33b--several-are-eligible-exactly-one-is-in-effect), and [R35c](../30-renderer.md#r35c--the-composed-type-is-the-unit-of-rendering)'s closing rule | [D-219](../90-decision-log.md): *several converters may be **eligible** for a type, but **exactly one is in effect** per rendering* — and it justifies that by analogy: **the same shape [D-217](../90-decision-log.md) gives renderers**. The rule is then relied on twice: [D-220](../90-decision-log.md) narrows the converter list from the chosen renderer's result shape, and [D-223](../90-decision-log.md) turns *which converter* into a control that may disappear. | [D-236](../90-decision-log.md): a node carries an **ordered list of renderers** — one mandatory, further ones appended — which **supersedes the single-renderer half of [D-217](../90-decision-log.md)**. The shape D-219 pointed at no longer exists. D-236 lists what survives from D-217 (purpose stays out of the registry key) and says **nothing about converters**. | Whether *exactly one converter in effect* still holds. Read literally, D-219 now says converters follow a **list**, which contradicts its own sentence in the same line; read by intent, the rule stands and its stated reason has simply expired. It is not cosmetic: if a node may draw with a value renderer **and** a colour-code renderer ([D-236](../90-decision-log.md)), the two appended renderers may want **different** converters at the same moment — which is exactly the *value plus colour rings* case [D-226](../90-decision-log.md) made *one setting in one place*. Either a converter is chosen **per renderer in the list** rather than per rendering, or the appended-renderer case cannot carry its own mapping. No decision says which. |
| [30 Renderer](../30-renderer.md) | [One consequence: the preview previews a *level*](../30-renderer.md#one-consequence-the-preview-previews-a-level) | *The preview should let the author choose **which level** is being previewed — admin, block or frontend.* The reason given: *it arguably needs that anyway, since the same model may legitimately look different in each.* Written as a consequence of [D-100](../90-decision-log.md)/[D-103](../90-decision-log.md); it carries **no `D-nnn` of its own**. | [D-231](../90-decision-log.md): *the preview always shows how it will look **in the front end***, and its **only permitted deviation is bounding the size**, because that crops the view rather than altering it. [D-254](../90-decision-log.md) then requires the block editor to ask the server for the same rendering. | Whether a level switch on the preview is still wanted, and if so how it escapes D-231's *only permitted deviation*. Previewing the **admin** level is by definition not showing what the front end will show — yet the level is what carries the depth limit ([D-103](../90-decision-log.md)), which is the whole reason the switch was proposed: a preview at a stricter depth warns about something the front end handles fine. So either the switch goes and the depth limit is always the front end's, or D-231's rule is about **appearance** while depth is a second axis it does not govern. The passage was never a decision, so nothing was superseded and nothing was upheld. |

---

## Resolved — fourth round, 2026-08-23

| # | How it was settled |
|---|---|
| `symbol` as attribute vs label role | **[D-260](../90-decision-log.md)** — always a label; the difference is only how many locale rows exist. The character-class test was tried and does not hold (`kg`, `m`, `Hz` are letters and universal). [C44](../10-domain-core.md)'s modelled attribute goes. **[D-259](../90-decision-log.md)** supplied the missing piece: a renderer is told **which role** to display, which is what the owner actually needed. |

---

## Resolved — fifth round, 2026-08-23

| # | How it was settled |
|---|---|
| second renderer catch-up · 1 · do converters follow renderers into a list | **[D-277](../90-decision-log.md)** — renderer and converter form a **pair**, and the converter belongs to the list entry rather than to the node. [D-219](../90-decision-log.md)'s *one converter in effect* becomes *one per rendering*, which was always the true statement. The converter half may be empty. |

---

## Resolved — sixth round, 2026-08-23. All twenty-two settled.

| # | How it was settled |
|---|---|
| second renderer catch-up · 2 · preview *level* switch vs [D-231](../90-decision-log.md) | **[D-278](../90-decision-log.md)** — resolved by **removing** one side: the *level* is retired, since what it did is purpose, depth and visibility, all of which exist already. [D-231](../90-decision-log.md) stands unchanged. **[D-279](../90-decision-log.md)** adds the rule that decides what the preview covers: preview what you cannot see while configuring — the search view yes, the tree row no. |

---

## From writing the specification, 2026-08-23

| Document | Line or section | What the text says | Which decision disagrees | What is unclear |
|---|---|---|---|---|
| [10 Domain core](../10-domain-core.md), *Multiplicity, restriction* — and [D-221](../90-decision-log.md) | **Restrictions narrow downwards and never widen.** A use site may restrict further; it may not reopen, *or the guarantee was worth nothing*. Written while specifying, from [D-221](../90-decision-log.md)'s *only `Ohm` is permitted* case. | [D-088](../90-decision-log.md): **an override may narrow *and* widen. No monotonicity rule.** A use site may allow something the node did not. | Both are settings resolved along one chain, so they cannot both hold. Three readings: (a) [D-221](../90-decision-log.md) is later and supersedes, and widening is gone — but [D-088](../90-decision-log.md) was decided deliberately and its case (*this one use may also take Volt*) is not obviously wrong; (b) they govern different things — a **permitted set** may not widen while other settings may — which needs saying, because a permitted set **is** a setting; (c) widening is allowed but must be **visible**, since the objection to it is that a guarantee made high up quietly stops holding. ⚠️ **Found while writing the specification, not in review** — it is exactly the kind of thing that only surfaces when the model is stated once in a single voice. |

---

## Resolved — seventh round, 2026-08-23. All twenty-three settled.

| # | How it was settled |
|---|---|
| specification · 1 · narrow-only vs narrow-and-widen | **[D-310](../90-decision-log.md)** — [D-088](../90-decision-log.md) stands. *A use site is an attribute, and it may do everything the node may* (the owner). My *never widen* sentence was an over-generalisation of [D-221](../90-decision-log.md)'s unit case, written without checking whether a rule already existed. What survives is a **reporting** requirement, not a prohibition: a widening should be visible. And the consequence is now stated where it matters — a restriction on a type is a **default, not a guarantee**. |
