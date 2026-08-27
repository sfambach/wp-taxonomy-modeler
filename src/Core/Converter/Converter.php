<?php declare(strict_types=1);

namespace Taxmod\Core\Converter;

use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Model\TypedValue;

/**
 * A mapping between a stored value and the characters a person writes and reads.
 *
 * ⚠️ **A converter is the mapping, a renderer is the form** ([D-219](../../../docs/NewConcept/90-decision-log.md)).
 * The owner destroyed an earlier split of mine — *notation* versus *encoding* — in one sentence: **a
 * traffic light with ten colours is a resistor colour code.** So there is one construct here and the
 * form of the control is somebody else's business.
 *
 * ⚠️ **The only axis is invertible or not** ([D-076](../../../docs/NewConcept/90-decision-log.md),
 * [R36](../../../docs/NewConcept/30-renderer.md)). An invertible converter may serve display, **input**
 * and **search**; a lossy one is display only — *and the consequence that matters is that a search must
 * never run against a rounded form, because a row displayed as `8.50` while holding `8.4999` answers the
 * wrong way.*
 *
 * ```mermaid
 * flowchart LR
 *   V["stored value"] -->|shown| C["characters a person reads"]
 *   C -->|written, invertible only| V
 * ```
 *
 * ⚠️ **It converts and it does not judge** ([R36a](../../../docs/NewConcept/30-renderer.md#r36a--the-converter-removes-what-cannot-have-been-meant-the-validator-asks-about-the-rest)):
 * *the converter removes what cannot have been meant, the validator asks about the rest.* So
 * {@see self::written()} refuses characters it cannot map and says so with an exception
 * ([CD-10](../../../CLAUDE.md)); it never guesses, and it never returns a quiet zero.
 *
 * ⚠️ **Exactly one is in effect per rendering** ([R33b](../../../docs/NewConcept/30-renderer.md#r33b--several-are-eligible-exactly-one-is-in-effect)),
 * *which is the one place converters and renderers do **not** run parallel* — a node may draw with
 * several renderers at once ([D-236](../../../docs/NewConcept/90-decision-log.md)) and nothing extends
 * that here.
 *
 * @see docs/NewConcept/30-renderer.md
 */
interface Converter
{
    /**
     * The name it is chosen by — the value that lands in the `converter` setting.
     *
     * ⚠️ **A token, not a label**, exactly as a renderer's name is: it is stored in the model and
     * compared, so it is never translated (`AR-2`).
     */
    public function name(): string;

    /**
     * Which of [R33a](../../../docs/NewConcept/30-renderer.md#r33a--there-are-a-few-kinds-of-converter-parameterised-by-data)'s
     * four forms this one is.
     *
     * ⚠️ **The engine branches on the form of the mapping, never on its content**
     * ([D-148](../../../docs/NewConcept/90-decision-log.md)) — the same criterion
     * [D-085](../../../docs/NewConcept/90-decision-log.md) uses for settings. *It is what lets a
     * traffic light be data an author enters rather than work for a developer.*
     */
    public function kind(): ConverterKind;

    /**
     * Whether the original can be recovered from the characters.
     *
     * ⚠️ *Asked rather than assumed, because [R36](../../../docs/NewConcept/30-renderer.md) says the
     * difference has to be stated or somebody will build a search on a converter that cannot answer
     * one.*
     */
    public function isInvertible(): bool;

    /**
     * Which simple types it can map — the registry key, the same way a renderer's `handles()` is.
     *
     * @return list<SimpleType>
     */
    public function handles(): array;

    /**
     * The stored value as the characters a person reads.
     *
     * ⚠️ *Nothing stays nothing.* A missing value means *not answered*
     * ([D-232](../../../docs/NewConcept/90-decision-log.md)), and a converter that turned it into a
     * `0` or a dash would hide that for good — so callers hand in a value that holds something.
     */
    public function shown(TypedValue $value): string;

    /**
     * The characters a person wrote, as a value to store.
     *
     * ⚠️ **Only an invertible converter may be asked** ([D-076](../../../docs/NewConcept/90-decision-log.md)).
     * A lossy one throws rather than inventing a reading, because the one thing worse than refusing
     * input is accepting it as something else.
     *
     * @throws \Taxmod\Core\Exception\NotAValueOfThatType   when the characters are not this mapping's
     *                                                      own form — refused, never coerced
     * @throws \LogicException                              when the converter is lossy
     */
    public function written(string $characters, ?SimpleType $type = null): TypedValue;
}
