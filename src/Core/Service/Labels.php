<?php declare(strict_types=1);

namespace Taxmod\Core\Service;

use Taxmod\Core\Model\Label;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\SeededRole;
use Taxmod\Core\Repository\FrameworkNodes;
use Taxmod\Core\Repository\LabelRepository;

/**
 * What a thing is called — and what to say when nobody has said.
 *
 * ```mermaid
 * flowchart LR
 *   A["role · number"] --> B["role · one"] --> C["help · one"] --> D["node.name"]
 * ```
 *
 * ⚠️ **Number before role** (D-153): a missing plural form falls back to the base form of the
 * **same** role before giving up on the role. *Resistances* falling back to *Resistance* is a
 * near miss; falling back to the help text is a different word entirely.
 *
 * ⚠️ **The chain ends on `node.name` and never on nothing** (D-020, D-209). A screen with an
 * empty cell where a name should be is worse than a screen showing the internal name — and the
 * internal name is always there, because a node cannot exist without one (D-022).
 *
 * @see docs/NewConcept/40-i18n.md
 */
final class Labels
{
    public function __construct(
        private readonly LabelRepository $labels,
        private readonly FrameworkNodes $framework,
    ) {
    }

    /**
     * What to show for a node, in a role and a locale.
     *
     * @param string $number A plural category; the base form when it does not matter.
     */
    public function of(
        Node $node,
        SeededRole $role = SeededRole::Form,
        string $locale = '',
        string $number = Label::BASE_NUMBER,
        string $path = '',
    ): string {
        $stored = $this->indexed($this->labels->forOwners([$node->id]), $path);

        $roleId = $this->framework->roleId($role);
        $helpId = $this->framework->roleId(SeededRole::Help);

        foreach ($this->attempts($roleId, $helpId, $number, $locale) as [$tryRole, $tryNumber, $tryLocale]) {
            $found = $stored[$tryRole . "\0" . $tryNumber . "\0" . $tryLocale] ?? null;

            if ($found !== null && $found->text !== '') {
                return $found->text;
            }
        }

        return $node->name;
    }

    /**
     * What to show for a whole set of nodes, in one query.
     *
     * ⚠️ **This exists because a renderer may not fetch.** A reference is drawn as *the target's
     * label* ([D-105](../../../docs/NewConcept/90-decision-log.md)), and a renderer is handed
     * everything it needs and reaches out to nothing
     * ([D-159](../../../docs/NewConcept/90-decision-log.md)) — so the labels have to be resolved
     * **before** the descent, for every reference at once. Asking per reference would be a query
     * per row of a parts list, which is the loop `CD-7` forbids.
     *
     * @param  list<Node>          $nodes
     * @return array<int, string>  Keyed by node id.
     */
    public function forNodes(
        array $nodes,
        SeededRole $role = SeededRole::Form,
        string $locale = '',
        string $number = Label::BASE_NUMBER,
    ): array {
        if ($nodes === []) {
            return [];
        }

        // ⚠️ Grouped by owner, because `indexed()` flattens for a single one — a batch that used
        // it would give every node the last node's labels, which is the kind of fault that shows
        // up as *the wrong name on one row* and gets blamed on the data.
        $stored = [];

        foreach ($this->labels->forOwners(array_map(static fn (Node $n): int => $n->id, $nodes)) as $label) {
            if ($label->path !== '') {
                continue;
            }

            $stored[$label->ownerId][$label->roleId . "\0" . $label->number . "\0" . $label->locale] = $label;
        }

        $roleId = $this->framework->roleId($role);
        $helpId = $this->framework->roleId(SeededRole::Help);
        $order  = $this->attempts($roleId, $helpId, $number, $locale);

        $found = [];

        foreach ($nodes as $node) {
            $found[$node->id] = $node->name;

            foreach ($order as [$tryRole, $tryNumber, $tryLocale]) {
                $label = $stored[$node->id][$tryRole . "\0" . $tryNumber . "\0" . $tryLocale] ?? null;

                if ($label !== null && $label->text !== '') {
                    $found[$node->id] = $label->text;

                    break;
                }
            }
        }

        return $found;
    }

    /**
     * The order the chain is tried in.
     *
     * ⚠️ **The locale falls back to the neutral row before the role gives way.** A label stored
     * without a locale is one somebody wrote for everybody; using it beats dropping to a
     * different role, which would answer a different question.
     *
     * @return list<array{0: int, 1: string, 2: string}>
     */
    private function attempts(int $roleId, int $helpId, string $number, string $locale): array
    {
        $locales = $locale === '' ? [''] : [$locale, ''];
        $numbers = $number === Label::BASE_NUMBER ? [Label::BASE_NUMBER] : [$number, Label::BASE_NUMBER];

        $order = [];

        foreach ($numbers as $tryNumber) {
            foreach ($locales as $tryLocale) {
                $order[] = [$roleId, $tryNumber, $tryLocale];
            }
        }

        foreach ($locales as $tryLocale) {
            $order[] = [$helpId, Label::BASE_NUMBER, $tryLocale];
        }

        return $order;
    }

    /** Write one label. */
    public function put(Label $label): void
    {
        $this->labels->put($label);
    }

    /** @return list<Label> Everything stored for this owner, for a screen that lists them. */
    public function storedFor(int $ownerId): array
    {
        return $this->labels->forOwners([$ownerId]);
    }

    /**
     * @param list<Label> $labels
     *
     * @return array<string,Label>
     */
    private function indexed(array $labels, string $path): array
    {
        $byKey = [];

        foreach ($labels as $label) {
            if ($label->path !== $path) {
                continue;
            }

            $byKey[$label->roleId . "\0" . $label->number . "\0" . $label->locale] = $label;
        }

        return $byKey;
    }
}
