# `Taxmod\Core` — the domain

What lives here: the model itself. Nodes, relations, settings, labels — the things
[`docs/NewConcept/10-domain-core.md`](../../docs/NewConcept/10-domain-core.md) describes, and the
rules that hold them together.

## What this must not depend on

⚠️ **No WordPress.** Not a function, not a constant, not a global. `CD-1` and
[D-170](../../docs/NewConcept/90-decision-log.md): WordPress is not underneath the core but
**around** it, and every arrow points inward. What the core needs from the outside — storage, a
clock, id allocation, translation — it **declares as an interface** in `Repository/`, and the
boundary in [`src/WordPress/`](../WordPress/README.md) fulfils it.

**The test for this is mechanical:** the core's tests run **without a WordPress bootstrap**. A
WordPress call that drifts in here fails on the first run rather than years later.

## Concept

[`docs/NewConcept/10-domain-core.md`](../../docs/NewConcept/10-domain-core.md) — `locked` since
2026-08-24 ([D-338](../../docs/NewConcept/90-decision-log.md)). Start with
[the core on one page](../../docs/NewConcept/10-domain-core.md#the-core-on-one-page): fourteen
sentences that everything here follows from.
