<?php declare(strict_types=1);

namespace Taxmod\Core\Event;

/**
 * Where something happens in the model: which node, and within it which edge, which record, which field.
 *
 * ```mermaid
 * flowchart LR
 *   N["node"] --> E["edge?"]
 *   N --> R["record?"]
 *   R --> F["field?"]
 * ```
 *
 * Step 1 of the renderer plan ([D-628](../../../docs/NewConcept/90-decision-log.md),
 * [D-1424](../../../docs/NewConcept/90-decision-log.md)): a listener can only subscribe narrowly, and a complaint can only
 * stand at its field, once both name the same place.
 *
 * The token is safe inside an HTML attribute by construction (digits, lower-case letters, `_`, `-`, `:`), so the edge draws
 * it without escaping.
 *
 * @see docs/pakete/renderer-ereignisse/plan.md
 */
final class Address
{
    private const FIELD = '/^[a-z0-9_:-]+$/';

    public function __construct(
        public readonly int $nodeId,
        public readonly ?int $edgeId = null,
        public readonly ?int $recordId = null,
        public readonly ?string $field = null,
    ) {
        if ($nodeId <= 0 || ($edgeId !== null && $edgeId <= 0) || ($recordId !== null && $recordId <= 0)) {
            throw new \InvalidArgumentException('An address names positive ids only.');
        }

        if ($field !== null && preg_match(self::FIELD, $field) !== 1) {
            throw new \InvalidArgumentException('A field name in an address is a key, not text: ' . $field);
        }
    }

    public static function ofNode(int $nodeId): self
    {
        return new self($nodeId);
    }

    public static function ofEdge(int $nodeId, int $edgeId): self
    {
        return new self($nodeId, $edgeId);
    }

    public static function ofRecord(int $nodeId, int $recordId): self
    {
        return new self($nodeId, null, $recordId);
    }

    public function withEdge(int $edgeId): self
    {
        return new self($this->nodeId, $edgeId, $this->recordId, $this->field);
    }

    public function withRecord(int $recordId): self
    {
        return new self($this->nodeId, $this->edgeId, $recordId, $this->field);
    }

    public function withField(string $field): self
    {
        return new self($this->nodeId, $this->edgeId, $this->recordId, $field);
    }

    /** `node=12;edge=5;record=7;field=min` — only the parts that are set. */
    public function token(): string
    {
        return implode(';', array_filter([
            'node=' . $this->nodeId,
            $this->edgeId === null ? null : 'edge=' . $this->edgeId,
            $this->recordId === null ? null : 'record=' . $this->recordId,
            $this->field === null ? null : 'field=' . $this->field,
        ]));
    }

    public static function fromToken(string $token): self
    {
        $parts = [];

        foreach (explode(';', $token) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, null);

            if ($value === null || isset($parts[$key]) || ! in_array($key, ['node', 'edge', 'record', 'field'], true)) {
                throw new \InvalidArgumentException('Not an address: ' . $token);
            }

            $parts[$key] = $value;
        }

        $id = static function (?string $value) use ($token): ?int {
            if ($value === null) {
                return null;
            }

            if (preg_match('/^[1-9][0-9]*$/', $value) !== 1) {
                throw new \InvalidArgumentException('Not an address: ' . $token);
            }

            return (int) $value;
        };

        return new self($id($parts['node'] ?? '') ?? 0, $id($parts['edge'] ?? null), $id($parts['record'] ?? null), $parts['field'] ?? null);
    }

    public function equals(self $other): bool
    {
        return $this->token() === $other->token();
    }
}
