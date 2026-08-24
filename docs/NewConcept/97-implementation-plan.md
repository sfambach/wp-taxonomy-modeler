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
| **2** | The tree | parent and child, move, expand and collapse · **done 2026-08-24** |
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
| **1** | ~~Model ids come from a counter in `wp_options`~~ | ⚠️ **Withdrawn the same day → [D-339](90-decision-log.md).** The owner asked what the safest form would be, and the counter was not it: it lived in a different table from the ids it guarded, so a partial restore could put it behind the data and make it reissue numbers — silently, because `owner_id` is one column over nodes and edges. **Replaced by an `identities` table with its own `AUTO_INCREMENT`**, never deleted from, with `owner_id` as a real foreign key. [D-340](90-decision-log.md) generalises the migration half of it. | resolved |
| **3** | **Parking is a move, not a flag.** A parked node simply sits under the trash; there is no `deleted` column, and **the path it came from lives only in the changelog**, which is what a restore will read. | [C101](10-domain-core.md) says *marked deleted and parked*, which reads like two things. One place owns each fact, so the position **is** the mark. | **Medium.** If a *mark* is genuinely wanted separately from the position, it becomes an engine-owned setting ([D-084](90-decision-log.md)) and nothing built here has to move. |
| **4** | **Reserved words get a suffix: `before_state` / `after_state`, and `setting_key`.** | `BEFORE`, `AFTER` and `KEY` are reserved in MySQL; the CONTRACTs name them `before`, `after`, `key`. ⚠️ **`key` was worse than a naming nuisance** — backticked, it broke `dbDelta`s index parser silently, so that index would not have been maintained on later upgrades. | None in substance — names only. |
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

---

## Package 2 — done 2026-08-24

**What it delivers:** the inheritance edges as real rows, `nodes.path` derived from them, moving,
reordering, and a screen that draws the tree as a tree. **94 checks pass** — 47 in the core run
(no WordPress), 28 in Package 1's boundary run, 19 in Package 2's.

### ⚠️ What building it found in Package 1

**The truth and the derived value were the wrong way round.** Package 1 stored the tree *only* as
`nodes.path` — but [D-014](90-decision-log.md) calls the path *derived, rebuildable, never a
second truth*, and the thing it is meant to derive **from** is the inheritance edge, which did not
exist. It worked, and it was inverted.

Package 2 turns it back: the edge is written first, the path is rewritten from it, and a schema
migration gave every existing node the edge it should always have had. **Both boundary runs now
assert that every stored path can be rebuilt from the edges alone** — if the two ever drift, that
check fails rather than every descendant query going quietly wrong.

### What was assumed that the concept did not say

| # | Assumption | Why | How wrong it can be |
|---|---|---|---|
| **1** | **Reordering is a swap with the neighbour**, not a renumbering of all siblings. | A renumbering is one write per row, which is the loop `CD-7` forbids. A swap is always exactly two. | Low. It also matches the only control there is — up and down. |
| **2** | **Positions need not be unique**; ties fall back to id order. | Nothing decided it, and forcing uniqueness would mean rewriting siblings on every insert. | Low. The swap forces equal positions apart when it meets them. |
| **3** | **`allInheritanceEdges()` reads every edge at once** to draw the tree. | Two queries for a tree of any depth. Asking per parent would be one query per level. ⚠️ Rests on [D-308](90-decision-log.md): this is a modeller, so the **model** stays in the hundreds even when records run to thousands. | **Medium.** If a model ever reaches tens of thousands of nodes, the screen must page instead. |
| **4** | **The move chooser leaves out impossible targets** — the node itself and its own subtree. | Offering a choice that always fails is a trap laid for the person using it. | None. The core still refuses them; the screen is convenience, not the guarantee. |
| **5** | **The migration reads each parent out of the path.** | ⚠️ The one moment the derived value *is* the source — unavoidable, because in versions 1 and 2 nothing else recorded it. | None, and it cannot recur: from version 3 the edge is written first. |
| **6** | **The trash gets an ordinary inheritance edge.** | It is a child of the root like any other; a framework node without an edge would be a node the tree cannot see. | None. |
| **7** | **Reordering is up and down only.** Drag and drop is not built. | The owner asked for it ([20 Interaction](20-interaction.md)); it is a screen concern, and the core operation it needs already exists. | None — deliberate scope. |

### Where it can be seen

**Taxonomy Modeller** in the admin menu. Add a node, add a child with **+**, rename it, move it
with the chooser, order it with **↑ ↓**, throw it away. Reload — it is the way it was left.

```bash
php vendor/phpunit/phpunit/phpunit
```

```bash
php scripts/dev/package2-check.php C:/Devel/Wordpress
```

### What Package 2 delivers, and what it deliberately leaves

✅ **Closed the same day it was reported.** The package was first announced without
**expand and collapse**, which its own line requires; that was reported rather than left out, and
then built — together with **[U4](20-interaction.md)**, *delete the branch or only this node*.

⚠️ **Both were built as core behaviour, not as screen tricks**, which is what lets them survive
the scaffolding ([D-344](90-decision-log.md)): collapsing is a question about the tree, answered
in `Tree` and asked the same way by every surface; and *only this node* promotes the children to
their grandparent in **one statement**, because a write per child is the loop `CD-7` forbids.

**What is not built, and belongs to the real surface** — none of it was claimed:

| | What the concept asks | Where |
|---|---|---|
| **U1** | the row shows the frequent, a `⋯` menu holds everything — ⚠️ **touch has no right-click** | [U1](20-interaction.md) |
| **U5** | dragging moves whole branches, and several at once | [U5](20-interaction.md) |
| **U6** | duplicating puts the copy **directly beneath**, with an indexed name | [U6](20-interaction.md) |
| **U21** | the tree row draws the node's icon | [U21](20-interaction.md) |

⚠️ **U4's second half is not as harmless as its button** ([D-041](90-decision-log.md)): the tree
*is* inheritance, so children promoted to the grandparent **lose whatever they inherited from the
node being removed**. That is [D-155](90-decision-log.md)'s move reached through a different
button, which is exactly why deleting asks instead of guessing.

### ⚠️ A line the provisional screen must not cross

[R20a](30-renderer.md#r20a--the-detail-view-is-not-a-special-screen) and
[D-190](90-decision-log.md) settle that the detail view is **a frame holding attributes rendered
under the `edit` purpose** — not a hand-built screen. The stated reason is exactly the risk this
plan's temporary surface carries:

> otherwise there are two ways to draw a field — the official one, and the one the admin screen
> was built with — and they drift.

**Package 2's screen is that second way.** It is defensible only because it draws **no fields at
all**: names, buttons and a select, nothing that renders a value.

> **The rule for every package after this one: the moment an attribute value has to appear on
> screen, it goes through a renderer.** Not a quick `<input>` in the admin template that gets
> tidied up later — that is how the two ways start, and the legacy is the evidence.

---

## The order the surfaces impose — corrected 2026-08-24

⚠️ **A tree row is a rendered node** ([R18](30-renderer.md#r18r20--the-surfaces-are-renderers-all-the-way-up)),
the settings side is a rendered page ([R20](30-renderer.md)), and *nothing is drawn by hand
anywhere*. That was an owner statement from the first week and it changes the running order.

**The real surface cannot come next.** A node renderer needs settings, labels and attributes to
render; until those exist there is nothing for it to draw.

| | | |
|---|---|---|
| **3** | Attributes as relations, the three branches | the model starts to be able to say something |
| **4** | Settings and the chain | a renderer has something to resolve |
| **5** | Labels, roles, locales | a row has something to write |
| **then** | **the tree renderer and the split screen** | ⚠️ and the scaffolding below is **deleted**, not refactored ([D-344](90-decision-log.md)) |

**What the scaffolding is for, and its two limits:** it exists so behaviour is checkable before
renderers exist. It draws **no value** and must never begin to. Everything asserted against it
tests the **core** — which the real surface uses unchanged — so losing it costs nothing.

**Finishing Package 2** — expand/collapse and [U4](20-interaction.md)'s *branch or only this node*
— is still worth doing, because both are **behaviour**: collapsing is a selection question and
U4 needs children promoted to the grandparent, which nothing in the core does yet.

### ⚠️ Not built: the deletion event ([D-127](90-decision-log.md))

**Parking writes one changelog line per node.** Nothing records that several things fell in **one**
act, which is what [D-127](90-decision-log.md) calls a trash entry — *one deletion, with everything
that fell with it, and restore puts back the whole event.*

Today it does not show, because parking a branch moves the subtree and restoring moves it back:
the paths carry the grouping by accident. **It shows the moment something falls that is not a
descendant** — a promoted child ([OQ-083](91-open-questions.md)), and later an edge pointing at a
deleted node, which [D-127](90-decision-log.md) names explicitly.

*Reported rather than left out. It is not needed to finish Package 2 and it is needed before
deletion can be trusted with attributes.*

### The bracket and the attributes — noted for Package 3

The owner, while the change group was being built: *later, something will probably have to happen
with the attributes in the same change group.*

⚠️ **He is right, and [D-127](90-decision-log.md) says it in as many words** — *the node **and
every edge that pointed at it***. An attribute **is** an edge ([D-031](90-decision-log.md)), so
deleting a node parks every attribute that used it, and those rows belong under the same bracket
as the deletion that caused them. Without it, [D-347](90-decision-log.md)'s restore brings back a
node whose attributes stayed in the trash — the exact failure [D-127](90-decision-log.md) was
written to prevent.

**Nothing is missing in the concept; it is a piece of work.** It cannot be built before Package 3,
because there are no attributes yet. Recorded here so that it is built **with** them rather than
noticed afterwards:

| When attributes exist | What must fall under the deleting act's bracket |
|---|---|
| a node is parked | every edge pointing **at** it, parked with it ([D-127](90-decision-log.md)) |
| an attribute is removed at one use site | the edge, and the orphaned overrides promoted per [D-156](90-decision-log.md) |
| a node moves between branches | the reparenting and every edge whose **kind** it rewrote ([D-162](90-decision-log.md)) |
