<?php declare(strict_types=1);

namespace Taxmod\WordPress\Persistence;

use Taxmod\Core\Converter\ConverterRegistry;
use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Renderer\RendererRegistry;
use Taxmod\Core\Repository\FrameworkNodes;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\Core\Validator\ValidatorRegistry;

/**
 * Renderer, Konverter und Validatoren als Knoten unter `Settings`.
 *
 * ⚠️ **Sie lagen unter `Constants`, und der Eigentümer hat sie in den Ast `Settings` gelegt** —
 * *«Renderer und Converter hatten wir in den Settings abgelegt», «der Validatorknoten liegt sehr
 * wohl in Settings, und da soll er auch sein, mit Converter und Renderer zusammen».* **Diese Saat
 * suchte sie danach weiter unter `Constants` und legte am 2026-08-31 vierundzwanzig Knoten doppelt
 * an** — nichts sah kaputt aus, weil die Datensätze weiter auf die echten zeigten.
 *
 * ⚠️ **Warum kein eigener Zweig** ([D-511](../../../docs/NewConcept/90-decision-log.md)):
 * *sie **sind** Konstanten im genauen Sinn des Codes — `Storage::NodeRef`, «a fixed value a person
 * may extend, so the value is a reference to a node». Ein eigener Zweig hätte `relationKind()`,
 * `storage()` und `holdsData()` in **jeder** Eigenschaft genau wie `Constants` beantwortet, und drei
 * Fälle mit identischer Antwort sind die Doppelung, die dieses Projekt überall sonst verbietet.*
 *
 * ⚠️ **Diese Saat zählt keine Namen selbst auf.** *Sie fragt `RendererRegistry::namesForNodes()` und
 * `ConverterRegistry::namesForNodes()`. Eine eigene Liste wäre die Doppelung, die auseinanderläuft,
 * ohne dass etwas rot wird: ein neuer Renderer im Code, kein Knoten im Modell, und die Auswahl zeigt
 * ihn nie.*
 *
 * ⚠️ **Und sie findet ihre Knoten über die Id, nicht über den Namen** ([D-510](../../../docs/NewConcept/90-decision-log.md),
 * [D-512](../../../docs/NewConcept/90-decision-log.md)). *Die drei älteren Saaten tun das noch nicht
 * (Arbeitsliste, Zeile 80), und an dieser Klassenfamilie ist der Fehler schon eingetreten:
 * `boundTheNumbers()` suchte `int`, der Knoten heisst seit [D-428](../../../docs/NewConcept/90-decision-log.md)
 * `Integer` — eine frisch gesäte Installation hätte ihre Zahlengrenzen nie bekommen. **Eine vierte
 * Namensbindung anzulegen, während das im Bericht steht, wäre die Krankheit zu vermehren.**
 *
 * ```mermaid
 * flowchart TD
 *     S[Settings] --> R[Renderer]
 *     S --> K[Converter]
 *     S --> V[Validator]
 *     R --> G["Gruppierungsknoten, vom Eigentümer"]
 *     G --> R1["die Renderer-Namen"]
 *     K --> K1[binary, hexadecimal, octal, roman]
 *     V --> V1[range, shape]
 * ```
 *
 * ⚠️ **`Validator` war leer, und das war eine Aussage.** *Ein leerer Behälter sagt «der Ort steht,
 * es liegt nichts darin» — ein fehlender sagt nichts, und der nächste Leser legt ihn woanders an.*
 * **Seit dem 2026-08-31 liegen zwei darin**, `range` und `shape`
 * ({@see \Taxmod\Core\Validator\ShippedValidators}), und sie kommen aus derselben Naht wie die
 * Renderer: `namesForNodes()`.
 *
 * @see docs/NewConcept/50-wordpress-persistence.md
 */
final class RenderingScaffold
{
    /** ⚠️ *Eigene Fassung neben den anderen Saaten — sie sind verschiedene Lieferungen ([D-119](../../../docs/NewConcept/90-decision-log.md)).* */
    public const OPTION = 'taxmod_rendering_scaffold';

    /**
     * ⚠️ **Sie steigt, wenn der Code Namen dazubekommt** — sonst läuft {@see importOnce()} nie wieder
     * und die neuen liegen nirgends als Knoten. *2: `binary` und `octal`
     * ([D-523](../../../docs/NewConcept/90-decision-log.md)). 3: die zwei Validatoren, und der Ast
     * wechselte von `Constants` auf `Settings`.*
     */
    public const VERSION = 3;

    public const OPTION_PREFIX = 'taxmod_render_';

    /** Die drei Behälter, in der Reihenfolge, in der sie erscheinen. */
    public const CONTAINERS = ['Renderer', 'Converter', 'Validator'];

    /**
     * `taxmod_render_renderer_chooser_dialog_id` — sprechend, wie `taxmod_type_int_id`.
     *
     * ⚠️ *Bindestriche werden zu Unterstrichen, damit ein Optionsname so aussieht wie die
     * benachbarten. Der Knotenname behält seinen Bindestrich: `chooser-dialog` ist der Name, den
     * `Renderer::name()` liefert, und die Bindung geht ohnehin über die Id.*
     */
    public static function optionFor(string $container, string $name): string
    {
        return self::OPTION_PREFIX
            . strtolower($container)
            . '_'
            . str_replace('-', '_', $name)
            . '_id';
    }

    /** Der Behälter selbst hat auch eine Id zu merken. */
    public static function optionForContainer(string $container): string
    {
        return self::OPTION_PREFIX . strtolower($container) . '_id';
    }

    public function __construct(
        private readonly ModelEditor $editor,
        private readonly FrameworkNodes $framework,
        private readonly RendererRegistry $renderers,
        private readonly ConverterRegistry $converters,
        /**
         * ⚠️ *Voreingestellt auf `null`, damit ein älterer Aufrufer nicht bricht — und dann liegen
         * keine Validatorknoten. **Kein stiller Standardsatz**: wer sie will, gibt die Registratur
         * herein, und wer sie vergisst, sieht es an einem leeren Behälter.*
         */
        private readonly ?ValidatorRegistry $validators = null,
    ) {
    }

    /**
     * Einmal je Fassung, wie die anderen Saaten.
     *
     * @return list<string> Was angelegt wurde; leer, wenn nichts zu tun war.
     */
    public function importOnce(): array
    {
        if ((int) get_option(self::OPTION, 0) >= self::VERSION) {
            return [];
        }

        $created = $this->import();

        update_option(self::OPTION, self::VERSION, true);

        return $created;
    }

    /**
     * ⚠️ *Getrennt von {@see importOnce()}, damit eine Prüfung sie direkt rufen kann — dasselbe
     * Muster wie bei den anderen Saaten.*
     *
     * @return list<string>
     */
    public function import(): array
    {
        // ⚠️ **Der Ast `Settings`, nicht `Constants` — auf sein Wort, zweimal.** *«Renderer und Converter
        // hatten wir in den Settings abgelegt» und «der Validatorknoten liegt sehr wohl in Settings, und
        // da soll er auch sein, mit Converter und Renderer zusammen.»*
        //
        // ⚠️ **Hier stand `Constants`, und das hat wirklich Schaden angerichtet.** *Gemessen am
        // 2026-08-31: die drei Behälter liegen unter `Settings` (`1.40768.…`), die Saat suchte sie unter
        // `Constants` (`1.406.410`), fand sie nicht — und legte **24 Knoten** ein zweites Mal an, einen
        // kompletten leeren `Renderer`-Baum samt `Converter` und `Validator`. **Nichts sah kaputt aus:**
        // die Verweise in den Datensätzen zeigten weiter auf die echten, der Schirm zeichnete richtig,
        // der Lauf meldete grün. Aufgefallen ist es nur, weil eine andere Prüfung plötzlich einen
        // zweiten Knoten namens `form` fand.*
        //
        // ⚠️ *Ich habe das als offene Frage aufgeschrieben ([OQ-141](../../../docs/NewConcept/91-open-questions.md))
        // — **es war keine.** Er hatte den Ort zweimal genannt; offen war nur diese Zeile.*
        $heimat  = $this->framework->rootOf(Branch::Settings);
        $created = [];

        foreach (self::CONTAINERS as $container) {
            // ⚠️ *Der Behälter selbst wird von keiner Klasse umgesetzt — er ist ein Ort, kein
            // Renderer. `null` heisst hier genau das (TASK-008).*
            $node = $this->ensure($heimat, $container, self::optionForContainer($container), null, $created);

            foreach ($this->namesFor($container) as $name) {
                $this->ensure(
                    $node,
                    $name,
                    self::optionFor($container, $name),
                    $this->classFor($container, $name),
                    $created
                );
            }
        }

        return $created;
    }

    /**
     * Die Namen, die unter einem Behälter liegen — gelesen, nicht aufgezählt.
     *
     * @return list<string>
     */
    private function namesFor(string $container): array
    {
        return match ($container) {
            'Renderer'  => $this->renderers->namesForNodes(),
            'Converter' => $this->converters->namesForNodes(),
            // ⚠️ *Kein `default`, weil ein vierter Behälter hier auffallen soll und nicht still leer
            // bleiben, nur weil niemand an ihn gedacht hat.*
            'Validator' => $this->validators?->namesForNodes() ?? [],
        };
    }

    /**
     * Die PHP-Klasse hinter diesem Namen — gelesen, nicht aufgezählt (TASK-008).
     *
     * ⚠️ *Aus derselben Naht wie {@see namesFor()}: die Registratur weiss, was sie registriert hat.
     * **Eine eigene Zuordnung Name → Klasse wäre die Doppelung, die auseinanderläuft**, ohne dass
     * etwas rot wird — genau die, vor der der Kommentar am Kopf dieser Klasse warnt.*
     */
    private function classFor(string $container, string $name): ?string
    {
        return match ($container) {
            'Renderer'  => $this->renderers->classFor($name),
            'Converter' => $this->converters->classFor($name),
            'Validator' => $this->validators?->classFor($name),
        };
    }

    /**
     * Der Knoten dieses Namens — über die gemerkte Id gefunden, sonst gemacht.
     *
     * ⚠️ **Die Reihenfolge ist Id, dann Name, dann anlegen** ([D-510](../../../docs/NewConcept/90-decision-log.md)).
     * *Der Namensschritt ist der Notnagel für eine Installation, die vor dieser Fassung gesät wurde,
     * und er schreibt die Id nach, damit er beim nächsten Mal nicht mehr gebraucht wird.*
     *
     * ⚠️ **Die gemerkte Id gilt, wo der Knoten auch liegt — nur nicht im Müll.** *Hier stand «die
     * gemerkte Id wird gegen den **Elternknoten** geprüft», und der Grund war richtig: eine Option kann
     * auf einen Knoten zeigen, den jemand in den Müll gezogen hat ([D-119](../../../docs/NewConcept/90-decision-log.md):
     * eine Saat ist danach gewöhnlicher Inhalt). **Aber die Prüfung war zu breit** — sie schlug auch an,
     * wenn der Eigentümer den Knoten **verschoben** hat, und ein Umzug ist erlaubt. Genau daran sind am
     * 2026-08-31 vierundzwanzig Knoten doppelt entstanden.*
     *
     * ⚠️ *Gefragt wird jetzt, was gemeint war: **liegt er im Müll?** Ein Umzug innerhalb des Modells
     * lässt die gemerkte Id gelten, ein Parken nicht.*
     *
     * @param list<string> $created
     */
    private function ensure(
        Node $parent,
        string $name,
        string $option,
        ?string $className,
        array &$created
    ): Node {
        // ⚠️ **Zuerst der Knoten selbst** (TASK-009). *Er sagt, welche Klasse ihn umsetzt, und damit
        // braucht diese Saat keine WordPress-Option mehr, um ihn wiederzufinden — die Bindung liegt
        // im Modell, wo `AR-1` sie haben will. **Der Weg über die Option bleibt als Notnagel
        // darunter**, für eine Installation, die vor Fassung 23 gesät wurde; er schreibt die Klasse
        // nach und macht sich damit selbst unnötig.*
        if ($className !== null) {
            $ausDerSpalte = $this->editor->nodeImplementing($className);

            if ($ausDerSpalte !== null) {
                return $ausDerSpalte;
            }
        }

        $children = $this->editor->childrenOf($parent->id);
        $known    = (int) get_option($option, 0);

        if ($known > 0) {
            $gemerkt = $this->editor->find($known);

            $muell = $this->framework->trash();

            // ⚠️ *Der Mülleimer **selbst** zählt mit: `isDescendantOf()` ist streng, und eine Option, die
            // auf den Eimer zeigt, wäre sonst geglaubt. Genau darauf zeigt die Gegenprüfung.*
            if ($gemerkt !== null && $gemerkt->id !== $muell->id && ! $gemerkt->isDescendantOf($muell)) {
                return $this->sagtSeineKlasse($gemerkt, $className);
            }
        }

        foreach ($children as $child) {
            if ($child->name === $name) {
                // ⚠️ *Der Notnagel macht sich selbst unnötig.*
                $this->merken($option, $child->id, $className);

                return $this->sagtSeineKlasse($child, $className);
            }
        }

        $made      = $this->editor->createNode($name, $parent->id);
        $created[] = $parent->name . ' > ' . $name;

        $this->merken($option, $made->id, $className);

        return $this->sagtSeineKlasse($made, $className);
    }

    /**
     * Die Id in die Option schreiben — **nur noch für die drei Behälter** (TASK-009).
     *
     * ⚠️ **Wer eine Klasse nennt, braucht keine Option mehr**, und zwei Orte für dieselbe Auskunft
     * sind die Doppelung, die `CLAUDE.md` verbietet. *Die Behälter `Renderer`, `Converter` und
     * `Validator` sind ein **Ort** und keine Klasse — sie behalten ihre Option, bis entschieden ist,
     * woran ein Behälter sonst zu erkennen wäre (`INF-020`).*
     */
    private function merken(string $option, int $id, ?string $className): void
    {
        if ($className !== null) {
            return;
        }

        update_option($option, $id, true);
    }

    /**
     * Den Klassennamen in den Knoten schreiben, wenn er noch nicht dasteht (TASK-008).
     *
     * ⚠️ **Auch an einem Knoten, den es schon gab** — *sonst bekäme nur eine frische Installation die
     * Angabe, und die bestehende bliebe für immer auf den Optionen angewiesen. {@see
     * \Taxmod\Core\Service\ModelEditor::setImplementedBy()} ist von sich aus still, wenn nichts anders
     * ist, also kostet das an einer gefüllten Zeile nichts.*
     *
     * ⚠️ *`null` schreibt **nichts** und löscht auch nichts: die Behälter fragen mit `null`, und eine
     * Registratur, die gerade fehlt, soll keine Angabe wegräumen, die sie nicht kennt.*
     */
    private function sagtSeineKlasse(Node $node, ?string $className): Node
    {
        if ($className === null || $node->implementedBy === $className) {
            return $node;
        }

        return $this->editor->setImplementedBy($node->id, $className);
    }
}
