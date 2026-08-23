# New Concept — index and working agreement

Re-planning round started **2026-08-22**. This directory is the **single source of truth** for
the project. Everything under [`../legacy/`](../legacy/README.md) is frozen. The rules for
agents working in this repo are at [`/CLAUDE.md`](../../CLAUDE.md).

## Status board

| # | Document | Status | Contains |
|---|---|---|---|
| 00 | [Vision and scope](00-vision-and-scope.md) | `agreed` | owner statements V1–V9 |
| 01 | [Glossary](01-glossary.md) | `draft` | fixed vocabulary, rejected words, dictation notes |
| 10 | [Domain core — the model](10-domain-core.md) | `draft` | owner statements C1–C113; branches, data packs, runtime extension; 39 stale OQ references still to rewrite |
| 20 | [Interaction](20-interaction.md) | `open` | U0 … U17 |
| 30 | [Renderer](30-renderer.md) | `draft` (caught up 2026-08-23) | owner statements R1–R76; registry, surfaces, preview |
| 40 | [I18n and labels](40-i18n.md) | `draft` (caught up 2026-08-23) | owner statements I1–I10; labels table, base name, fallback chain |
| 50 | [Persistence](50-wordpress-persistence.md) | `draft` (caught up 2026-08-23) | owner statements P1–P14; the model is the schema; search; typed columns |
| 60 | [Calculation](60-calculation.md) | `draft` (caught up 2026-08-23) | owner statements K1–K12; calculation vs converter, model vs display, structured expressions |
| 70 | [Model change and migration](70-migration.md) | `draft` (caught up 2026-08-23) | owner statements M1–M17; rename vs replace, export, the conflict resolver |
| 90 | [Decision log](90-decision-log.md) | `open` | D-001 … D-297 |
| 91 | [Open questions](91-open-questions.md) | `open` | OQ-001 … OQ-080 (80 answered or closed, none open) |
| 96 | [Scenario check](96-scenario-check.md) | `draft` | six worlds modelled against the concept; five carried |
| 95 | [Roadmap](95-roadmap.md) | `draft` | Release 2 contents; what waits on an event; the parking lot |
| 98 | [Documentation style](98-documentation-style.md) | `agreed` | how everything here is written |

**Status vocabulary:** `empty` → `draft` (written, not reviewed) → `agreed` (confirmed by the
owner) → `locked` (do not re-litigate without a superseding decision entry).

**One file per topic** ([D-010](90-decision-log.md)). The model is one document, not five.
What keeps a document readable is its internal structure — a chain of small diagram units —
not the number of files.

## Seed sketches — not yet absorbed

The original restart sketches. **Input**, not concept. Each is absorbed into a numbered
document and then marked absorbed here.

| File | Absorbed into | State |
|---|---|---|
| [`NewConcept.md`](NewConcept.md) | 00 Vision and scope | partly — restart rationale still only here |
| [`TreeMeremaid.md`](TreeMeremaid.md) | 10 Domain core | not absorbed |
| [`I18nMeremaid.md`](I18nMeremaid.md) | 40 I18n (+ 10 Domain core) | not absorbed |
| [`RendererMeremaid.md`](RendererMeremaid.md) | 30 Renderer | not absorbed |

## How we work

**The owner dictates, the concept gets written** — document by document, until the concept is
complete ([D-006](90-decision-log.md)). Only then is `legacy/` harvested, as a cross-check.

Per document:

1. The owner states the properties and additions for that topic.
2. It is written up in the [documentation style](98-documentation-style.md): small mermaid
   diagram per *Sachverhalt*, explanation, code only where detail demands it.
3. Anything the statement leaves open becomes an entry in
   [open questions](91-open-questions.md) — it is never invented to fill a gap.
4. Anything decided gets an entry in [the decision log](90-decision-log.md).
5. This status board is updated in the same change.

Legacy harvesting, when it starts, runs through [`_harvest/`](_harvest/README.md).

## Ground rules

Full set in [`/CLAUDE.md`](../../CLAUDE.md). The ones that bite hardest here:

- **Nothing enters the concept without an explicit owner statement or decision** — not even
  the obvious.
- **Unclear stays unclear.** Undecided things become open questions, never inventions.
- **Legacy is quoted, never inherited.** Old `Q<n>` ids stay in `legacy/`. New decisions get
  new ids and name their legacy source if they reuse one.
- **Code blocks are labelled `CONTRACT` or `SKETCH`.** Unlabelled means sketch.
- **No production code before [10 Domain core](10-domain-core.md) is `locked`**
  ([D-004](90-decision-log.md)). Marked throwaway spikes are fine.

## Document order

Vision & scope → Glossary → **Domain core** → Renderer → I18n → Persistence → Roadmap.

Renderer, i18n and persistence all hang off the domain core. If the core moves, they get
built twice.

## Next session — set by the owner, 2026-08-22

Two sittings planned, the concept round drawing to a close:

1. **Finish the remaining questions.** Seven are open, three of them deliberately parked.
   [OQ-056](91-open-questions.md) has a proposal on the table that was never answered — start
   there.
2. **Layout concept and what the interface must let people do.** Not yet written anywhere:
   - **What may a user do in the tree?**
   - **What may they do in the detail view?**
   - **How are blocks put together?**
   - **What is special about Gutenberg, and about the front end.**

That second half needs a document of its own; [30 Renderer](30-renderer.md) answers *how a thing
is drawn*, not *what a person is allowed to do with it*.
