# `Taxmod\Core\Event` — addresses and events

What lives here: the pieces of the renderer-and-events plan ([`docs/pakete/renderer-ereignisse/plan.md`](../../../docs/pakete/renderer-ereignisse/plan.md), D-628, D-1424). Step 1 brought the `Address`, step 2 the PSR-14 `Dispatcher` and `ListenerProvider` (subscription by class and, for a `Named` event, by name); the events follow in steps 3–5.

Must not depend on: WordPress, or anything outside `Taxmod\Core` and `psr/event-dispatcher` (three interfaces, no code).
