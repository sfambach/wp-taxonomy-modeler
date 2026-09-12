<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\RelationKind;

/**
 * Die Zusammenfassung eines verwiesenen Satzes — ein paar seiner Felder in einer Zeile, und beim
 * Bearbeiten ein Auswahlfeld über die Sätze des Ziels.
 *
 * ⚠️ **[D-106](../../../docs/NewConcept/90-decision-log.md) nennt die drei Stufen der Anzeige eines Verweises:**
 * *«reference (label and link) · summary (a few of the target's attributes, what a parts-list row wants) ·
 * expand (the whole target)».* Das hier ist die zweite. **Welche Felder**, sagt der Zielknoten in seiner
 * Einstellung `summary_fields` — als Vorgabe am Knoten, an der Kante überschreibbar
 * ([D-753](../../../docs/NewConcept/90-decision-log.md), sein Wort: *«aber wenn wirs am Knoten haben, können
 * wirs an der Kante so übernehmen»*).
 *
 * ⚠️ *Der Renderer selbst liest keinen Satz: die Worte kommen aufgelöst mit — für den gezeigten Satz in
 * `refersTo`, für die Auswahl in `options` —, in **einer** Abfrage je Block
 * ({@see \Taxmod\Core\Service\Rendering::summariesOf()}), wie [D-363](../../../docs/NewConcept/90-decision-log.md)
 * es für die Labels vorschreibt.*
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class SummaryRenderer extends TypedFieldRenderer
{
    public const NAME = 'summary';

    /** Die Einstellung am Zielknoten, die sagt, welche Felder den Satz ausmachen. */
    public const FIELDS = 'summary_fields';

    public const SEPARATOR = ' · ';

    public function name(): string
    {
        return self::NAME;
    }

    /** Kein einfacher Typ: gezeichnet wird ein Verweis auf einen Satz, und den gibt es nur an einer Aggregation. */
    public function handles(): array
    {
        return [];
    }

    public function fits(Renderable $subject): bool
    {
        return $subject instanceof Relation && $subject->kind === RelationKind::Aggregation;
    }

    protected function display(RenderContext $context): string
    {
        if ($context->value->isNothing()) {
            return $this->createHtmlValueSpan('');
        }

        if ($context->surroundings->refersTo === null) {
            // ⚠️ *Ein Verweis, dessen Satz nicht mehr da ist, bleibt sichtbar — als Nummer, gekennzeichnet (D-363).*
            return '<span class="taxmod-value taxmod-dangling">'
                . RenderResult::escape('#' . (string) $context->value->reference)
                . '</span>';
        }

        return $this->createHtmlValueSpan(RenderResult::escape($context->surroundings->refersTo));
    }

    protected function input(RenderContext $context): string
    {
        $gewaehlt = $context->value->reference;
        $markup   = '<select name="' . RenderResult::escape($context->fieldName) . '"'
            . ($context->surroundings->formId === '' ? '' : ' form="' . RenderResult::escape($context->surroundings->formId) . '"')
            . ' class="taxmod-choice taxmod-summary-choice">';

        if ($context->surroundings->mayBeNothing || $gewaehlt === null) {
            $markup .= '<option value=""' . ($gewaehlt === null ? ' selected' : '') . '>—</option>';
        }

        $steht = false;

        foreach ($context->surroundings->options as $satzId => $wort) {
            $ist    = (int) $satzId === $gewaehlt;
            $steht  = $steht || $ist;
            $markup .= '<option value="' . (int) $satzId . '"' . ($ist ? ' selected' : '') . '>' . RenderResult::escape((string) $wort) . '</option>';
        }

        if ($gewaehlt !== null && ! $steht) {
            $markup .= '<option value="' . (int) $gewaehlt . '" selected>#' . (int) $gewaehlt . '</option>';
        }

        return $markup . '</select>';
    }
}
