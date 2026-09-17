<?php declare(strict_types=1);

namespace Taxmod\Core\Addon;

/**
 * Was ein Validator beanstandet — als **Schlüssel mit Platzhaltern**, nicht als Satz.
 *
 * ⚠️ **Der Kern macht keine Worte** (`AR-2`, [OQ-087](../../../docs/NewConcept/91-open-questions.md)).
 * *Ein Satz hier wäre benutzersichtbarer Text, hart im Code — genau das, was `AR-2` verbietet. Also
 * reist ein Schlüssel, und den Satz baut der Rand über den Textbereich.*
 *
 * ⚠️ **Die Platzhalter sind benannt und bleiben es** — *[R36b](../../../docs/NewConcept/30-renderer.md#r36b--a-validator-message-is-a-label-and-there-is-one-per-validator)
 * wörtlich: «Platzhalter überleben, und das benannte Format bleibt Pflicht. Ein Satz, der aus
 * Bruchstücken zusammengesetzt wird, ist in eine Sprache mit anderer Wortstellung nicht übersetzbar.»
 * Deshalb `['min' => '3']` und nicht eine Liste, die nach Position gedeutet wird.*
 *
 * ⚠️ **Sie sagt, was falsch ist, nicht was zu tun ist.** *R36b, zweite Regel: die angebotene Korrektur
 * ist Verhalten, und Verhalten ist Code ([D-036](../../../docs/NewConcept/90-decision-log.md)) — nicht
 * die Sache des Autors und nicht die dieser Klasse.*
 *
 * ⚠️ *Der Validator nennt sich selbst mit, weil ein Feld **mehrere** tragen kann
 * ([D-158](../../../docs/NewConcept/90-decision-log.md)) und eine Meldung sonst nicht zuzuordnen wäre —
 * dieselbe Adressierung, die R36b für den Text eines Validators beschreibt.*
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class Complaint
{
    /**
     * @param string                $validator Der Name des Validators, der beanstandet hat.
     * @param string                $key       Der Schlüssel des Satzes — ein Token, nie ein Text.
     * @param array<string, string> $values    Benannte Platzhalter, etwa `['min' => '3']`.
     */
    public function __construct(
        public readonly string $validator,
        public readonly string $key,
        public readonly array $values = [],
    ) {
    }
}
