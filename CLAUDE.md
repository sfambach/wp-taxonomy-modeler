# wp-taxonomy-modeler — rules for agents

**Read this before acting.** These rules bind every agent working in this repo — Claude Code,
Cursor, or a human.

## Where things stand (2026-08-22)

The project **restarted its concept phase**. The previous planning round is frozen under
[`docs/legacy/`](docs/legacy/README.md) and has no authority. The concept being written now
lives in [`docs/NewConcept/`](docs/NewConcept/README.md).

**Current gate: concept. No production code yet** — see `PR-2`.

---

## PR — Process rules

| | Rule |
|---|---|
| **PR-1** | **Source of truth is [`docs/NewConcept/`](docs/NewConcept/README.md).** Nothing else. `docs/legacy/` is a frozen quarry — quote it, never inherit from it. |
| **PR-1b** | **The legacy is harvested and closed.** Documentation and code were swept on 2026-08-23 and every finding is placed in [`03`](docs/NewConcept/_harvest/03-legacy-inspiration.md) and [`04`](docs/NewConcept/_harvest/04-legacy-code-inspiration.md) — decided, already covered, contradicting, a workaround, or deliberately not taken. **Look in the sheets first; the old material is evidence, not a source.** It is never extended and never used as a template. The old plugin code lives in [`legacy-code/`](legacy-code/README.md) and no longer runs; the repository root is free for the rebuild. |
| **PR-2** | **No production code until [`10-domain-core.md`](docs/NewConcept/10-domain-core.md) has status `locked`.** Spikes are allowed if the code says `THROWAWAY` at the top. |
| **PR-3** | **Nothing is decided until it is in [`90-decision-log.md`](docs/NewConcept/90-decision-log.md)** with a `D-<nnn>` id. A decision reached in chat and not written down did not happen. |
| **PR-4** | **Unclear stays unclear.** Anything undecided becomes an entry in [`91-open-questions.md`](docs/NewConcept/91-open-questions.md). Never invent an answer to fill a gap, and never pick one silently because it seemed obvious. |
| **PR-5** | **The concept is written from the statements of the owner first.** Legacy is harvested afterwards, as a cross-check, through a sheet in `docs/NewConcept/_harvest/`. |
| **PR-6** | **Documentation style** per [`98-documentation-style.md`](docs/NewConcept/98-documentation-style.md): one small mermaid diagram per *Sachverhalt*, explanation beneath, code only where detail demands it. Code blocks are labelled `CONTRACT` or `SKETCH`. |
| **PR-7** | **Report faithfully.** If something is unverified, say so. If a step was skipped, say so. Never present a plausible reconstruction as a finding. |
| **PR-8** | **Rule hygiene** applies to this file — see the last section. |

Dev environment (Laragon on Windows, SQLite on the cloud VM): [`AGENTS.md`](AGENTS.md).

---

## CD — Code standard

### CD-1 · Layering — WordPress at the edge, modern PHP in the core

Decided 2026-08-22 ([D-009](docs/NewConcept/90-decision-log.md)).

| Layer | Convention |
|---|---|
| **Boundary** — hooks, REST routes, admin screens, activation, CLI, blocks | WordPress conventions. Capabilities, nonces, `sanitize_*`, `esc_*`, text domain, `$wpdb->prefix`. |
| **Core** — domain model, repositories, renderers, validators, converters | Modern PHP 8. Namespaces, `declare(strict_types=1)`, PSR-4, typed properties and returns, constructor promotion. |

The core must not call WordPress functions. It stays testable and reasonable about without a
WordPress bootstrap. WordPress reaches *into* it, never the other way round.

### CD-2 … CD-12

| | Rule |
|---|---|
| **CD-2** | Every PHP file starts with `<?php declare(strict_types=1);` as the **first line**. No closing `?>` in pure-PHP files. |
| **CD-3** | Class loading via **Composer PSR-4**. No `require_once` for classes. |
| **CD-4** | **Type everything** that can be typed: properties, parameters, returns. `mixed` needs a reason in a comment. |
| **CD-5** | At the boundary, in this order, every time: **capability check → nonce → validate → sanitize → act → escape on output**. No exceptions, not even for admin-only screens. |
| **CD-6** | Custom tables: `$wpdb->prefix . 'taxmod_<name>'`, created via `dbDelta()` on activation, guarded by a stored **schema version** option so upgrades are deterministic. Prepared statements only — `$wpdb->prepare()` for anything with a variable in it. |
| **CD-7** | **No N+1.** No SQL inside a loop, no recursive function that queries per level. Tree traversal is solved once, in one place, and every caller uses it. |
| **CD-8** | Presentation code **returns** strings. No `echo` inside renderers, loops, shortcodes or hooks. Use `ob_start()` / `ob_get_clean()` only when a third-party API forces output. |
| **CD-9** | **Names say what the thing is.** Rename when the word lies. No abbreviations that need a lookup, and no `data` / `info` / `manager` / `helper` as a whole name. |
| **CD-10** | Errors: **exceptions inside the core**, translated to `WP_Error` at the boundary. Never a bare `false` to signal failure. Never silence an exception without handling it. |
| **CD-11** | Versioning: semantic `MAJOR.MINOR.PATCH`, starting at `0.0.1`. `MAJOR` moves **only** for an official release. Plugin header, PHP version constant, `package.json` and any `readme.txt` stable tag change **in the same commit**. |
| **CD-12** | Gutenberg blocks live in the **`taxo/`** namespace — `taxo/<slug>`, title starting `Taxo `, keyword `taxo`. ⚠️ **Inherited from the old plugin, no decision behind it** — under question, see [OQ-081](docs/NewConcept/91-open-questions.md). |

### Prohibited

- ❌ Duplicating a fact. One place owns each piece of state; everything else derives.
- ❌ Special-casing by display name, label, path, or a specific node.
- ❌ Interpolating variables into SQL.
- ❌ Presentation logic inside domain objects — no HTML, no formatting, no locale decisions.
- ❌ Committing commented-out code, or a `TODO` without an owner and a reason.

---

## AR — Architecture rules

**An architecture rule exists only with a decision id.** No `D-<nnn>` → no rule. If the
decision is superseded, the rule is deleted in the same commit. This is the rule that keeps
this file from turning back into a frozen snapshot of a model we have outgrown.

| | Rule | Decision |
|---|---|---|
| **AR-1** | **The model is stored in tables owned by this plugin**, not in WordPress posts, postmeta, terms or CPTs. Base tables: nodes, settings, labels, relations. *Storage of the content that the models describe is not decided yet* — see [OQ-015](docs/NewConcept/91-open-questions.md). | [D-007](docs/NewConcept/90-decision-log.md), [D-019](docs/NewConcept/90-decision-log.md) |
| **AR-2** | **Nothing user-visible is hard-coded.** Software strings go through the WordPress text domain; the names of user-created nodes are labels stored in the model, per locale. The two never share a mechanism. | [D-019](docs/NewConcept/90-decision-log.md), [D-020](docs/NewConcept/90-decision-log.md) |

That is the **complete** list. Everything else about the model — what a node is, whether an
attribute is an edge, whether a type is data or code, where the renderer runs — is **open**
and lives in [`91-open-questions.md`](docs/NewConcept/91-open-questions.md). Do not act as if
any of it were settled, and do not settle it in passing while doing something else.

---

## DC — Documentation in code

Enough that a reader can navigate, not so much that the code doubles in size.

| | Rule |
|---|---|
| **DC-1** | Comments explain **why**, not what. If a comment restates the code, delete one of them — usually the comment. |
| **DC-2** | Every class and interface gets a short docblock: **one sentence of purpose**, plus the concept document it implements (`@see docs/NewConcept/10-domain-core.md`). Nothing else is mandatory. |
| **DC-3** | `@param` / `@return` only where the **type declaration cannot say it** — array shapes, generics, units, ranges. Never as an echo of the signature. |
| **DC-4** | For a flow that is genuinely hard to see from one file — a registry lookup, a resolution walk, a graph traversal — put a **small mermaid diagram in the docblock**. Same style as the concept docs. |
| **DC-5** | Each top-level source folder has a `README.md`: what lives here, what it must not depend on, where its concept document is. Short. This is the documentation skeleton. |

---

## Rule hygiene

The previous rule set grew to **82 KB, most of it always-on**, and became the main reason this
project kept re-deciding the same questions. The safeguards:

1. **Architecture rules cite a decision.** No exceptions.
2. **No version numbers, function names, file paths or specific node names in rules.** Those
   belong in the code and in the decision log. A rule that names `0.0.558` is a changelog.
3. **This file stays under ~250 lines.** Adding a rule means asking what to remove.
4. **A rule that no longer changes what an agent does is deleted**, not kept for reference.
5. **Rules do not answer open questions.** If a rule and
   [`91-open-questions.md`](docs/NewConcept/91-open-questions.md) disagree, the open question
   wins and the rule is wrong.
