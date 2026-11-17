<?php declare(strict_types=1);

namespace Taxmod\Core\Description;

use Taxmod\Core\Event\Address;

/**
 * One thing a person can do — what {@see \Taxmod\Core\Renderer\Control} already says, without markup (plan §3: «`Control`
 * zieht als `BedienelementBeschreibung` fast unverändert um»).
 *
 * @see docs/pakete/renderer-ereignisse/plan.md
 */
final class ControlDescription implements Describes
{
    public function __construct(
        public readonly string $name,
        public readonly string $value,
        public readonly string $label,
        public readonly bool $available = true,
        public readonly bool $destroys = false,
        public readonly string $icon = '',
        public readonly ?Address $address = null,
    ) {
    }

    public static function fromControl(\Taxmod\Core\Renderer\Control $control): self
    {
        return new self($control->name, $control->value, $control->label, $control->available, $control->destroys, $control->icon, $control->address);
    }

    public function toArray(): array
    {
        return [
            'name'      => $this->name,
            'value'     => $this->value,
            'label'     => $this->label,
            'available' => $this->available,
            'destroys'  => $this->destroys,
            'icon'      => $this->icon,
            'address'   => $this->address?->token(),
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['name'],
            (string) $data['value'],
            (string) $data['label'],
            (bool) $data['available'],
            (bool) $data['destroys'],
            (string) $data['icon'],
            isset($data['address']) ? Address::fromToken((string) $data['address']) : null,
        );
    }
}
