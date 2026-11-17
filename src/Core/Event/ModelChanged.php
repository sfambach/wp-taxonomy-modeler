<?php declare(strict_types=1);

namespace Taxmod\Core\Event;

/**
 * Something in the model changed — sent by the service that changed it, written into the chronicle by one listener
 * ({@see JournalListener}). Four kinds, the owner's division ([D-631](../../../docs/NewConcept/90-decision-log.md)): node,
 * edge, node record, edge record (a value of a record at an edge).
 *
 * ```mermaid
 * flowchart LR
 *   S["ModelEditor · Labels · DataEntry · SettingsEditor"] -->|dispatch| D[Dispatcher] --> J[JournalListener] --> C[(changelog)]
 *   J -->|"act number"| S
 * ```
 *
 * ⚠️ *The payload stays what the chronicle already stores — before and after as written frozen state — so the chronicle is
 * the same row for row (`journal-parity-check`). The act number comes back on the event: some services hand it on.*
 *
 * @see docs/pakete/renderer-ereignisse/plan.md
 */
abstract class ModelChanged
{
    private ?int $act = null;

    final public function __construct(
        public readonly int $ownerId,
        public readonly string $what,
        public readonly ?string $before,
        public readonly ?string $after,
        /** The version this change produced — no default ([D-634](../../../docs/NewConcept/90-decision-log.md)); null only where a row has none. */
        public readonly ?int $version,
        public readonly ?int $changeGroupId = null,
    ) {
    }

    /** The chronicle's word for the owner — `node`, `relation`, `record`, `record_value`. */
    abstract public function ownerKind(): string;

    /** @return class-string<self> */
    public static function classFor(string $ownerKind): string
    {
        return match ($ownerKind) {
            'node'         => NodeChanged::class,
            'relation'     => EdgeChanged::class,
            'record'       => NodeRecordChanged::class,
            'record_value' => EdgeRecordChanged::class,
            default        => throw new \InvalidArgumentException("No model event for owner kind «{$ownerKind}»."),
        };
    }

    public static function of(string $ownerKind, int $ownerId, string $what, ?string $before, ?string $after, ?int $version, ?int $changeGroupId = null): self
    {
        $class = self::classFor($ownerKind);

        return new $class($ownerId, $what, $before, $after, $version, $changeGroupId);
    }

    /** Set once, by the listener that wrote the row. */
    public function settle(int $act): void
    {
        if ($this->act !== null) {
            throw new \LogicException('This change was written already.');
        }

        $this->act = $act;
    }

    /** The act the row belongs to; a change nobody wrote has none. */
    public function act(): int
    {
        return $this->act ?? throw new \LogicException("Nobody wrote «{$this->what}» into the chronicle.");
    }
}
