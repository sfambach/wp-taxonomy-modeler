<?php declare(strict_types=1);

namespace Taxmod\Core\Model\Setting;

use Taxmod\Core\Model\NodeClass\Attribut;

/**
 * **Umrechnung** — eine Wertklasse mit Faktor und Offset (Anforderung 3.6.4).
 *
 * ⚠️ **Sein Wort** ([D-712](../../../../docs/NewConcept/90-decision-log.md)): *«ich meinte für factor
 * eine eigene klasse … kann für präfix, aber auch für temperatur-umrechnungen verwendet werden»* —
 * und *«umrechnungssatz hört sich gut an.»* Ihr Objekt hängt als Einstellungsobjekt an `kilo`
 * (Faktor 1000) oder an `Celsius` (Faktor 1, Offset 273,15).
 *
 * ⚠️ *Beide Dezimalzahlen als Zeichenkette — nie Fliesskomma ([D-057](../../../../docs/NewConcept/90-decision-log.md)).*
 *
 * @see docs/einstellungen-anforderungen.md
 */
final class Conversion
{
    #[Attribut(decimal: true)]
    public string $factor = '1';

    #[Attribut(decimal: true)]
    public string $offset = '0';
}
