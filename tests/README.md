# Tests — two runs, and that is the point

**The whole reason there are two** is [D-169](../docs/NewConcept/90-decision-log.md) and
[D-170](../docs/NewConcept/90-decision-log.md): WordPress sits **around** the core, not
underneath it. A rule that is only true because WordPress happens to behave a certain way is a
rule the core cannot be trusted with.

| Run | What it covers | Needs |
|---|---|---|
| **`core`** — `tests/Core/` | the domain: nodes, paths, versions, parking | nothing. No WordPress, no database |
| **boundary** — `scripts/dev/package*-check.php` | tables, foreign keys, edges, attributes, `$wpdb`, migrations | a running WordPress and its database |

```bash
php vendor/phpunit/phpunit/phpunit
```

```bash
php scripts/dev/package1-check.php /path/to/wordpress
php scripts/dev/package2-check.php /path/to/wordpress
php scripts/dev/package3-check.php /path/to/wordpress
php scripts/dev/package4-check.php /path/to/wordpress
php scripts/dev/package5-check.php /path/to/wordpress
php scripts/dev/package6-check.php /path/to/wordpress
```

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
