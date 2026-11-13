<?php declare(strict_types=1);

namespace Taxmod\Core\Model\Setting;

use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\NodeClass\AttributeDeclaration;
use Taxmod\Core\Model\NodeClass\AttributeType;

/**
 * Properties a module declares for a group of the model (D-1259): yes/no, e.g. «test after goods-in», or an id, e.g. the default
 * storage place (a record) — kept as node settings, inherited by every node below until one says otherwise. The rows carry this
 * class as their declaring class.
 *
 * ```mermaid
 * flowchart LR
 *   M["Material: test = yes"] --> B["Bauteile (nothing set) → yes"]
 *   B --> K["Kabel: test = no → no"]
 * ```
 *
 * @see docs/NewConcept/90-decision-log.md D-1259
 */
final class NodeProperties
{
    public const YES_NO = 'bool';

    /** A record or node id; the module says which, the setting only keeps the number. */
    public const ID = 'id';

    /** A plain whole number, e.g. a rank (D-1341). */
    public const NUMBER = 'number';

    /** @var array<string, string> key → YES_NO, ID or NUMBER */
    private readonly array $types;

    /** @param array<string, string> $types the keys modules have declared, each with YES_NO, ID or NUMBER */
    public function __construct(array $types)
    {
        $this->types = array_filter($types, static fn (string $type, string|int $key): bool => preg_match('/^[a-z0-9_]+$/', (string) $key) === 1 && in_array($type, [self::YES_NO, self::ID, self::NUMBER], true), ARRAY_FILTER_USE_BOTH);
    }

    public function declares(string $key): bool
    {
        return isset($this->types[$key]);
    }

    public function declaration(string $key): ?AttributeDeclaration
    {
        return match ($this->types[$key] ?? null) {
            self::YES_NO => new AttributeDeclaration(self::class, $key, AttributeType::Bool),
            self::ID, self::NUMBER => new AttributeDeclaration(self::class, $key, AttributeType::Int),
            default      => null,
        };
    }

    /**
     * The value that holds at each node: its own, else the nearest ancestor's; null where no node on the way says anything.
     *
     * @param list<Node>          $nodes
     * @param list<SettingsValue> $rows  the rows named after the key, from anywhere in the model
     * @return array<int, bool|int|null> node id → value: a yes/no as bool, an id as int
     */
    public function inherited(array $nodes, array $rows, string $key): array
    {
        $own = $this->own($rows, $key);

        $out = [];

        foreach ($nodes as $node) {
            $out[$node->id] = null;

            foreach (array_reverse([...$node->ancestorIds(), $node->id]) as $id) {
                if (array_key_exists($id, $own)) {
                    $out[$node->id] = $own[$id];

                    break;
                }
            }
        }

        return $out;
    }

    /**
     * The values nodes carry themselves, without inheritance — so a form can tell «own yes» from «yes from above».
     *
     * @param list<SettingsValue> $rows the rows named after the key, from anywhere in the model
     * @return array<int, bool|int> node id → own value; nodes without one are left out
     */
    public function own(array $rows, string $key): array
    {
        $own = [];

        foreach ($rows as $row) {
            if ($row->klasse === self::class && $row->attribut === $key && $row->aktiv && $row->relationId === null && $row->nodeId !== null && $row->value->int !== null) {
                $own[$row->nodeId] = ($this->types[$key] ?? self::YES_NO) !== self::YES_NO ? $row->value->int : $row->value->int === 1;
            }
        }

        return $own;
    }
}
