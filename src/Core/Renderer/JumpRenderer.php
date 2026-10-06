<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

use Taxmod\Core\Model\SimpleType;

/**
 * Zeichnet ein Sprung-Feld als Link zum Zielknoten, gefiltert nach diesem Satz ([D-769](../../../docs/NewConcept/90-decision-log.md)).
 *
 * ⚠️ *Beim Anzeigen und beim Bearbeiten derselbe Link: ein Sprung hat nichts einzugeben. Das Wort für den Screenreader kommt
 * vom Rand (`AR-2`) und reist als `shown`; die Adresse ist der gerechnete Wert.*
 *
 * @see \Taxmod\Core\Model\Type\JumpType
 */
final class JumpRenderer extends TypedFieldRenderer
{
    public const NAME = 'jump';

    public function name(): string
    {
        return self::NAME;
    }

    public function handles(): array
    {
        return [SimpleType::Jump];
    }

    protected function display(RenderContext $context): string
    {
        return $this->link($context);
    }

    protected function input(RenderContext $context): string
    {
        return $this->link($context);
    }

    private function link(RenderContext $context): string
    {
        $adresse = $context->value->isNothing() ? '' : (string) $context->value->text;

        if ($adresse === '') {
            return $this->createHtmlValueSpan('');
        }

        return $this->createHtmlValueSpan(
            '<a class="taxmod-jump" href="' . RenderResult::escape($adresse) . '">'
            . IconMarkup::dashicon('external', (string) ($context->shown ?? ''))
            . '</a>'
        );
    }
}
