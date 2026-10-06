<?php declare(strict_types=1);

namespace Taxmod\Core\Converter;

/**
 * The four forms a mapping can take — [R33a](../../../docs/NewConcept/30-renderer.md#r33a--there-are-a-few-kinds-of-converter-parameterised-by-data).
 *
 * ⚠️ **The engine branches on the *form* of the mapping and never on its content**
 * ([D-148](../../../docs/NewConcept/90-decision-log.md)). *That is what turns a traffic light into
 * data an author enters instead of work for a developer — and it is the same criterion
 * [D-085](../../../docs/NewConcept/90-decision-log.md) uses to decide what may be a setting.*
 *
 * ⚠️ *Four, and the concept says four cover every case raised so far. A fifth needs a case, not a
 * hunch (`PR-4`).*
 *
 * @see docs/NewConcept/30-renderer.md
 */
enum ConverterKind: string
{
    /** A table: AWG ↔ mm², digit ↔ colour. */
    case Lookup = 'lookup';

    /** Bounds to an output: a traffic light, tolerance classes, size charts. */
    case Threshold = 'threshold';

    /** A factor and a unit: metric prefixes, the capacitor code. */
    case Scale = 'scale';

    /** A pattern: dates, part numbers. */
    case Format = 'format';
}
