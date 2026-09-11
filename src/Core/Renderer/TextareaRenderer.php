<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

use Taxmod\Core\Model\SettingKey;
use Taxmod\Core\Model\SimpleType;

/**
 * Several lines — for the long end of `text`, which is one type covering *short strings and long
 * ones alike, source code included* ([D-316](90-decision-log.md)).
 *
 * ⚠️ **Not the default.** A text is one line until somebody says otherwise; guessing from the
 * length of what happens to be stored would make the control jump about as the content grows.
 *
 * ⚠️ **`cols` × `rows` are free settings, and they live on the node like any other.** A setting
 * only one renderer reads is still a setting on the node — the chain does not ask who will read
 * it, which is exactly how `range_min` works. *There is no such thing as a homeless setting here,
 * and the premise that a renderer needs to own one in order to have it was simply wrong.* What
 * keeps these two free rather than reserved is that no decision names them: [R17](../../../docs/NewConcept/30-renderer.md#r12r17)
 * names `min`, `max` and `step` for numbers and nothing names a text area's shape.
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class TextareaRenderer extends TypedFieldRenderer
{
    /** Spalten und Zeilen des Textfelds — ohne Vorgabe nimmt die Breite `display_size` des Knotens. */
    #[\Taxmod\Core\Model\NodeClass\Attribut]
    public ?int $cols = null;

    #[\Taxmod\Core\Model\NodeClass\Attribut]
    public ?int $rows = null;

    public const NAME = 'textarea';

    public function name(): string
    {
        return self::NAME;
    }

    public function handles(): array
    {
        return [SimpleType::Text];
    }

    protected function display(RenderContext $context): string
    {
        // ⚠️ Newlines survive as newlines. Turning them into `<br>` would be the renderer
        // rewriting the value on the way out, and the same text would read differently
        // depending on which renderer drew it.
        return '<span class="taxmod-value" style="white-space:pre-wrap">'
            . RenderResult::escape($this->outputValue($context))
            . '</span>';
    }

    protected function input(RenderContext $context): string
    {
        return '<textarea'
            . $this->createHtmlAttribute('name', $context->fieldName)
            // ⚠️ *Ohne dies schickt die Eingabe nichts, wenn sie ausserhalb ihres Formulars steht.*
            . $this->createHtmlAttribute('form', $context->surroundings->formId)
            // ⚠️ *[R32](../../../docs/NewConcept/30-renderer.md#r28r32--the-rule-complete), auf sein Wort: ein
            // Eingabefeld muss sich immer gleich verhalten, und bei `1..1` muss ein Wert gesetzt sein.*
            . ($context->surroundings->mayBeNothing ? '' : ' aria-required="true"')
            // ⚠️ **`cols` zuerst, `display_size` als Rückfall** ([D-659](../../../docs/NewConcept/90-decision-log.md)).
            // *Beide sagen dasselbe in derselben Einheit — eine Breite in Zeichen —, und `cols` ist die
            // Angabe, die **diesen** Rand meint. **Die Anzeigebreite ist der Wunsch, den jeder Rand
            // berücksichtigt, der kann**; ihn hier zu übergehen hiesse, dass die Breite eines Feldes
            // davon abhinge, welchen Renderer der Knoten gewählt hat.*
            . $this->createHtmlAttribute(
                'cols',
                $this->numberSetting($context, 'cols')
                    ?? $this->numberSetting($context, SettingKey::DisplaySize->value)
            )
            . $this->createHtmlAttribute('rows', $this->numberSetting($context, 'rows'))
            . '>' . RenderResult::escape($this->outputValue($context)) . '</textarea>';
    }
}
