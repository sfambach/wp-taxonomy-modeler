# `Taxmod\WordPress\Import` — the import inbox at the boundary

What lives here: the WordPress side of the shared part of the import modules — the vault keyed with the auth salt
(D-951), the inbox table, the «Inbox» page, the «Import» configuration tab, the hourly schedule and the gateway to the
abilities (D-952, D-953). A module registers its source through the filter `taxmod_import_sources`.

A module checks `class_exists(\Taxmod\WordPress\Import\Vault::class)` and `Vault::API` before it relies on it.

Concept: [`docs/pakete/import/konzept.md`](../../../docs/pakete/import/konzept.md).
