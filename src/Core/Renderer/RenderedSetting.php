<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

use Taxmod\Core\Model\ResolvedSetting;
use Taxmod\Core\Model\SettingKey;
use Taxmod\Core\Model\SettingShape;
use Taxmod\Core\Model\SimpleType;

/**
 * One setting, drawn — the settings side's answer to {@see RenderedField}.
 *
 * ⚠️ **A setting is not an attribute, so it does not borrow that class.** An attribute is an edge
 * pointing at a type; a setting is a key resolved along a chain, and it carries **where it came
 * from** ([D-079](../../../docs/NewConcept/90-decision-log.md)), which an attribute has no notion
 * of. Sharing one class would have meant a null edge on every row.
 *
 * ⚠️ **`result` is null when the key is a *choice* rather than a value** — multiplicity's four
 * constants, or a name a registry answers to. Those want a chooser, one is decided
 * ([D-244](../../../docs/NewConcept/90-decision-log.md)) and none is built, so they keep their own
 * controls rather than pretending to be fields.
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class RenderedSetting
{
    public function __construct(
        public readonly string $key,
        public readonly SettingShape $shape,
        public readonly ?SimpleType $type,
        public readonly ResolvedSetting $setting,
        public readonly ?RenderResult $result = null,
        public readonly ?string $rendererName = null,
    ) {
    }

    /** Whether a control was drawn, or whether the caller has to offer a set instead. */
    public function wasDrawn(): bool
    {
        return $this->result !== null;
    }

    /**
     * Whether the engine owns this key.
     *
     * ⚠️ **Asked of {@see SettingKey}, never inferred from the value.** A free key belongs to
     * whoever made it and the engine knows nothing of its type, so nothing is drawn for it and its
     * characters are printed. Reading a type off whatever value happens to be stored is exactly
     * the guessing [D-354](../../../docs/NewConcept/90-decision-log.md) was written to end.
     */
    public function isEngineOwned(): bool
    {
        return SettingKey::isReserved($this->key);
    }
}
