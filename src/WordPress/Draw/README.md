# `Taxmod\WordPress\Draw` — the edge drawer

What lives here: the drawer that turns the core's descriptions (`Taxmod\Core\Description`) into the admin's HTML, one way of drawing per kind (D-1424, D-1429 A14). Escaping happens here and only here.

Must not depend on: anything the core does not hand it — a drawer reads the description and nothing else (D-636).

`register.php` hands the drawer to the core through the autoloader; it is scaffolding of step 6c and goes when the page is drawn once at the edge (A15). Concept: [`umsetzung-2026-11.md`](../../../docs/pakete/renderer-ereignisse/umsetzung-2026-11.md).
