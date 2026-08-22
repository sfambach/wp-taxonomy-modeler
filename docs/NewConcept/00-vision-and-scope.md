---
title: Vision and scope
status: draft
round: R1 (in progress)
last_updated: 2026-08-22
---

# Vision and scope

> **Status: `draft`.** Contains the owner's spoken statement of 2026-08-22, written down but
> **not yet confirmed**. Nothing here is `agreed` until the owner has checked the wording —
> in particular the points marked ⚠️. Do not implement from this file.

## Product statement

**A taxonomy modeller.** It lets a user build **data models** — and it models them the way
the world is shaped, as **objects and their relationships**, not as relational tables.

The user builds a model by building a **tree**.

## Core shape — as stated by the owner (2026-08-22)

Numbered so later documents and decisions can cite them.

| # | Statement |
|---|---|
| **V1** | The model consists of **nodes and edges**. |
| **V2** | The nodes sit in a **tree**. |
| **V3** | The tree represents the **inheritance hierarchy only** — nothing else. |
| **V4** | The **root node has no parent**. Every other node inherits from its ancestors. |
| **V5** | **Fundamentally all nodes are the same.** There is one kind of node, not a class hierarchy of node kinds. |
| **V6** | There are a few **special nodes**, for **data types** and for **calculations**. |
| **V7** | Those special nodes are **created in the configuration**. |
| **V8** | Essentially every node has: **one renderer** (responsible for display), **one converter** (may manipulate the output), and **one or more validators** (check whether user input is correct). |
| **V9** | The validator concept is deliberately special: a validator can, at the same time, **offer a way to correct** the invalid data. |

### What V3 and V5 rule out

Stated here because both cut against the seed sketches, and that is the point:

- **V3** means the tree is *not* the general-purpose relationship graph. If there are edges
  that are not inheritance, they are a separate concern from the tree. → drives
  [OQ-002](91-open-questions.md).
- **V5** means the `DomainNode` / `ValueNode` / `I18nValueNode` split in
  [`I18nMeremaid.md`](I18nMeremaid.md) is, as drawn, **against the vision**. Differences
  between nodes come from configuration (V7, V8), not from subclassing. → drives
  [OQ-003](91-open-questions.md).

## ⚠️ Transcription notes

The statement above was dictated. These readings need an explicit yes/no:

| Heard | Read as | Confidence |
|---|---|---|
| "Taximodeller" | *taxonomy modeller* | high |
| "über die Gastronomie auf" | dictation noise, dropped | medium — say if something was meant here |
| "Hutknoten" | *root node* (Wurzelknoten) | high |
| "Grundmenüs sind alle Knoten gleich" | *fundamentally, all nodes are the same* | medium |

## Still to write in this document

- Who the users are, and what they are trying to achieve.
- **Non-goals** — what this explicitly will not do.
- The boundary against host plugins such as `wp-electronic-parts`.
- Success criteria per phase.

## Harvest candidates

| Source | What is in it |
|---|---|
| [`NewConcept.md`](NewConcept.md) | Restart rationale; *model the world, not relational tables*. Largely superseded by the statement above. |
| [`../legacy/PRODUCT.md`](../legacy/PRODUCT.md) | Old product statement. |
| [`../legacy/plans/project-plan.md`](../legacy/plans/project-plan.md) | Sections *Problem*, *Goal*, *Non-goals*, *Success criteria*, *Relationship to wp-electronic-parts*. |
| [`../legacy/plans/mvp-requirements.md`](../legacy/plans/mvp-requirements.md) | Personas, FR1–FR7, explicit non-requirements. |
| [`../legacy/plans/use-cases.md`](../legacy/plans/use-cases.md) | Use-case cards — a reality check on scope. |
