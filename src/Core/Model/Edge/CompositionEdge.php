<?php declare(strict_types=1);

namespace Taxmod\Core\Model\Edge;

use Taxmod\Core\Model\Relation;

/**
 * Eine Kante auf einen Teil — der Datensatz dahinter stirbt mit dem Datensatz des Besitzers.
 *
 * ⚠️ **Es ist eine Aussage ueber Datensaetze, nicht ueber Knoten**
 * ([D-639](../../../../docs/NewConcept/90-decision-log.md)). *Sein Wort, als Berichtigung an mich:
 * «du vermischst jetzt grade Modell und Daten, oder? Komposition sagt ja nicht, dass wenn ich den
 * Knoten loesche im Modell, auch der Kompositionsknoten mitgeloescht wird. Er sagt nur: wenn ich
 * einen **Datensatz** loesche — also den Datensatz von Kunde A —, dann muss auch die Adresse von
 * Kunde A geloescht werden.»*
 *
 * ⚠️ **Deshalb darf ein **Zielknoten** hier selbstverstaendlich geteilt sein.** *Gemessen zeigen 36
 * von 42 Kompositionskanten auf Typknoten — `Text` 26 mal, `Einheitenwert` 6 mal —, und das ist
 * richtig: ein Typ ist im Modell geteilt, exklusiv ist der Datensatz dahinter. **Ein Waechter, der
 * das geteilte Ziel verboten haette, haette 36 richtige Zeilen berichtigt** (D-639).*
 *
 * @see docs/pakete/modelltabellen/package.md
 */
final class CompositionEdge extends Relation
{
    public function isSetting(): bool
    {
        return false;
    }

    public function deletesRecordWithOwner(): bool
    {
        return true;
    }
}
