<?php declare(strict_types=1);

namespace Taxmod\Core\Validator;

use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Model\TypedValue;

/**
 * Hat der Wert die Form, die sein Typ verspricht?
 *
 * ⚠️ **Es gibt ihn nur, weil drei Typen absichtlich lose sind — und das ist gemessen, nicht vermutet.**
 * *Am 2026-08-31 durchprobiert:*
 *
 * | Typ | nimmt heute an | müsste er? |
 * |---|---|---|
 * | `email` | `kein-at`, `a@b`, `@b.example` | nein |
 * | `color` | `rot`, `#gg0000`, `#f00` | nein |
 * | `version` | `eins`, `1.2` | nein |
 * | `int`, `decimal`, `char`, `bool`, `datetime` | **verweigern selbst** | — |
 *
 * *Für die letzten fünf wäre ein Formvalidator eine **zweite Heimat** für eine Regel, die der Typ schon
 * durchsetzt. Für die ersten drei fehlt wirklich etwas.*
 *
 * ⚠️ **Warum die Typen lose bleiben und nicht schärfer werden.** *Ein Typ verweigert beim **Lesen** —
 * auch beim Lesen aus der Datenbank, auch bei einem Import, auch bei Bestand, der vor der Regel
 * entstanden ist. **Eine Verschärfung dort macht alte Daten unlesbar.** Ein Validator beanstandet, und
 * die Zeile bleibt lesbar: genau der Unterschied, den [R36a](../../../docs/NewConcept/30-renderer.md#r36a--the-converter-removes-what-cannot-have-been-meant-the-validator-asks-about-the-rest)
 * beschreibt.*
 *
 * ⚠️ **Der Typ entscheidet, welche Form gilt — und im ersten Entwurf tat er das nicht.** *Da prüfte
 * diese Klasse «passt irgendeine der drei Formen» und liess damit `1.2.3` als **E-Mail** durch, weil es
 * eine gültige Fassungsnummer ist. Ein Validator, der drei Regeln kennt und nicht weiss, welche gilt,
 * ist keine Prüfung, sondern eine Lotterie.*
 *
 * ⚠️ **Kein `default`-Zweig:** *ein vierter loser Typ soll hier auffallen und nicht still durchfallen —
 * dieselbe Vorsicht, die {@see \Taxmod\WordPress\Persistence\RenderingScaffold} für ihre Behälter nimmt.*
 *
 * ⚠️ *Die Sechsstellen-Form von `color` kennt {@see \Taxmod\Core\Renderer\ColorRenderer} auch — dort
 * entscheidet sie, **ob der Farbwähler den Wert halten kann**, hier, **ob er richtig ist**. Zwei Fragen,
 * dieselbe Form; zusammengelegt würde eine Anzeigefrage zu einem Verbot.*
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class ShapeValidator implements Validator
{
    public const NAME = 'shape';

    public function name(): string
    {
        return self::NAME;
    }

    public function handles(): array
    {
        return [SimpleType::Email, SimpleType::Color, SimpleType::Version];
    }

    public function check(TypedValue $value, ?SimpleType $type, array $settings): array
    {
        if ($value->isNothing() || $type === null) {
            return [];
        }

        $muster = $this->shapeOf($type);

        if ($muster === null) {
            return [];
        }

        $zeichen = $value->text;

        // ⚠️ *Kein Text heisst: dieser Wert trägt nichts, was diese Form haben könnte. Dann urteilt
        // dieser Validator nicht — «warum steht hier eine Zahl» ist die Frage des Typs.*
        if ($zeichen === null || $zeichen === '') {
            return [];
        }

        if (preg_match($muster, $zeichen) === 1) {
            return [];
        }

        return [new Complaint(self::NAME, 'wrong_shape', ['value' => $zeichen])];
    }

    /** Die Form dieses Typs, oder `null`, wenn der Typ sich selbst schon verteidigt. */
    private function shapeOf(SimpleType $type): ?string
    {
        return match ($type) {
            // ⚠️ *Bewusst genügsam: ein Zeichen vor dem `@`, ein Name mit mindestens einem Punkt danach,
            // keine Leerzeichen. **Die vollständige Adressgrammatik zu prüfen wäre ein Fehler** — sie
            // erlaubt Dinge, die jeder für falsch hält, und verbietet Dinge, die zustellbar sind.*
            SimpleType::Email => '/^[^@\s]+@[^@\s.]+(?:\.[^@\s.]+)+$/',
            // ⚠️ *Sechs Stellen mit `#`. Die Kurzform `#f00` ist absichtlich **nicht** erlaubt: sie ist
            // eine zweite Schreibweise für denselben Wert, und zwei Schreibweisen in einer Spalte machen
            // jeden Vergleich zweideutig.*
            SimpleType::Color => '/^#[0-9a-fA-F]{6}$/',
            // ⚠️ *Drei Zahlen — `MAJOR.MINOR.PATCH` (`CD-11`). `1.2` ist keine Fassung dieses Projekts.*
            SimpleType::Version => '/^\d+\.\d+\.\d+$/',
            default => null,
        };
    }
}
