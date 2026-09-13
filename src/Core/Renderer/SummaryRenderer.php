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
        // ⚠️ **Mit Baum ein Dialog statt einer langen Liste** ([D-791](../../../docs/NewConcept/90-decision-log.md), Zeile 149).
        if ($context->surroundings->recordTree !== []) {
            return $this->dialog($context);
        }

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

    /**
     * Die Satzauswahl als Dialog: der Baum der Knoten als aufklappbare Äste, darin je Satz ein Auswahlknopf.
     *
     * ⚠️ *Sein Wort: «einen baum ansicht … wo ich erst den knoten auswähle … dann … datensatz aus» und «wir brauchen einen dialog»
     * (D-791). Ohne Skript: der Dialog ist die eine Form aus {@see DialogMarkup}, die Äste sind `<details>`, die Knöpfe gehören über
     * `form="…"` zum Formular des Satzes und werden mit ihm gespeichert. Offen steht der Ast des gewählten Satzes.*
     */
    private function dialog(RenderContext $context): string
    {
        $gewaehlt = $context->value->reference;
        $baum     = $context->surroundings->recordTree;
        $name     = RenderResult::escape($context->fieldName);
        $form     = $context->surroundings->formId === '' ? '' : ' form="' . RenderResult::escape($context->surroundings->formId) . '"';
        $knopf    = static fn (string $wert, bool $an, string $wort, string $suche = ''): string => '<label class="taxmod-record-choice"'
            . ($suche === '' ? '' : ' data-taxmod-search="' . RenderResult::escape($suche) . '"') . '>'
            . '<input type="radio" name="' . $name . '" value="' . $wert . '"' . ($an ? ' checked' : '') . $form . '> ' . $wort . '</label>';

        // *Welche Äste den gewählten Satz enthalten — sie stehen offen, damit er zu sehen ist.*
        $offen = [];
        $steht = false;

        foreach ($baum as $i => $zeile) {
            if ($gewaehlt === null || ! isset($zeile['records'][$gewaehlt])) {
                continue;
            }

            $steht = true;
            $tiefe = $zeile['depth'];

            for ($j = $i; $j >= 0; $j--) {
                if ($baum[$j]['depth'] <= $tiefe) {
                    $offen[$j] = true;
                    $tiefe     = $baum[$j]['depth'] - 1;
                }
            }
        }

        // ⚠️ **Die Suche** ([D-791](../../../docs/NewConcept/90-decision-log.md) Schritt 2) — *ein Feld ohne Namen, also schickt es nichts ab;
        // das Skript blendet aus, was nicht passt. Ohne Skript steht es da und tut nichts, und der Baum zeigt alles wie vorher.*
        $koerper = '<input type="search" class="taxmod-record-search" autocomplete="off" aria-label="' . RenderResult::escape((string) ($baum[0]['name'] ?? '')) . '">';

        if ($context->surroundings->mayBeNothing || $gewaehlt === null) {
            $koerper .= $knopf('', $gewaehlt === null, '—');
        }

        // ⚠️ *Ein gespeicherter Satz ausserhalb des Baums bleibt wählbar und sichtbar (D-360) — sonst schriebe das nächste Speichern nichts.*
        if ($gewaehlt !== null && ! $steht) {
            $koerper .= $knopf((string) (int) $gewaehlt, true, RenderResult::escape($context->surroundings->refersTo ?? '#' . $gewaehlt));
        }

        $tiefe = -1;

        foreach ($baum as $i => $zeile) {
            while ($tiefe >= $zeile['depth']) {
                $koerper .= '</details>';
                $tiefe--;
            }

            $koerper .= '<details class="taxmod-record-branch"' . ($zeile['depth'] === 0 || isset($offen[$i]) ? ' open' : '') . '>'
                . '<summary>' . RenderResult::escape($zeile['name'])
                . ($zeile['records'] === [] ? '' : ' <span class="taxmod-record-count">(' . count($zeile['records']) . ')</span>')
                . '</summary>';

            foreach ($zeile['records'] as $satzId => $wort) {
                $koerper .= $knopf((string) (int) $satzId, (int) $satzId === $gewaehlt, RenderResult::escape((string) $wort), (string) ($zeile['search'][$satzId] ?? ''));
            }

            $tiefe = $zeile['depth'];
        }

        while ($tiefe >= 0) {
            $koerper .= '</details>';
            $tiefe--;
        }

        // ⚠️ *Ohne gewählten Satz steht nur das Zeichen «—» — dann ist der Öffner ein Zeichenknopf: randlos, und sein Name steht für
        // den Vorleser dabei, wie beim Knotenwähler ({@see ChooserRenderer}; `icon-button-check`). Der Name ist der Knoten, aus dem
        // gewählt wird — die Wurzel des Baums; `render()` ist in {@see TypedFieldRenderer} endgültig und reicht das Feld nicht herein.*
        $nichts = $context->surroundings->refersTo === null || $gewaehlt === null;
        $jetzt  = $nichts
            ? '<span class="taxmod-nothing">—</span><span class="screen-reader-text">' . RenderResult::escape((string) ($baum[0]['name'] ?? '')) . '</span>'
            : RenderResult::escape((string) $context->surroundings->refersTo);

        return DialogMarkup::of(
            'taxmod-record-dialog-' . preg_replace('/[^a-z0-9_-]/i', '', $context->surroundings->formId . $context->fieldName),
            $jetzt,
            $jetzt,
            '<div class="taxmod-record-tree">' . $koerper . '</div>',
            '',
            $nichts ? 'button taxmod-icon-button taxmod-dialog-open' : 'button taxmod-record-dialog-open'
        );
    }
}
