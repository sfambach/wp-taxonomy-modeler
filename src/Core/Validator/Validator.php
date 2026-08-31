<?php declare(strict_types=1);

namespace Taxmod\Core\Validator;

use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Model\TypedValue;

/**
 * Eine Frage an einen Wert, die der **Typ** nicht stellen kann.
 *
 * ⚠️ **Die Grenze zum Konverter steht im Konzept, und sie ist scharf**
 * ([R36a](../../../docs/NewConcept/30-renderer.md#r36a--the-converter-removes-what-cannot-have-been-meant-the-validator-asks-about-the-rest),
 * [D-166](../../../docs/NewConcept/90-decision-log.md)): *der Konverter entfernt, was nicht gemeint sein
 * kann; der Validator fragt nach dem Rest.* Ein abschliessendes Leerzeichen wird still gestrichen — es
 * hat nie jemand gemeint. Ob eine Zahl in ihre Grenzen passt, ist eine Frage.
 *
 * ⚠️ **Die Grenze zum Typ ist gemessen und nicht geraten.** *Am 2026-08-31 durchprobiert: `int`,
 * `decimal`, `char`, `bool` und `datetime` **verweigern** einen unmöglichen Wert schon selbst — dort
 * wäre ein Formvalidator eine zweite Heimat für eine Regel. `email` nimmt `kein-at`, `color` nimmt
 * `rot`, `version` nimmt `eins`: **dort fehlt wirklich etwas.** Der mitgelieferte Satz ist genau nach
 * dieser Messung geschnitten.*
 *
 * ⚠️ **Mehrere je Feld** ([D-158](../../../docs/NewConcept/90-decision-log.md)): *«ein Attribut kann
 * mehrere Validatoren tragen — Bereich, Format, Eindeutigkeit — also gibt es drei Meldungen statt
 * einer.»* Damit ist ein Validator **nicht** wie ein Konverter, von dem genau einer in Kraft ist
 * ([R33b](../../../docs/NewConcept/30-renderer.md#r33b--several-are-eligible-exactly-one-is-in-effect)).
 *
 * ⚠️ **Er macht keine Worte.** *Der Kern kann keinen benutzersichtbaren Text erzeugen (`AR-2`,
 * [OQ-087](../../../docs/NewConcept/91-open-questions.md)), also gibt er eine {@see Complaint} mit einem
 * **Schlüssel** und **Platzhaltern** zurück. Den Satz baut der Rand.
 * [R36b](../../../docs/NewConcept/30-renderer.md#r36b--a-validator-message-is-a-label-and-there-is-one-per-validator)
 * verlangt genau das: «Platzhalter überleben, und das benannte Format bleibt Pflicht» — ein Satz, der
 * aus Bruchstücken zusammengesetzt wird, ist in eine Sprache mit anderer Wortstellung nicht
 * übersetzbar.*
 *
 * ⚠️ **Er sagt, was falsch ist, nicht was zu tun ist.** *R36b, zweite Regel: die **angebotene
 * Korrektur** ist Verhalten, und Verhalten ist Code ([D-036](../../../docs/NewConcept/90-decision-log.md)).*
 *
 * ```mermaid
 * flowchart LR
 *   V["Wert"] --> T{"der Typ nimmt ihn?"}
 *   T -->|nein| R["verweigert, hier endet es"]
 *   T -->|ja| P["Validatoren dieses Typs"]
 *   P --> B["Beschwerden, oder keine"]
 * ```
 *
 * @see docs/NewConcept/30-renderer.md
 */
interface Validator
{
    /**
     * Der Name, unter dem er gewählt wird — der Wert, der in der Einstellung `validator` landet.
     *
     * ⚠️ **Ein Token, kein Label**, genau wie bei einem Renderer oder Konverter: er steht im Modell und
     * wird verglichen, also wird er **nie** übersetzt (`AR-2`).
     */
    public function name(): string;

    /**
     * Für welche einfachen Typen er überhaupt in Frage kommt.
     *
     * @return list<SimpleType> Leer heisst «für jeden» — und das braucht in einem Docblock einen Grund.
     */
    public function handles(): array;

    /**
     * Was an diesem Wert zu beanstanden ist — oder `null`, wenn nichts.
     *
     * ⚠️ **Ein fehlender Wert ist keine Beanstandung.** *Ob ein Wert **da sein muss**, sagt die
     * Multiplizität ([D-549](../../../docs/NewConcept/90-decision-log.md)), und sie sagt es an einer
     * Stelle. Ein Validator, der «leer» beanstandet, wäre die zweite — und die beiden würden
     * auseinanderlaufen.*
     *
     * ⚠️ *Die Einstellungen kommen **herein** und werden nicht geholt: derselbe Zuschnitt wie beim
     * Zeichnen ([D-445](../../../docs/NewConcept/90-decision-log.md)) — wer prüft, fragt die Datenbank
     * nicht.*
     *
     * ⚠️ **Der Typ reist mit, und ohne ihn war der erste Entwurf falsch.** *Ein Formvalidator, der drei
     * Formen kennt und «irgendeine passt» prüft, lässt `1.2.3` als E-Mail durch — die Fassungsnummer
     * passt. **Welche Form gilt, sagt der Typ**, und er wird hier hereingegeben statt geraten, genau wie
     * bei {@see \Taxmod\Core\Converter\Converter::written()}.*
     *
     * @param array<string, TypedValue> $settings Die aufgelösten Angaben dieser Verwendungsstelle,
     *                                            nach Schlüssel — `min`, `max` und was sonst gilt.
     * @return list<Complaint>          Leer heisst: nichts zu beanstanden.
     */
    public function check(TypedValue $value, ?SimpleType $type, array $settings): array;
}
