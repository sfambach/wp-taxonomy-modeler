<?php declare(strict_types=1);

namespace Taxmod\Core\Converter;

use Taxmod\Core\Exception\NotAPossibleTarget;
use Taxmod\Core\Model\SimpleType;

/**
 * Which converters exist, and which of them a given type may be given.
 *
 * ⚠️ **Two sides, and they are not the same question**
 * ([R33b](../../../docs/NewConcept/30-renderer.md#r33b--several-are-eligible-exactly-one-is-in-effect)):
 * *several may be **eligible** for a type — the stock side — and exactly **one** is in effect per
 * rendering.* This class answers the stock side; which one is in effect is a setting with a default
 * and a per-use-site override ([D-219](../../../docs/NewConcept/90-decision-log.md)).
 *
 * ⚠️ **No default per type, and that is the difference from the renderer registry.** A renderer must
 * always be resolved, because something has to draw the field
 * ([R33c](../../../docs/NewConcept/30-renderer.md#r33c--automatic-is-a-default-never-a-fact),
 * [D-352](../../../docs/NewConcept/90-decision-log.md)). **A converter has no such obligation**: no
 * converter means the value is shown as it is stored, which is a complete answer and not a gap. *So
 * there is nothing here to mark default, and inventing one would put a mapping on values nobody asked
 * to map.*
 *
 * ```mermaid
 * flowchart LR
 *   T["type"] --> E["eligible converters"]
 *   E --> C["the converter setting chooses one"]
 *   C --> F["in effect for this rendering"]
 * ```
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class ConverterRegistry
{
    /** @var array<string, Converter> */
    private array $byName = [];

    public function add(Converter $converter): void
    {
        $this->byName[$converter->name()] = $converter;
    }

    public function knows(string $name): bool
    {
        return isset($this->byName[$name]);
    }

    /**
     * ⚠️ *Refused rather than answered with the nearest thing.* A stored name nobody answers to is a
     * fault the settings side has to see — the same call `CD-10` makes everywhere: never a bare
     * `false` to signal failure.
     */
    public function byName(string $name): Converter
    {
        return $this->byName[$name] ?? throw NotAPossibleTarget::noConverterNamed($name);
    }

    /**
     * The converters a type may be given, in registration order.
     *
     * ⚠️ *A `null` type has none. A subject with no simple type is a record reference or a
     * composition, and mapping a value it does not hold would be answering a different question.*
     *
     * @return list<Converter>
     */
    public function eligibleFor(?SimpleType $type): array
    {
        if ($type === null) {
            return [];
        }

        $eligible = [];

        foreach ($this->byName as $converter) {
            if (in_array($type, $converter->handles(), true)) {
                $eligible[] = $converter;
            }
        }

        return $eligible;
    }

    /**
     * The converters that can also read what a person writes, for a type.
     *
     * ⚠️ *Separate from {@see self::eligibleFor()} because [R36](../../../docs/NewConcept/30-renderer.md)
     * says the difference has to be **stated**: a search box offering `> XII` needs the mapping to run
     * on the way in, and a lossy converter cannot answer that.*
     *
     * @return list<Converter>
     */
    public function invertibleFor(?SimpleType $type): array
    {
        return array_values(array_filter(
            $this->eligibleFor($type),
            static fn (Converter $one): bool => $one->isInvertible()
        ));
    }
}
