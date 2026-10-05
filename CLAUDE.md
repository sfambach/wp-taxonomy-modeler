# wp-taxonomy-modeler — rules for agents

**Read this before acting.** These rules bind every agent working in this repo — Claude Code,
Cursor, or a human.

## Where things stand (2026-09-01)

**The concept in `docs/NewConcept/` is outdated and is being replaced** ([D-568](docs/NewConcept/90-decision-log.md),
the owner: «das alte Konzept ist veraltet, wir brauchen ein neues und das muss in Zukunft definiert
werden»). It is **harvested material now** — quotable as evidence, never as a source — exactly as
`docs/legacy/` has been since 2026-08-23. **It is not extended any more, not even by open questions.**
Until the new concept has a shape, new input goes to [`docs/neues-konzept-eingang.md`](docs/neues-konzept-eingang.md).

The working model is [`docs/arbeitsmodell.md`](docs/arbeitsmodell.md), written by the owner. It
governs how things are decided, changed and documented from here on.

**Current state: building, and the table concept is under review** — see
[`docs/review-tabellen.md`](docs/review-tabellen.md). Packages 1–7 are built and guarded; work runs
down [the working list](docs/NewConcept/97-implementation-plan.md#the-working-list), where the
owner's posted changes are appended in the order he asks for them.

⚠️ **No counts in this file.** *Two stood here and both were wrong — «25 open questions» when 67 of
them said «Closed» in their own text, and «459 decisions» when there were 548. **A count in a rules
file is a changelog.** What matters is guarded instead: `references-check` fails on a `D-`/`OQ-` id
that was never written, `superseded-check` on a live claim resting on a withdrawn one,
`rules-index-check` on a rule missing from the index, `concept-drift-check` on a model document
changing without a reason, and `confirmed-quote-check` on a decision called confirmed that carries
no sentence of his.*

---

## PR — Process rules

| | Rule |
|---|---|
| **PR-1** | **Source of truth is the current package documentation and the active decisions** ([`docs/arbeitsmodell.md`](docs/arbeitsmodell.md) §16). **`docs/NewConcept/` and `docs/legacy/` are both frozen quarries — quote them, never inherit from them, and never use either as a template** ([D-568](docs/NewConcept/90-decision-log.md)). *Warum, und was es gekostet hat: [D-570](docs/NewConcept/90-decision-log.md).* |
| **PR-2** | **There is no lock. A concept is either finished — then it is built — or outdated — then it is replaced, with a reason** ([D-565](docs/NewConcept/90-decision-log.md)). There is no third state and **there is no quiet change**: a gap found while building becomes a decision ([D-222](docs/NewConcept/90-decision-log.md)), never an edit to the concept. **A decided model is not softened without a reason; replacing it with one is wanted.** Guarded by `scripts/dev/concept-drift-check.php`. Work proceeds in the packages of [`97-implementation-plan.md`](docs/NewConcept/97-implementation-plan.md), and **every package ends with something the owner can operate** and a list of what was assumed that the concept did not say. *Warum, und was es gekostet hat: [D-565](docs/NewConcept/90-decision-log.md).* |
| **PR-3** | **Nothing is decided until it is in [`90-decision-log.md`](docs/NewConcept/90-decision-log.md)** with a `D-<nnn>` id. A decision reached in chat and not written down did not happen. |
| **PR-4** | **Unclear stays unclear.** Anything undecided becomes an entry in [`docs/neues-konzept-eingang.md`](docs/neues-konzept-eingang.md) — the old question sheet is closed with the concept it belonged to. Never invent an answer to fill a gap, and never pick one silently because it seemed obvious. |
| **PR-6** | **Documentation style** per [`98-documentation-style.md`](docs/NewConcept/98-documentation-style.md): one small mermaid diagram per *Sachverhalt*, explanation beneath, code only where detail demands it. Code blocks are labelled `CONTRACT` or `SKETCH`. |
| **PR-7** | **Report faithfully.** If something is unverified, say so. If a step was skipped, say so. Never present a plausible reconstruction as a finding. |
| **PR-8** | **Rule hygiene** applies to this file — see the last section. |
| **PR-9** | **What works keeps working: both test runs are green before anything is committed** ([D-564](docs/NewConcept/90-decision-log.md)) — the **core** run under PHPUnit, which loads no WordPress, and the **boundary** run against a real database. **Every package adds its checks to the net**; a package nothing guards is one the next may quietly break. **A check guards the *current* target state, never a past one** — when the concept changes the check changes with it, **but changing or deleting a check is a visible part of that concept change** and never happens in passing. See [`tests/README.md`](tests/README.md). *Warum, und was es gekostet hat: [D-564](docs/NewConcept/90-decision-log.md).* |
| **PR-13** | **The yardstick for a specification is whether a person can check it, not whether the AI can build from it** ([D-566](docs/NewConcept/90-decision-log.md)). **If the only reader who can hold the model is the thing that also writes the code, nobody can check the code.** *Warum, und was es gekostet hat: [D-566](docs/NewConcept/90-decision-log.md).* |
| **PR-10** | **Look it up before you say it. No claim about the concept without a quotation from it.** Whenever an answer turns on *what was decided* — a mechanism, a rule, where something belongs — read the source and **quote the sentence**, with its `D-<nnn>`. Recalling it is not reading it. ⚠️ **Und das Gegenstück, teurer erkauft: eine Messung am Code ist keine Antwort auf eine Konzeptfrage.** *Der Code ist der **Ist**-Stand und hinkt jeder Entscheidung hinterher. Wer «so ist es gebaut» sagt, wo «so haben wir es entschieden» gefragt war, gibt eine **falsche** Antwort, keine vorläufige. Also: erst der Beschluss mit seiner `D-<nnn>`, und der Ist-Stand nur als **eigene, benannte Zeile** («gebaut: nein»). Am 2026-09-05 an einem Abend dreimal verkehrt herum beantwortet — Renderer-Wähler, sprachneutrale Zeile, Installationsbildschirm —, jedes Mal hat der Eigentümer es gemerkt und nicht ich.* *Warum, und was es gekostet hat: [D-572](docs/NewConcept/90-decision-log.md), [D-645](docs/NewConcept/90-decision-log.md).* |

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
| **CD-12** | Gutenberg blocks live in the **`taxmod/`** namespace — `taxmod/<slug>`, keyword `taxmod` ([D-337](docs/NewConcept/90-decision-log.md)). The human-readable block **title** is not a token: it is a translatable string like any other user-visible text (`AR-2`). |

### Prohibited

- ❌ Duplicating a fact. One place owns each piece of state; everything else derives.
- ❌ Special-casing by display name, label, path, or a specific node.
- ❌ Interpolating variables into SQL.
- ❌ Presentation logic inside domain objects — no HTML, no formatting, no locale decisions.
- ❌ Committing commented-out code, or a `TODO` without an owner and a reason.

---

## AR — Architecture rules

**An architecture rule needs a basis that can be checked: a decision id, or a measurement plus
the owner's confirmation** ([D-569](docs/NewConcept/90-decision-log.md)). If the basis falls, the
rule is deleted in the same commit. ⚠️ *The second form was added on 2026-09-01 because both rules
below had **true content and dead citations** — `AR-2` rested on a withdrawn decision. A measurement
survives its own justification: «not one occurrence» can be re-run, a decision from ten days ago
cannot.* This is the rule that keeps
this file from turning back into a frozen snapshot of a model we have outgrown.

| | Rule | Basis |
|---|---|---|
| **AR-1** | **The model is stored in tables owned by this plugin**, not in WordPress posts, postmeta, terms or CPTs. ⚠️ **Measured 2026-09-01: `wp_insert_post`, `get_post_meta`, `wp_insert_term`, `register_post_type` and six more — *not one occurrence* in `src/`, against 13 own tables.** *The table list that stood here is gone: it named `settings`, which is dying ([D-529](docs/NewConcept/90-decision-log.md)), and omitted `records` and `record_values`. Naming the tables is deliberately **not** part of this rule any more — the table concept is under review ([D-568](docs/NewConcept/90-decision-log.md), [`docs/review-tabellen.md`](docs/review-tabellen.md)), and a rule that names them would freeze the errors it inherited.* | **measurement + owner's confirmation** ([D-569](docs/NewConcept/90-decision-log.md)) |
| **AR-2** | **Nothing user-visible is hard-coded.** Software strings go through the WordPress text domain; the names of user-created nodes are labels stored in the model, per locale. The two never share a mechanism. ⚠️ **Measured 2026-09-01: 195 text-domain calls at the boundary; in the whole core exactly one display-shaped string, and it is the *initial name* of a shipped node, which the owner may rename.** *The open edge is a different one and belongs to the new concept: shipped names arrive in one language, and `labels.locale` is filled in 4 of 47 rows.* | **measurement + owner's confirmation** ([D-569](docs/NewConcept/90-decision-log.md)) |

That is the **complete** list. Everything else about the model — what a node is, whether an
attribute is an edge, whether a type is data or code, where the renderer runs — is **open**
and lives in the entry sheet [`docs/neues-konzept-eingang.md`](docs/neues-konzept-eingang.md),
since [D-568](docs/NewConcept/90-decision-log.md) closed the old concept. Do not act as if
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
