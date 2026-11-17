<?php declare(strict_types=1);

namespace Taxmod\Core\Description;

use Taxmod\Core\Event\Address;

/**
 * A message on a page — a key with named placeholders, never a sentence (`AR-2`); the edge makes the words. From a
 * {@see \Taxmod\Core\Addon\Complaint} it keeps its place, so it can stand at its field (plan §3, step 5).
 *
 * @see docs/pakete/renderer-ereignisse/plan.md
 */
final class Message implements Describes
{
    /** @param array<string, string> $placeholders */
    public function __construct(
        public readonly string $key,
        public readonly array $placeholders = [],
        public readonly ?Address $address = null,
    ) {
    }

    public static function fromComplaint(\Taxmod\Core\Addon\Complaint $complaint): self
    {
        return new self($complaint->key, $complaint->values, $complaint->address);
    }

    public function toArray(): array
    {
        return ['key' => $this->key, 'placeholders' => $this->placeholders, 'address' => $this->address?->token()];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['key'],
            array_map('strval', (array) ($data['placeholders'] ?? [])),
            isset($data['address']) ? Address::fromToken((string) $data['address']) : null,
        );
    }
}
