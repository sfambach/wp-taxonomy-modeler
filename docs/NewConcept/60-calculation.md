---
title: Calculation
status: draft
round: R1 (in progress)
last_updated: 2026-08-23
---

# Calculation

> **Status: `draft`.** Owner statements of 2026-08-22 plus a design answering the question the
> owner put: *is a calculation a relation, or an entirely different concept?*
>
> **Caught up on 2026-08-23.** The recommendations below were taken as decisions on the day they
> were written — [D-043](90-decision-log.md), [D-045](90-decision-log.md),
> [D-130](90-decision-log.md) — and the sections marked with an id carry the decision that settled
> them. Where the text and a decision disagree, nothing was resolved here; the row went to
> [`_harvest/contradictions.md`](_harvest/contradictions.md), per `PR-4`.

## Purpose

Define how a value that is **computed** rather than entered is modelled — and where the boundary
runs against the converter, which already exists.

## Owner statement — 2026-08-22

| # | Statement |
|---|---|
| **K1** | Switching a **prefix** — gram to kilogram — has to change the value with it. An internal conversion, handled differently again in the interface. |
| **K2** | There are **computed values assembled from other fields**. Hidden fields may be added up and the result shown in another field. |
| **K3** | A parts list has a **total price**, which comes from the prices of its positions, each of which comes from **quantity × unit price**. |
| **K4** | Elsewhere, **averages** or **sums** are wanted. |
| **K5** | ⚠️ In the old concept a calculation could also be a **transformation** — a text transformation, say. **The owner asks for this to be questioned.** |
| **K6** | There is a difference between calculations **in the model** and calculations **for display**. A parts list may get a frontend footer that sums quantity and price — regardless of whether a total already exists in the model. |

## K1 and K5 — the first cut: this is the converter, not a calculation

```mermaid
---
config:
  theme: dark
  themeVariables:
    mainBkg: "#1e1e1e"
    background: "#1e1e1e"
    primaryColor: "#1e1e1e"
    classText: "#ffffff"
    textColor: "#ffffff"
    lineColor: "#ffffff"
---
flowchart LR
    Q{does removing it lose information} -->|yes| C[calculation]
    Q -->|no, only appearance changes| K[converter]
```

**K1 is a converter, and K5 is refused** — taken as written, [D-043](90-decision-log.md): *unit
conversion and text transformation are converters, not calculations.*

- A **calculation** produces a value that **did not exist** — a sum, an average, a product.
- A **converter** changes the **form** of a value that already exists — two decimal places,
  uppercase, gram to kilogram.

`1000 g` and `1 kg` are the same quantity. Nothing is gained or lost, only rewritten — so unit
conversion is a converter, and [V8](00-vision-and-scope.md) already puts one on every node. It
needs no new concept, and it also explains K1's aside that this is *handled differently in the
interface*: the converter runs on the way out and, for a unit switch, also on the way in.

By the same test **a text transformation is a converter too**, which is why K5 should not be
folded into calculation. Merging them would put two unrelated jobs under one word — the failure
the glossary exists to prevent.

## K6 — the second cut: model or display

```mermaid
---
config:
  theme: dark
  themeVariables:
    mainBkg: "#1e1e1e"
    background: "#1e1e1e"
    primaryColor: "#1e1e1e"
    classText: "#ffffff"
    textColor: "#ffffff"
    lineColor: "#ffffff"
---
flowchart TD
    E[one expression language] --> M[model calculation]
    E --> D[display calculation]
    M -.- MN["belongs to an attribute · yields a value · read-only for the user"]
    D -.- DN["belongs to a renderer · yields output only · nothing is stored"]
```

The owner's distinction is exact and worth keeping as a hard line:

| | **Model calculation** | **Display calculation** |
|---|---|---|
| Owned by | an **attribute** | a **renderer** |
| Produces | a value in the model | output, nothing more |
| Example | the parts list total price (K3) | the frontend footer summing a column (K6) |
| Survives a different view | yes | no |
| Configured in | the settings of the attribute | the settings of the renderer |

**The same expression language serves both.** Only the owner and the lifetime differ. A footer
sum is not a lesser calculation; it is one whose result nobody needs to keep. Taken as written,
[D-043](90-decision-log.md): *two owners, one expression language.*

## K6a — A view and a report are two things, and only one of them is deferred

```mermaid
---
config:
  theme: dark
  themeVariables:
    mainBkg: "#1e1e1e"
    background: "#1e1e1e"
    primaryColor: "#1e1e1e"
    classText: "#ffffff"
    textColor: "#ffffff"
    lineColor: "#ffffff"
---
flowchart TD
    V["view · a named calculation belonging to no node"] --> DF["deferred by decision"]
    RP["report · prepared output that leaves the building"] --> RS["renderer side · rules are stored"]
```

The owner asked for the English word **`View`**, and then, describing what he might want, described
something else: *one could produce reports. Exporting a parts list, say, or creating an invoice.*
[D-201](90-decision-log.md) separates the two and says they must not share a deferral, a mechanism
or a word:

- **A view is a named computation belonging to no node.** It stays deferred on its existing
  criterion ([D-200](90-decision-log.md)), and the interim stands: aggregated figures hang on nodes
  as computed fields ([D-140](90-decision-log.md)).
- **A report is prepared output** — something in a form that leaves the building. That puts it on
  the renderer side, not here.

⚠️ **[D-202](90-decision-log.md) corrects [D-201](90-decision-log.md)** on what a report *does*. The
owner: *a report can contain calculations, like the front end does, but ones that arise at the time
of the export — at the time the output is produced. And a report can contain raw data, or accumulate
it, or combine it with other data. Linked, joined, however you want to put it.* So a report **does
compute, at output time**, and it **joins**. What falls with the correction is the guess that a
report needs no new mechanism: **a join across unrelated records is not a descent**. What stands is
the separation itself, and that **the rules for a report are stored** — *this is how the report
looks* — which makes a report a configured thing rather than a written one.

**Open:** whether a report's output-time calculation is the same expression language as the two
owners above — a relative edge-id path cannot express a join across unrelated records — is not
decided. See [`_harvest/contradictions.md`](_harvest/contradictions.md).

## K2, K3, K4 — what a calculation *is*

**A calculation is not a relation kind. It is a property of an attribute** — one whose value comes
from an expression instead of from input ([D-043](90-decision-log.md)).

```mermaid
---
config:
  theme: dark
  themeVariables:
    mainBkg: "#1e1e1e"
    background: "#1e1e1e"
    primaryColor: "#1e1e1e"
    classText: "#ffffff"
    textColor: "#ffffff"
    lineColor: "#ffffff"
---
flowchart TD
    BL[Parts list] -->|gesamtpreis · computed| SUM["sum over positions"]
    BL -->|positionen 1..*| POS[Position]
    POS -->|preis · computed| MUL["menge × einzelpreis"]
    POS -->|menge| M[Integer]
    POS -->|einzelpreis| P[Preis]
```

K3 drawn out. Two computed attributes, at two levels, and the upper one reaches across a
composition edge with multiplicity — which is what makes it an *aggregate* rather than a formula.

### Why not a relation kind

The old tree had `calc` as a relation type ([harvest 01](_harvest/01-standard-tree.md), B7).
Against reviving it:

- **A relation kind is a structural connection between two nodes.** A calculation is a **rule**,
  not a connection. Making it a kind would put a rule in the place where structure lives.
- The dependencies are **already implied by the expression**. Storing them again as edges records
  one fact twice, which the code standard forbids.
- [D-036](90-decision-log.md) closed the set of relation kinds precisely because each kind carries
  rules the engine enforces. A calculation carries no such rules — it carries an expression.

### Where it does earn an edge

**Invalidation.** To know which computed attributes must be recomputed when something changes,
the system needs the dependencies **backwards**. That is a graph, and materialising it is the
only sane way to index it.

But it is **derived from the expression, never authored** — parsed out when the expression is
saved, thrown away and rebuilt at will. Same rule as the resolved-settings cache
([D-016](90-decision-log.md)): a derivative, never a second source of truth.

### It fits what is already decided

- **[C19](10-domain-core.md) already anticipated this.** Hidden attributes exist, in the owner's
  own words, so they can feed calculations. K2 is that statement arriving.
- **A computed attribute is read-only** for the user — the `read_only` setting from C19, set by
  the calculation rather than by hand.
- **The preview shows it working.** Change an input, the computed value follows
  ([R23](30-renderer.md)).

## How a calculation knows what feeds it

The owner named this as the part with no answer yet: *where does the calculation learn which
fields it is fed from?*

**The same way an override knows what it overrides — a relative path of edge ids**
([D-045](90-decision-log.md)). [OQ-025](91-open-questions.md) already settled that addressing for
overrides, and reusing it means calculations introduce no new way of pointing at anything.

```mermaid
---
config:
  theme: dark
  themeVariables:
    mainBkg: "#1e1e1e"
    background: "#1e1e1e"
    primaryColor: "#1e1e1e"
    classText: "#ffffff"
    textColor: "#ffffff"
    lineColor: "#ffffff"
---
flowchart TD
    BL[Parts list] -->|#42 positionen · 1..*| POS[Position]
    BL -->|#94 gesamtpreis| G[Preis]
    POS -->|#88 menge| M[Integer]
    POS -->|#93 einzelpreis| E[Preis]
    POS -->|#95 preis| P[Preis]
```

The owner's own example, addressed:

| computed attribute | operation | operands |
|---|---|---|
| `#95` Position.preis | `multiply` | `[#88]` , `[#93]` |
| `#94` Parts list.gesamtpreis | `sum` | `[#42, #95]` |

Two things fall out of this and both are useful:

**Scalar or aggregate is not something anyone declares.** `[#88]` reaches one value because `#88`
has multiplicity 1. `[#42, #95]` crosses `#42`, which is `1..*` — so it reaches *many* values, and
the operation must be an aggregate. **The multiplicity along the path decides it**, which means
the model can check that a `multiply` is not accidentally pointed at a collection.

**The dependency graph is parsed, not authored.** Every edge id appearing in an expression is a
dependency. Reversing that index gives *what must be recomputed when this changes*
— materialised for speed, rebuilt from the expressions at will, never a second source of truth
([D-016](90-decision-log.md)).

### This is also why the old `calc` edge existed

The previous project needed a way to *point* at the inputs, and the only pointing mechanism it
had was an edge — so calculation became a relation kind. With edge-id paths inside a setting, the
pointing is there without a new kind of edge, and the dependency edges come back as a derived
index rather than as authored structure.

## The expression — a structured tree, clicked rather than typed

Closing [OQ-047](91-open-questions.md). Neither pole works on its own:

| | Why not |
|---|---|
| **a picked operation over a picked field** — what the old `Aggregate` branch did | too weak. `Position.preis = menge × einzelpreis` already has **two** operands and an operator. |
| **a free formula language** | needs a parser, evaluation safety, validation, and error messages a **model author** can act on — and the audience is not a programmer. |

### The shape

```mermaid
---
config:
  theme: dark
  themeVariables:
    mainBkg: "#1e1e1e"
    background: "#1e1e1e"
    primaryColor: "#1e1e1e"
    classText: "#ffffff"
    textColor: "#ffffff"
    lineColor: "#ffffff"
---
flowchart TD
    M["multiply"] --> A["[#88] menge"]
    M --> B["[#93] einzelpreis"]
    S["sum over [#42] positionen"] --> C["[#95] preis"]
```

A small tree of **operations** and **operands**, built in the modeller with a picker. The set of
operations is small and closed: **arithmetic** (`+ − × ÷`) and **aggregates** over a collection
(`sum`, `avg`, `min`, `max`, `count`).

| What it buys | |
|---|---|
| **no parser** | no syntax errors, no injection, no messages nobody can act on |
| **always-valid references** | operands are picked from the real attributes and stored as **edge ids** ([D-045](90-decision-log.md)), so renaming and moving leave them intact |
| **type checking while building** | the picker does not offer a text column for a multiplication, so the error never comes into being |
| **the dependency graph *is* the tree** | D-045's invalidation index falls out instead of being parsed |
| **scalar or aggregate is checkable** | the multiplicity along the path says which, so a `multiply` aimed at a collection can be refused |

**The honest drawback:** `(a + b) × c ÷ d` is tedious to assemble by clicking, and anyone who knows
formulas will want to type.

**And the mitigation is clean:** a formula field may later be added as a **second way to author**
the same structure — typed, parsed, stored as the tree. **The structure is the truth; text is an
input method.** So deciding this now costs nothing and closes nothing off.

### The escape for hard cases

What cannot be expressed structurally — a real bill-of-materials rollup with special rules — arrives
as a **registered calculation strategy**, exactly like renderers, converters and validators
([D-036](90-decision-log.md)). A developer registers it; the author picks it from a list.

> **Simple things structurally, hard things as a registered strategy.**

The mechanism already exists; it is the same one used everywhere else.

### Storage

The expression tree is **serialised into the setting**. Nothing ever queries *inside* an
expression, and the dependency index is extracted when it is written
([D-045](90-decision-log.md)) — so a queryable structure would buy nothing and cost a table.

## K3a — The walk has a cycle guard and no depth limit

```mermaid
---
config:
  theme: dark
  themeVariables:
    mainBkg: "#1e1e1e"
    background: "#1e1e1e"
    primaryColor: "#1e1e1e"
    classText: "#ffffff"
    textColor: "#ffffff"
    lineColor: "#ffffff"
---
flowchart TD
    W["a walk stops"] --> C{why}
    C -->|cycle · already seen| R["render: a reference · calculation: guarded, terminates"]
    C -->|depth limit| D["rendering only · never a calculation"]
```

[D-100](90-decision-log.md) closes [OQ-019](91-open-questions.md), and **one guard serves both the
render descent and the calculation walk**. Cycles are **detected, not forbidden** — mutual
references are ordinary modelling — and visited identities are remembered. The depth limit is a
setting with a default, not a constant.

[D-104](90-decision-log.md) then draws the line between the two walks: **a depth limit is a
rendering concern only, and calculations must never be truncated.** A truncated rendering shows
*less than the truth* and says so; a truncated sum **states an untruth**, in the same typeface as a
correct number, with nothing about it looking unfinished. So the calculation walk needs the **cycle**
guard — without it it never terminates — and carries **no depth cap**.

**If a calculation cannot complete, its result is not a number.** It is marked *not computable*, and
every renderer shows that rather than a value.

## K3b — A missing input has three modes, and *zero* is not one of them

```mermaid
---
config:
  theme: dark
  themeVariables:
    mainBkg: "#1e1e1e"
    background: "#1e1e1e"
    primaryColor: "#1e1e1e"
    classText: "#ffffff"
    textColor: "#ffffff"
    lineColor: "#ffffff"
---
flowchart TD
    M["an input is missing"] --> S["strict · the whole is not computable"]
    M --> P["partial (default) · compute what is there, mark the result"]
    M --> U["substitute · use the attribute's default"]
```

[D-147](90-decision-log.md), closing [OQ-062](91-open-questions.md). The mode is set **per
attribute**. `substitute` uses the attribute's **default**, which already inherits
([D-030](90-decision-log.md), [D-015](90-decision-log.md)), so a class estimate falls out of the
hierarchy with no new mechanism.

⚠️ **Treating a missing input as zero is explicitly not an option** — [D-104](90-decision-log.md)
forbids it, and it is recorded as rejected so nobody adds it later for convenience.

**Marking happens in two places and they say different things:** at the **value** it says *here is
the cause*, for whoever will fix it; at the **aggregate** it says *this number is incomplete*, for
whoever **uses** it — and that person may never see the position. Without the second, an incomplete
figure travels onward as if it were complete. Three distinguishable states at a value:

| State | Shown as |
|---|---|
| **computed** | the value |
| **not computable** | `—`, with a reason |
| **estimated** | the figure, marked as an estimate |

## K6b — a report is a wider bracket, not a second language

```mermaid
flowchart LR
  R["Report"] --> A["waehlt eine Menge"]
  A --> G["gruppiert"]
  G --> F["Formel je Gruppe"]
```

Expressions address their operands by **relative edge paths** ([D-045](90-decision-log.md)) — *walk
this edge, then that one* — which reaches everything hanging off a node. *Turnover per supplier per
month* is not reachable that way: the things being joined have **no edge between them**.

**The answer is not a more powerful expression language** ([D-243](90-decision-log.md)). A report is
**selection + grouping + expression**, and the arithmetic per group is an ordinary expression,
unchanged. Same separation as [D-234](90-decision-log.md) one level up: the block selects and the
renderer draws; the report selects and groups, and the expression computes.

**The honest price:** reports will not do everything SQL does. In exchange nobody has to learn a
second language, and since reports are Release 2 ([D-203](90-decision-log.md)) what is settled here
is only **where they may not grow**.

## K6c — an `Ausdruck` is a frozen report

⚠️ **A German word collision worth keeping**, because it produced a real misunderstanding
([D-242](90-decision-log.md)): *Ausdruck* means **printout**, not *expression*. In German the word
for an expression is **Formel**.

| | | |
|---|---|---|
| **Report** | live — recomputes on every call | a window |
| **Printout** · *Ausdruck* | **frozen** — what stood in it then stays | a document |

An **invoice is not a report but a printout of one**, which is why later price corrections must not
reach it. Same distinction as [D-143](90-decision-log.md)'s frozen computed value, arriving from a
different direction.

## Backward aggregates — reaching into another model

| # | Statement (owner, 2026-08-22) |
|---|---|
| **K7** | If a parts list should always show the **average price**, it is recalculated each time rather than snapshotted — and the calculation **feeds from another model**. |
| **K8** | **A backward-read value is computed at read time, not materialised.** It is not a stored value; it is worked out afresh on every display. |

[D-045](90-decision-log.md) settled *how* a calculation addresses its inputs. It did not settle
*how far they may reach* — and K7 reaches further than anything so far.

### What it is

```mermaid
---
config:
  theme: dark
  themeVariables:
    mainBkg: "#1e1e1e"
    background: "#1e1e1e"
    primaryColor: "#1e1e1e"
    classText: "#ffffff"
    textColor: "#ffffff"
    lineColor: "#ffffff"
---
flowchart LR
    B[Part] -.->|who points at me<br/>through artikel| O["Bestellpositionen"]
    O --> A["avg of their preis"]
```

Not *where do I point* but **who points at me**. A relative forward path cannot express it, so an
operand may also be a **backward path**.

**The infrastructure already exists.** That query is [D-070](90-decision-log.md)'s *all BOMs
containing part X* — `WHERE edge_id = artikel AND value_ref = this record`, indexed. Only the
expression form was missing, not the capability.

It brings several useful figures with it: average purchase price, how many parts lists use this
part, the date of the last order.

### K8 — and it is not materialised

The reason is invalidation. Forward is contained: change a value, its ancestors are affected.
**Backward fans out** — a new order line changes a computed field on a *part*, which would touch
every parts list using that part. With [D-072](90-decision-log.md) materialising computed values,
every order write would start a cascade.

| | Computed | Searchable |
|---|---|---|
| **forward calculation** | on write, materialised | **yes** ([D-070](90-decision-log.md)) |
| **backward aggregate** | **on read** | **no** — a deliberate exception to [D-072](90-decision-log.md) |

An average purchase price is a **figure one reads**, not a field one filters on. Anyone who does
need to filter on one turns materialisation on deliberately and accepts the cascade.

### And it is [D-065](90-decision-log.md) once more

| | Is | Behaves |
|---|---|---|
| the average over orders | a **description** — what one currently pays | **tracks**, recomputed |
| the price **in** an order line | an **agreement** | **freezes** |

Both are prices on the same part, and they behave oppositely because they are different
statements.

## Three states, and freezing as the way out

| # | Statement (owner, 2026-08-22) |
|---|---|
| **K9** | A value could be **frozen**, and regenerated on request when it has drifted too far — noticed while editing the parts list anyway. |
| **K10** | *In principle it is only an approximation.* |
| **K11** | Parts get dearer and cheaper year on year, so **the average may simply not be the right thing** — perhaps the value of stock on hand, falling back to what one would pay to reorder. |
| **K12** | Every time a parts list is **saved**, the values could be recalculated: it has been touched anyway, and then the figures are current. |

```mermaid
---
config:
  theme: dark
  themeVariables:
    mainBkg: "#1e1e1e"
    background: "#1e1e1e"
    primaryColor: "#1e1e1e"
    classText: "#ffffff"
    textColor: "#ffffff"
    lineColor: "#ffffff"
---
flowchart TD
    F["live forward<br/>on write · searchable"] --- B["live backward<br/>on read · not searchable"]
    B --- Z["frozen<br/>once, on request · searchable · no cascade"]
```

The three states of [D-143](90-decision-log.md):

| | Computed | Searchable | Cascade |
|---|---|---|---|
| **live forward** | on write, materialised | yes | forward |
| **live backward** | on read | no | none |
| **frozen** | **once, on request** | **yes** | **none** |

**A frozen value has no running dependency** — it is a stored number that was computed once. So
[D-142](90-decision-log.md)'s contagion does not reach it, and `gesamtpreis` is searchable again
even though the average behind it is backward. K10 is the reason it is right: **propagating an
approximation live through an archive costs much and gains nothing.**

### K12 — and it follows from a decision already taken

| | On save | Explicit recalculate |
|---|---|---|
| **tracking list** | recalculates, **reports afterwards** | not needed |
| **frozen list** | **untouched** | shows the difference, then applies |

Both behaviours come out of one setting ([D-065](90-decision-log.md),
[D-146](90-decision-log.md)): a costing list *describes* and tracks; a quotation *was agreed* and
freezes. A quotation that changed because someone opened and saved it would make *frozen*
meaningless.

Even the tracking case reports — *12 prices updated* — non-blocking but visible. Correcting a typo
should not move a hundred numbers unnoticed.

### Recalculation is an event, and staleness is information

[D-144](90-decision-log.md) adds three things to that:

- **An explicit *recalculate* shows what will change before applying it** — *12 of 100 positions
  change · Widerstand 10k 0,043 → 0,051 €*. A silent refresh of a hundred line prices is alarming;
  this is the same shape as the conflict resolver.
- **A frozen value carries a timestamp**, so nobody has to guess how old it is.
- **The current figure is cheap to compute on read** ([D-140](90-decision-log.md)), so a list can
  carry a **hint**: *3 positions differ by more than 10% from current prices* — which turns *I think
  this is out of date* into information.

### K11 — the method is pluggable, and there is more than one right answer

The average is one of a family: moving average, FIFO, the value of stock on hand, replacement cost.
Each is a **registered calculation strategy**, chosen by setting — the escape hatch of
[D-130](90-decision-log.md), and the same mechanism as renderers and validators. Settled as
[D-145](90-decision-log.md), which adds that the several valuations **coexist as separate named
attributes** rather than competing for one field called `preis`.

**Each is only as good as its data.** Average needs order lines; FIFO needs receipts with quantity
and date; stock value needs inventory. None of that exists yet, which is why the average is the
starting point — it is what the data supports.

**And they are different numbers for different purposes, not competitors:**

```
Part
  einstandspreis           what it cost me        FIFO or average
  wiederbeschaffungspreis  what it would cost     last order, supplier price
  listenpreis              the catalogue figure
```

A quotation wants the second; a retrospective costing wants the first. Naming each for what it
*is* dissolves the argument about which method is correct.

## Open

All four questions this document opened have since been answered. Kept, with what answered them,
so the trail is readable:

| | Answered by |
|---|---|
| [OQ-045](91-open-questions.md) — what can an expression reach? | [D-045](90-decision-log.md), a relative path of edge ids; the *reach* half by [D-140](90-decision-log.md), which adds the backward path. |
| [OQ-046](91-open-questions.md) — when does a model calculation run? | [D-072](90-decision-log.md), materialised on input change — with the backward exception of [D-140](90-decision-log.md) and the third state of [D-143](90-decision-log.md). |
| [OQ-047](91-open-questions.md) — what is the expression language? | [D-130](90-decision-log.md), a structured tree built with a picker. |
| [OQ-019](91-open-questions.md) — cycles and depth | [D-100](90-decision-log.md) and [D-104](90-decision-log.md): one cycle guard for both walks, no depth cap on a calculation. |

What is genuinely open now sits in [`_harvest/contradictions.md`](_harvest/contradictions.md) and,
for views, in [OQ-069](91-open-questions.md) — deferred by decision with a named trigger
([D-200](90-decision-log.md)).

## Harvest candidates

| Source | What is in it |
|---|---|
| [`_harvest/01-standard-tree.md`](_harvest/01-standard-tree.md) | The `Definition › Aggregate` branch: *aggregate operations, the operation chosen per field slot, the type staying the column value type.* Closest thing to a prior design — a cross-check, not an inheritance. |
| [`../legacy/plans/data-structure.md`](../legacy/plans/data-structure.md) | Q57 and Q125 touched calculation and the `calc` relation. |
