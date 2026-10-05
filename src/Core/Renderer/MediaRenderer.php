<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Model\Type\MediaType;
use Taxmod\Core\Port\MediaFile;
use Taxmod\Core\Port\MediaLibrary;

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



    public function name(): string
    {
        return self::NAME;
    }

    public function handles(): array
    {
        return [SimpleType::Media];
    }



    protected function display(RenderContext $context): string
    {
        $adresse = $context->value->isNothing() ? '' : trim((string) $context->value->text);

        if ($adresse === '') {
            return $this->createHtmlValueSpan('');
        }

        // *Mit Beschriftungsfeld ist die Beschriftung der Linktext (D-856); leer bleibt es die aus der Datei gerechnete.*
        return $this->createHtmlValueSpan(self::link($adresse, (string) ($context->surroundings->refersTo ?? ''), self::opensNewTab($context), $context->surroundings->mediaLibrary));
    }

    /** Die Datei der Mediathek hinter `media:<Id>`, oder `null` — für eine Adresse von draussen oder ohne Naht (D-865). */
    public static function fileOf(string $adresse, ?MediaLibrary $bibliothek): ?MediaFile
    {
        $id = MediaType::libraryIdOf($adresse);

        return $id === null || $bibliothek === null ? null : ($bibliothek->filesFor([$id])[$id] ?? null);
    }


    /** Der gezeichnete Link: die Beschriftung, sonst die aus der Datei gerechnete Beschreibung ([D-846](../../../docs/NewConcept/90-decision-log.md), [D-856](../../../docs/NewConcept/90-decision-log.md)). */
    public static function link(string $adresse, string $beschriftung = '', bool $neuerTab = true, ?MediaLibrary $bibliothek = null): string
    {
        $wort  = trim($beschriftung) !== '' ? trim($beschriftung) : self::describe($adresse, $bibliothek);
        $datei = self::fileOf($adresse, $bibliothek);

        // ⚠️ *Eine Id, zu der die Mediathek nichts sagt, steht als das da, was gespeichert ist — kein erfundenes Wort (`AR-2`).*
        if (MediaType::libraryIdOf($adresse) !== null && $datei === null) {
            return '<span class="taxmod-media taxmod-media-missing">' . RenderResult::escape($wort) . '</span>';
        }

        $ziel = $datei === null ? self::absolute($adresse) : $datei->url;
        $auf  = $neuerTab ? ' target="_blank" rel="noopener"' : '';

        // *Ein Bild zeigt sein Vorschaubild; der Klick öffnet das ganze Bild (D-865).*
        if ($datei !== null && $datei->isImage()) {
            return '<a class="taxmod-media taxmod-media-image" href="' . RenderResult::escape($ziel) . '" title="' . RenderResult::escape($wort) . '"' . $auf . '>'
                . '<img class="taxmod-media-thumb" src="' . RenderResult::escape($datei->thumbnail) . '" alt="' . RenderResult::escape($wort) . '" loading="lazy"></a>';
        }

        if ($datei !== null) {
            return '<a class="taxmod-media" href="' . RenderResult::escape($ziel) . '"' . $auf . '>' . RenderResult::escape($wort) . '</a>';
        }

        // *Ob in einem neuen Tab, sagt die Einstellung `new_tab` (D-858); Vorgabe ja.*
        return '<a class="taxmod-media" href="' . RenderResult::escape(self::absolute($adresse)) . '"' . ($neuerTab ? ' target="_blank" rel="noopener"' : '') . '>'
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

    public static function describe(string $adresse, ?MediaLibrary $bibliothek = null): string
    {
        // ⚠️ *Eine Mediathek-Datei heisst wie ihre Bildunterschrift, sonst wie ihr Titel, sonst wie ihre Datei ([D-879](../../../docs/NewConcept/90-decision-log.md)) —
        // sein Wort: «bilder haben eigentlich immer eine caption/titel». Gemessen am 2026-09-19: der Titel ist fast immer der Dateiname
        // der Kamera («20260605_220411»), die Unterschrift sagt, was zu sehen ist.*
        $datei = self::fileOf($adresse, $bibliothek);

        if ($datei !== null) {
            foreach ([$datei->caption, $datei->title] as $wort) {
                if (trim($wort) !== '') {
                    return trim($wort);
                }
            }

            return self::describe($datei->url);
        }

        if (MediaType::libraryIdOf($adresse) !== null) {
            return trim($adresse);
        }

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

        // ⚠️ *Mit Beschriftungsfeld stehen die Knöpfe und der Link rechts hinter der Zeile, nicht am Feld (D-856, D-858) — sein Wort: «alle
        // buttons bitte nach rechts».*
        if ($context->fieldName === '' || $context->surroundings->refersTo !== null) {
            return $link;
        }

        return '<span class="taxmod-media-input">'
            . $link
            . self::buttons($context->fieldName, '', $context->surroundings->dialogWords, self::opensNewTab($context))
            . ($adresse === '' ? '' : ' ' . $this->display($context))
            . '</span>';
    }

    /** Ob ein Link in einem neuen Tab öffnet — die Einstellung `new_tab` am Medienfeld, Vorgabe ja ([D-858](../../../docs/NewConcept/90-decision-log.md)). */
    private static function opensNewTab(RenderContext $context): bool
    {
        $wert = $context->setting(\Taxmod\Core\Model\Type\MediaType::NEW_TAB);

        return $wert === null || $wert->isNothing() || (bool) $wert->rawValue();
    }

    /**
     * Die Knöpfe eines Medienfeldes, rechts vom Feld ([D-858](../../../docs/NewConcept/90-decision-log.md)): die Mediathek und der Linkdialog.
     * *Der Kern zeichnet nur Knopf, Wort und die Namen der Felder, die gefüllt werden; was dahinter aufgeht, stellt der Rand (`CD-1`).*
     *
     * @param array<string, string> $worte Die Worte vom Rand: `upload` (Mediathek) und `link` (Linkdialog).
     */
    public static function buttons(string $adresseName, string $beschriftungName, array $worte, bool $neuerTab): string
    {
        $daten = ($adresseName === '' ? '' : ' data-taxmod-address="' . RenderResult::escape($adresseName) . '"')
            . ($beschriftungName === '' ? '' : ' data-taxmod-caption="' . RenderResult::escape($beschriftungName) . '"')
            . ' data-taxmod-newtab="' . ($neuerTab ? '1' : '0') . '"';
        $knopf = static function (string $klasse, string $symbol, string $wort) use ($daten): string {
            return '<button type="button" class="button ' . ControlMarkup::ICON_ONLY . ' ' . $klasse . '" style="color:#1d2327"'
                . ($wort === '' ? '' : ' title="' . RenderResult::escape($wort) . '"') . $daten . '>'
                . IconMarkup::dashicon($symbol, $wort) . '</button>';
        };

        return $knopf('taxmod-media-library', 'media-default', (string) ($worte['upload'] ?? ''))
            . $knopf('taxmod-media-wplink', 'admin-links', (string) ($worte['link'] ?? ''));
    }
}
