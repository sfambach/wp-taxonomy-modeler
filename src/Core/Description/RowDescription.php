<?php declare(strict_types=1);

namespace Taxmod\Core\Description;

use Taxmod\Core\Event\Address;

/**
 * One row: a tree line, a field row, a record — its depth, its place, its fields and its controls (plan §3).
 *
 * @see docs/pakete/renderer-ereignisse/plan.md
 */
final class RowDescription implements Describes
{
    /**
     * @param list<FieldDescription>   $fields
     * @param list<ControlDescription> $controls
     */
    public function __construct(
        public readonly int $depth,
        public readonly Address $address,
        public readonly array $fields = [],
        public readonly array $controls = [],
    ) {
    }

    public function toArray(): array
    {
        return [
            'depth'    => $this->depth,
            'address'  => $this->address->token(),
            'fields'   => array_map(static fn (FieldDescription $f): array => $f->toArray(), $this->fields),
            'controls' => array_map(static fn (ControlDescription $c): array => $c->toArray(), $this->controls),
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (int) $data['depth'],
            Address::fromToken((string) $data['address']),
            array_map(static fn (array $f): FieldDescription => FieldDescription::fromArray($f), (array) $data['fields']),
            array_map(static fn (array $c): ControlDescription => ControlDescription::fromArray($c), (array) $data['controls']),
        );
    }
}
