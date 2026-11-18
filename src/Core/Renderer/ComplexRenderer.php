<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

use Taxmod\Core\Description\ComplexView;
use Taxmod\Core\Description\ShownValue;
use Taxmod\Core\Description\UnfoldingSummary;
use Taxmod\Core\Description\ValueKind;
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

        $used   = [];
        $bloecke = [['heading' => null, 'hint' => '', 'body' => $this->form($subject, $context, $einfach, $used)]];

        foreach ($einzeln as $part) {
            $bloecke[] = ['heading' => $part->relation->name, 'hint' => $part->hint, 'body' => $this->form($subject, $context, $this->summarised($part->rows[0] ?? [], $context), $used)];
        }

        foreach ($mehrere as $part) {
            $bloecke[] = [
                'heading' => $part->relation->name,
                'hint'    => $part->hint,
                'body'    => [...$this->table($subject, $context, $part->rows, $part->rowActs, $used, $part->siteSettings), ...($part->after === '' ? [] : [$part->after])],
            ];
        }

        // ⚠️ *Die festen Angaben — Nummer, Version, Art — wie der Aufrufer sie hereinreicht: Wort und Wert, in einer Zeile; dieselbe Naht wie
        // die Vorspalten der Tabelle, die Worte kommen vom Rand (`AR-2`).*
        return RenderResult::described(new ComplexView($context->surroundings->rowLead[0] ?? [], $bloecke), ...array_values(array_unique($used)));
    }

    /**
     * @param list<RenderedField> $parts
     * @param list<string>        $used
     */
    /** @return list<\Taxmod\Core\Description\Describes> */
    private function form(Renderable $subject, RenderContext $context, array $parts, array &$used): array
    {
        if ($parts === []) {
            return [];
        }

        $result = (new FormRenderer())->render($subject, $this->with($context, new Surroundings(parts: $parts, formId: $context->surroundings->formId)));
        $used   = [...$used, ...$result->usedRelations];

        return [$result->piece()];
    }

    /**
     * @param list<list<RenderedField>> $rows
     * @param list<string>              $rowActs je Zeile ihr Entfernen (D-758)
     * @param list<string>              $used
     * @param array<string, \Taxmod\Core\Model\ResolvedSetting> $siteSettings was an der Stelle des Teils eingestellt ist (D-999)
     */
    /** @return list<\Taxmod\Core\Description\Describes> */
    private function table(Renderable $subject, RenderContext $context, array $rows, array $rowActs, array &$used, array $siteSettings = []): array
    {
        $result = (new TableRenderer())->render(
            $subject,
            $this->with($context, settings: [...$context->settings, ...$siteSettings], surroundings: new Surroundings(
                records: array_map(fn (array $row): array => $this->summarised($row, $context), $rows),
                formId: $context->surroundings->formId,
                rowActs: implode('', $rowActs) === '' ? [] : $rowActs,
            ))
        );
        $used   = [...$used, ...$result->usedRelations];

        return [$result->piece()];
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
                    RenderResult::described(new UnfoldingSummary($worte, $field->result->piece()), ...$field->result->usedRelations),
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
            RenderResult::described(new ShownValue($worte, ValueKind::Text), ...$field->result->usedRelations),
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

    /** @param array<string, \Taxmod\Core\Model\ResolvedSetting>|null $settings */
    private function with(RenderContext $context, Surroundings $surroundings, ?array $settings = null): RenderContext
    {
        return new RenderContext(
            purpose: $context->purpose,
            value: $context->value,
            settings: $settings ?? $context->settings,
            locale: $context->locale,
            level: $context->level,
            editable: $context->editable,
            surroundings: $surroundings,
            developerMode: $context->developerMode,
        );
    }
}
