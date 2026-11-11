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
| M-2 | **Build one board** (RF process): tick off what is already assembled, to see what is still missing | the board's parts list, position by position → tick when assembled; each tick raises «assembled» of that position by one board | same field as the GUI (assembled count per position, [D-1146](../../NewConcept/90-decision-log.md)); in the GUI the count is raised directly as today | 2026-11-11, thread «Fertigung», spec `taxmod-fertigung/spezifikation-fertigungsprozess.md` §4 | — |

## 2 · Stock (`wp_tax_stock`)

*No need reported yet.*

## 3 · Modeller (`wp-taxonomy-modeler`)

*No need reported yet.*

## 4 · Scanning (cross-cutting, Modeller)

| # | Process | Steps on the PDA | Data / booking | Reported | Later / maybe |
|---|---|---|---|---|---|
| X-1 | **Scan a code** and jump to / act on the record | scan → recognise the record → offer the module's targets | same reader as the scanner test page: core `ScannedCode::read()` (O/K/M/E id per [D-1032](../../NewConcept/90-decision-log.md), bare record id, `taxmod_open_record` link, any separator incl. «ß» from US layout on a German keyboard) and filter `taxmod_scan_links` for module targets (stock: place/box, material) — [D-1206](../../NewConcept/90-decision-log.md) | 2026-11-06, thread «Scanner-Testseite» | scan context, e.g. place first, then material = transfer (open) |

Hardware at hand: Eyoyo barcode/QR scanner, keyboard input.

## Open (cross-cutting, undecided)

- Device and form: browser page on a phone/handheld or own app — open.
- Scanning: which codes (label QR from the print module, manufacturer barcodes) — open.
- Scan input: keyboard wedge (as the Eyoyo) vs. camera — open.
- Scan context (what a second scan means after the first) — open.
- Offline operation — open.
