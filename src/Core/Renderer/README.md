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

## What must not happen here

- **No WordPress.** Not `esc_html()`, not `__()`. Escaping is `RenderResult::escape()`, plain PHP.
- **No writing** ([D-159](../../../docs/NewConcept/90-decision-log.md)) — not even to tidy up a
  value that arrives malformed. A renderer is handed what there is and returns a string.
- **No walking.** The chain is resolved before the renderer is called; it reads settings, it does
  not fetch them.

## Concept

[`docs/NewConcept/30-renderer.md`](../../../docs/NewConcept/30-renderer.md).
