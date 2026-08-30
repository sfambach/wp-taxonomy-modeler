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
final class TableRenderer implements Renderer
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
        $usedEdges = [];

        if ($spalten === []) {
            return RenderResult::of('');
        }

        $zeilen = '';

        foreach ($datensaetze as $felder) {
            $nachKante = [];

            foreach ($felder as $feld) {
                if ($feld instanceof RenderedField && ! $feld->isHidden()) {
                    $nachKante[$feld->edge->id] = $feld;
                    $usedEdges                  = [...$usedEdges, ...$feld->result->usedEdges];
                }
            }

            $zellen = '';

            // ⚠️ **Über die Spaltenliste und nicht über die vorhandenen Felder.** *Fehlt einem
            // Datensatz ein Feld, muss die Zelle **leer** erscheinen und nicht wegfallen — sonst
            // verrutscht die ganze Zeile, und das sieht wie Daten aus.*
            foreach ($spalten as $edgeId => $name) {
                $feld    = $nachKante[$edgeId] ?? null;
                $zellen .= '<td class="taxmod-table-cell">' . ($feld?->result->markup ?? '') . '</td>';
            }

            $zeilen .= '<tr class="taxmod-table-row">' . $zellen . '</tr>';
        }

        return new RenderResult(
            '<table class="taxmod-table">'
            . $this->head($spalten, $context)
            . '<tbody>' . $zeilen . '</tbody>'
            . '</table>',
            array_values(array_unique($usedEdges))
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
                    $gesehen[$feld->edge->id] = $feld->edge;
                }
            }
        }

        uasort(
            $gesehen,
            static fn ($a, $b): int => [$a->position, $a->id] <=> [$b->position, $b->id]
        );

        return array_map(static fn ($edge): string => $edge->name, $gesehen);
    }

    /** @param array<int,string> $spalten */
    private function head(array $spalten, RenderContext $context): string
    {
        // ⚠️ *Fehlt die Einstellung, wird der Kopf gezeichnet — eine Tabelle ohne Spaltennamen ist
        // schlechter zu lesen als eine mit.*
        if ($context->setting('with_label')?->asBool() === false) {
            return '';
        }

        $zellen = '';

        foreach ($spalten as $name) {
            $zellen .= '<th class="taxmod-table-head" scope="col">' . RenderResult::escape($name) . '</th>';
        }

        return '<thead><tr>' . $zellen . '</tr></thead>';
    }
}
