<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

use Taxmod\Core\Model\Node;

/**
 * Mehrere Datensätze eines Knotens untereinander — Zeilen sind Datensätze, Spalten sind Felder.
 *
 * ⚠️ **Auf sein Wort, und der Vergleich ist seiner:** *«wie der Form-Renderer ein Formular erstellt,
 * soll der Table-Renderer eine Tabelle erstellen … er bekommt auch einen Knoten und kann **mehrere
 * Datensätze untereinander** darstellen».*
 *
 * ⚠️ **Deshalb braucht er eine andere Zutat als das Formular.** *`parts` ist **ein** Satz gezeichneter
 * Felder — ein Datensatz. Eine Tabelle braucht mehrere, also {@see Surroundings::$records}. Und
 * `rows` war es nicht: ein {@see DrawnRow} trägt **eine** Zelle mit einer Tiefe, das ist der Baum.*
 *
 * ⚠️ **Er zeichnet nichts selbst** — dieselbe Abmachung wie {@see FormRenderer}
 * ([D-366](../../../docs/NewConcept/90-decision-log.md)): *ein Behälter legt aus, was der Abstieg
 * gezeichnet hat. Darum meldet er keinen Typ (`handles() === []`): er fasst keinen an.*
 *
 * ⚠️ *Die Kopfzeile hängt an `with_label` — der Einstellung, die er mit `form` teilt und die deshalb
 * an ihrer gemeinsamen Gruppe hängt. **Fehlt sie, wird der Kopf gezeichnet:** eine Tabelle ohne
 * Spaltennamen ist für einen Leser schlechter als eine mit.*
 *
 * ```mermaid
 * flowchart TD
 *   R["records: je Datensatz die gezeichneten Felder"] --> T[table]
 *   W["with_label"] --> T
 *   T --> M["thead aus den Feldnamen, ein tr je Datensatz"]
 * ```
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class TableRenderer extends RendererNode
{
    public const NAME = 'table';

    public function name(): string
    {
        return self::NAME;
    }

    /**
     * ⚠️ *Auch beim Bearbeiten: eine Tabelle von drei Datensätzen bleibt eine Tabelle, wenn man in
     * ihr etwas ändert. Was sich unterscheidet, hat der Abstieg schon gezeichnet.*
     */
    public function supports(): array
    {
        return [Purpose::Display, Purpose::Edit];
    }

    /** ⚠️ *Kein Typ — er ist ein Behälter und fasst keinen an. Dieselbe Antwort gibt {@see FormRenderer}.* */
    public function handles(): array
    {
        return [];
    }

    public function fits(Renderable $subject): bool
    {
        return $subject instanceof Node;
    }

    public function render(Renderable $subject, RenderContext $context): RenderResult
    {
        // ⚠️ *Ein einzelner Satz Teile ist eine Tabelle mit einer Zeile — so bleibt der Renderer auch
        // dort brauchbar, wo bisher nur ein Datensatz gezeichnet wurde.*
        $datensaetze = $context->surroundings->records;

        if ($datensaetze === [] && $context->surroundings->parts !== []) {
            $datensaetze = [$context->surroundings->parts];
        }

        $spalten   = $this->columns($datensaetze);
        $usedRelations = [];

        if ($spalten === []) {
            return RenderResult::of('');
        }

        // ⚠️ **Die Spalten vor und hinter den Feldern** — *auf sein Wort: «Action sollte rechts sein,
        // Record, Version davor, sodass wir eine schmale Zeile bekommen».* Die Überschriften kommen vom
        // Aufrufer, weil der Kern keine Worte machen kann (`AR-2`).
        $vorspalten = [];

        foreach ($context->surroundings->rowLead as $vorne) {
            foreach (array_keys($vorne) as $kopf) {
                $vorspalten[$kopf] = true;
            }
        }

        $vorspalten = array_keys($vorspalten);
        $mitActs    = $context->surroundings->rowActs !== [];

        $zeilen = '';

        foreach ($datensaetze as $nummer => $felder) {
            $nachKante = [];

            foreach ($felder as $feld) {
                if ($feld instanceof RenderedField && ! $feld->isHidden()) {
                    $nachKante[$feld->relation->id] = $feld;
                    $usedRelations                  = [...$usedRelations, ...$feld->result->usedRelations];
                }
            }

            $zellen = '';

            // ⚠️ *Auch hier über die Spaltenliste: fehlt einer Zeile eine Vorspalte, bleibt die Zelle
            // leer statt wegzufallen.*
            foreach ($vorspalten as $kopf) {
                $zellen .= '<td class="taxmod-table-lead">'
                    . ($context->surroundings->rowLead[$nummer][$kopf] ?? '')
                    . '</td>';
            }

            // ⚠️ **Über die Spaltenliste und nicht über die vorhandenen Felder.** *Fehlt einem
            // Datensatz ein Feld, muss die Zelle **leer** erscheinen und nicht wegfallen — sonst
            // verrutscht die ganze Zeile, und das sieht wie Daten aus.*
            foreach ($spalten as $relationId => $name) {
                $feld    = $nachKante[$relationId] ?? null;
                $zellen .= '<td class="taxmod-table-cell">' . ($feld?->result->markup ?? '') . '</td>';
            }

            if ($mitActs) {
                $zellen .= '<td class="taxmod-table-acts">'
                    . ($context->surroundings->rowActs[$nummer] ?? '')
                    . '</td>';
            }

            $zeilen .= '<tr class="taxmod-table-row">' . $zellen . '</tr>';
        }

        return new RenderResult(
            '<table class="taxmod-table">'
            . $this->head($spalten, $context, $vorspalten, $mitActs)
            . '<tbody>' . $zeilen . '</tbody>'
            . '</table>',
            array_values(array_unique($usedRelations))
        );
    }

    /**
     * Die Spalten: jede Kante, die in irgendeinem Datensatz vorkommt, in der Reihenfolge des Modells.
     *
     * ⚠️ *Aus **allen** Datensätzen zusammengetragen, nicht aus dem ersten: ein Feld, das nur der
     * dritte trägt, ist trotzdem eine Spalte.*
     *
     * @param  list<list<RenderedField>> $datensaetze
     * @return array<int,string>         Kanten-Id => Feldname
     */
    private function columns(array $datensaetze): array
    {
        $gesehen = [];

        foreach ($datensaetze as $felder) {
            foreach ($felder as $feld) {
                if ($feld instanceof RenderedField && ! $feld->isHidden()) {
                    $gesehen[$feld->relation->id] = $feld->relation;
                }
            }
        }

        uasort(
            $gesehen,
            static fn ($a, $b): int => [$a->sortOrder, $a->id] <=> [$b->sortOrder, $b->id]
        );

        return array_map(static fn ($relation): string => $relation->name, $gesehen);
    }

    /**
     * @param array<int,string> $spalten
     * @param list<string>      $vorspalten Die Überschriften der Spalten vor den Feldern.
     * @param bool              $mitActs    Ob rechts eine Spalte für Bedienelemente steht.
     */
    private function head(array $spalten, RenderContext $context, array $vorspalten = [], bool $mitActs = false): string
    {
        // ⚠️ *Fehlt die Einstellung, wird der Kopf gezeichnet — eine Tabelle ohne Spaltennamen ist
        // schlechter zu lesen als eine mit.*
        if ($context->setting('with_label')?->asBool() === false) {
            return '';
        }

        $zellen = '';

        foreach ($vorspalten as $name) {
            $zellen .= '<th class="taxmod-table-head" scope="col">' . RenderResult::escape($name) . '</th>';
        }

        foreach ($spalten as $name) {
            $zellen .= '<th class="taxmod-table-head" scope="col">' . RenderResult::escape($name) . '</th>';
        }

        // ⚠️ *Ohne Wort: die Aktionsspalte trägt Bilder, und ein Kopf über Bildern ist ein Wort, das der
        // Kern nicht machen kann (`AR-2`). Die Feldzeile hält es genauso.*
        if ($mitActs) {
            $zellen .= '<th class="taxmod-table-head" scope="col"></th>';
        }

        return '<thead><tr>' . $zellen . '</tr></thead>';
    }
}
