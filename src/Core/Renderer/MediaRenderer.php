<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

use Taxmod\Core\Model\SimpleType;

/**
 * Zeichnet ein Medienfeld: angezeigt ein Link auf die Datei, bearbeitet ein Feld für den Link und eines zum Hochladen.
 *
 * ⚠️ *Sein Wort ([D-793](../../../docs/NewConcept/90-decision-log.md)): «it is a media type, both is the right answer». Das
 * Hochladefeld heisst wie das Wertfeld mit `_upload` hinter dem ersten Namensteil — dieselbe Regel wie `_list` und `_set` bei den
 * Einstellungen —, damit der Rand weiss, in welches Feld die Adresse der hochgeladenen Datei gehört.*
 *
 * ⚠️ *Ein Textfeld und kein `type="url"`: ein Browser, der eine unvollständige Adresse verweigert, sperrte das Speichern des ganzen
 * Satzes — derselbe Fehler, den `required` schon einmal gemacht hat.*
 *
 * @see \Taxmod\Core\Model\Type\MediaType
 */
final class MediaRenderer extends TypedFieldRenderer
{
    public const NAME = 'media';

    public const UPLOAD_SUFFIX = '_upload';

    public function name(): string
    {
        return self::NAME;
    }

    public function handles(): array
    {
        return [SimpleType::Media];
    }

    /** Der Name des Hochladefeldes zu einem Wertfeld: `taxmod_value[7][9]` wird `taxmod_value_upload[7][9]`. */
    public static function uploadNameFor(string $fieldName): string
    {
        return (string) preg_replace('/^([A-Za-z0-9_]+)/', '$1' . self::UPLOAD_SUFFIX, $fieldName, 1);
    }

    protected function display(RenderContext $context): string
    {
        $adresse = $context->value->isNothing() ? '' : trim((string) $context->value->text);

        if ($adresse === '') {
            return $this->createHtmlValueSpan('');
        }

        // *Mit Beschriftungsfeld ist die Beschriftung der Linktext (D-856); leer bleibt es die aus der Datei gerechnete.*
        return $this->createHtmlValueSpan(self::link($adresse, (string) ($context->surroundings->refersTo ?? '')));
    }

    /** Der gezeichnete Link: die Beschriftung, sonst die aus der Datei gerechnete Beschreibung ([D-846](../../../docs/NewConcept/90-decision-log.md), [D-856](../../../docs/NewConcept/90-decision-log.md)). */
    public static function link(string $adresse, string $beschriftung = ''): string
    {
        $wort = trim($beschriftung) !== '' ? trim($beschriftung) : self::describe($adresse);

        return '<a class="taxmod-media" href="' . RenderResult::escape(self::absolute($adresse)) . '" target="_blank" rel="noopener">'
            . RenderResult::escape($wort) . '</a>';
    }

    /**
     * Die Beschreibung eines Mediums, aus der Datei selbst gemacht ([D-846](../../../docs/NewConcept/90-decision-log.md)) — sein Wort:
     * *«media sollte eine beschreibung haben, kann aus der media datei generiert werden».*
     *
     * ⚠️ *Gerechnet, nicht gespeichert: `schaltplan_v2-final.pdf` heisst «schaltplan v2 final (PDF)»; eine Adresse ohne Datei heisst wie
     * Rechner und Weg, `github.com/sfambach/diskbuddy64`. **Angenommen, nicht von ihm gesagt:** der Dateiname genügt — WordPress legt den
     * Titel einer hochgeladenen Datei ebenfalls aus ihm an.*
     */
    /** *Eine Adresse ohne «https://» — `www.google.de`, `github.com/x` — ist eine Adresse und keine Datei «www google (DE)»; der Link zeigt nach draussen.* */
    public static function absolute(string $adresse): string
    {
        $adresse = trim($adresse);

        return ! str_contains($adresse, '://') && preg_match('~^(www\.[^/\s]+|[a-z0-9-]+(\.[a-z0-9-]+)+/)~i', $adresse) === 1 ? 'https://' . $adresse : $adresse;
    }

    public static function describe(string $adresse): string
    {
        $adresse = self::absolute($adresse);

        $pfad    = parse_url($adresse, PHP_URL_PATH);
        $pfad    = is_string($pfad) ? rtrim($pfad, '/') : '';
        $rechner = parse_url($adresse, PHP_URL_HOST);
        $rechner = is_string($rechner) ? (string) preg_replace('/^www\./', '', $rechner) : '';
        $datei   = $pfad === '' ? ($rechner === '' ? basename($adresse) : '') : basename($pfad);
        $endung  = pathinfo($datei, PATHINFO_EXTENSION);

        if ($endung !== '' && preg_match('/^[A-Za-z0-9]{1,5}$/', $endung) === 1) {
            $wort = trim((string) preg_replace('/[\s_\-.]+/', ' ', rawurldecode(pathinfo($datei, PATHINFO_FILENAME))));

            return ($wort === '' ? $datei : $wort) . ' (' . strtoupper($endung) . ')';
        }

        return $rechner !== '' ? $rechner . $pfad : $adresse;
    }

    protected function input(RenderContext $context): string
    {
        $adresse = $context->value->isNothing() ? '' : (string) $context->value->text;
        $form    = $context->surroundings->formId === '' ? '' : ' form="' . RenderResult::escape($context->surroundings->formId) . '"';
        $link    = '<input type="text" inputmode="url" class="taxmod-media-link" name="' . RenderResult::escape($context->fieldName) . '"'
            . ' value="' . RenderResult::escape($adresse) . '" size="30"' . $form . '>';

        if ($context->fieldName === '') {
            return $link;
        }

        // ⚠️ **Der Dateiknopf ist ein Symbol rechts vom Feld** ([D-846](../../../docs/NewConcept/90-decision-log.md), [D-847](../../../docs/NewConcept/90-decision-log.md))
        // — sein Wort: *«das folder symbol oder datei symbol für den knopf verwenden und den knopf nach rechts».* Das Dateifeld selbst steckt
        // unsichtbar in der Beschriftung; ein Klick auf das Symbol öffnet die Dateiwahl, ohne Skript.
        $wort = (string) ($context->surroundings->dialogWords['upload'] ?? '');

        return '<span class="taxmod-media-input">'
            . $link
            . '<label class="button ' . ControlMarkup::ICON_ONLY . ' taxmod-media-pick" style="color:#1d2327"' . ($wort === '' ? '' : ' title="' . RenderResult::escape($wort) . '"') . '>'
            . IconMarkup::dashicon('media-default', $wort)
            . '<input type="file" class="taxmod-media-file screen-reader-text" name="' . RenderResult::escape(self::uploadNameFor($context->fieldName)) . '"' . $form . '>'
            . '</label>'
            . '<span class="taxmod-media-chosen description"></span>'
            // ⚠️ *Mit Beschriftungsfeld steht der Link nicht im Feld, sondern hinter «+» und Mülleimer der Zeile (D-856).*
            . ($adresse === '' || $context->surroundings->refersTo !== null ? '' : ' ' . $this->display($context))
            . '</span>';
    }
}
