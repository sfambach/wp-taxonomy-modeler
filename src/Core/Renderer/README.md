# `Taxmod\Core\Renderer` — everything a person sees

Sentence 14 of [the core on one page](../../../docs/NewConcept/10-domain-core.md#the-core-on-one-page):
**everything a person sees comes from a renderer.** A node carries an ordered list of them, one
mandatory; the purpose — display, edit, search — is **passed in**, not keyed on.

## The two axes, which are often confused

| Axis | Values | Lives in | Decision |
|---|---|---|---|
| **purpose** | display · edit · search | the context; `supports()` declares it | [D-217](../../../docs/NewConcept/90-decision-log.md) |
| **circumstance** | admin · block · front end, editable or not | an **option inside** one renderer | R15, [D-253](../../../docs/NewConcept/90-decision-log.md) |

⚠️ **Neither is part of the registry key.** Keeping both out is what stops three variants × three
levels × two edit modes from becoming eighteen classes instead of three.

## The key is the **type**

`Renderer::handles()` names the simple types a renderer can draw, and that is the registry key
(R14a). `ShippedRenderers::registry()` is the one place that says **which of the eligible ones is
the default per type** — a decision, and deliberately not a property of the renderer class, because
*which of the three ways to draw a number is the ordinary one* is not something the spinner knows
([D-352](../../../docs/NewConcept/90-decision-log.md)).

| | |
|---|---|
| **render time** | the chain named a renderer, or the type's default answers |
| **configuration time** | `eligibleFor()` — what this use site may be given |
| **nothing answers** | `chosenFor()` returns **null**, and the caller decides ([D-353](../../../docs/NewConcept/90-decision-log.md)) |

⚠️ **That last row is not a detail.** A **value** must never silently disappear, so
{@see \Taxmod\Core\Service\Rendering} falls back for display and edit — and the fallback **marks
its own markup**, because reaching it means nobody chose and the type has no default (R14b). An
**unanswerable filter** must never silently appear, so search yields nothing at all, which is
D-217's *not searchable* reached through a missing capability rather than a special case.

## What must not happen here

- **No WordPress.** Not `esc_html()`, not `__()`. Escaping is `RenderResult::escape()`, plain PHP.
- **No writing** ([D-159](../../../docs/NewConcept/90-decision-log.md)) — not even to tidy up a
  value that arrives malformed. A renderer is handed what there is and returns a string.
- **No walking.** The chain is resolved before the renderer is called; it reads settings, it does
  not fetch them.

## Concept

[`docs/NewConcept/30-renderer.md`](../../../docs/NewConcept/30-renderer.md).
