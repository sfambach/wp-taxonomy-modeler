# `Taxmod\Core\Import` — the shared part of the import modules

What lives here: what every import module (tax_mail, tax_api_ebay, tax_api_jlcpcb …) shares — the vault that seals
their secrets (D-951), the proposal format, the contract of a source (`ImportSource` and its roles), the duty table,
the intake decision and the change list for `taxmod/write-data` (D-952, D-953). A module brings only its source: connection, fetch,
mapping onto `OrderProposal`.

Must not depend on WordPress (`CD-1`); the key material comes in from [`src/WordPress/Import/`](../../WordPress/Import/).

Concept: [`docs/pakete/import/konzept.md`](../../../docs/pakete/import/konzept.md).
