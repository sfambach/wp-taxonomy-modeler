---
title: Implementation plan — packages, not sprints
status: draft
round: R1
last_updated: 2026-08-24
---

# Implementation plan

*Agreed with the owner on 2026-08-23, out of a fear he named plainly: the previous round with
another assistant **started just as well** and then cost him hours explaining why a confident
conclusion was nonetheless wrong.*

**The concept is not changed by this document.** It stays as it is; this only lays a grid over it
and starts building piece by piece. The interfaces are already given by the concept
([D-313](90-decision-log.md)).

---

## What actually changed since the last round

Not that the assistant reasons better. ⚠️ **Gaps get filled convincingly whether or not the filling
is right** — twice on 2026-08-23 alone, and both times the **owner** caught it.

What changed is that **there is something to point at.** When he says *that is wrong*, we look up
which decision says otherwise and it takes minutes. Last time there was no such paper, so every
contradiction became an argument.

---

## Three rules for every package

### 1 · A package ends with something the owner can operate

⚠️ **This is the actual protection.** Not *the storage layer is done* — nobody can check that, they
can only believe it. Instead: *you create a node in the tree, rename it, reload the page, and it is
still there.*

> **A package with no visible outcome is cut wrong.**

### 2 · Thin vertical slices, not horizontal layers

Depth or breadth was the owner's question; the answer is a **thin vertical slice**: from the table
to the screen, for **one small capability**.

A horizontal layer — *all the repositories* — cannot be checked by a person, and that is exactly
where the hours of explaining come from.

### 3 · After every package: what I assumed that was not in the concept

⚠️ **The cheapest insurance against the previous round.** Gaps **will** be found and filled; that
cannot be avoided. What can be avoided is filling them **silently**.

Every assumption becomes one line. The owner reads ten lines instead of a thousand, and whatever he
does not sign off becomes a decision or is taken out again.

**And if implementation shows something is missing, it becomes a decision in the log**
([D-222](90-decision-log.md)) — never a quiet change to the concept.

---

## The first cut

Six packages to the point where importing his real data first makes sense.

| | Package | What the owner checks |
|---|---|---|
| **1** | Tables, and a node exists | create, rename, send to trash — survives a restart · **done 2026-08-24** |
| **2** | The tree | parent and child, move, expand and collapse |
| **3** | Attributes as relations, the three branches | give a node an attribute; the relation kind appears by itself |
| **4** | Settings and the chain | a default at the type, an override at the attribute, reset to inherited |
| **5** | Labels, roles, locales | the same thing is called something else in English |
| **6** | Records | enter something against a model and find it again |

**After six, his first TablePress import has something to import into** — 23 tables, some 600
records, in three shapes ([96 Scenario check](96-scenario-check.md)).

⚠️ **Package 1 is not *the database layer*.** It is the smallest slice in which a node can be made,
seen, changed and destroyed. Everything else about it comes later.

---

## What this plan deliberately does not do

| Not this | Because |
|---|---|
| sprints with dates | the owner asked for **self-contained packages**, and a date is not a boundary |
| a full backlog up front | the first cut is six packages; the seventh is decided when the sixth is done |
| changes to the concept | it stays as it is; findings become decisions ([D-222](90-decision-log.md)) |
| a package without a visible outcome | it could not be checked, which is the whole point |

---

## Package 1 — done 2026-08-24

**What it delivers:** the seven tables, a root and a trash, and a screen on which a node can be
made, renamed and thrown away. 25 checks pass, including the owner's own: *create, rename, send
to trash — and it is still there after a reload.*

### What was assumed that the concept did not say

⚠️ **Rule 3 of this plan.** Gaps get filled while building; what can be avoided is filling them
**silently**. Ten lines, and whatever is not signed off becomes a decision or is taken out again.

| # | Assumption | Why it was needed | How wrong it can be |
|---|---|---|---|
| **1** | **Model ids come from a counter in `wp_options`**, incremented lock-free with `LAST_INSERT_ID(expr)`. | ⚠️ **The real gap.** Nodes and relations share **one** identity space (C11) so `owner_id` can be a single column ([D-090](90-decision-log.md)) — but two tables cannot share an `AUTO_INCREMENT`, MySQL has no sequences, and the seven tables of [D-083](90-decision-log.md) contain **no identity table**. | **Low.** It sits behind `IdentityAllocator` in the core, so a different mechanism — an eighth table, a different counter — is one class. ⚠️ But if the answer *is* an eighth table, then "seven tables" needs restating. |
| **2** | **`path` is ids joined by `.`, own id last**, `varchar(255)`. | [D-014](90-decision-log.md) says *materialised ancestor path* and nothing about its shape. | Low for the format. ⚠️ **The 255 is a real ceiling** — roughly 25 levels deep. |
| **3** | **Parking is a move, not a flag.** A parked node simply sits under the trash; there is no `deleted` column, and **the path it came from lives only in the changelog**, which is what a restore will read. | [C101](10-domain-core.md) says *marked deleted and parked*, which reads like two things. One place owns each fact, so the position **is** the mark. | **Medium.** If a *mark* is genuinely wanted separately from the position, it becomes an engine-owned setting ([D-084](90-decision-log.md)) and nothing built here has to move. |
| **4** | **The changelog columns are `before_state` / `after_state`.** | `BEFORE` and `AFTER` are reserved words in MySQL 8; the CONTRACT names them `before` / `after`. | None in substance — a name only. |
| **5** | **`labels` has no `translatable` column.** | ⚠️ **Two CONTRACTs disagree.** [10 Domain core](10-domain-core.md) lists it; the detailed one in [40 I18n](40-i18n.md) does not, and [D-317](90-decision-log.md) puts *translatable* on the **attribute**, not on the label. Followed the detailed one. | **Medium — and it wants a look.** One of the two contracts is wrong. |
| **6** | **The capability is `manage_options`.** | Nothing decided one. | Low, and easy to change — it is one constant. |
| **7** | **Column sizes:** names `varchar(191)`, decimals `decimal(30,10)`, locale and kind `varchar(20)`. | Not specified. 191 is the WordPress index limit under `utf8mb4`. | Low. `decimal(30,10)` wants a second look the day money and tolerances arrive. |
| **8** | **Only root and trash are seeded.** `Model`, `Compositions`, `Primitives` are **not** created yet. | They are the tree, and the tree is Package 2. | None — deliberate scope. |
| **9** | **Children sort by name.** | Order belongs to the **edge** (`position`), and there are no edges yet. | None — it is a placeholder that disappears in Package 2. |
| **10** | **Root and trash carry a plain `name`, not labels.** | Labels are per role and per locale and arrive in Package 5. | Low. ⚠️ Their **displayed** names will have to become labels, or `AR-2` is broken for exactly two nodes. |

### What was found in the concept while building

- ⚠️ **[10 Domain core](10-domain-core.md) still carried the superseded override rule.** *An
  override may narrow and widen* ([D-088](90-decision-log.md), [D-310](90-decision-log.md)) —
  [D-312](90-decision-log.md) had replaced both the same evening, and the passage contradicted
  sentence 10 of [the core on one page](10-domain-core.md#the-core-on-one-page). **Corrected.**
- The `labels` contract disagreement above (assumption 5).
- **[D-083](90-decision-log.md)'s "seven tables" and the shared identity space are in tension**
  (assumption 1).
