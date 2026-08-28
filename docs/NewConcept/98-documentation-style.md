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

## One topic, one owning place

**A topic gets one section that owns it. Every other mention is a pointer to that section.**
([D-469](90-decision-log.md).) The owner: *«everything that concerns one point or one subject area,
one category, simply summarise it together, so that one can then also find it together.»*

| | |
|---|---|
| **the owning section** | states the **current** state completely, and carries the thread's history as a table so a reader sees which arguments were already tried |
| **every other mention** | is corrected, or reduced to a link |
| **a mention that was wrong** | says so — *«this used to say X, and here is why it changed»* |

⚠️ **The failure this prevents is contradiction, not length.** *Measured on `hide`, which spanned
eleven decisions across six documents: a rule demanded a renderer honour a flag it never sees, and
two copies of one table disagreed because a correction had reached only the nearer one.* **A
duplicated table is the specific thing to look for** — the second copy is where a retired rule
survives.

⚠️ **Und was ganz überholt ist, zieht in den Dachboden** ([D-487](90-decision-log.md)): [92 Veraltete Entscheidungen](92-veraltete-entscheidungen.md). *Mit **drei** Bedingungen, und alle drei sind gemessen: die Aussage muss **ganz** überholt sein, die Entscheidung **selten zitiert**, und im Log bleibt immer ein **Stummel**. Eine teilweise überholte bleibt, wo sie ist — ihre Warnung steht im ersten Satz, was der billigere und wirksamere Schutz ist.*

⚠️ *Consolidating is not compressing. Nothing decided is dropped: the corrections are the valuable
part, because they say which readings were tried and failed. See
[Hiding](10-domain-core.md#hiding--hide-is-one-column-and-it-is-on-the-edge) for the worked example.*

---

## Why this way

The seed sketches ([`TreeMeremaid.md`](TreeMeremaid.md),
[`I18nMeremaid.md`](I18nMeremaid.md), [`RendererMeremaid.md`](RendererMeremaid.md)) already
use this style, and it works — the problems found in them were found *because* the diagrams
made the contradictions visible. The legacy round went the other way: 1589 lines of prose in
[one file](../legacy/plans/data-structure.md), where a contradiction can hide for months.

## Simplifying is the dangerous step

Added 2026-08-23, after a background pass found six contradictions in the concept documents and
**three of them had the same author and the same cause**: a term was renamed or a distinction was
flattened, and nothing checked what had been hanging from it.

| What was simplified | What silently broke |
|---|---|
| `long` retired in favour of `help` ([D-196](90-decision-log.md)) | it was the anchor of the label fallback chain ([D-151](90-decision-log.md)) |
| `Definition` gathered into one branch ([D-185](90-decision-log.md)) | it had separated data types from constants, which decides the relation kind ([D-193](90-decision-log.md)) |
| *use site* used loosely in a sentence ([D-162](90-decision-log.md)) | the glossary had given it a fixed meaning: a **relation** ([D-214](90-decision-log.md)) |

**The rule that follows:**

> **Before renaming a term or flattening a distinction, search for who cites the old one — and
> read what they were relying on it for.**

Adding is comparatively safe: a new term sits beside the others and nothing yet depends on it.
Removing and merging are the operations that reach backwards into text already written, and they
are the ones this project keeps paying for. `grep` costs seconds; a contradiction discovered three
weeks later costs an argument about what was meant.
