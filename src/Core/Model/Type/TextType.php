<?php declare(strict_types=1);

namespace Taxmod\Core\Model\Type;

use Taxmod\Core\Model\SimpleType;

/**
 * Kurze Zeichenketten und lange gleichermassen, Quelltext eingeschlossen
 * ([D-316](../../../../docs/NewConcept/90-decision-log.md)).
 *
 * @see docs/NewConcept/10-domain-core.md
 */
final class TextType extends SpecialisedType
{
    /**
     * Der Ast der Anbieter, deren Link-Vorlagen dieses Feld verlinken ([D-1484](../../../../docs/NewConcept/90-decision-log.md)) — das erste aktive
     * Glied gilt. ⚠️ *Sein Wort 2026-11-19: «für die einzelnen anbieter ist der link hinterlegt neue können aber angelegt werden also eher eine
     * auswhal».* Eine Liste, weil nur Listenglieder ihre Nummer tragen (wie `ziel` am Sprung, D-769).
     *
     * @var list<int> Knoten-Ids
     */
    #[\Taxmod\Core\Model\NodeClass\Attribut(listOf: 'node', refersTo: \Taxmod\Core\Model\NodeClass\Category::class, band: 'link')]
    public array $link_anbieter = [];

    public const LINK_ANBIETER = 'link_anbieter';

    /**
     * Welche Vorlage des Anbieters gilt — ein Feld des gewählten Asts, z. B. «Bestell-Link» oder «Produkt-Link». `{nr}` darin ist der
     * Inhalt dieses Feldes (D-1249).
     *
     * @var list<int> Kanten-Ids
     */
    #[\Taxmod\Core\Model\NodeClass\Attribut(listOf: 'relation', fieldsFrom: \Taxmod\Core\Model\NodeClass\FieldSource::ChosenProvider, band: 'link')]
    public array $link_vorlage = [];

    public const LINK_VORLAGE = 'link_vorlage';

    /**
     * «Anbieter aus Feld»: das Feld desselben Satzes, das den Anbieter nennt — ein Verweis auf ihn, oder sein Name als Text.
     *
     * @var list<int> Kanten-Ids
     */
    #[\Taxmod\Core\Model\NodeClass\Attribut(listOf: 'relation', fieldsFrom: \Taxmod\Core\Model\NodeClass\FieldSource::Owner, band: 'link')]
    public array $link_quelle = [];

    public const LINK_QUELLE = 'link_quelle';

    /** «Fester Anbieter»: sein Name, wo kein Feld ihn nennt. */
    #[\Taxmod\Core\Model\NodeClass\Attribut(band: 'link')]
    public string $link_fester_anbieter = '';

    public const LINK_FESTER_ANBIETER = 'link_fester_anbieter';

    /** Der Platzhalter der Vorlagen, der den Inhalt des Feldes aufnimmt — dieselbe Schreibweise wie in den Vorlagen der Logistik (D-1249). */
    public const PLACEHOLDER = '{nr}';

    /**
     * Die Vorlage mit dem Inhalt gefüllt, URL-kodiert; `null`, wenn eines leer ist, der Platzhalter fehlt oder das Ergebnis keine
     * http(s)-Adresse ist ([D-1484](../../../../docs/NewConcept/90-decision-log.md)).
     */
    public static function linkFrom(string $template, string $content): ?string
    {
        $template = trim($template);
        $content  = trim($content);

        if ($template === '' || $content === '' || ! str_contains($template, self::PLACEHOLDER)) {
            return null;
        }

        $url = str_replace(self::PLACEHOLDER, rawurlencode($content), $template);

        return preg_match('#^https?://[^\s]+$#i', $url) === 1 ? $url : null;
    }

    public function type(): SimpleType
    {
        return SimpleType::Text;
    }

    public function nodeName(): string
    {
        return 'Text';
    }

    public function column(): string
    {
        return 'value_text';
    }
}
