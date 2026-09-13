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

        $pfad = parse_url($adresse, PHP_URL_PATH);
        $name = basename(is_string($pfad) && $pfad !== '' ? $pfad : $adresse);

        return $this->createHtmlValueSpan(
            '<a class="taxmod-media" href="' . RenderResult::escape($adresse) . '" target="_blank" rel="noopener">'
            . RenderResult::escape($name)
            . '</a>'
        );
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

        return '<span class="taxmod-media-input">'
            . $link
            . '<input type="file" class="taxmod-media-file" name="' . RenderResult::escape(self::uploadNameFor($context->fieldName)) . '"' . $form . '>'
            . ($adresse === '' ? '' : ' ' . $this->display($context))
            . '</span>';
    }
}
