<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\SettingKey;

/**
 * The fallback — what draws a value when nothing better has been chosen.
 *
 * ⚠️ **A fallback is not a *default*.** The default renderer of a type is a setting somebody
 * chose; this is what answers when the whole chain is silent (D-091, D-168). It must therefore
 * fit **everything** and be dull on purpose: the moment it starts making decisions it becomes a
 * second way to draw a field, which is exactly what [R20a] warns about.
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class PlainRenderer implements Renderer
{
    public const NAME = 'plain';

    public function name(): string
    {
        return self::NAME;
    }

    public function supports(): array
    {
        return [Purpose::Display, Purpose::Edit, Purpose::Search];
    }

    public function fits(Node|Relation $subject): bool
    {
        // It is the fallback. Refusing anything would leave something undrawable.
        return true;
    }

    public function render(Node|Relation $subject, RenderContext $context): RenderResult
    {
        if ($context->setting(SettingKey::Hide->value)?->asBool() ?? false) {
            return RenderResult::of('');
        }

        $shown = $context->value->isNothing() ? '' : $context->value->describe();

        if (! $context->mayEdit()) {
            // ⚠️ Nothing is drawn as nothing, not as a dash or a zero. A missing value means
            // *not answered*, and inventing a placeholder here would hide that from the reader.
            return RenderResult::of('<span class="taxmod-value">' . RenderResult::escape($shown) . '</span>');
        }

        return RenderResult::of(
            '<input type="text" name="' . RenderResult::escape($context->fieldName) . '"'
            . ' value="' . RenderResult::escape($shown) . '">'
        );
    }
}
