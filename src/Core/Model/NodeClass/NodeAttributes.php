<?php declare(strict_types=1);

namespace Taxmod\Core\Model\NodeClass;

use Taxmod\Core\Converter\Converter;
use Taxmod\Core\Renderer\Renderer;
use Taxmod\Core\Addon\Addon;

/**
 * **Die Attribute der Basisklasse Knoten** — jeder Knoten hat sie (Anforderung 2.3.3, 3.6.1).
 *
 * ⚠️ **Sein Gerüst** ([D-712](../../../../docs/NewConcept/90-decision-log.md)): *«`node` hat das
 * Attribut `renderers` vom Typ list of Renderer und `read_only` vom Typ boolean»* — `read_only` ist
 * seither Spalte der Kante ([D-714](../../../../docs/NewConcept/90-decision-log.md)); geblieben sind
 * die drei Listen und die Breite. *Alle drei als Liste, sein Wort: «im grunde haben wir alle
 * möglichkeiten, einschränken können wir es immer noch.»*
 *
 * ⚠️ *Ein Trait und keine Oberklasse, weil die Typklassen schon von {@see \Taxmod\Core\Model\Node}
 * erben ([D-620](../../../../docs/NewConcept/90-decision-log.md)) und die anderen Knotenklassen nicht.
 * Die **Adresse** eines dieser Attribute ist trotzdem eine: der Vertrag nennt als erklärende Klasse
 * diesen Trait, nicht die Klasse, die ihn benutzt.*
 *
 * ⚠️ *`display_size = 20` ist `INFERRED` — das Konzept nennt keine Vorgabe; 20 Zeichen ist die
 * Breite, die die Eingabefelder heute haben.*
 *
 * @see docs/einstellungen-anforderungen.md
 */
trait NodeAttributes
{
    /** @var list<Renderer> */
    #[Attribut(listOf: Renderer::class, allowDuplicates: false)]
    public array $renderer = [];

    /** @var list<Converter> */
    #[Attribut(listOf: Converter::class)]
    public array $converter = [];

    /**
     * Die gewählten Zusatzfunktionen ([D-845](../../../../docs/NewConcept/90-decision-log.md)) — Vorbelegung, «Mehrere hinzufügen», die
     * Prüfungen beim Speichern. *Hier stand `validator`; die Prüfungen sind jetzt Zusatzfunktionen, und gewählt war nie eine.*
     *
     * @var list<Addon>
     */
    #[Attribut(listOf: Addon::class)]
    public array $addons = [];

}
