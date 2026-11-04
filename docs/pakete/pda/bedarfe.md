# Package · PDA — collected needs

**Status 2026-11-04.** Collection only. Nothing is decided and nothing is built. Every entry below is a need reported by a
module thread; none has a `D-<nnn>` yet (`PR-3`). Undecided points stay open (`PR-4`).

**His words (2026-11-04):**

> *«a pda machen wir später das sollten wir aber mal sammeln was wir für pda brauchen am besten einen treat aufmachen lassen
> und dem master sagen dasss alle ihre pda bedarfe an diesen pda phread melden»*

## How to add a need

One row per process, grouped by module. Columns:

| Column | Meaning |
|---|---|
| **Process** | what the person does at the shelf / workbench |
| **Steps on the PDA** | what the handheld shows and asks, in order |
| **Data / booking** | which module interface supplies the data and books the result |
| **Reported** | date, thread |
| **Later / maybe** | extensions mentioned but not asked for yet |

## 1 · Manufacturing (`wp_tax_manufacturing`)

| # | Process | Steps on the PDA | Data / booking | Reported | Later / maybe |
|---|---|---|---|---|---|
| M-1 | **Pick parts for a manufacturing order** into the order's project container | position by position: go to storage place → confirm quantity (type or scan) → part is moved into the order's project container | same as the on-screen pick list: stock filters `taxmod_stock_places_of` (where) and `taxmod_stock_book_pick` (move) | 2026-11-04, thread «Fertigung» | enter «assembled» count per position at the workbench ([D-1146](../../NewConcept/90-decision-log.md)) |

## 2 · Stock (`wp_tax_stock`)

*No need reported yet.*

## 3 · Modeller (`wp-taxonomy-modeler`)

*No need reported yet.*

## Open (cross-cutting, undecided)

- Device and form: browser page on a phone/handheld or own app — open.
- Scanning: which codes (label QR from the print module, manufacturer barcodes) — open.
- Offline operation — open.
