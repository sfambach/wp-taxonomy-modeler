<?php declare(strict_types=1);

namespace Taxmod\Core\Model\Setting;

use Taxmod\Core\Exception\MalformedSettingsValue;
use Taxmod\Core\Model\ReferenceSpace;
use Taxmod\Core\Model\TypedValue;

/**
 * Eine **Zeile** — ein gespeicherter Wert: ein Attribut, ein Träger, ein Wert (Anforderung 4.4).
 *
 * ```mermaid
 * flowchart LR
 *   T["Träger: Knoten **oder** Objekt"] --> Z["Zeile: klasse.attribut · position · aktiv"]
 *   K["dazu wahlweise: Kante"] -.-> Z
 *   Z --> W["genau ein Wert: int · decimal · text · Knoten · Objekt"]
 * ```
 *
 * ⚠️ **Die vier Zusagen, die diese Klasse selbst hält, damit kein Speicher sie halten muss:**
 * - **genau ein Träger** — `nodeId` oder `objectId`, nie beide, nie keiner (4.4.1);
 * - **die Kante nur als Zusatz** — `relationId` heisst «gilt nur an dieser Kante» und steht nie
 *   allein (4.4.2; sein Bild: *«ein knoten-datensatz, der zusätzlich zum knoten noch die kante
 *   bekommt»*);
 * - **genau ein Wert** — eine der Wertspalten, oder ein Objekt (4.4.5);
 * - **kein Datum, kein Datensatz** — die Typen einer Einstellung sind `bool`, `int`, `decimal`,
 *   `text`, Enum, Knotenverweis, Objekt (3.1.1), sonst nichts.
 *
 * ⚠️ *`bool` liegt in `wert_int` als `0`/`1` ([D-315](../../../../docs/NewConcept/90-decision-log.md))
 * — die Anforderung nennt `wert_bool` als eigene Spalte; beim Bauen fiel sie mit `wert_int` zusammen,
 * weil {@see TypedValue} einen Wahrheitswert schon immer so trägt. Der Vertrag weiss, welches Attribut
 * ein `bool` ist; die Ablage muss es nicht zweimal wissen. Vermerkt in der Anforderung 4.4.5.*
 *
 * @see docs/einstellungen-anforderungen.md
 */
final class SettingsValue
{
    /**
     * @param int|null $id       `null`, solange die Zeile nicht geschrieben ist.
     * @param int      $position Die Stelle in einer Liste, sonst 0 (4.4.4).
     * @param bool     $aktiv    Nur für Zeilen zu geerbten Listeneinträgen an einer Kante (5.5.3);
     *                           sonst immer `true`.
     */
    private function __construct(
        public readonly ?int $nodeId,
        public readonly ?int $objectId,
        public readonly ?int $relationId,
        public readonly string $klasse,
        public readonly string $attribut,
        public readonly TypedValue $value,
        public readonly ?int $valueObjectId,
        public readonly int $position = 0,
        public readonly bool $aktiv = true,
        public readonly ?int $id = null,
        public readonly int $version = 1,
    ) {
        if (($nodeId === null) === ($objectId === null)) {
            throw MalformedSettingsValue::notOneCarrier();
        }

        if ($relationId !== null && $relationId <= 0) {
            throw MalformedSettingsValue::relationIsNoCarrier();
        }

        if ($klasse === '' || $attribut === '') {
            throw MalformedSettingsValue::noAddress();
        }

        $hatWert   = ! $value->isNothing();
        $hatObjekt = $valueObjectId !== null;

        if ($hatWert === $hatObjekt) {
            throw MalformedSettingsValue::notOneValue();
        }

        if ($hatWert && ($value->date !== null || $value->referenceSpace === ReferenceSpace::Record)) {
            throw MalformedSettingsValue::typeNotASetting($value->typeName());
        }
    }

    /** Ein Wert am Knoten — oder an dieser Kante, wenn `$relationId` gesetzt ist. */
    public static function atNode(int $nodeId, string $klasse, string $attribut, TypedValue $value, ?int $relationId = null, int $position = 0, bool $aktiv = true): self
    {
        return new self($nodeId, null, $relationId, $klasse, $attribut, $value, null, $position, $aktiv);
    }

    /** Ein Wert in einem Einstellungsobjekt — oder an dieser Kante überschrieben. */
    public static function inObject(int $objectId, string $klasse, string $attribut, TypedValue $value, ?int $relationId = null, int $position = 0, bool $aktiv = true): self
    {
        return new self(null, $objectId, $relationId, $klasse, $attribut, $value, null, $position, $aktiv);
    }

    /** Ein komplexer Wert am Knoten: die Zeile zeigt auf ein Einstellungsobjekt. */
    public static function objectAtNode(int $nodeId, string $klasse, string $attribut, int $valueObjectId, ?int $relationId = null, int $position = 0, bool $aktiv = true): self
    {
        return new self($nodeId, null, $relationId, $klasse, $attribut, TypedValue::nothing(), $valueObjectId, $position, $aktiv);
    }

    /** Ein komplexer Wert in einem Objekt: ein Objekt, das ein Objekt hält (2c, beliebig tief). */
    public static function objectInObject(int $objectId, string $klasse, string $attribut, int $valueObjectId, ?int $relationId = null, int $position = 0, bool $aktiv = true): self
    {
        return new self(null, $objectId, $relationId, $klasse, $attribut, TypedValue::nothing(), $valueObjectId, $position, $aktiv);
    }

    /** Aus der Tabelle — der Speicher kennt alle Spalten auf einmal. */
    public static function fromStorage(
        int $id,
        int $version,
        ?int $nodeId,
        ?int $objectId,
        ?int $relationId,
        string $klasse,
        string $attribut,
        int $position,
        bool $aktiv,
        ?int $int,
        ?string $decimal,
        ?string $text,
        ?int $nodeRef,
        ?int $valueObjectId,
        ?int $relationRef = null,
    ): self {
        $value = match (true) {
            $nodeRef !== null     => TypedValue::ofReference($nodeRef),
            $relationRef !== null => TypedValue::ofRelationReference($relationRef),
            default               => TypedValue::fromStorage($int, $decimal, $text, null, null),
        };

        return new self($nodeId, $objectId, $relationId, $klasse, $attribut, $value, $valueObjectId, $position, $aktiv, $id, $version);
    }

    public function stored(int $id, int $version = 1): self
    {
        return new self($this->nodeId, $this->objectId, $this->relationId, $this->klasse, $this->attribut, $this->value, $this->valueObjectId, $this->position, $this->aktiv, $id, $version);
    }

    /** Dieselbe Zeile mit anderem Wert — eine Version weiter; der Speicher hebt die alte auf. */
    public function withValue(TypedValue $value): self
    {
        return new self($this->nodeId, $this->objectId, $this->relationId, $this->klasse, $this->attribut, $value, null, $this->position, $this->aktiv, $this->id, $this->version + 1);
    }

    public function movedTo(int $position): self
    {
        return $position === $this->position
            ? $this
            : new self($this->nodeId, $this->objectId, $this->relationId, $this->klasse, $this->attribut, $this->value, $this->valueObjectId, $position, $this->aktiv, $this->id, $this->version + 1);
    }

    public function withAktiv(bool $aktiv): self
    {
        return $aktiv === $this->aktiv
            ? $this
            : new self($this->nodeId, $this->objectId, $this->relationId, $this->klasse, $this->attribut, $this->value, $this->valueObjectId, $this->position, $aktiv, $this->id, $this->version + 1);
    }

    /** Die Adresse — Klasse + Attribut (Anforderung 3.3). */
    public function address(): string
    {
        return $this->klasse . '.' . $this->attribut;
    }

    public function isAtEdge(): bool
    {
        return $this->relationId !== null;
    }

    public function holdsAnObject(): bool
    {
        return $this->valueObjectId !== null;
    }
}
