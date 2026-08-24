<?php declare(strict_types=1);

namespace Taxmod\Core\Service;

use Taxmod\Core\Exception\CannotWiden;
use Taxmod\Core\Exception\ReservedKey;
use Taxmod\Core\Model\Narrowing;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\ResolvedSetting;
use Taxmod\Core\Model\Setting;
use Taxmod\Core\Model\SettingKey;
use Taxmod\Core\Model\SettingValue;
use Taxmod\Core\Repository\FrameworkNodes;
use Taxmod\Core\Repository\NodeRepository;
use Taxmod\Core\Repository\SettingRepository;

/**
 * The resolution chain: installation → model root → ancestors → node → use site.
 *
 * ```mermaid
 * flowchart LR
 *   I["installation"] --> R["model root"] --> A["ancestors"] --> N["node"] --> U["use site"]
 * ```
 *
 * ⚠️ **Walked key by key**, so a consumer may take a mix (D-079, D-093): the renderer from the
 * type, the multiplicity from the use site, the icon from three levels up. It is not one link
 * winning the whole set.
 *
 * ⚠️ **`model root → ancestors → node` is the node's path**, in order. That is what makes the
 * walk one indexed lookup rather than a climb (D-014), and why the chain is known before the
 * first query is sent.
 *
 * @see docs/NewConcept/10-domain-core.md
 */
final class Settings
{
    public function __construct(
        private readonly SettingRepository $settings,
        private readonly NodeRepository $nodes,
        private readonly FrameworkNodes $framework,
    ) {
    }

    /**
     * The chain for a node: the installation, then every step of its path.
     *
     * @return list<int>
     */
    public function chainFor(Node $node): array
    {
        return [$this->framework->installationId(), ...$node->ancestorIds(), $node->id];
    }

    /**
     * The chain for a use site: the target's chain, then the edge itself.
     *
     * ⚠️ **The use site is the last link and therefore the strongest** — which is exactly what
     * makes *a configured default plus a choice in the moment* one pattern rather than two
     * (D-032): the two ends of one walk that already exists.
     *
     * @return list<int>
     */
    public function chainForUseSite(Relation $edge): array
    {
        return [...$this->chainFor($this->nodes->byId($edge->toId)), $edge->id];
    }

    /**
     * Resolve every setting along a chain, key by key.
     *
     * @param list<int> $chain
     *
     * @return array<string,ResolvedSetting>
     */
    public function resolve(array $chain): array
    {
        $position = array_flip($chain);
        $winner   = [];

        // One query for the whole chain (D-014), then the walk happens in memory.
        foreach ($this->settings->forOwners($chain) as $setting) {
            $at = $position[$setting->ownerId] ?? null;

            if ($at === null) {
                continue;
            }

            $known = $winner[$setting->key] ?? null;

            if ($known === null || $at >= $known[1]) {
                $winner[$setting->key] = [$setting, $at];
            }
        }

        $last     = count($chain) - 1;
        $resolved = [];

        foreach ($winner as $key => [$setting, $at]) {
            $resolved[$key] = new ResolvedSetting($key, $setting->value, $setting->ownerId, $at === $last);
        }

        return $resolved;
    }

    /**
     * Write a setting at one link of a chain, refusing anything that widens a bound.
     *
     * @param list<int> $chain The chain the owner is the **last** link of.
     */
    public function put(array $chain, string $key, SettingValue $value): void
    {
        $ownerId = $chain[count($chain) - 1];
        $engine  = SettingKey::tryFrom($key);

        if ($engine === null) {
            // A free key may be anything that is not one of the engine's names (D-084).
            $this->settings->put(new Setting($ownerId, $key, $value));

            return;
        }

        $this->refuseWidening($engine, $chain, $value);

        $this->settings->put(new Setting($ownerId, $key, $value));
    }

    /**
     * Declare a free setting, refusing one of the engine's names.
     *
     * ⚠️ Separate from {@see put()} because the check belongs where a **new name** is invented,
     * not where a known one is written.
     */
    public function declareFree(array $chain, string $key, SettingValue $value): void
    {
        if (SettingKey::isReserved($key)) {
            throw ReservedKey::named($key);
        }

        $this->put($chain, $key, $value);
    }

    /**
     * Make a setting inherited again — the row disappears (D-266).
     *
     * ⚠️ **Not the same as writing nothing.** After a reset, later changes at the base arrive
     * here once more; after *set to nothing*, they deliberately do not.
     */
    public function reset(int $ownerId, string $key): void
    {
        $this->settings->forget($ownerId, $key);
    }

    /**
     * ⚠️ **Compared against what the chain says *above* this link**, not against the resolved
     * value — otherwise a link would be measured against itself and every write would pass.
     *
     * @param list<int> $chain
     */
    private function refuseWidening(SettingKey $key, array $chain, SettingValue $value): void
    {
        $above     = array_slice($chain, 0, -1);
        $inherited = $this->resolve($above)[$key->value] ?? null;

        if ($inherited === null || $inherited->value->isNothing()) {
            return;
        }

        $was = $inherited->value;

        switch ($key->direction()) {
            case Narrowing::OnceOnAlwaysOn:
                // D-311: what an ancestor declares mandatory stays mandatory for every
                // descendant. The same shape holds for hide and read_only.
                if ($was->asBool() && ! $value->asBool()) {
                    throw CannotWiden::mandatoryStays($key);
                }

                break;

            case Narrowing::OnlyUp:
                if ($this->lessThan($value, $was)) {
                    throw CannotWiden::bound($key, $was->describe(), $value->describe());
                }

                break;

            case Narrowing::OnlyDown:
                if ($this->lessThan($was, $value)) {
                    throw CannotWiden::bound($key, $was->describe(), $value->describe());
                }

                break;

            case Narrowing::Free:
                break;
        }
    }

    /**
     * Numeric comparison over whichever typed column carries the number.
     *
     * ⚠️ Decimals are compared as **numbers**, not as strings — `9` is not greater than `10`
     * merely because it starts with a nine. They are stored exactly (D-057), and a comparison
     * that spans the two columns is the one place they meet.
     */
    private function lessThan(SettingValue $a, SettingValue $b): bool
    {
        $left  = $a->int ?? ($a->decimal !== null ? (float) $a->decimal : null);
        $right = $b->int ?? ($b->decimal !== null ? (float) $b->decimal : null);

        if ($left === null || $right === null) {
            return false;
        }

        return $left < $right;
    }
}
