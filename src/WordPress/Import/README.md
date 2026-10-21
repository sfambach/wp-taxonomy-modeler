# `Taxmod\WordPress\Import` — the import inbox at the boundary

What lives here: the WordPress side of the shared part of the import modules — today the vault keyed with the auth
salt (D-951). Proposal table, review page, duty table and fetch schedule follow in the next steps (D-953).

A module checks `class_exists(\Taxmod\WordPress\Import\Vault::class)` and `Vault::API` before it relies on it.

Concept: [`docs/pakete/import/konzept.md`](../../../docs/pakete/import/konzept.md).
