<?php declare(strict_types=1);

namespace Taxmod\Core\Description;

use Taxmod\Core\Event\Address;

/**
 * One field as a page shows it: where it is, which renderer and type draw it, its value as stored characters, its label,
 * whether it can be written or is hidden, and the messages that belong to it (plan §3).
 *
 * @see docs/pakete/renderer-ereignisse/plan.md
 */
final class FieldDescription implements Describes
{
    /** @param list<Message> $messages */
    public function __construct(
        public readonly Address $address,
        public readonly string $renderer,
        public readonly string $type,
        public readonly ?string $value,
        public readonly string $label,
        public readonly bool $writable = true,
        public readonly bool $hidden = false,
        public readonly array $messages = [],
    ) {
    }

    public function toArray(): array
    {
        return [
            'address'  => $this->address->token(),
            'renderer' => $this->renderer,
            'type'     => $this->type,
            'value'    => $this->value,
            'label'    => $this->label,
            'writable' => $this->writable,
            'hidden'   => $this->hidden,
            'messages' => array_map(static fn (Message $m): array => $m->toArray(), $this->messages),
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Address::fromToken((string) $data['address']),
            (string) $data['renderer'],
            (string) $data['type'],
            isset($data['value']) ? (string) $data['value'] : null,
            (string) $data['label'],
            (bool) $data['writable'],
            (bool) $data['hidden'],
            array_map(static fn (array $m): Message => Message::fromArray($m), (array) $data['messages']),
        );
    }
}
