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
        public readonly TypedValue $value,
        /**
         * **Which place this answers for**, empty for the owner itself.
         *
         * ⚠️ **An address, not a multiplicity** ([D-409](../../../docs/NewConcept/90-decision-log.md)).
         * One key still holds one answer at one place; `path` says **which** place — which
         * attribute of this node, which member of a composed value. *Several rows for one key at
         * one place would be a multiplicity, and a setting has none.*
         *
         * ⚠️ **Empty means «the owner itself»**, which is why every row written before the column
         * existed keeps its meaning untouched. *Same choice `labels.path` made, and the reason four
         * decisions could assume this column existed without noticing it did not
         * ([OQ-092](../../../docs/NewConcept/91-open-questions.md)).*
         */
        public readonly string $path = '',
    ) {
    }

    /** The engine's own key, or null when it is a free one belonging to whoever made it. */
    public function engineKey(): ?SettingKey
    {
        return SettingKey::tryFrom($this->key);
    }
}
