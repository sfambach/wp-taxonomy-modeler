# `src/Core/Converter`

The mapping between a stored value and the characters a person writes and reads.

**Concept:** [`30-renderer.md`](../../../docs/NewConcept/30-renderer.md) — R33, R33a, R33b, R33c, R36,
R36a — and [D-219](../../../docs/NewConcept/90-decision-log.md), [D-076](../../../docs/NewConcept/90-decision-log.md),
[D-148](../../../docs/NewConcept/90-decision-log.md).

## What lives here

| | |
|---|---|
| `Converter` | the contract: `shown()` out, `written()` in |
| `ConverterKind` | R33a's four forms — lookup · threshold · scale · format |
| `ConverterRegistry` | which exist, and which a type may be given |
| `ShippedConverters` | the two that come in the box |

## What it must not depend on

**Nothing in `src/WordPress`** (`CD-1`). A converter is handed a value and returns characters; it
reads no repository, no option, no text domain.

**A converter never chooses itself.** Which one is in effect is a setting resolved along the chain
([D-219](../../../docs/NewConcept/90-decision-log.md)), and the descent runs it and hands the
characters over in `RenderContext::$shown` ([D-445](../../../docs/NewConcept/90-decision-log.md)). No
renderer knows converters exist — `TypedFieldRenderer::characters()` is the single seam, and it is
`final` for that reason.

## The two rules that are easy to get wrong

**A converter converts and does not judge** ([R36a](../../../docs/NewConcept/30-renderer.md#r36a--the-converter-removes-what-cannot-have-been-meant-the-validator-asks-about-the-rest)):
*the converter removes what cannot have been meant, the validator asks about the rest.* So `written()`
throws on characters it cannot map and never returns a quiet zero.

**Only an invertible one may be asked to read** ([D-076](../../../docs/NewConcept/90-decision-log.md)).
A lossy converter serves display only — *and a search must never run against a rounded form, because a
row displayed as `8.50` while holding `8.4999` answers the wrong way.*

## What is deliberately not here

**`2k7`** — the example the concept uses most. [D-220](../../../docs/NewConcept/90-decision-log.md)
says it lands in **two members of one composed value**, `number` and `prefix`, so it needs
composed-value rendering (S7). *A scalar `2k7` writing one number would look right and be the wrong
model.*

**No default per type.** A renderer must always be resolved; a converter must not
([R33b](../../../docs/NewConcept/30-renderer.md#r33b--several-are-eligible-exactly-one-is-in-effect)) —
no converter means the value is shown as it is stored, which is a complete answer.
