<?php declare(strict_types=1);

namespace Taxmod\Core\Validator;

use Taxmod\Core\Exception\NotAPossibleTarget;
use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Model\TypedValue;

/**
 * Welche Validatoren es gibt, welche zu einem Typ passen, und was sie an einem Wert beanstanden.
 *
 * ⚠️ **Der Unterschied zur Konverter-Registratur ist die Anzahl.** *Von einem Konverter ist genau
 * **einer** in Kraft ([R33b](../../../docs/NewConcept/30-renderer.md#r33b--several-are-eligible-exactly-one-is-in-effect)).
 * Von Validatoren sind es **alle, die passen** — [D-158](../../../docs/NewConcept/90-decision-log.md):
 * «ein Attribut kann mehrere Validatoren tragen — Bereich, Format, Eindeutigkeit — also gibt es drei
 * Meldungen statt einer.» Deshalb gibt {@see self::complaintsAbout()} eine **Liste** zurück und hört
 * nicht beim ersten Treffer auf.*
 *
 * ⚠️ **Und keinen Standard je Typ, wie bei den Konvertern.** *Ein Renderer muss immer aufgelöst werden,
 * weil irgendetwas das Feld zeichnen muss. Ein Validator hat diese Pflicht nicht: keiner heisst «nichts
 * zu fragen», und das ist eine vollständige Antwort. **Was `handles()` sagt, ist «kommt in Frage», nicht
 * «gilt».** Welche gelten, sagt das Modell über die Einstellung `validator`.*
 *
 * ```mermaid
 * flowchart LR
 *   T["Typ"] --> E["passende Validatoren"]
 *   E --> M["die Einstellung validator waehlt aus"]
 *   M --> B["Beschwerden, oder keine"]
 * ```
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class ValidatorRegistry
{
    /** @var array<string, Validator> */
    private array $byName = [];

    public function add(Validator $validator): void
    {
        $this->byName[$validator->name()] = $validator;
    }

    public function knows(string $name): bool
    {
        return isset($this->byName[$name]);
    }

    /**
     * ⚠️ *Ein unbekannter Name ist ein Fehler und kein leeres Ergebnis — `CD-10`. **Ein Validator, der
     * still nicht läuft, ist schlimmer als keiner**: die Zeile sieht geprüft aus.*
     */
    public function byName(string $name): Validator
    {
        return $this->byName[$name] ?? throw NotAPossibleTarget::thereIsNoSuchValidator($name);
    }

    /** @return list<string> Die Namen, in der Reihenfolge der Anmeldung. */
    public function names(): array
    {
        return array_keys($this->byName);
    }

    /**
     * Die Namen, die als **Knoten** unter `Validator` stehen sollen.
     *
     * ⚠️ **Dieselbe Naht, die die Renderer und Konverter benutzen** — *eine Saat mit eigener Namensliste
     * läuft auseinander, ohne dass etwas rot wird: ein neuer Validator im Code, kein Knoten im Modell,
     * und die Auswahl zeigt ihn nie.*
     *
     * @return list<string>
     */
    public function namesForNodes(): array
    {
        return $this->names();
    }

    /**
     * Welche Validatoren für diesen Typ in Frage kommen.
     *
     * ⚠️ *Ein leeres `handles()` heisst «für jeden» — heute hat keiner der mitgelieferten eines, und ein
     * künftiger bräuchte im Docblock einen Grund.*
     *
     * @return list<Validator>
     */
    public function eligibleFor(?SimpleType $type): array
    {
        if ($type === null) {
            return [];
        }

        $aus = [];

        foreach ($this->byName as $validator) {
            $handles = $validator->handles();

            if ($handles === [] || in_array($type, $handles, true)) {
                $aus[] = $validator;
            }
        }

        return $aus;
    }

    /**
     * Was **diese** Validatoren an diesem Wert beanstanden.
     *
     * ⚠️ **Alle, nicht der erste.** *[D-158](../../../docs/NewConcept/90-decision-log.md): drei Meldungen
     * statt einer. Beim ersten Treffer aufzuhören hiesse, einen Menschen dreimal hintereinander speichern
     * zu lassen, um drei Dinge zu erfahren.*
     *
     * ⚠️ *Ein Name, den die Registratur nicht kennt, wird **übersprungen** und nicht geworfen: die Liste
     * kommt aus dem Modell, und ein Tippfehler dort darf nicht das Speichern einer ganzen Seite
     * verhindern. Dass ein unbekannter Name auffällt, ist Sache von {@see self::byName()} — dort, wo
     * jemand ihn absichtlich holt.*
     *
     * @param  list<string>             $names    Die Namen aus der Einstellung `validator`.
     * @param  array<string, TypedValue> $settings
     * @return list<Complaint>
     */
    public function complaintsAbout(TypedValue $value, ?SimpleType $type, array $names, array $settings): array
    {
        $aus = [];

        foreach ($names as $name) {
            if (! $this->knows($name)) {
                continue;
            }

            $validator = $this->byName[$name];
            $handles   = $validator->handles();

            // ⚠️ *Ein Validator, der diesen Typ nicht kann, urteilt nicht — sonst beanstandete der
            // Bereichsvalidator jeden Text, weil er ihn nicht vergleichen kann.*
            if ($type !== null && $handles !== [] && ! in_array($type, $handles, true)) {
                continue;
            }

            foreach ($validator->check($value, $type, $settings) as $complaint) {
                $aus[] = $complaint;
            }
        }

        return $aus;
    }
}
