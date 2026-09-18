<?php declare(strict_types=1);

namespace Taxmod\Core\Model\Type;

use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Model\TypedValue;

/**
 * Das Medienfeld — eine Datei oder ein Link, gespeichert als Adresse.
 *
 * ⚠️ **Sein Wort** ([D-793](../../../../docs/NewConcept/90-decision-log.md)), auf die Frage «Link oder Hochladen» für die
 * Gerber-Dateien einer Platine: *«it is a media type, both is the right answer».* *Der Kern kennt nur die Adresse: ein Link wird so
 * gespeichert, wie er eingegeben wird; eine hochgeladene Datei legt der Rand in die Mediathek und trägt deren Adresse ein
 * ({@see \Taxmod\WordPress\Admin\NodesScreen}). So bleibt der Wert in beiden Fällen dasselbe — und der Kern ruft kein WordPress (`CD-1`).*
 *
 * @see \Taxmod\Core\Renderer\MediaRenderer
 */
final class MediaType extends SpecialisedType
{
    /**
     * Welches Nachbarfeld die Beschriftung trägt ([D-856](../../../../docs/NewConcept/90-decision-log.md)) — sein Wort: *«ja die beschriftung
     * soll der link sein»*. Die Kandidaten sind die Felder des Knotens, dem das Medienfeld gehört (beim `Medium`: Adresse, Beschriftung).
     *
     * @var list<int> Kanten-Ids — das erste aktive Glied gilt
     */
    #[\Taxmod\Core\Model\NodeClass\Attribut(listOf: 'relation', fieldsFrom: \Taxmod\Core\Model\NodeClass\FieldSource::Owner)]
    public array $caption_field = [];

    public const CAPTION_FIELD = 'caption_field';

    public function type(): SimpleType
    {
        return SimpleType::Media;
    }

    public function nodeName(): string
    {
        return 'Media';
    }

    public function column(): string
    {
        return 'value_text';
    }

    public function valueFrom(string $characters): TypedValue
    {
        $adresse = trim($characters);

        return $adresse === '' ? TypedValue::nothing() : TypedValue::ofText($adresse);
    }
}
