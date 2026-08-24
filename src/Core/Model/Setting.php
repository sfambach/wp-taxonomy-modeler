<?php declare(strict_types=1);

namespace Taxmod\Core\Model;

/**
 * One stored setting: whose it is, what it is called, and what it holds.
 *
 * ⚠️ **An override is the same thing wherever it sits; only its owner differs** (D-087). There
 * is no separate *node override* and *use-site override* — one construct, a different
 * `owner_id`. That is why this class has no notion of which link of the chain it belongs to.
 *
 * @see docs/NewConcept/10-domain-core.md
 */
final class Setting
{
    public function __construct(
        public readonly int $ownerId,
        public readonly string $key,
        public readonly SettingValue $value,
    ) {
    }

    /** The engine's own key, or null when it is a free one belonging to whoever made it. */
    public function engineKey(): ?SettingKey
    {
        return SettingKey::tryFrom($this->key);
    }
}
