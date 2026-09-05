<?php declare(strict_types=1);

namespace Taxmod\Core\Model\Edge;

use Taxmod\Core\Model\Relation;

/**
 * Eine Einstellungskante — **eine Komposition, die der Modellierer setzt statt eines Benutzers**.
 *
 * ⚠️ **[D-526](../../../../docs/NewConcept/90-decision-log.md), sein Vorschlag und seine
 * Begruendung:** *«nehmen wir an, es gibt nur settings, nicht setting_composition und
 * setting_aggregation, weil settings immer eine Komposition ist — und settings erbt von
 * composition.»* **Das «erbt von» steht seit [D-639](../../../../docs/NewConcept/90-decision-log.md)
 * nicht mehr als Methode in einer Aufzaehlung, sondern als Verhalten in dieser Klasse.**
 *
 * ⚠️ **Und sie ist die einzige der drei, die nicht vom Ast abgelesen wurde** — `Composition` und
 * `Aggregation` folgten aus dem Ort des Ziels
 * ([D-161](../../../../docs/NewConcept/90-decision-log.md)), **eine Einstellung sagt jemand**. Seit
 * [D-621](../../../../docs/NewConcept/90-decision-log.md) sagt die Kante ohnehin, was etwas hier ist.
 *
 * ⚠️ *Warum sie mitloescht: die Werte an einer Einstellungskante gehoeren dem Knoten, den sie
 * einstellen — sie ohne ihn stehen zu lassen erzeugte genau die Waisen, die `orphans-check` zaehlt
 * ([D-591](../../../../docs/NewConcept/90-decision-log.md)).*
 *
 * @see docs/pakete/modelltabellen/package.md
 */
final class SettingEdge extends Relation
{
    public function isSetting(): bool
    {
        return true;
    }

    public function deletesRecordWithOwner(): bool
    {
        return true;
    }
}
