<?php declare(strict_types=1);

namespace Taxmod\Core\Model;

/**
 * One thing somebody entered against a model node.
 *
 * ⚠️ **Its id comes from the record space, not the model's** (D-164). The two halves do not
 * share a number space: between nodes and relations the ambiguity is real, and there it must be one
 * space; between model and data it is not, because the target's **branch** decides which sort a
 * reference resolves to, deterministically and per relation (D-131).
 *
 * ⚠️ **It keeps the model version it was written against** (D-060, D-210) — *written against*,
 * not *checked against*. Records at several versions are a normal steady state, and only what
 * actually conflicted is ever touched.
 *
 * @see docs/NewConcept/10-domain-core.md
 */
final class NodeRecord
{
    public function __construct(
        public readonly int $id,
        public readonly int $nodeId,
        public readonly int $nodeVersion,
        public readonly string $createdAt,
        /**
         * Wem diese Zeile gehört — einer Eingabe, dem Modell, oder dem Bauen.
         *
         * ⚠️ **Ein Kennzeichen und kein eigener Speicher** ([D-028](../../../docs/NewConcept/90-decision-log.md)):
         * *«Testdaten sind gewöhnliche Daten, gekennzeichnet … kein eigener Testdaten-Speicher und
         * keine dritte Art von Ding.»* **Dieselbe Regel trägt jetzt einen Fall mehr**
         * ([D-521](../../../docs/NewConcept/90-decision-log.md)): eine Zeile, die der **Autor**
         * geschrieben hat, ist auch nur gewöhnliche Daten, gekennzeichnet.
         *
         * ⚠️ *Es hiess bis Schema 15 `isTest` und war ein `bool` — **und ein `bool` hält drei Zustände
         * nicht**. Was es steuert, bleibt: die Sichtbarkeit vorn und sonst nichts.*
         *
         * ⚠️ *Zuletzt in der Aufzählung und mit Vorgabe, damit jede vorhandene Aufrufstelle
         * weiterläuft und **eine gewöhnliche Eingabe** meint — dasselbe, was `DEFAULT 'user'` in
         * der Tabelle für die vorhandenen Zeilen tut.*
         */
        public readonly RecordType $recordType = RecordType::User,
        /**
         * Die Verwendungsstelle, der dieser Satz gehört — `0`, wenn er dem Knoten gehört.
         *
         * ⚠️ **Sein Wort, und es war schon einmal vorgesehen:** *«aber wir hatten die relation id
         * schon vorgesehen im record»* ([D-667](../../../docs/NewConcept/90-decision-log.md)).
         *
         * ⚠️ **Damit fällt `relation_records.path`.** *Dort standen zwei Nummern als **Text**
         * (`<Verwendungsstelle>.<Einstellungskante>`), weil es keine Stelle gab, an der die erste
         * hingehörte. Jetzt gibt es sie: **der Satz sagt, zu wem er gehört**, und die Wertzeile sagt
         * nur noch, welche Einstellung sie meint. Sein Bild vom Zwischenschritt, den er verworfen
         * hat: «also verklausulierst du path als Text».*
         *
         * ⚠️ *`0` und nicht `null`: «gehört keiner Kante» ist kein Sonderfall, sondern der
         * Normalfall — jeder Satz, den es vor Fassung 37 gab.*
         */
        public readonly int $relationId = 0,
    ) {
    }
}
