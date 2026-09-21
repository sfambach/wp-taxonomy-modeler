<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\SimpleType;

/**
 * Ein zusammengesetzter Knoten als Ganzes — wie die Seite, nur für Daten ([D-758](../../../docs/NewConcept/90-decision-log.md)).
 *
 * ⚠️ **Sein Wort:** *«einen Knoten-Renderer, der komplexe Knoten rendert … als erstes die fixen Daten möglichst
 * kompakt, darunter die Felder, die einfache Datentypen sind, darunter jeweils als Formular mit Überschrift die
 * komplexen mit Multiplizität höchstens 1, darunter die komplexen mit mehr als 1 in Tabellenform»* — und
 * *«für die Formular- und Tabellenansichten sollen intern wieder der Table- und Form-Renderer verwendet werden».*
 *
 * ```mermaid
 * flowchart TD
 *   F["feste Angaben · kompakt"] --> S["einfache Felder · form"]
 *   S --> E["komplex, höchstens 1 · Überschrift + form"]
 *   E --> M["komplex, mehr als 1 · Überschrift + table"]
 * ```
 *
 * ⚠️ **Er zeichnet keinen Wert selbst** — dieselbe Abmachung wie Formular und Tabelle
 * ([D-366](../../../docs/NewConcept/90-decision-log.md)): der Abstieg zeichnet, er legt aus. *Die Felder eines Teils
 * bekommt er einzeln über {@see RenderedField::$rows}.*
 *
 * ⚠️ **Eine Stufe tiefer steht nur die Zusammenfassung**, auf sein Wort: *«sollten die komplexen Felder wieder
 * komplexe Felder haben, sollen diese erstmal als Link mit Summary dargestellt werden».* *Wohin der Link führt, ist
 * nicht entschieden (`INF-052`); bis dahin klappt er die Felder an Ort und Stelle auf, damit sie bedienbar bleiben.*
 * ⚠️ **Das gilt nur für die Anzeige** ([D-894](../../../docs/NewConcept/90-decision-log.md)), sein Befund an BerryBase:
 * *«ich denke bei der eingabe sollten die felder zu sehen sein»*. *In der Eingabe stehen die Felder offen da.*
 *
 * ⚠️ *Er hiess zuerst `node`; umbenannt auf sein Wort am 2026-09-13: «benenne den mal in Komplex-Renderer um».*
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class ComplexRenderer extends RendererNode
{
    public const NAME = 'complex';

    public function name(): string
    {
        return self::NAME;
    }

    public function supports(): array
    {
        return [Purpose::Display, Purpose::Edit];
    }

    /** @return list<SimpleType> Leer: ein Behälter, gewählt für das, was ein Knoten **ist**. */
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
        $einfach = [];
        $einzeln = [];
        $mehrere = [];

        foreach ($context->surroundings->parts as $part) {
            if ($part->isHidden()) {
                continue;
            }

            // ⚠️ *Eine Einstellung bleibt, wo das Formular sie hinlegt — hinten bei den einfachen: der Renderer ist für Daten.*
            // ⚠️ **Ein Teil mit eigenem Behälter, der in eine Zeile passt, steht bei den einfachen** ([D-802](../../../docs/NewConcept/90-decision-log.md)) —
            // *sein Befund: «compact is selected for all unit values, so why is rendering not working». Gemessen: an `Parts List` und
            // `Resistor` bekam jeder Einheitenwert einen eigenen Block mit Überschrift und Formular — der Komplex-Renderer fragte nur
            // «hat Teile», nicht, womit der Teil gezeichnet wird. Formular, Tabelle und Komplex bleiben Blöcke; alles andere
            // (compact) zeichnet sein eigener Behälter, in der Zeile.*
            $inZeile = ! in_array($part->rendererName, [FormRenderer::NAME, TableRenderer::NAME, self::NAME], true);

            if ($part->rows === [] || $part->relation->isSetting() || ($inZeile && ! $part->relation->multiplicity->allowsMany())) {
                $einfach[] = $part;
            } elseif ($part->relation->multiplicity->allowsMany()) {
                $mehrere[] = $part;
            } else {
                $einzeln[] = $part;
            }
        }

        $markup = $this->fixed($context);
        $used   = [];

        $markup .= $this->form($subject, $context, $einfach, $used);

        foreach ($einzeln as $part) {
            $markup .= $this->block($part, $this->form($subject, $context, $this->summarised($part->rows[0] ?? [], $context), $used));
        }

        foreach ($mehrere as $part) {
            $markup .= $this->block($part, $this->table($subject, $context, $part->rows, $part->rowActs, $used) . $part->after);
        }

        if ($markup === '') {
            return RenderResult::of('');
        }

        return new RenderResult('<div class="taxmod-node">' . $markup . '</div>', array_values(array_unique($used)));
    }

    /**
     * Die festen Angaben — Nummer, Version, Art —, wie der Aufrufer sie hereinreicht: Wort und Wert, in einer Zeile.
     *
     * ⚠️ *Dieselbe Naht wie die Vorspalten der Tabelle: die Worte kommen vom Rand (`AR-2`), der Wert als fertiges Markup.*
     */
    private function fixed(RenderContext $context): string
    {
        $chips = '';

        foreach ($context->surroundings->rowLead[0] ?? [] as $wort => $wert) {
            $chips .= '<span class="taxmod-complex-fixed-item"><span class="taxmod-complex-fixed-label">'
                . RenderResult::escape((string) $wort) . '</span> ' . $wert . '</span>';
        }

        return $chips === '' ? '' : '<div class="taxmod-complex-fixed">' . $chips . '</div>';
    }

    /**
     * @param list<RenderedField> $parts
     * @param list<string>        $used
     */
    private function form(Renderable $subject, RenderContext $context, array $parts, array &$used): string
    {
        if ($parts === []) {
            return '';
        }

        $result = (new FormRenderer())->render($subject, $this->with($context, new Surroundings(parts: $parts, formId: $context->surroundings->formId)));
        $used   = [...$used, ...$result->usedRelations];

        return $result->markup;
    }

    /**
     * @param list<list<RenderedField>> $rows
     * @param list<string>              $rowActs je Zeile ihr Entfernen (D-758)
     * @param list<string>              $used
     */
    private function table(Renderable $subject, RenderContext $context, array $rows, array $rowActs, array &$used): string
    {
        $result = (new TableRenderer())->render(
            $subject,
            $this->with($context, new Surroundings(
                records: array_map(fn (array $row): array => $this->summarised($row, $context), $rows),
                formId: $context->surroundings->formId,
                rowActs: implode('', $rowActs) === '' ? [] : $rowActs,
            ))
        );
        $used   = [...$used, ...$result->usedRelations];

        return $result->markup;
    }

    /**
     * Ein Feld, dessen Teil selbst zusammengesetzt ist, wird zu seiner Zusammenfassung — aufklappbar; in der Eingabe
     * bleiben seine Felder offen ([D-894](../../../docs/NewConcept/90-decision-log.md)).
     *
     * @param  list<RenderedField> $row
     * @return list<RenderedField>
     */
    private function summarised(array $row, RenderContext $context): array
    {
        if ($context->purpose === Purpose::Edit) {
            return $row;
        }

        return array_map(
            static function (RenderedField $field): RenderedField {
                if ($field->rows === [] || $field->relation->isSetting() || $field->isHidden()) {
                    return $field;
                }

                $worte = self::summaryOf($field);

                // ⚠️ *Ein leerer Teil klappt nicht zu ([D-889](../../../docs/NewConcept/90-decision-log.md)): «…» hinter einem Pfeil
                // sagt nichts, und gerade dort will er tippen. Gemessen an einem neuen Lieferanten: Strasse und Ort standen als «…» da.*
                if ($worte === '') {
                    return $field;
                }

                return new RenderedField(
                    $field->relation,
                    $field->type,
                    self::NAME,
                    new RenderResult(
                        '<details class="taxmod-complex-link"><summary>'
                        . RenderResult::escape($worte)
                        . '</summary>' . $field->result->markup . '</details>',
                        $field->result->usedRelations
                    ),
                    $field->readOnly,
                    $field->valueId,
                    $field->hint,
                    $field->rows,
                    $worte
                );
            },
            $row
        );
    }

    /**
     * Derselbe Teil, aber nur als Worte — ohne Bedienelemente.
     *
     * ⚠️ **Für die Satztabelle** ([D-889](../../../docs/NewConcept/90-decision-log.md), sein Befund zur Adresse in der
     * Filterzeile: *«das sieht auch nicht so schön aus»*): *ein zusammengesetzter Teil brachte dort sein ganzes Formular mit —
     * bei einer Adresse zwei Zeilen mit vier Eingaben in einer Zelle. In der Tabelle steht deshalb nur, was drinsteht; die
     * Filterzeile (Satz 0) bleibt leer, weil über zusammengesetzte Felder ohnehin nicht gefiltert wird ([D-768](../../../docs/NewConcept/90-decision-log.md)).*
     */
    public static function asWords(RenderedField $field, bool $leer = false): RenderedField
    {
        $worte = $leer ? '' : self::summaryOf($field);

        return new RenderedField(
            $field->relation,
            $field->type,
            self::NAME,
            new RenderResult('<span class="taxmod-value">' . RenderResult::escape($worte) . '</span>', $field->result->usedRelations),
            true,
            $field->valueId,
            $field->hint,
            [],
            $worte
        );
    }

    /** Die Worte eines Teils: die Werte seiner Felder, der Reihe nach, leere ausgelassen. */
    public static function summaryOf(RenderedField $field): string
    {
        if ($field->rows === []) {
            return $field->summary;
        }

        $worte = [];

        foreach ($field->rows as $row) {
            $teil = [];

            foreach ($row as $inner) {
                $wort = $inner->isHidden() ? '' : self::summaryOf($inner);

                if ($wort !== '') {
                    $teil[] = $wort;
                }
            }

            if ($teil !== []) {
                $worte[] = implode(', ', $teil);
            }
        }

        return implode(' · ', $worte);
    }

    private function block(RenderedField $part, string $body): string
    {
        if ($body === '') {
            return '';
        }

        return '<div class="taxmod-complex-block"><h4 class="taxmod-complex-heading">'
            . RenderResult::escape($part->relation->name) . HintMarkup::icon($part->hint) . '</h4>'
            . $body . '</div>';
    }

    private function with(RenderContext $context, Surroundings $surroundings): RenderContext
    {
        return new RenderContext(
            purpose: $context->purpose,
            value: $context->value,
            settings: $context->settings,
            locale: $context->locale,
            level: $context->level,
            editable: $context->editable,
            surroundings: $surroundings,
            developerMode: $context->developerMode,
        );
    }
}
