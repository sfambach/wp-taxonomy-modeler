<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

use Taxmod\Core\Model\SimpleType;

/**
 * An address that can be written to — the renderer [D-322](90-decision-log.md) names when it
 * admits `email` as a type at all.
 *
 * ⚠️ **This is why `email` is a type and `phone` is a validator** (D-319): a type has to earn its
 * place through storage, rendering or ordering, and *clickable as `mailto:`* is the rendering that
 * earns it. Validity is a separate question and not asked here.
 *
 * ⚠️ **It links what it was given, unchecked.** A renderer never writes and never tidies (D-159),
 * so an address that is not one becomes a link that does not work — visibly, which is the
 * validator's cue rather than something to paper over.
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class MailtoRenderer extends TypedFieldRenderer
{
    public const NAME = 'mailto';

    public function name(): string
    {
        return self::NAME;
    }

    public function handles(): array
    {
        return [SimpleType::Email];
    }

    protected function display(RenderContext $context): string
    {
        $address = $this->outputValue($context);

        if ($address === '') {
            return $this->createHtmlValueSpan('');
        }

        // `rawurlencode` would eat the `@`; the address goes in escaped as an attribute, which is
        // what stops a `"` in it from breaking out of the href.
        return $this->createHtmlValueSpan(
            '<a href="mailto:' . RenderResult::escape($address) . '">'
            . RenderResult::escape($address)
            . '</a>'
        );
    }

    protected function input(RenderContext $context): string
    {
        return RenderResult::htmlTag('input', [
            'type'  => 'email',
            'name'  => $context->fieldName,
            // ⚠️ *Ohne dies schickt die Eingabe nichts, wenn sie ausserhalb ihres Formulars steht.*
            'form'  => $context->surroundings->formId,
            // ⚠️ *[R32](../../../docs/NewConcept/30-renderer.md#r28r32--the-rule-complete), auf sein Wort: ein
            // Eingabefeld muss sich immer gleich verhalten, und bei `1..1` muss ein Wert gesetzt sein.*
            'aria-required' => $context->surroundings->mayBeNothing ? null : 'true',
            'value' => $this->outputValue($context),
        ]);
    }
}
