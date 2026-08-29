<?php declare(strict_types=1);

namespace Taxmod\WordPress\Persistence;

use Taxmod\Core\Converter\ConverterRegistry;
use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Renderer\RendererRegistry;
use Taxmod\Core\Repository\FrameworkNodes;
use Taxmod\Core\Service\ModelEditor;

/**
 * Renderer, Konverter und Validatoren als Knoten unter `Constants`.
 *
 * ⚠️ **Warum unter `Constants` und nicht in einem eigenen Zweig** ([D-511](../../../docs/NewConcept/90-decision-log.md)):
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
 *     C[Constants] --> R[Renderer]
 *     C --> K[Converter]
 *     C --> V[Validator]
 *     R --> R1[plain … 16 Namen]
 *     K --> K1[hexadecimal, roman]
 *     V --> V1[noch keiner]
 * ```
 *
 * ⚠️ **`Validator` wird leer angelegt, und das ist eine Aussage.** *Validatoren sind nicht gebaut
 * (Arbeitsliste, Zeile 8). Ein leerer Behälter sagt «der Ort steht, es liegt nichts darin» — ein
 * fehlender Behälter sagt nichts, und der nächste Leser legt ihn woanders an.*
 *
 * @see docs/NewConcept/50-wordpress-persistence.md
 */
final class RenderingScaffold
{
    /** ⚠️ *Eigene Fassung neben den anderen Saaten — sie sind verschiedene Lieferungen ([D-119](../../../docs/NewConcept/90-decision-log.md)).* */
    public const OPTION = 'taxmod_rendering_scaffold';

    public const VERSION = 1;

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
        $constants = $this->framework->rootOf(Branch::Constants);
        $created   = [];

        foreach (self::CONTAINERS as $container) {
            $node = $this->ensure($constants, $container, self::optionForContainer($container), $created);

            foreach ($this->namesFor($container) as $name) {
                $this->ensure($node, $name, self::optionFor($container, $name), $created);
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
            'Validator' => [],
        };
    }

    /**
     * Der Knoten dieses Namens — über die gemerkte Id gefunden, sonst gemacht.
     *
     * ⚠️ **Die Reihenfolge ist Id, dann Name, dann anlegen** ([D-510](../../../docs/NewConcept/90-decision-log.md)).
     * *Der Namensschritt ist der Notnagel für eine Installation, die vor dieser Fassung gesät wurde,
     * und er schreibt die Id nach, damit er beim nächsten Mal nicht mehr gebraucht wird.*
     *
     * ⚠️ **Die gemerkte Id wird gegen den Elternknoten geprüft, nicht bloss auf Existenz.** *Eine
     * Option kann auf einen Knoten zeigen, den jemand in den Müll gezogen oder verschoben hat
     * ([D-119](../../../docs/NewConcept/90-decision-log.md): eine Saat ist danach gewöhnlicher
     * Inhalt). Ohne diese Prüfung würde die Saat einen Knoten im Müll als «vorhanden» melden, und die
     * Auswahl zeigte auf etwas, das dort nicht mehr hängt.*
     *
     * @param list<string> $created
     */
    private function ensure(Node $parent, string $name, string $option, array &$created): Node
    {
        $children = $this->editor->childrenOf($parent->id);
        $known    = (int) get_option($option, 0);

        if ($known > 0) {
            foreach ($children as $child) {
                if ($child->id === $known) {
                    return $child;
                }
            }
        }

        foreach ($children as $child) {
            if ($child->name === $name) {
                // ⚠️ *Der Notnagel macht sich selbst unnötig.*
                update_option($option, $child->id, true);

                return $child;
            }
        }

        $made      = $this->editor->createNode($name, $parent->id);
        $created[] = $parent->name . ' > ' . $name;

        update_option($option, $made->id, true);

        return $made;
    }
}
