<?php declare(strict_types=1);

namespace Taxmod\Core\Description;

use Taxmod\Core\Event\Address;

/**
 * One page as the core describes it — one per page, not per renderer ([D-628](../../../docs/NewConcept/90-decision-log.md)),
 * typed objects and not text ([D-629](../../../docs/NewConcept/90-decision-log.md)). An edge drawer draws it; a second, tiny
 * one proves it carries everything ([D-636](../../../docs/NewConcept/90-decision-log.md)).
 *
 * ```mermaid
 * flowchart LR
 *   P[PageDescription] --> S[SectionDescription] --> R[RowDescription] --> F[FieldDescription]
 *   R --> C[ControlDescription]
 *   P --> M[Message]
 *   P -->|"only where it leaves the process"| J[JSON]
 * ```
 *
 * @see docs/pakete/renderer-ereignisse/plan.md
 */
final class PageDescription implements Describes
{
    /**
     * @param list<SectionDescription> $sections
     * @param list<Message>            $messages
     */
    public function __construct(
        public readonly string $title,
        public readonly Address $address,
        public readonly array $sections = [],
        public readonly array $messages = [],
    ) {
    }

    public function toArray(): array
    {
        return [
            'title'    => $this->title,
            'address'  => $this->address->token(),
            'sections' => array_map(static fn (SectionDescription $s): array => $s->toArray(), $this->sections),
            'messages' => array_map(static fn (Message $m): array => $m->toArray(), $this->messages),
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['title'],
            Address::fromToken((string) $data['address']),
            array_map(static fn (array $s): SectionDescription => SectionDescription::fromArray($s), (array) $data['sections']),
            array_map(static fn (array $m): Message => Message::fromArray($m), (array) $data['messages']),
        );
    }

    /** The written form, for where the description leaves the process (a block, REST). */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function fromJson(string $json): self
    {
        return self::fromArray((array) json_decode($json, true, 512, JSON_THROW_ON_ERROR));
    }
}
