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
