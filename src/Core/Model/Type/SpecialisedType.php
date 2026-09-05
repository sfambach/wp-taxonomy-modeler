<?php declare(strict_types=1);

namespace Taxmod\Core\Model\Type;

use Taxmod\Core\Exception\NotAValueOfThatType;
use Taxmod\Core\Model\FieldType;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Model\TypedValue;

/**
 * Ein einfacher Datentyp als **die Klasse seines Knotens** — eine je Typ.
 *
 * ⚠️ **Auf sein Wort** ([D-484](../../../../docs/NewConcept/90-decision-log.md)): *«ich hätte gerne
 * spezialisierte Klassen, weil dann auch klar ist, wie viele spezialisierte Typen wir haben.»* **Sein
 * Grund ist das Inventar**, und es ist der Grund, aus dem {@see SpecialisedTypes::all()} existiert:
 * *wie viele Typen es gibt, stand vorher an drei Stellen, und die stimmten nicht überein.*
 *
 * ⚠️ **Und sie sind keine leeren Hüllen** — der Eigentümer am 2026-09-05: *«die Klassen tun ja schon
 * was — zum Beispiel muss ja eine Funktionalität für min/max da sein, und ich kann sie prüfen: wenn
 * ich einen int-Knoten habe, kann ich das softwaretechnisch prüfen.»* Also trägt die Klasse, was
 * ihren Typ ausmacht: **wo sein Wert liegt**, **wie er gelesen wird**, **welche Form er verspricht**
 * und **ob eine Grenze für ihn einen Sinn ergibt**.
 *
 * ```mermaid
 * flowchart LR
 *   E["SimpleType · elf Faelle"] -->|"for()"| K["die Klasse des Falls"]
 *   K --> V["valueFrom · column · shape · bounds"]
 *   E -.->|"delegiert"| K
 * ```
 *
 * **Die Aufzählung bleibt die Adresse, die Klasse ist die Wahrheit.** *Der Aufzählungsfall ist das,
 * was in Zeilen, Signaturen und Tabellen steht; jede Auskunft darüber holt er sich hier. Zwei Orte
 * für dieselbe Auskunft wären genau die Doppelung, die `CLAUDE.md` verbietet.*
 *
 * ⚠️ **Was ausdrücklich *nicht* hier steht: welche Renderer der Typ zulässt.** *[D-483](../../../../docs/NewConcept/90-decision-log.md)
 * hat das verworfen — «wenn ich einen neuen Renderer hinzufüge, weiss der Knoten das gar nicht». Der
 * Anspruch bleibt beim Renderer ([D-481](../../../../docs/NewConcept/90-decision-log.md)).*
 *
 * ⚠️ **Sie erbt von {@see Node}, und das ist [D-620](../../../../docs/NewConcept/90-decision-log.md).**
 * *Bis zum 2026-09-05 stand hier eine Hierarchie **neben** dem Knoten — ein Typbeschreiber, während der
 * `int`-Knoten daneben ein schlichtes `Node` war. Der Eigentümer: «einen int-Knoten und eine
 * int-Klasse zusätzlich zu führen, reisst was auseinander, das eigentlich zusammengehört.» **Jetzt ist
 * der aus der Datenbank geladene `Integer`-Knoten ein {@see IntType}** — dieselbe Sache, ein Ort.*
 *
 * ⚠️ **Ein Exemplar ohne Id ist der Steckbrief des Typs, kein Knoten.** *{@see SpecialisedTypes::for()}
 * baut es, um zu fragen, was der Typ kann — Spalte, Muster, Grenzen. Diese Antworten hängen an der
 * **Klasse** und nicht an der Zeile, also antwortet der Steckbrief genauso wie der geladene Knoten.
 * **Zwei Wege, eine Wahrheit** — und darum steht sie nur hier.*
 *
 * @see docs/NewConcept/10-domain-core.md
 */
abstract class SpecialisedType extends Node
{
    /**
     * ⚠️ **Alles hat eine Voreinstellung, damit `new IntType()` weiter der Steckbrief ist** — und alles
     * steht in der Reihenfolge von {@see Node::fromStorage()}, damit eine geladene Zeile hier ankommt.
     *
     * ⚠️ *`implemented_by` wird **durchgereicht und nicht ergänzt**. Der Steckbrief trägt darum `null`,
     * und das ist richtig: die Spalte gehört einer Zeile, und ein Steckbrief ist keine. **Sie hier zu
     * erfinden hiesse auch, sie nicht mehr löschen zu können** — und der Notnagel in
     * {@see \Taxmod\WordPress\Persistence\SeededTypeNodes} lebt genau von einem Typknoten, an dem sie
     * fehlt.*
     */
    public function __construct(
        int $id = 0,
        int $version = 0,
        ?string $name = null,
        string $path = '',
        ?FieldType $fieldType = null,
        ?string $implementedBy = null,
        ?int $parentNodeId = null,
        int $sortOrder = 0,
        bool $hide = false,
    ) {
        parent::__construct(
            $id,
            $version,
            $name ?? $this->nodeName(),
            $path,
            $fieldType,
            $implementedBy,
            $parentNodeId,
            $sortOrder,
            $hide,
        );
    }

    /** Der Aufzählungsfall, den diese Klasse ausmacht. */
    abstract public function type(): SimpleType;

    /** Wie der gesäte Knoten heisst, den dieser Typ bekommt ([D-428](../../../../docs/NewConcept/90-decision-log.md)). */
    abstract public function nodeName(): string;

    /** Die Spalte in `relation_records`, in der ein Wert dieses Typs liegt. */
    abstract public function column(): string;

    /**
     * Wie der Typ ausgesprochen heisst, wo eine Beschriftung fehlt.
     *
     * ⚠️ *Kein benutzersichtbarer Text im Sinne von `AR-2` — es ist der maschinennahe Name, den der
     * Rand übersetzt. Voreinstellung ist der Wert des Aufzählungsfalls.*
     */
    public function humanName(): string
    {
        return $this->type()->value;
    }

    /** Das Muster, das ein Eingabefeld tragen darf, oder `null`. */
    public function pattern(): ?string
    {
        return null;
    }

    /** Welche Bildschirmtastatur das Bedienelement anfordern soll. */
    public function inputMode(): ?string
    {
        return null;
    }

    /**
     * Die Form, die dieser Typ **verspricht, aber nicht erzwingt** — für {@see \Taxmod\Core\Validator\ShapeValidator}.
     *
     * ⚠️ **Hierher gewandert aus dem Formvalidator**, wo sie als `match` über drei Typen stand. *Es
     * ist eine Aussage über den Typ und nicht über den Validator: `null` heisst «dieser Typ
     * verteidigt sich beim Lesen selbst», und welche drei das nicht tun, ist gemessen worden.*
     */
    public function wellFormedShape(): ?string
    {
        return null;
    }

    /**
     * Ob `min` und `max` für diesen Typ einen Sinn ergeben.
     *
     * ⚠️ **Hierher gewandert aus {@see \Taxmod\Core\Validator\RangeValidator::handles()}**, und das
     * ist die Frage, die der Eigentümer am 2026-09-05 gestellt hat: *«wenn ich einen int-Knoten habe,
     * kann ich das softwaretechnisch prüfen.»* Jetzt sagt der Typ es selbst, und der Validator liest
     * es ab, statt eine zweite Liste zu führen.
     */
    public function hasBounds(): bool
    {
        return false;
    }

    /**
     * Die Zeichen als Wert dieses Typs lesen — oder verweigern.
     *
     * ⚠️ *Leer heisst nichts, und das entscheidet niemand typspezifisch:* {@see SimpleType::valueFrom()}
     * fängt es vorher ab. Voreinstellung ist «so ablegen, wie es kam» — ob eine Adresse eine ist, ist
     * die Frage eines Validators ([D-319](../../../../docs/NewConcept/90-decision-log.md)).
     */
    public function valueFrom(string $characters): TypedValue
    {
        return TypedValue::ofText($characters);
    }

    /** Weist zurück, was die Form dieses Typs nicht hat. */
    final protected function mustMatchItsShape(string $characters): void
    {
        $pattern = $this->pattern();

        if ($pattern !== null && preg_match('/^' . $pattern . '$/', $characters) !== 1) {
            throw $this->refuse($characters);
        }
    }

    final protected function refuse(string $characters): NotAValueOfThatType
    {
        return NotAValueOfThatType::submitted($characters, $this->type()->value);
    }
}
