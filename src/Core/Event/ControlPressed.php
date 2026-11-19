<?php declare(strict_types=1);

namespace Taxmod\Core\Event;

/**
 * A person pressed a control — the trigger «Bedienelement betätigt» of plan §2, named by the control's value (`save_record`,
 * `move` …). The listener for that name does the act and leaves its outcome here; the page reads it back.
 *
 * ```mermaid
 * flowchart LR
 *   P["POST do=…"] --> E["ControlPressed(name, address)"] --> L["the one listener for that name"] --> O["outcome · stay"]
 * ```
 *
 * ⚠️ *One class, not one per name: the provider chooses by class **and** name (plan §2, [D-633](../../../docs/NewConcept/90-decision-log.md)).*
 *
 * @see docs/pakete/renderer-ereignisse/plan.md
 */
final class ControlPressed implements Named
{
    use EventId;

    private mixed $outcome = null;

    private bool $answered = false;

    private ?int $stay = null;

    /** @var list<\Taxmod\Core\Addon\Complaint> */
    private array $complaints = [];

    public function __construct(
        private readonly string $name,
        public readonly ?Address $address = null,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * What the act gave back — a message, a node, a record, a result; the page decides what it shows.
     *
     * `mixed` because the acts answer with what their service returns, and the page has always read it that way.
     */
    public function answer(mixed $outcome): mixed
    {
        if ($this->answered) {
            throw new \LogicException("«{$this->name}» was answered twice — two listeners did the same act.");
        }

        $this->answered = true;
        $this->outcome  = $outcome;

        return $outcome;
    }

    /** The node the page opens on afterwards, for an act whose point is «now work on that one». */
    public function stayAt(int $nodeId): int
    {
        $this->stay = $nodeId;

        return $nodeId;
    }

    public function outcome(): mixed
    {
        return $this->outcome;
    }

    public function stay(): ?int
    {
        return $this->stay;
    }

    /** What the act's listeners had to complain about — all of them, each with its place (plan step 5, D-632). */
    public function complain(\Taxmod\Core\Addon\Complaint ...$complaints): void
    {
        array_push($this->complaints, ...$complaints);
    }

    /** @return list<\Taxmod\Core\Addon\Complaint> */
    public function complaints(): array
    {
        return $this->complaints;
    }
}
