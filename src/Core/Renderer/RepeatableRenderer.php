<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

use Taxmod\Core\Model\Relation;

/**
 * Mehrere Werte eines Feldes, mit Hinzufügen und Entfernen — und es weiss nichts über Typen.
 *
 * ⚠️ **[D-527](../../../docs/NewConcept/90-decision-log.md), auf seine Bitte:** *«ein zusätzliches
 * Rendergerüst für Multiplizität, um Datensätze hinzuzufügen und zu entfernen, das möglichst generisch
 * ist, um es überall wiederverwenden zu können.»*
 *
 * ⚠️ **Es zeichnet nichts selbst.** *Die Einträge kommen fertig gezeichnet in
 * {@see Surroundings::$parts} — dieselbe Abmachung, mit der ein Formular seine Felder bekommt
 * ([D-366](../../../docs/NewConcept/90-decision-log.md)): **ein Container legt aus, was der Abstieg
 * gezeichnet hat, und zeichnet es nicht selbst.** Darum ist es für Zahlen, Texte, Verweise und
 * zusammengesetzte Teile dasselbe Gerüst.*
 *
 * ⚠️ **Und es weiss auch nichts über Mehrfachheit.** *Ob ein Feld mehrere tragen darf, löst die Kette
 * an der Verwendungsstelle auf; **der Rand fragt, bevor er dieses Gerüst überhaupt wählt**. Hier
 * stünde die Antwort ein zweites Mal, und zwei Orte für eine Regel sind einer zu viel.*
 *
 * ⚠️ *Jeder Eintrag findet sein Entfernen über seine **Zeilen-Id**: mehrere Werte sind mehrere Zeilen
 * auf einer Kante ([D-530](../../../docs/NewConcept/90-decision-log.md)), und ein Steuerelement trägt
 * diese Id in `value`. **Nicht über die Stellung in der Liste** — die verschiebt sich, sobald einer
 * entfernt wird, und dann löscht der zweite Klick den falschen.*
 *
 * ```mermaid
 * flowchart TD
 *   P["parts: gezeichnete Einträge"] --> R[repeatable]
 *   A["actions: entfernen je Pfad + einmal hinzufügen"] --> R
 *   R --> L["Liste, jede Zeile mit ihrem Knopf, darunter «hinzufügen»"]
 * ```
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class RepeatableRenderer implements Renderer
{
    public const NAME = 'repeatable';

    /** Der Name, unter dem ein Steuerelement «diesen Wert entfernen» ankommt; sein `value` ist der Pfad. */
    public const REMOVE = 'repeatable:remove';

    /** Der Name, unter dem «einen weiteren hinzufügen» ankommt. */
    public const ADD = 'repeatable:add';

    public function name(): string
    {
        return self::NAME;
    }

    /**
     * ⚠️ *Für **jeden** Zweck: eine Liste von drei Werten ist auch beim Lesen eine Liste von drei
     * Werten. Was sich zwischen Anzeigen und Bearbeiten ändert, sind die Steuerelemente — und die
     * kommen vom Rand, nicht von hier.*
     */
    public function supports(): array
    {
        return [Purpose::Display, Purpose::Edit];
    }

    /**
     * ⚠️ **Kein Typ, und das ist der Punkt.** *Es ist ein Behälter, kein Zeichner — es gilt für jeden
     * Typ, weil es keinen anfasst. Dieselbe Antwort gibt {@see FormRenderer}.*
     */
    public function handles(): array
    {
        return [];
    }

    /** ⚠️ *Eine Verwendungsstelle, denn mehrere Werte gehören einem **Feld** und nicht einem Knoten.* */
    public function fits(Renderable $subject): bool
    {
        return $subject instanceof Relation;
    }

    public function render(Renderable $subject, RenderContext $context): RenderResult
    {
        $entfernen = [];
        $hinzu     = null;

        foreach ($context->surroundings->actions as $control) {
            if (! $control instanceof Control) {
                continue;
            }

            if ($control->name === self::REMOVE) {
                $entfernen[$control->value] = $control;
            }

            if ($control->name === self::ADD) {
                $hinzu = $control;
            }
        }

        $zeilen    = '';
        $usedEdges = [];

        foreach ($context->surroundings->parts as $part) {
            if (! $part instanceof RenderedField) {
                continue;
            }

            $usedEdges = [...$usedEdges, ...$part->result->usedEdges];

            // ⚠️ *Über die Zeilen-Id und nicht über die Stellung: entfernt jemand den zweiten von
            // dreien, rutschen die übrigen in der Liste nach oben, **ihre Ids bleiben** — eine
            // Zuordnung nach Stellung zeigte danach auf den falschen Eintrag.*
            $knopf = $entfernen[$part->valueId] ?? null;

            $zeilen .= '<li class="taxmod-repeatable-entry">'
                . '<span class="taxmod-repeatable-value">' . $part->result->markup . '</span>'
                . ($knopf === null ? '' : '<span class="taxmod-repeatable-act">' . ControlMarkup::button($knopf) . '</span>')
                . '</li>';
        }

        // ⚠️ **Eine leere Liste bleibt eine Liste.** *Sie zeigt den Knopf zum Hinzufügen und sonst
        // nichts — **ein Feld ohne Werte verschwindet nicht**, sonst könnte man ihm nie einen geben.*
        return RenderResult::of(
            '<ul class="taxmod-repeatable">' . $zeilen . '</ul>'
            . ($hinzu === null ? '' : '<div class="taxmod-repeatable-add">' . ControlMarkup::button($hinzu) . '</div>'),
            ...$usedEdges
        );
    }
}
