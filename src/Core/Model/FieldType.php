<?php declare(strict_types=1);

namespace Taxmod\Core\Model;

/**
 * Was die Werte eines Feldes sind, das auf diesen Knoten zeigt — Daten des Benutzers oder des Autors.
 *
 * ⚠️ **[D-518](../../../docs/NewConcept/90-decision-log.md), seine Frage wörtlich:** *«eine Option
 * erfinden, die sagt: ist Field oder ist Setting?»* — und sein Zusatz, der die Form entschied: *«ich
 * weiss nicht, ob wir da nicht mehrere Werte nehmen, damit man das später erweitern kann.»*
 *
 * ⚠️ **Ein Aufzählungstyp und kein `bool`, und das ist gemessen nötig statt vorsorglich.** *Von den
 * fünf Belegen in [D-508](../../../docs/NewConcept/90-decision-log.md) ist **einer schon ein dritter
 * Fall**: `multiplicity` gilt **nur** an der Verwendungsstelle ({@see SettingKey::isEdgeOnly()}).
 * **Ein Schalter hätte am ersten Tag eine Ausnahme gebraucht.***
 *
 * ⚠️ **Er sitzt am Knoten, nicht an der Kante** — auf sein Wort: *«der Knoten bekommt eine
 * zusätzliche Spalte, wie es die Kante auch hat.»* *Und die Kollision, die dagegen sprach, ist mit
 * [D-516](../../../docs/NewConcept/90-decision-log.md) verschwunden: `Integer` ist Ziel von
 * Autoren- **und** Benutzerdaten, `Integer › min` nicht — **ein eigener Knoten je Angabe ist genau,
 * was eine Markierung am Knoten möglich macht.***
 *
 * ⚠️ **`null` heisst «frag meine Vorfahren», nicht «unbekannt».** *Dieselbe Auflösung, die
 * {@see \Taxmod\Core\Service\Rendering} für den **Typ** schon fährt — der Vorfahrenlauf, den
 * [D-516](../../../docs/NewConcept/90-decision-log.md) gemessen hat. **Eine Spalte plus Vorfahrenlauf
 * gibt Vererbung ohne die Settings-Maschinerie**, und genau daran scheiterte die Überlegung, ob so
 * eine Angabe überhaupt eine Spalte sein darf: `multiplicity` blieb ein Setting, *«because it inherits
 * and can be narrowed»* ([50 Persistence](../../../docs/NewConcept/50-wordpress-persistence.md)).*
 *
 * @see docs/NewConcept/02-field-and-setting.md
 */
enum FieldType: string
{
    /**
     * Der Benutzer trägt den Wert ein; er liegt in einem Datensatz.
     *
     * ⚠️ *Der Fall ohne Markierung. **Nicht weil er der wichtigere ist**, sondern weil 97 von 136
     * Knoten ihn heute erfüllen und eine Vorgabe, die für alles Bestehende stimmt, keine Wanderung
     * braucht.*
     *
     * ⚠️ **Hiess `Field` mit dem Wert `field` und heisst seit TASK-007 `Model` mit dem Wert
     * `model`** — *nach seiner Selbstkorrektur: «Entschuldigung, Model und Settings, richtig.»
     * **Gewandert ist dabei nichts, gemessen: der Wert `field` stand in keiner einzigen Zeile**,
     * weder lebend (97 leer, 39 `setting`) noch im Schatten (17 202 leer, 190 `setting`).*
     *
     * ⚠️ **Offen bleibt, ob «nichts» dasselbe ist wie `model`** — *124 von 128 Knoten sagten nichts,
     * und der Vorfahrenlauf antwortet mit {@see standard()}. Ob das dastehen **muss**, ist nicht
     * entschieden (`PR-4`) und wird hier nicht beiläufig beantwortet.*
     */
    case Model = 'model';

    /**
     * Der Autor trägt den Wert ein; er gehört zum Modell.
     *
     * ⚠️ *Das, was bis [D-506](../../../docs/NewConcept/90-decision-log.md) «eine Einstellung» hiess.
     * **Das Wort steht hier, weil der Eigentümer es benutzt** — «ist Field oder ist Setting» — und
     * nicht, weil es ein zweites Konzept bezeichnet: es ist ein Feld mit dieser einen Angabe.*
     */
    case Setting = 'setting';

    /** Was gilt, wenn niemand etwas gesagt hat — auch am Ende des Vorfahrenlaufs. */
    public static function standard(): self
    {
        return self::Model;
    }

    /**
     * Aus dem, was in der Spalte steht — `null` und Unfug ergeben `null`.
     *
     * ⚠️ *`tryFrom` und nicht `from`: eine Spalte kann einen Wert einer neueren Fassung enthalten,
     * wenn jemand zurückrollt, und dann ist «niemand hat etwas gesagt» die richtige Antwort und keine
     * Ausnahme.*
     */
    public static function fromStorage(?string $stored): ?self
    {
        return $stored === null || $stored === '' ? null : self::tryFrom($stored);
    }

    /** Die Überschrift, unter der Felder dieser Sorte stehen ([D-518](../../../docs/NewConcept/90-decision-log.md)). */
    public function headingKey(): string
    {
        return match ($this) {
            self::Model   => 'fields',
            self::Setting => 'settings',
        };
    }
}
