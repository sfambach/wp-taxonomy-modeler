<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Die Regel der **Wahl** steht an genau einer Stelle — und dieser Wächter zählt nach.
 *
 * ⚠️ **Er existiert, weil der Eigentümer die Ursache benannt hat und sie sich messen liess.** *«Ich
 * hatte ja gesagt, mach einen Choice-Renderer und verwende den immer, dass er sich überall gleich
 * verhält. Von der Multiplizität zum Choice ist ja ein Weg … und es kann sein, dass du den mehrfach
 * erfindest.»* **Gemessen am 2026-08-31: die Regel stand einmal, der Weg dorthin viermal — und ich hatte
 * an diesem Tag einen fünften Zweig dazugebaut.**
 *
 * ⚠️ **Ein Wächter über den Quelltext und nicht über das Verhalten, und das ist Absicht.** *Ein
 * Nachbau der Regel verhält sich anfangs **gleich** — deshalb hat es niemand gesehen. Rot wird er erst,
 * wenn die beiden auseinanderlaufen, also nach der Änderung, die schon geschehen ist. **Was man messen
 * muss, ist nicht das Ergebnis, sondern die Zahl der Orte.***
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class OneChoiceRuleTest extends TestCase
{
    /** Die eine Stelle, an der die Regel stehen darf. */
    private const HOME = 'Choice.php';

    /**
     * ⚠️ *`ChoiceRenderer` darf die Antworten **benutzen** — er zeichnet sie. Was er nicht darf, ist sie
     * ausrechnen; genau das stand bis heute dort.*
     */
    #[Test]
    public function nobody_but_the_choice_counts_outcomes(): void
    {
        $gefunden = $this->sourcesMatching('/count\s*\(\s*\$\w*[oO]ffered\w*\s*\)\s*\+|count\s*\(\s*\$\w*[oO]ptions\w*\s*\)\s*\+/');

        self::assertSame(
            [],
            $gefunden,
            'Ausgänge werden ausserhalb von ' . self::HOME . ' gezählt: ' . implode(', ', $gefunden)
        );
    }

    /**
     * ⚠️ **«Keine Möglichkeit und nichts ist nicht erlaubt» ist R31a**, und diese Bedingung darf nur
     * einmal geschrieben stehen. *Sie war der Grund, dass `validator` als Textfeld erschien: eine zweite
     * Stelle entschied «keine Einträge, also keine Auswahl» statt «keine Einträge, also gesperrt».*
     */
    #[Test]
    public function nobody_but_the_choice_decides_unsatisfiable(): void
    {
        $gefunden = $this->sourcesMatching('/===\s*\[\]\s*&&\s*!\s*\$\w*[mM]ayBeNothing/');

        self::assertSame([], $gefunden, 'R31a steht auch in: ' . implode(', ', $gefunden));
    }

    /**
     * ⚠️ **Wer `mayBeNothing` aus der Multiplizität ableitet, baut den Weg nach.** *Genau dieses
     * Muster stand viermal: `! $relation->multiplicity->requiresOne()`, `$shape !== OneOfFour`, ein festes
     * `true` und «wird in Ruhe gelassen». **Die Multiplizität allein zu fragen ist erlaubt und nötig** —
     * `Rendering` filtert damit, welche Multiplizitäten ein `bool` überhaupt angeboten bekommt
     * ([D-412](../../docs/NewConcept/90-decision-log.md)), und das ist eine andere Frage. Verboten ist
     * nur die eine: **aus der Multiplizität schliessen, ob nichts erlaubt ist.***
     *
     * ⚠️ *Der erste Entwurf dieses Wächters war dateiweit und hat genau jenen `bool`-Filter gemeldet —
     * **ein Wächter, der richtige Arbeit anzeigt, wird abgeschaltet**, und dann bewacht er nichts.*
     */
    #[Test]
    public function nobody_but_the_choice_derives_may_be_nothing_from_the_multiplicity(): void
    {
        $treffer = [];

        foreach ($this->coreSources() as $pfad => $inhalt) {
            if (basename($pfad) === self::HOME) {
                continue;
            }

            // ⚠️ *Kommentare zählen nicht: dieses Projekt erklärt seine Gründe im Docblock, und ein
            // erklärter Grund ist keine zweite Regel.*
            $code = $this->withoutComments($inhalt);

            // `mayBeNothing` und `requiresOne` im Abstand weniger Zeichen — also im selben Ausdruck.
            if (preg_match('/mayBeNothing.{0,80}requiresOne|requiresOne.{0,80}mayBeNothing/s', $code) === 1) {
                $treffer[] = basename($pfad);
            }
        }

        self::assertSame(
            [],
            $treffer,
            'mayBeNothing wird aus der Multiplizität abgeleitet in: ' . implode(', ', $treffer)
        );
    }

    /**
     * ⚠️ **Der Gegenfall, und ohne ihn wiegt der Wächter nichts.** *Ein Muster, das nirgends passt, ist
     * immer grün — auch wenn `Choice` gelöscht würde. Also muss die Regel **dort** nachweisbar stehen.*
     */
    #[Test]
    public function the_rule_is_actually_in_the_choice(): void
    {
        $quelle = (string) file_get_contents($this->coreRoot() . '/Renderer/' . self::HOME);

        self::assertMatchesRegularExpression('/count\s*\(\s*\$this->options\s*\)\s*\+/', $quelle);
        self::assertMatchesRegularExpression('/===\s*\[\]\s*&&\s*!\s*\$this->mayBeNothing/', $quelle);
        self::assertStringContainsString('requiresOne()', $quelle);
    }

    /**
     * Jede Kerndatei, deren Code auf dieses Muster passt — ausser der einen Heimat.
     *
     * @return list<string>
     */
    private function sourcesMatching(string $muster): array
    {
        $treffer = [];

        foreach ($this->coreSources() as $pfad => $inhalt) {
            if (basename($pfad) === self::HOME) {
                continue;
            }

            if (preg_match($muster, $this->withoutComments($inhalt)) === 1) {
                $treffer[] = basename($pfad);
            }
        }

        return $treffer;
    }

    /** @return array<string, string> Pfad => Inhalt */
    private function coreSources(): array
    {
        $aus = [];

        $lauf = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->coreRoot()));

        foreach ($lauf as $datei) {
            if (! $datei instanceof \SplFileInfo || $datei->getExtension() !== 'php') {
                continue;
            }

            $aus[$datei->getPathname()] = (string) file_get_contents($datei->getPathname());
        }

        return $aus;
    }

    private function coreRoot(): string
    {
        return dirname(__DIR__, 2) . '/src/Core';
    }

    /**
     * ⚠️ *Blockkommentare **und** Zeilenkommentare weg. Ohne das melden die Docblocks dieses Projekts,
     * die ihre Gründe ausführlich nennen, jede Regel als Nachbau — und ein Wächter, der ständig fälschlich
     * rot ist, wird abgeschaltet.*
     */
    private function withoutComments(string $quelle): string
    {
        $ohne = preg_replace('#/\*.*?\*/#s', '', $quelle);

        if ($ohne === null) {
            self::fail('Kommentare liessen sich nicht entfernen — kein Ergebnis zum Prüfen.');
        }

        $ohne = preg_replace('#^\s*//.*$#m', '', $ohne);

        if ($ohne === null) {
            self::fail('Zeilenkommentare liessen sich nicht entfernen.');
        }

        return $ohne;
    }
}
