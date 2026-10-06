<?php declare(strict_types=1);

namespace Taxmod\Core\Model\Type;

use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Model\TypedValue;

/**
 * Ein Verweis auf einen WordPress-Benutzer.
 *
 * ⚠️ Als **Text** gespeichert, wie jeder undurchsichtige Schlüssel eines fremden Systems (`P4d`) —
 * der Kern sieht eine Zeichenkette und weiss nichts von WordPress
 * ([D-171](../../../../docs/NewConcept/90-decision-log.md)).
 *
 * ⚠️ **Er war der Typ ohne Renderer**, und genau das hat [D-484](../../../../docs/NewConcept/90-decision-log.md)
 * am 2026-08-28 gemessen: *im Aufzählungstyp, gesät, und nichts zeichnet ihn.* **Der Befund war der
 * Beleg für diese Klassen** — eine Liste an einer Stelle hätte ihn gezeigt. *Seit
 * [D-649](../../../../docs/NewConcept/90-decision-log.md) zeichnet ihn {@see \Taxmod\Core\Renderer\UserRefRenderer}.*
 *
 * ⚠️ **Und hier wohnt die Regel, die es sonst nirgends gibt** ([D-650](../../../../docs/NewConcept/90-decision-log.md)):
 * *bei `user_ref` — und nur dort — bestimmt `read_only`, **woher der Wert kommt**. Sein Wort:
 * «`read_only` könnte das genau machen: wenn es read only ist, wird die angemeldeter-Benutzer-Logik
 * verwendet, sonst die Liste.» Und zur Verortung: «soll nur an `user_ref` so sein, müsste ja auch
 * eine eigene Klasse sein, stimmts?»*
 *
 * ```mermaid
 * flowchart LR
 *   L["read_only = ja"] --> A["der angemeldete Benutzer · der Rand legt die Id vor"]
 *   B["read_only = nein"] --> W["aus einer Liste waehlen · noch nicht baubar"]
 * ```
 *
 * ⚠️ **Warum die Doppelbedeutung hier vertretbar ist und sonst nirgends** ([D-650](../../../../docs/NewConcept/90-decision-log.md)):
 * *bei `user_ref` fallen die beiden Aussagen zusammen — **wer nicht wählen kann, für den ist der
 * eigene Benutzer der einzig sinnvolle Wert.** Kein anderer Typ erfährt davon, weil die Regel in
 * seiner Klasse steht ({@see SpecialisedType::presetFor()} sagt für alle anderen `null`).*
 *
 * @see docs/NewConcept/10-domain-core.md
 */
final class UserRefType extends SpecialisedType
{
    public function type(): SimpleType
    {
        return SimpleType::UserRef;
    }

    public function nodeName(): string
    {
        return 'User reference';
    }

    public function humanName(): string
    {
        return 'user reference';
    }

    public function column(): string
    {
        return 'value_text';
    }

    /**
     * Ob der Wert **der angemeldete Benutzer** ist — die eine Hälfte von [D-650](../../../../docs/NewConcept/90-decision-log.md).
     *
     * ⚠️ *Gesperrt heisst hier nicht «du darfst nicht ändern», sondern «es gibt nichts zu wählen,
     * also bist du es». Das ist der ganze Unterschied zu jedem anderen Typ, an dem `read_only` nur
     * die Bedienbarkeit beantwortet.*
     */
    public function takesTheSignedInUser(bool $readOnly): bool
    {
        return $readOnly;
    }

    /**
     * Ob der Wert **aus einer Liste** gewählt wird — die andere Hälfte, und sie ist nicht baubar.
     *
     * ⚠️ **Sein Wort dazu** ([D-650](../../../../docs/NewConcept/90-decision-log.md)): *«die Liste
     * müsste aber auch aus dem Frontend kommen»* — also erst mit dem Rand-Wähler
     * ([D-624](../../../../docs/NewConcept/90-decision-log.md)). *Bis dahin ist die eine Hälfte in
     * Betrieb und die andere **sichtbar gesperrt, mit Begründung**
     * ({@see \Taxmod\Core\Renderer\UserRefRenderer::input()}) — nicht erfunden.*
     */
    public function isPickedFromAList(bool $readOnly): bool
    {
        return ! $readOnly;
    }

    /**
     * Die Vorbelegung beim Anlegen: die Id, die der Rand vorlegt — und nur, wo gesperrt ist.
     *
     * ⚠️ **Warum es beim Anlegen überhaupt eine braucht** ([D-609](../../../../docs/NewConcept/90-decision-log.md)):
     * *«ein Datensatz entsteht beim ersten Schreiben, nicht beim Ansehen» — also gibt es beim Anlegen
     * noch keinen Wert, aus dem der Rand einen Namen machen könnte. **Die Id wird vorgelegt, damit sie
     * mit dem ersten Schreiben Teil des Datensatzes wird**; von da an ist sie ein Wert wie jeder
     * andere.*
     *
     * ⚠️ **Nur bei `read_only`, und das ist dieselbe Regel wie oben und keine zweite.** *Wo gewählt
     * werden darf, wäre eine stille Vorbelegung eine Antwort, die niemand gegeben hat — genau der
     * Fehler, den [D-609](../../../../docs/NewConcept/90-decision-log.md) und
     * [D-610](../../../../docs/NewConcept/90-decision-log.md) an leeren Datensätzen und Nullzeilen
     * ausgeräumt haben.*
     *
     * ⚠️ *Ohne angemeldeten Benutzer gibt es keine Vorbelegung — `null` ist die ehrliche Antwort, und
     * das Feld bleibt unbeantwortet.*
     */
    public function presetFor(bool $readOnly, ?string $signedInUser): ?TypedValue
    {
        if (! $this->takesTheSignedInUser($readOnly) || $signedInUser === null || $signedInUser === '') {
            return null;
        }

        return TypedValue::ofText($signedInUser);
    }
}
