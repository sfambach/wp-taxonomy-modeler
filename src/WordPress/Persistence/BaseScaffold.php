<?php declare(strict_types=1);

namespace Taxmod\WordPress\Persistence;

use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Repository\FrameworkNodes;
use Taxmod\Core\Service\ModelEditor;

/**
 * The base scaffold — the simple data types, imported **once**.
 *
 * ⚠️ **Once, and then hands off** (D-119). After the import these are ordinary authored content:
 * a model that never needs `color` may throw it away, and reactivating the plugin must not bring
 * it back. That is what the stored version guards — not *do the nodes exist*, but *has the
 * scaffold been delivered*. Asking the first question instead would quietly undo the owner's
 * deletions on every activation, which is exactly the kind of helpfulness nobody asked for.
 *
 * ⚠️ **Not framework-protected** ([D-194](../../../docs/NewConcept/90-decision-log.md)):
 * protection covers the handful of nodes the machinery stands on, and a data type is not one of
 * them.
 *
 * ⚠️ **Composed types are not here yet.** `quantity`, `money`, `range`, `period`, `tolerance`,
 * `ratio`, `address`, `markup` and `Link` are composed *of* attributes, so seeding them means
 * seeding edges — a bigger step, and one that wants the renderers to be worth looking at.
 *
 * @see docs/NewConcept/10-domain-core.md
 */
final class BaseScaffold
{
    public const OPTION = 'taxmod_base_scaffold';

    /** Raise it only to deliver something genuinely new; every raise re-enters every install. */
    public const VERSION = 1;

    public function __construct(
        private readonly ModelEditor $editor,
        private readonly FrameworkNodes $framework,
    ) {
    }

    /** @return list<string> The names actually created, so a caller can report what it did. */
    public function importOnce(): array
    {
        if ((int) get_option(self::OPTION, 0) >= self::VERSION) {
            return [];
        }

        $created = $this->import();

        update_option(self::OPTION, self::VERSION, true);

        return $created;
    }

    /**
     * Create what is not there, whatever the stored version says.
     *
     * Separate from {@see importOnce()} so a check script can call it directly — and so the
     * *once* is a decision of the caller rather than something buried in the writing.
     *
     * @return list<string>
     */
    public function import(): array
    {
        $dataTypes = $this->framework->rootOf(Branch::DataTypes);

        $taken = [];

        foreach ($this->editor->childrenOf($dataTypes->id) as $child) {
            $taken[$child->name] = true;
        }

        $created = [];

        foreach (SimpleType::cases() as $type) {
            if (isset($taken[$type->value])) {
                continue;
            }

            $this->editor->createNode($type->value, $dataTypes->id);
            $created[] = $type->value;
        }

        return $created;
    }
}
