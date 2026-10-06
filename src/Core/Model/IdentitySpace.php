<?php declare(strict_types=1);

namespace Taxmod\Core\Model;

/**
 * Aus welchem Id-Raum eine Nummer stammt — aus dem der **Knoten** oder aus dem der **Kanten**.
 *
 * ⚠️ **Dasselbe Muster wie {@see ReferenceSpace}, und es steht in
 * [`package.md` §6](../../../docs/pakete/modelltabellen/package.md):** *ein Fremdschlüssel nennt
 * seine Zieltabelle; kann eine Spalte auf mehr als eine zeigen, nennt eine zweite Spalte den Raum.*
 * So macht es `changelog.owner_kind` seit jeher, so macht es `relation_records.value_ref_kind` seit
 * TASK-005 ([D-164](../../../docs/NewConcept/90-decision-log.md),
 * [D-597](../../../docs/NewConcept/90-decision-log.md)).
 *
 * ⚠️ **Der Anlass ist gemessen und kein Entwurf am Reissbrett** (`INF-035`, 2026-09-05): *`package5-check`
 * fragte `labels` nach einer frisch angelegten **Kante** und bekam sechs Zeilen — die Kante trug
 * dieselbe Nummer wie ein zwei Zeilen zuvor entstandener **Knoten**, der schon fünf Beschriftungen
 * hatte. **Seit [D-581](../../../docs/NewConcept/90-decision-log.md) ein Knoten keine Vererbungskante
 * mehr anlegt, laufen die beiden Zähler verschieden schnell** — und dann treffen sie sich.*
 *
 * ⚠️ *Ein Label hängt an einem Knoten **oder** an einer Kante
 * ([D-410](../../../docs/NewConcept/90-decision-log.md)), und die Id allein sagt seither nicht mehr,
 * an welchem.*
 *
 * @see docs/pakete/modelltabellen/package.md
 */
enum IdentitySpace: string
{
    /** Ein Knoten des Modells — `nodes.id`. */
    case Node = 'node';

    /** Eine Kante des Modells — `relations.id`. */
    case Relation = 'relation';
}
