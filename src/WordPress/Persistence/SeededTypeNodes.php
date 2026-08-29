<?php declare(strict_types=1);

namespace Taxmod\WordPress\Persistence;

use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Repository\FrameworkNodes;
use Taxmod\Core\Repository\NodeRepository;
use Taxmod\Core\Repository\TypeNodes;

/**
 * The seeded data type nodes, found by the ids the seed wrote into options.
 *
 * ⚠️ **The same shape as {@see SeededFrameworkNodes}, deliberately** ([D-510](../../../docs/NewConcept/90-decision-log.md)):
 * an option per node, written when the node is made. *The third technique was already lying there
 * and it is the right one — `taxmod_root_id`, `taxmod_branch_model_id`, `taxmod_role_symbol` — and
 * a rename breaks none of them.*
 *
 * ⚠️ **The one difference from the framework nodes: these may be thrown away.** A base scaffold is
 * imported once and is afterwards ordinary authored content ([D-119](../../../docs/NewConcept/90-decision-log.md)),
 * so an option here may point at a node that has been trashed or purged. *That is why nothing in
 * this class reads a node to answer — it answers with an id, and the caller that needs the node
 * looks for it where it should be and gets null when it is gone.*
 *
 * @see docs/NewConcept/50-wordpress-persistence.md
 */
final class SeededTypeNodes implements TypeNodes
{
    public const OPTION_PREFIX = 'taxmod_type_';

    /** Named like the ones that were already right: `taxmod_type_int_id` beside `taxmod_root_id`. */
    public static function optionFor(SimpleType $type): string
    {
        return self::OPTION_PREFIX . $type->value . '_id';
    }

    public function __construct(
        // ⚠️ *Only the Notnagel reads nodes. Nothing on the id path touches the database beyond the
        // options, which are autoloaded.*
        private readonly NodeRepository $nodes,
        private readonly FrameworkNodes $framework,
    ) {
    }

    /**
     * @var array<string,int>|null The ids this request has read, by {@see SimpleType::$value}.
     *                             One read of the options per object, because the ancestor walk in
     *                             {@see \Taxmod\Core\Service\Rendering} asks once per level and a
     *                             query per level is the loop `CD-7` forbids.
     */
    private ?array $ids = null;

    public function nodeId(SimpleType $type): ?int
    {
        return $this->ids()[$type->value] ?? null;
    }

    public function typeOf(int $nodeId): ?SimpleType
    {
        if ($nodeId <= 0) {
            return null;
        }

        $value = array_search($nodeId, $this->ids(), true);

        return $value === false ? null : SimpleType::from($value);
    }

    public function remember(SimpleType $type, int $nodeId): void
    {
        update_option(self::optionFor($type), $nodeId, true);

        // ⚠️ *Only when the cache is already built. Seeding it with a single entry would make every
        // other type answer «not written down» without the options ever having been read.*
        if ($this->ids !== null) {
            $this->ids[$type->value] = $nodeId;
        }
    }

    /** @return array<string,int> */
    private function ids(): array
    {
        if ($this->ids !== null) {
            return $this->ids;
        }

        $ids     = [];
        $missing = [];

        foreach (SimpleType::cases() as $type) {
            $id = (int) get_option(self::optionFor($type), 0);

            if ($id > 0) {
                $ids[$type->value] = $id;
            } else {
                $missing[] = $type;
            }
        }

        return $this->ids = $missing === [] ? $ids : $this->fromTheirNames($ids, $missing);
    }

    /**
     * The Notnagel — and it writes the id down so it is not needed a second time.
     *
     * ⚠️ **Without this an upgrade would be a loss** ([D-510](../../../docs/NewConcept/90-decision-log.md)):
     * an installation seeded before the decision has no option for any type, and a lookup that only
     * knew ids would report every seeded type as absent.
     *
     * ⚠️ *One query for the whole branch, not one per missing type — and it runs once per
     * installation, because the last thing it does is make itself unnecessary.*
     *
     * @param  array<string,int> $ids     What the options already answered.
     * @param  list<SimpleType>  $missing The types no option pointed at.
     * @return array<string,int>
     */
    private function fromTheirNames(array $ids, array $missing): array
    {
        $wanted = [];

        foreach ($missing as $type) {
            $wanted[$type->value] = true;
        }

        foreach ($this->nodes->childrenOf($this->framework->rootOf(Branch::DataTypes)) as $child) {
            $type = SimpleType::fromNodeName($child->name);

            // ⚠️ *A node another type already answers with is not this one. Two nodes can carry the
            // same name (D-022), and `int` beside `Integer` is exactly the pair this survived.*
            if ($type === null || ! isset($wanted[$type->value]) || in_array($child->id, $ids, true)) {
                continue;
            }

            $ids[$type->value] = $child->id;
            unset($wanted[$type->value]);

            $this->remember($type, $child->id);
        }

        return $ids;
    }
}
