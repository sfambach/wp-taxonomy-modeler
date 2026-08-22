---
title: Glossary
status: draft
round: R1 (in progress)
last_updated: 2026-08-22
---

# Glossary

One word per concept. Every term here is used in exactly this sense throughout
`NewConcept/`, and nowhere in a second sense.

**Why this file exists.** The previous round drifted between *Eigenschaft*, *Attribute*,
*Parameter*, *Slot* and *Property* for overlapping ideas, and the model became impossible to
hold in one head. This round has already produced one collision of its own — *Label* was used
both as the umbrella for all display texts and as the name of one particular role
([D-023](90-decision-log.md)) — which is what prompted writing this down.

## The model

| Term | Deutsch | Means |
|---|---|---|
| **Identity** | Identität | The common base of everything the model persists. Carries the `id` and a `version`. `Node` and `Relation` are its two shapes, and they draw ids from **one** space ([C11](10-domain-core.md)). |
| **Node** | Knoten | A thing in the model. All nodes are fundamentally the same kind ([V5](00-vision-and-scope.md)); differences come from configuration, not from subclassing. |
| **Relation** | Kante, Verbindung | A directed edge from one node to another. **One construct** — inheritance, composition and aggregation are its *kinds*, not separate classes ([D-012](90-decision-log.md)). |
| **Kind** (of a relation) | Art | Which of the three a relation is. Not to be confused with *type*. |
| **Inheritance** | Vererbung | The relation kind that forms the tree. At most one parent per node, acyclic, protected, and the only kind exempt from edge settings ([C9](10-domain-core.md)). |
| **Composition** | Komposition | A relation kind whose target belongs to the whole and **is deleted with it** ([C12](10-domain-core.md)). |
| **Aggregation** | Aggregation | A relation kind whose target is **independent** and survives its whole ([C13](10-domain-core.md)). |
| **Attribute** | Attribut | **A relation, seen from the node that owns it** ([D-031](90-decision-log.md)). Its `kind` is the connection, its `to` is the type, its name and multiplicity and defaults hang on it as name, labels and settings. There is no separate attribute object — the *wrapper* the author edits is a screen, not a table. |
| **Use site** | Verwendungsstelle | A relation, seen as the place where a shared node is used. Configuration that applies to one use only lives here, not on the node ([C8](10-domain-core.md)). |
| **Binding** | Konstante, Bindung | A named slot in the installation configuration pointing at a node ([D-120](90-decision-log.md)). The engine asks for the slot and never names an id or a node name, so ids may shift freely. Carries **only** the pointer — no renderer, no defaults. |

## Configuration and text

| Term | Deutsch | Means |
|---|---|---|
| **Setting** | Einstellung | A configuration value on an identity: `key` + typed `value`. Conceptually an attribute ([D-011](90-decision-log.md)), stored in its own table. Holds `min`, `max`, `step`, `hide`, `read_only`, `order`, the renderer choice, and **`icon`** — which lives here rather than with labels because it is language-neutral. |
| **Fixed attribute** | festes Attribut | An attribute every node has, stored **on the node** as a column ([C4](10-domain-core.md)). The set is [OQ-017](91-open-questions.md). |
| **Extended attribute** | erweitertes Attribut | An attribute arriving through specialisation, stored generically in settings ([C5](10-domain-core.md)). |
| **Label** | Bezeichnung | The human-readable text of an identity, in one **role** and one **locale**: `owner` + `role` + `locale` + `text`. Its own table ([D-019](90-decision-log.md)). |
| **Role** (of a label) | Rolle | Which label this is: `long`, `form`, `table`, `symbol`. Written **without** a prefix ([D-023](90-decision-log.md)) — the role is `form`, never `label.form`. |
| **Base name** | Basisname | `Node.name`. Required, locale-neutral, entered at creation. Display of last resort, **never** a lookup key. Not unique — duplicates are normal ([D-022](90-decision-log.md)). |
| **Owner** | Eigentümer | The single identity a setting, label or changelog item belongs to. One column, because ids come from one space. |
| **Override** | Überschreibung | A setting or label on a use site that replaces the base value. Stored **sparsely** — only what differs — so a change to the base reaches every use site that did not override it ([D-015](90-decision-log.md)). |

## Presentation

| Term | Deutsch | Means |
|---|---|---|
| **Renderer** | Renderer | The only thing that produces display ([R1](30-renderer.md)). PHP ([D-021](90-decision-log.md)). |
| **Registry** | Registrierung | The one place all renderers register. Answers two lookups: by renderer name at render time, by node type at configuration time ([R12–R14](30-renderer.md)). |
| **Variant** | Variante | A fundamentally different presentation — field, spinner, slider. **Own renderer each** ([D-018](90-decision-log.md)). |
| **Circumstance** | Umstand | Level (admin / block / frontend), editable or not, hidden. **Option inside** one renderer, not a new renderer ([D-018](90-decision-log.md)). |
| **Converter** | Converter | May manipulate output ([V8](00-vision-and-scope.md)). Whether it also runs on input is [OQ-007](91-open-questions.md). |
| **Validator** | Validator | Checks user input, and may **offer a correction** ([V9](00-vision-and-scope.md)). One or more per node. |
| **Preview** | Vorschau | Every node has one, driven by test data, showing an edit view and a display view, and reacting to every settings change ([R21–R23](30-renderer.md)). |
| **Reference renderer** | Verweisrenderer | Draws the target label plus a link and **does not descend** ([D-105](90-decision-log.md)). The default for **aggregation**; composition expands instead. Bounds the batched load as well as the display. |

## Process

| Term | Means |
|---|---|
| **Owner statement** | Something the project owner said, written down verbatim in meaning and given an id (`V<n>`, `C<n>`, `R<n>`, `P<n>`, `I<n>`). The raw material of the concept. |
| **Decision** | An entry `D-<nnn>` in [the decision log](90-decision-log.md). **Nothing is decided until it is there.** |
| **Open question** | An entry `OQ-<nnn>` in [open questions](91-open-questions.md). Where anything undecided goes, instead of being invented. |
| **Harvest** | Taking content out of `legacy/` through a reviewed sheet, item by item, with an explicit *take / rework / drop*. Never inheritance. |
| **Seed sketch** | One of the four original restart files. Input, not concept. |

## Rejected words

Kept so a discarded term cannot quietly return under another name.

| Word | Why not |
|---|---|
| *Eigenschaft*, *Parameter*, *Slot*, *Property* | All meant roughly *attribute* in the previous round, in slightly different and drifting senses. Use **attribute** — and remember it is not defined yet ([OQ-010](91-open-questions.md)). |
| *Translation* | Rejected by the owner ([I8](40-i18n.md)): these are not translations in the software sense, they are the same name in another language. Use **label**. |
| *`label.form`* as a role name | The `label.` prefix made *label* look like both the umbrella and the role ([D-023](90-decision-log.md)). The role is **`form`**. |
| *Type node* / *domain node* / *value node* | Presume node subtypes, which [V5](00-vision-and-scope.md) argues against and [OQ-004](91-open-questions.md) has not settled. |
| *Primary key* for an attribute | The primary key is the **`id`** ([D-055](90-decision-log.md)). An article number is soft identity — human-meaningful, correctable, survivable. Use **`unique`**, optionally with a group name for composite constraints ([D-115](90-decision-log.md)). |

## Dictation notes

The owner statements were dictated, and speech recognition produces a few recurring substitutions.
Recorded so that a later reader — or a fresh session quoting the raw statements — does not stumble
over them.

| Heard | Means |
|---|---|
| *Rennrad*, *Renntrainer*, *Rennerad*, *Ränderer*, *Intranderer* | **Renderer** |
| *Renderengten* | **Renderer registry** |
| *ohne IT* | **owner_id** |
| *gelockt* | **geloggt** — logged, not locked ([C85](10-domain-core.md)) |
| *Blogs* | **blocks** |
| *Hutknoten* | **root node** |
| *Bombe*, *Bombes* | **BOM** — parts list |
| *OML* | **UML** |
| *Beschichtung* | **Schachtelung** — nesting |
| *vermisst* | **vermischst** — mixing, not missing |
| *schonenswert*, *wieder schonenswert* | **Widerstandswert** — resistance value |
| *Waldkatz* | **wildcard** |
| *Fahrrad*, *Nanofahrrad* | **Farad**, nanofarad |
