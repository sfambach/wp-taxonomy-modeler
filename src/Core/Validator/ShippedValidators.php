<?php declare(strict_types=1);

namespace Taxmod\Core\Validator;

/**
 * Der Satz, der mitkommt — und er ist **nach einer Messung geschnitten**, nicht nach Gefühl.
 *
 * ⚠️ **Der Eigentümer wollte «Standardvalidatoren für jeden einfachen Datentyp».** *Beim Durchprobieren
 * am 2026-08-31 stellte sich heraus, dass «für jeden» die falsche Zahl wäre: **`int`, `decimal`, `char`,
 * `bool` und `datetime` verweigern einen unmöglichen Wert schon selbst.* Ein Formvalidator dort wäre
 * eine zweite Heimat für eine Regel, die der Typ durchsetzt — und die zweite läuft irgendwann anders.
 *
 * ⚠️ **Zwei bleiben, und jeder hat seinen gemessenen Grund:**
 *
 * | Validator | für | weil |
 * |---|---|---|
 * | `range` | `int`, `decimal`, `datetime` | der Typ sagt, was ein Wert **ist**; `min`/`max` sagen, was er **sein darf** |
 * | `shape` | `email`, `color`, `version` | diese drei Typen nehmen heute `kein-at`, `rot` und `eins` an |
 *
 * ⚠️ **Was absichtlich fehlt, und jedes Fehlen ist eine Aussage:**
 *
 * *Kein «nicht leer» — ob ein Wert da sein **muss**, sagt die Multiplizität
 * ([D-549](../../../docs/NewConcept/90-decision-log.md)), an einer Stelle. Kein Nachkommastellen-Prüfer —
 * wie viele eine Dezimalzahl trägt, ist [OQ-085](../../../docs/NewConcept/91-open-questions.md) und nicht
 * entschieden. Keine Textlänge — es gibt keinen Schlüssel dafür, und einen zu erfinden wäre eine
 * Entscheidung in einer Registratur. Keine **Eindeutigkeit** — [D-158](../../../docs/NewConcept/90-decision-log.md)
 * nennt sie als Beispiel, aber sie braucht eine Abfrage, und ein Validator im Kern fragt die Datenbank
 * nicht (`CD-1`); sie gehört an den Rand und ist eigene Arbeit.*
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class ShippedValidators
{
    public static function registry(): ValidatorRegistry
    {
        $registry = new ValidatorRegistry();

        $registry->add(new RangeValidator());
        $registry->add(new ShapeValidator());

        return $registry;
    }
}
