# `Taxmod\WordPress` — the boundary

What lives here: everything that knows WordPress exists. Hooks, admin screens, activation, the
`$wpdb` repositories, REST routes and blocks when they arrive.

## What this does

**It fulfils the interfaces the core declares** — `NodeRepository`, `IdentityAllocator`, `Clock`,
`Changelog`, `FrameworkNodes` — and it **translates rather than decides**
([D-170](../../docs/NewConcept/90-decision-log.md)). A rule that lives here instead of in the
core is a rule a second boundary would have to reinvent.

⚠️ **`CD-5` is the order and it never varies:** capability check → nonce → validate → sanitize →
act → escape on output. Not even on a screen only an administrator can reach.

Other standing rules that bite here: prepared statements for anything with a variable in it
(`CD-6`), no SQL inside a loop (`CD-7`), and presentation code **returns** strings rather than
echoing (`CD-8`).

## What this must not do

- **Decide anything about the model.** If a rule is about what a node *is*, it belongs in
  [`src/Core/`](../Core/README.md).
- **Leak WordPress inward.** The core takes interfaces, never a `$wpdb` or a `WP_Post`.

## Concept

[`docs/NewConcept/50-wordpress-persistence.md`](../../docs/NewConcept/50-wordpress-persistence.md)
for the tables, [`20-interaction.md`](../../docs/NewConcept/20-interaction.md) for the screens.
