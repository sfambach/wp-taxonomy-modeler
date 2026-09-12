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
 * ## Die Lage — ein Umschalter und nicht zwei Renderer (TASK-063)
 *
 * ⚠️ **Sein Auftrag am 2026-09-06, wörtlich:** *«ich würde gerne hier auch horizontal und vertikal
 * einfügen, horizontal kopf oben daten darunter, vertikal kopf links daten rechts davon, wir können
 * auch zwei rendere daraus machen ist mir im prinzip egal».* **Es wurde einer**, weil er dieselbe
 * Frage am Kompaktrenderer schon entschieden hat ([D-471](../../../docs/NewConcept/90-decision-log.md)):
 * *zwei Renderer, die sich in **einer** Achse unterscheiden, sind zwei Registrierungen, zwei Namen im
 * `renderer`-Schlüssel und zwei Stellen, an denen dieselbe Tabellenhaftigkeit gepflegt wird.*
 *
 * | | Kopf | Daten |
 * |---|---|---|
 * | `horizontal` (Vorgabe) | oben, ein `thead` mit `scope="col"` | darunter, **eine Zeile** je Datensatz |
 * | `vertical` | links, je Zeile ein `th scope="row"` | rechts daneben, **eine Spalte** je Datensatz |
 *
 * ⚠️ **Schlüssel, Worte und Vorgabe stehen nicht hier**, sondern in {@see Orientation} — dieselbe
 * Stelle, aus der {@see CompactRenderer} liest. *Sie hier abzuschreiben hiesse, die Vorgabe zweimal
 * zu haben, und die zweite behielte still die alte, wenn die erste sich ändert.*
 *
 * ⚠️ **Beide Lagen zeichnen dieselben Zellen; nur ihre Reihenfolge dreht sich.** *Die Zellen werden
 * einmal gesammelt ({@see self::zellen()}) und dann ausgelegt — sonst wäre `with_label`, die
 * Vorspalte und die Aktionsspalte je zweimal zu pflegen, und genau das ist die Doppelung, gegen die
 * D-471 entschieden hat.*
 *
 * ```mermaid
 * flowchart TD
 *   R["records: je Datensatz die gezeichneten Felder"] --> T[table]
 *   W["with_label"] --> T
 *   O["orientation"] --> T
 *   T --> Z["Zellen: je Datensatz, je Spalte"]
 *   Z -->|horizontal| H["Kopf oben, ein tr je Datensatz"]
 *   Z -->|vertical| V["Kopf links, ein tr je Spalte"]
 * ```
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class TableRenderer extends RendererNode
{
    // ⚠️ *Dieselben zwei wie am kompakten Renderer — jede Klasse erklärt sie für sich, die
    // Adresse `Klasse.Attribut` hält sie auseinander (Anforderung 3.3.3, D-712 D2).*
    #[\Taxmod\Core\Model\NodeClass\Attribut]
    public Orientation $orientation = Orientation::Horizontal;

    #[\Taxmod\Core\Model\NodeClass\Attribut]
    public bool $with_label = true;

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

        $spalten = $this->columns($datensaetze);

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

        // ⚠️ **Nichts zu zeichnen heisst: weder Felder noch Vorspalten** — *und hier stand
        // `$spalten === []` allein, **oberhalb** der Vorspalten.*
        //
        // ⚠️ **Das war der Grund für seinen Fehlerbericht** (`INF-067`): *«Ich kann zu date time kein
        // example anlegen».* *Der Satz entstand, und die Tabelle warf ihn weg: ein einfacher Datentyp
        // hat keine eigenen Kanten, also **null Feldspalten** — und dieser Rücksprung nahm die
        // Nummer, die Version, die Art und die Bedienelemente gleich mit, obwohl sie alle
        // dastanden. **Gemessen: die ganze Zeile fiel weg, nicht nur eine Zelle.***
        //
        // ⚠️ *Seit [D-673](../../../docs/NewConcept/90-decision-log.md) trägt die Vorspalte auch den
        // **eigenen Wert** des Knotens — und damit ist eine Tabelle ohne Feldspalten kein Sonderfall
        // mehr, sondern der gewöhnliche Fall für jeden Knoten unter `Primitives`.*
        if ($spalten === [] && $vorspalten === []) {
            return RenderResult::of('');
        }

        $usedRelations = [];
        $zeilen        = [];

        foreach ($datensaetze as $nummer => $felder) {
            $zeilen[] = $this->zellen($felder, $context, $vorspalten, $spalten, $mitActs, $nummer, $usedRelations);
        }

        // ⚠️ *Die Köpfe stehen in **einer** Liste, in der Reihenfolge der Zellen — waagerecht werden
        // sie eine Zeile, senkrecht die erste Spalte. Ohne Wort bleibt die Aktionsspalte
        // ([AR-2](../../../CLAUDE.md): der Kern macht keine Worte).*
        // ⚠️ **Der Kopf trägt das Fragezeichen der Hilfe, wie die Formularzeile** ([D-662](../../../docs/NewConcept/90-decision-log.md):
        // *«überall dort, wo help label ist»*). *Aufgefallen, als die Vorschau ohne gewählten Behälter zur Tabelle
        // wurde (D-748) und der Wächter zur Hilfe rot wurde: die Tabelle hatte nie eines gezeichnet. Die Köpfe sind
        // ab hier fertiges Markup — Name entwertet, Zeichen dahinter.*
        $hinweise = $this->hints($datensaetze);
        $koepfe   = array_map(static fn (string $kopf): string => RenderResult::escape($kopf), $vorspalten);

        foreach ($spalten as $relationId => $name) {
            $koepfe[] = RenderResult::escape($name) . HintMarkup::icon($hinweise[$relationId] ?? '');
        }

        if ($mitActs) {
            $koepfe[] = '';
        }

        $lage = Orientation::fromContext($context);

        // ⚠️ *Fehlt die Einstellung, wird der Kopf gezeichnet — eine Tabelle ohne Spaltennamen ist
        // schlechter zu lesen als eine mit.*
        $mitKopf = $context->setting('with_label')?->asBool() !== false;

        return new RenderResult(
            '<table class="taxmod-table taxmod-table-' . $lage->value . '">'
            . ($lage->isVertical()
                ? $this->senkrecht($koepfe, $zeilen, $mitKopf)
                : $this->waagerecht($koepfe, $zeilen, $mitKopf))
            . '</table>',
            array_values(array_unique($usedRelations))
        );
    }

    /**
     * Die Zellen **eines** Datensatzes, in der Reihenfolge der Spaltenliste — Klasse und Markup je Zelle.
     *
     * ⚠️ **Über die Spaltenliste und nicht über die vorhandenen Felder.** *Fehlt einem Datensatz ein
     * Feld, muss die Zelle **leer** erscheinen und nicht wegfallen — sonst verrutscht die ganze Zeile,
     * und das sieht wie Daten aus. Senkrecht wäre der Schaden derselbe, nur um 90 Grad gedreht.*
     *
     * @param  list<RenderedField>       $felder
     * @param  list<string>              $vorspalten
     * @param  array<int,string>         $spalten
     * @param  list<string>              $usedRelations gesammelt über alle Datensätze
     * @return list<array{0:string,1:string}> je Zelle: CSS-Klasse, fertiges Markup
     */
    private function zellen(
        array $felder,
        RenderContext $context,
        array $vorspalten,
        array $spalten,
        bool $mitActs,
        int|string $nummer,
        array &$usedRelations
    ): array {
        $nachKante = [];

        foreach ($felder as $feld) {
            if ($feld instanceof RenderedField && ! $feld->isHidden()) {
                $nachKante[$feld->relation->id] = $feld;
                $usedRelations                  = [...$usedRelations, ...$feld->result->usedRelations];
            }
        }

        $zellen = [];

        // ⚠️ *Auch hier über die Spaltenliste: fehlt einer Zeile eine Vorspalte, bleibt die Zelle
        // leer statt wegzufallen.*
        foreach ($vorspalten as $kopf) {
            $zellen[] = ['taxmod-table-lead', (string) ($context->surroundings->rowLead[$nummer][$kopf] ?? '')];
        }

        foreach (array_keys($spalten) as $relationId) {
            $feld     = $nachKante[$relationId] ?? null;
            $zellen[] = ['taxmod-table-cell', $feld?->result->markup ?? ''];
        }

        if ($mitActs) {
            $zellen[] = ['taxmod-table-acts', (string) ($context->surroundings->rowActs[$nummer] ?? '')];
        }

        return $zellen;
    }

    /**
     * Kopf oben, darunter eine Zeile je Datensatz — die Vorgabe.
     *
     * @param list<string>                          $koepfe
     * @param list<list<array{0:string,1:string}>>  $zeilen
     */
    private function waagerecht(array $koepfe, array $zeilen, bool $mitKopf): string
    {
        $kopf = '';

        if ($mitKopf) {
            $zellen = '';

            foreach ($koepfe as $name) {
                $zellen .= '<th class="taxmod-table-head" scope="col">' . $name . '</th>';
            }

            $kopf = '<thead><tr>' . $zellen . '</tr></thead>';
        }

        $rumpf = '';

        foreach ($zeilen as $zellenDerZeile) {
            $zellen = '';

            foreach ($zellenDerZeile as [$klasse, $markup]) {
                $zellen .= '<td class="' . $klasse . '">' . $markup . '</td>';
            }

            $rumpf .= '<tr class="taxmod-table-row">' . $zellen . '</tr>';
        }

        return $kopf . '<tbody>' . $rumpf . '</tbody>';
    }

    /**
     * Kopf links, rechts daneben eine **Spalte** je Datensatz.
     *
     * ⚠️ **Kein `thead`, und das ist kein Versehen.** *Ein `thead` steht für eine Kopf**zeile**; hier
     * ist der Kopf eine Spalte, und jede Zeile trägt ihre eigene Überschrift als `th scope="row"`.
     * Ein `thead` um die erste Spalte gäbe es im HTML nicht — es gibt keine Spaltengruppe, die Zellen
     * enthält.*
     *
     * @param list<string>                          $koepfe
     * @param list<list<array{0:string,1:string}>>  $zeilen die Zellen je **Datensatz**
     */
    private function senkrecht(array $koepfe, array $zeilen, bool $mitKopf): string
    {
        $rumpf = '';

        foreach ($koepfe as $stelle => $name) {
            $zellen = $mitKopf
                ? '<th class="taxmod-table-head" scope="row">' . $name . '</th>'
                : '';

            foreach ($zeilen as $zellenDerZeile) {
                [$klasse, $markup] = $zellenDerZeile[$stelle];
                $zellen           .= '<td class="' . $klasse . '">' . $markup . '</td>';
            }

            $rumpf .= '<tr class="taxmod-table-row">' . $zellen . '</tr>';
        }

        return '<tbody>' . $rumpf . '</tbody>';
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
    /**
     * Die Hilfe je Spalte — die erste, die ein Satz für die Kante mitbringt.
     *
     * @param  list<list<RenderedField>> $datensaetze
     * @return array<int,string>         Kanten-Id => Hilfetext, nur wo einer steht
     */
    private function hints(array $datensaetze): array
    {
        $hinweise = [];

        foreach ($datensaetze as $felder) {
            foreach ($felder as $feld) {
                if ($feld instanceof RenderedField && $feld->hint !== '' && ! isset($hinweise[$feld->relation->id])) {
                    $hinweise[$feld->relation->id] = $feld->hint;
                }
            }
        }

        return $hinweise;
    }

    private function columns(array $datensaetze): array
    {
        // ⚠️ **Kein Sortieren nach `sort_order`** — sein Befund am 2026-09-12: *«Reihenfolge stimmt nicht»*. *Die
        // Stellung beim Besitzer ist nicht die Anordnung, die am Kind gilt (D-698, D-744); die hat der Aufrufer
        // hergestellt, und jeder Satz bringt seine Felder in ihr mit. **Die Spalten sind die Vereinigung dieser
        // Folgen**: ein Feld, das nur ein späterer Satz trägt, rückt hinter das letzte, das in seinem Satz davor
        // stand — so bleibt «Name vor Menge», auch wenn der erste Satz kein «Name» hat.*
        $reihe    = [];
        $relation = [];

        foreach ($datensaetze as $felder) {
            $davor = null;

            foreach ($felder as $feld) {
                if (! $feld instanceof RenderedField || $feld->isHidden()) {
                    continue;
                }

                $id = $feld->relation->id;

                if (! isset($relation[$id])) {
                    $relation[$id] = $feld->relation;
                    $stelle        = $davor === null ? 0 : array_search($davor, $reihe, true) + 1;
                    array_splice($reihe, $stelle, 0, [$id]);
                }

                $davor = $id;
            }
        }

        $spalten = [];

        foreach ($reihe as $id) {
            $spalten[$id] = $relation[$id]->name;
        }

        return $spalten;
    }

}
