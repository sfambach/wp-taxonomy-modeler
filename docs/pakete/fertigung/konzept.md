# Package · Manufacturing («Fertigung», `wp_tax_manufacturing`) — concept draft

**Status 2026-11-04.** Decided: [D-1142](../../NewConcept/90-decision-log.md) (own module, build status per board **and** per
parts-list position). Everything else below is my proposal (`INFERRED`) until it has a `D-<nnn>` (`PR-3`). Built: nothing.

**His words (2026-11-04):**

> *«beim s7vrm projekt sind alle platinen bis auf die spule aufgebaut, das könne wir glaube ich aktuelle noch nicht abbilden ich glaube
> wir sollten das manufacturing als eigenes modul behandeln das sich in tax und tax logistik integriert»*

His choice on the card «Fertigung als eigenes Modul so bauen?»: *«Eigenes Modul, je Platine + Position»*.

## 1 · Who owns what

```mermaid
flowchart LR
  M["Modeller<br/>Projekt · Platine · Revision · Stückliste · Karte (D-1114)"]
  F["Fertigung<br/>Fertigungsauftrag · Status je Platine · je Position"]
  L["Logistik (wp_tax_stock)<br/>Bestand · Orte · Vorgänge · Reservierung"]
  F -- reads --> M
  F -- reads stock / asks for bookings --> L
```

- **Modeller** stays data structure: project, board, revision, parts list, finished card ([D-1114](../../NewConcept/90-decision-log.md)).
- **Logistik** keeps quantities, places, transactions and reservations ([D-1083](../../NewConcept/90-decision-log.md),
  [D-1088](../../NewConcept/90-decision-log.md)). It offers a read interface (stock per place/material, today
  `StockService::presentIn()`) and books withdrawal/receipt when the manufacturing module asks.
- **Fertigung** owns the build: orders, build status, demand vs. stock.

## 2 · Data (INFERRED)

The «Bauläufe» of the stock draft ([`../lager/konzept.md`](../lager/konzept.md) §3.7, never built) move here and become:

| Node | Field | Type | Mult. |
|---|---|---|---|
| Fertigungsaufträge | Projekt | → Projekte | 1..1 |
| | Stückzahl | Ganzzahl | 1..1 |
| | Gebinde | → Lagerort (project box, D-1030) | 0..1 |
| | Status | Konstante: geplant · in Bau · fertig · abgebrochen | 1..1 |
| | Begonnen am / Fertig am | Datum | 0..1 |
| | Baugruppen | → Fertigung Baugruppe (composition, D-498) | 0..* |
| Fertigung Baugruppe | Revision | → Revision | 1..1 |
| | Status | Konstante: offen · Teile da · bestückt · geprüft · fertig | 1..1 |
| | Offene Positionen | → Part List Item | 0..* |
| | Exemplar | → finished card (D-1114) | 0..1 |

- **Demand list ([D-1145](../../NewConcept/90-decision-log.md)):** per board the board itself and every position, «Anzahl ×
  Stückzahl», against the order's box: what lies in it and below it is there. «Best.» = nein drops out; an option can be
  «nicht verwenden». *Replaces the ticks «missing positions» of 0.0.1/0.0.2.*
- **Fitted as a count ([D-1146](../../NewConcept/90-decision-log.md)):** per position, on how many boards it is fitted
  (0 … Stückzahl). «Platinen bestückt» = the smallest count over the used positions, calculated, not chosen — so the
  assembly status of the table above is no longer stored. Saving books the difference as consumption from the box;
  taking a count back books it back. «Fehlt» = what the remaining boards need and the box does not hold.
  Model (INFERRED): composition «Bestückt» (Position → Part List Item, Platinen Integer) on the assembly, «Geprüft» and
  «Fertig» as counts on the assembly.
- **Completion per board ([D-1143](../../NewConcept/90-decision-log.md)):** when a board is «fertig», one dialog books at the
  logistics module (a) the consumption of its parts (transaction «Verbrauch», reference = order, D-1088) and (b) the receipt of the
  finished board as **its own material** (reference = revision, INFERRED). The receipt lands in **quality inspection**
  (Zustand «in Prüfung», not available, not sellable). Inspection passed → released to regular stock or sale; failed → repair
  (INFERRED: rework is a new order position, not a new order).
- Status «fertig» on the order closes it once every board is completed or cancelled.
- Every step undoable, dialogs, shared table template (project rules).

## 3 · Migration

- *Logistik › Fertigung › Projekt-Gebinde* ([D-1139](../../NewConcept/90-decision-log.md), stock 0.29.0) moves into the module:
  `Core/ProjectBill.php`, `StockService::projectBoxes()/projectBill()`, `StockPage::projectBoxList()`, the «production» area of
  `LogisticsShell::navigation()`. The stock keeps `presentIn()` as read interface.
- No data migration: there are no manufacturing records yet.

## 4 · Open

1. ~~s7vrm: is the coil a board of its own or a part?~~ A part on every board, his word 2026-11-04: *«alles bestückt bis auf die Spule … eventuell müsste man die durchmessen»* — every board «bestückt», the coil an open position, the note on the board.
2. Menu: own top-level entry «Fertigung» or a tab under Logistik?
3. Is the inspection step mandatory for every board, or chosen per order?
4. ~~Repository~~ created by the owner 2026-11-04; built: 0.0.1 (orders, status per board, open positions, note; no bookings yet).
