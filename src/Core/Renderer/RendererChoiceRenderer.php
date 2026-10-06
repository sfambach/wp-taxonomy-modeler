<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\SimpleType;

/**
 * Die Renderer-Wahl — **ein eigener Renderer, damit die Logik an einer Stelle liegt**.
 *
 * ⚠️ **Auf sein Wort** ([D-647](../../../docs/NewConcept/90-decision-log.md)): *«wenn wir auch das
 * Renderer-Feld rendern, können wir einen eigenen Renderer erstellen, der die Renderbox rendert …
 * und hier könnten wir die Registry verwenden, um die invaliden Knoten auszusieben und auch nur als
 * Liste anzuzeigen, weil eine Hierarchie hier zu viel wäre.»*
 *
 * ```mermaid
 * flowchart LR
 *   K["die Renderer-Knoten"] --> S{"Registratur · eligibleFor"}
 *   S -->|"trägt keine taugliche Klasse"| W["fällt heraus"]
 *   S -->|"taugt für diesen Knoten"| L["ein Eintrag der Liste"]
 *   L --> C["choice zeichnet das Auswahlfeld"]
 * ```
 *
 * ⚠️ **Von den Knoten aus, gesiebt durch die Registratur — und nicht umgekehrt**
 * ([D-647](../../../docs/NewConcept/90-decision-log.md)). *Sein Einwand: «in der Registry stehen
 * aktuell nur die Renderer-Namen, nicht die Knoten-Ids, die du eigentlich speichern musst.»
 * **Gemessen: 27 Kennungen in der Registratur gegen 17 Renderer-Knoten mit Klasse** — von der
 * Registratur aus gedacht stünden zehn Einträge in der Liste, die niemand speichern kann. Die Brücke
 * ist `nodes.implemented_by` ([D-620](../../../docs/NewConcept/90-decision-log.md)): Kennung →
 * Klasse → Spalte → Knoten-Id.*
 *
 * ⚠️ **Flach, und das erfüllt [R63](../../../docs/NewConcept/30-renderer.md) statt sie zu umgehen.**
 * *Die Regel leitet Liste oder Baum aus der **Tiefe der wählbaren Menge** ab
 * ([D-109](../../../docs/NewConcept/90-decision-log.md)) — die Menge der Renderer-Knoten hat eine
 * Ebene, also ist die Liste das Ergebnis der Regel und keine Ausnahme von ihr.*
 *
 * ⚠️ **Beschriftet mit der `select`-Rolle, Rückfall auf den Namen** — sein Wort: *«vielleicht sogar
 * eher select label»*. *In einem Auswahlfeld ist das die Rolle, für die es die Rollen gibt.
 * **Gemessen sind 3 `select`-Beschriftungen belegt gegen 195 Namen**, der Rückfall trägt es also
 * fast durchweg — und er kostet nichts: {@see \Taxmod\Core\Service\Labels::forNodes()} fällt von der
 * Rolle auf `name` und von dort auf den Knotennamen zurück
 * ([D-646](../../../docs/NewConcept/90-decision-log.md)).*
 *
 * ⚠️ **Gespeichert bleibt der Verweis und nicht der Name.** *Kennung und Beschriftung fallen
 * auseinander, und das ist gewollt (`AR-2`): die Registratur liefert die **Kennung**
 * (`chooser-dialog`), der Knoten die **Beschriftung** je Sprache. Ein blosser Name im Wertfeld liesse
 * ausserdem die eigenen Einstellungen der Renderer-Knoten — `converter`, `with_label`, `label_role` —
 * ohne Ort.*
 *
 * ⚠️ **Er selbst bekommt keinen Knoten** ([D-648](../../../docs/NewConcept/90-decision-log.md)):
 * *«für den Renderer-Renderer erstellen wir da einen Knoten? … würde eher nein sagen, ist was
 * Internes.»* **Ein Knoten machte ihn wählbar** — dann stünde in der Renderer-Liste eines Textfeldes
 * der Eintrag «Renderer-Wähler», und man müsste hinterher mit einer Regel verbieten, was der Knoten
 * erst möglich gemacht hat. Also `addForSurfaces()`, wie die anderen zehn internen.
 *
 * ⚠️ **Das Auswahlfeld selbst zeichnet weiterhin {@see ChoiceRenderer}, und der Boden bleibt im
 * Kode.** *Sonst bräuchte man einen Renderer, um den Renderer zu wählen, mit dem man den Renderer
 * wählt. Hier steht die **Menge**, dort die **Gestalt** — [R28–R32](../../../docs/NewConcept/30-renderer.md#r28r32--the-rule-complete)
 * werden nicht ein zweites Mal geschrieben.*
 *
 * ⚠️ **Er holt nichts** ([D-159](../../../docs/NewConcept/90-decision-log.md)). *Die Knoten und ihre
 * Beschriftungen sind zwei Abfragen **vor** dem Abstieg und werden hereingereicht; was hier steht,
 * ist die Entscheidung, welche davon eine Möglichkeit sind.*
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class RendererChoiceRenderer extends RendererNode
{
    public const NAME = 'renderer-choice';

    public function name(): string
    {
        return self::NAME;
    }

    public function supports(): array
    {
        return [Purpose::Display, Purpose::Edit];
    }

    /** @return list<SimpleType> Leer: gewählt wird ein Knoten, kein einfacher Wert. */
    public function handles(): array
    {
        return [];
    }

    public function fits(Renderable $subject): bool
    {
        return true;
    }

    /**
     * Welche Renderer-Knoten dieser Knoten haben darf — **die Knoten, gesiebt durch die Registratur**.
     *
     * ⚠️ *Die Reihenfolge ist die Sieb-Reihenfolge und nicht die der Registratur: gezählt wird, was
     * einen Knoten hat. Ein Renderer ohne Knoten ist keine Möglichkeit, weil man ihn nicht speichern
     * kann; ein Knoten ohne tauglichen Renderer ist keine, weil er nichts zeichnet — **genau so fällt
     * der Zwischenknoten `render with label` heraus, ohne dass jemand ihn löschen oder verschieben
     * muss**.*
     *
     * ⚠️ *Was im Müll liegt, ist keine Möglichkeit — dieselbe Grenze wie in
     * {@see \Taxmod\Core\Service\ModelEditor::nodeImplementing()}.*
     *
     * @param  array<string, Node> $candidates Klasse => Knoten, wie {@see \Taxmod\Core\Repository\NodeRepository::byImplementations()} antwortet.
     * @param  array<int, string>  $labels     Knoten-Id => `select`-Beschriftung, schon aufgelöst.
     * @return array<int, string>  Knoten-Id => Beschriftung, nach Beschriftung sortiert.
     */
    public function offer(
        Renderable $subject,
        ?SimpleType $type,
        RendererRegistry $registry,
        array $candidates,
        array $labels = [],
        ?Node $trash = null,
        /**
         * Ob es an diesem Gegenstand etwas zu wählen gibt — für die Renderer, die eine Menge brauchen.
         *
         * ⚠️ **Sein Befund am 2026-09-06, zweimal gemeldet:** *«aktuell werden die beiden chooser
         * angeboten das kann aber nicht richig sein weil der knoten keine kinder hat»* — an `Ampere`,
         * einem Blatt. **Ich hatte es zuvor an der falschen Stelle repariert und «gemessen» gemeldet,
         * was auf seinem Schirm nie stand.** Diese Liste hier ist die, die er sieht.
         */
        bool $thereIsAChoice = true,
    ): array {
        $sieve = [];

        // ⚠️ **Ohne Zweck gefragt, und das ist der zweite Teil desselben Befunds.** *Hier stand
        // `Purpose::Edit` fest. Für einen **Konstantenknoten** wie `Ampere` beantwortet das die
        // falsche Frage: er wird angezeigt und nicht eingegeben, also blieben nur die zwei Chooser
        // übrig — die einzigen, die einen Knotenverweis **bearbeiten** können. Gefragt gehört, was
        // ihn überhaupt zeichnen kann; welcher Zweck gerade dran ist, entscheidet später die
        // Auflösung ([R33c](../../../docs/NewConcept/30-renderer.md)).*
        foreach ($registry->eligibleFor($subject, $type, null, $thereIsAChoice) as $one) {
            $sieve[$one::class] = true;
        }

        $offer = [];

        foreach ($candidates as $class => $node) {
            if (! isset($sieve[$class])) {
                continue;
            }

            if ($trash !== null && ($node->id === $trash->id || $node->isDescendantOf($trash))) {
                continue;
            }

            $offer[$node->id] = $labels[$node->id] ?? $node->name;
        }

        // ⚠️ *Nach der Beschriftung, damit die Liste sich nicht mit der Anlagereihenfolge umsortiert —
        // und nach dem, was dasteht, nicht nach der Kennung dahinter.*
        asort($offer);

        return $offer;
    }

    /**
     * Welcher Eintrag der Liste dem gerade geltenden Renderer entspricht.
     *
     * ⚠️ **Die Zeile muss zeigen, was gilt, sonst wäre sie eine Falle** — *eine Auswahlliste ohne
     * Vorauswahl zeigt immer den ersten Eintrag, und der nächste Klick schreibt ihn.*
     *
     * ⚠️ **Über die Klasse und nicht über den angezeigten Text.** *Solange die Liste Knotennamen trug,
     * fand ein Namensvergleich den Eintrag noch. **Mit der `select`-Beschriftung fände er ihn nicht
     * mehr** — die Kennung `chooser-dialog` und die Beschriftung «Auswahldialog» sind zwei
     * verschiedene Wörter (`AR-2`). Also geht der Weg denselben Bogen wie die Liste selbst: Kennung →
     * Klasse → Knoten.*
     *
     * @param  array<int, string>  $offer      Wie {@see self::offer()} sie liefert.
     * @param  array<string, Node> $candidates Klasse => Knoten.
     */
    public function chosenIn(array $offer, array $candidates, RendererRegistry $registry, ?string $name): ?int
    {
        if ($name === null || $name === '') {
            return null;
        }

        $class = $registry->classFor($name);

        if ($class === null) {
            return null;
        }

        $node = $candidates[$class] ?? null;

        return $node !== null && isset($offer[$node->id]) ? $node->id : null;
    }

    /**
     * ⚠️ *Gezeichnet wird eine Wahl wie jede andere — {@see ChoiceRenderer} ist die einzige Stelle,
     * an der [R28–R32](../../../docs/NewConcept/30-renderer.md#r28r32--the-rule-complete) steht.*
     */
    public function render(Renderable $subject, RenderContext $context): RenderResult
    {
        return (new ChoiceRenderer())->render($subject, $context);
    }
}
