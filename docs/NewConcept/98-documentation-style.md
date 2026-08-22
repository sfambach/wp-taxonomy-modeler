---
title: Documentation style
status: agreed
round: R1
last_updated: 2026-08-22
---

# Documentation style

How every document in `NewConcept/` is written. Agreed by the owner on 2026-08-22 —
see [D-005](90-decision-log.md).

## The rule

**Diagram first, then prose, then code if needed.** A concept document is a sequence of
small units, one per *Sachverhalt* — one fact, one relationship, one mechanism:

1. **A small mermaid diagram.** Small is the point, not a nice-to-have.
2. **An explanation** underneath: what the diagram says, and *why* it is that way.
3. **Code, only where detail demands it** — a signature, an interface, a table definition.

A document is never one large diagram with a wall of text after it. It is many small units.

## How small is small

| | Guidance |
|---|---|
| Boxes per diagram | **3–7.** At eight, split it. |
| Ideas per diagram | **One.** If the caption needs an "and", it is two diagrams. |
| Fields shown | Only those the explanation actually talks about. Full field lists belong in the code block, not the diagram. |

Splitting is cheap and the same class may appear in several diagrams, each time showing only
the part that unit is about. Repetition across small diagrams is **wanted** — it is what makes
each unit readable on its own.

## Unit template

Copy this shape. `<ID>` makes the unit citable from the decision log and from open questions.

````markdown
### <ID> — <one-line title>

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
classDiagram
    A --> B : verb
```

<Explanation: what it says, and why it is this way. Two to six sentences.>

**Open:** <anything undecided — with a link to its `OQ-<nnn>`, or nothing at all.>

```php
// Only when the detail matters. Mark which kind it is:
// CONTRACT  — normative, this is the agreed shape
// SKETCH    — illustration only, not binding
```
````

## Fixed conventions

- **The theme block above is standard.** Every diagram carries it, so all diagrams look
  alike. (Note: it hardcodes dark. On a light background the diagrams stay dark — accepted.)
- **Unit ids are stable and never reused.** Prefix by document: `C<n>` domain core,
  `R<n>` renderer, `S<n>` settings, `P<n>` persistence, `I<n>` i18n, `V<n>` vision.
- **Code blocks are labelled `CONTRACT` or `SKETCH`.** An unlabelled code block is a sketch.
  This is what stopped working last round: sketches were read as specifications.
- **Cardinalities are written out** (`1`, `0..*`, `1..*`) whenever they carry meaning. The
  difference between `0..*` and `1..*` has already caused one open question
  ([OQ-008](91-open-questions.md)).
- **Diagrams do not contradict each other.** If two units need different shapes of the same
  thing, that is an open question, not two diagrams.

## Why this way

The seed sketches ([`TreeMeremaid.md`](TreeMeremaid.md),
[`I18nMeremaid.md`](I18nMeremaid.md), [`RendererMeremaid.md`](RendererMeremaid.md)) already
use this style, and it works — the problems found in them were found *because* the diagrams
made the contradictions visible. The legacy round went the other way: 1589 lines of prose in
[one file](../legacy/plans/data-structure.md), where a contradiction can hide for months.
