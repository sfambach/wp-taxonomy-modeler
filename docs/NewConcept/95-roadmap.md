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

## Still to write

- Phases within Release 1, each with an entry and an exit condition.
- What *locked* means for [10 Domain core](10-domain-core.md), since [PR-2](../../CLAUDE.md) makes
  it the gate before any production code.

## Harvest candidates

| Source | What is in it |
|---|---|
| [`../legacy/ROADMAP.md`](../legacy/ROADMAP.md) | Old phase plan. |
| [`../legacy/plans/project-plan.md`](../legacy/plans/project-plan.md) | Section *Delivery phases* (Phase 0 / 0b / 1 / 2 / 3). |
