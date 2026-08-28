# Tests — two runs, and that is the point

**The whole reason there are two** is [D-169](../docs/NewConcept/90-decision-log.md) and
[D-170](../docs/NewConcept/90-decision-log.md): WordPress sits **around** the core, not
underneath it. A rule that is only true because WordPress happens to behave a certain way is a
rule the core cannot be trusted with.

| Run | What it covers | Needs |
|---|---|---|
| **`core`** — `tests/Core/` | the domain: nodes, paths, versions, parking | nothing. No WordPress, no database |
| **boundary** — `scripts/dev/*-check.php` | tables, foreign keys, edges, attributes, `$wpdb`, migrations, and both admin screens | a running WordPress and its database |
| **references** — `scripts/dev/references-check.php` | that every `D-` and `OQ-` the repository cites actually exists | nothing |

```bash
php vendor/phpunit/phpunit/phpunit
```

```bash
for c in scripts/dev/*-check.php; do php "$c" || echo "FAILED: $c"; done
```

⚠️ **The boundary run is a glob and no longer a hand-written list**, because the hand-written
one went stale: `package7`, `unitvalue`, `settings-screen` and `references` all existed while this
file still named seven scripts. *A list of checks that omits checks is worse than no list — it reads
as the whole net.*

| Check | What it guards |
|---|---|
| `package1`…`package7` | one per package of [97 Implementation plan](../docs/NewConcept/97-implementation-plan.md) |
| `scaffold` | the seeded data types and framework nodes |
| `unitvalue` | a value with a prefix and a unit — `2.7 kΩ` stored and read back ([D-394](../docs/NewConcept/90-decision-log.md)) |
| `settings-screen` | the installation screen, and that its two sizes reach the stylesheet ([D-397](../docs/NewConcept/90-decision-log.md)) |
| `preview` | the preview, and that `hide` and `read_only` actually **do** something ([D-160](../docs/NewConcept/90-decision-log.md), [D-399](../docs/NewConcept/90-decision-log.md)) |
| `path` | `settings.path` — the address, and that a path never falls back to the empty one ([D-413](../docs/NewConcept/90-decision-log.md)) |
| `labels-page-save` | that the texts travel with the page save — the fields name the page's form, an unchanged one writes nothing, an emptied one loses its row ([D-384](../docs/NewConcept/90-decision-log.md), [D-392](../docs/NewConcept/90-decision-log.md)) |
| `journal-address` | that a journal entry carries its **address** and not only its value — key, path, type and value, readable back out of the column ([D-427](../docs/NewConcept/90-decision-log.md)) — **and that all 10918 rows already in the table still parse**, with the reader it replaced compared against on every one of the 2904 in the old order |
| `references` | that no file cites a `D-` or `OQ-` id that was never written ([`PR-3`](../CLAUDE.md)) |

⚠️ **`references-check` exists because seven decisions were cited in code and never written**, all
on 2026-08-26 — `D-392` through `D-397`, each sitting in a docblock as if it had authority. *A
dangling id is not a typo: it is a rule that nobody agreed to, quoted as though somebody had.*

⚠️ **A check reports `all green` or a count of failures and exits non-zero**, so the loop above is
the whole boundary run.

⚠️ **A WordPress call that drifts into `Taxmod\Core` fails on the first run**, immediately,
because nothing is there to answer it. That is a mechanical check on `CD-1`, not a promise.

## Fakes, not mocks

`tests/Core/Fake/` holds small real implementations — an array-backed repository, a counter, a
list of logged changes. They **do the thing**, so a test asserts an outcome rather than that a
method was called. Where a fake and the database could drift apart, the boundary run is what
catches it.

## The rule

⚠️ **Both runs are green before anything is committed**
([D-342](../docs/NewConcept/90-decision-log.md)). And **every package adds its checks to the
net** — a package whose behaviour nothing guards is a package the next one may quietly break.
