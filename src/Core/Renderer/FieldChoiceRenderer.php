<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

use Taxmod\Core\Description\Choosing;
use Taxmod\Core\Description\ShownValue;
use Taxmod\Core\Description\ValueKind;
use Taxmod\Core\Model\SimpleType;

/**
 * A field of a chosen node, chosen by its name and stored as its relation id — the field of a rule filter (D-1541).
 *
 * His card 21.11.2026: «Felder des gewählten Knotens» — the choice offers the fields of the node another field of the same
 * record (or of the record that holds it) points at, inherited ones included. Which field that is says `knoten_feld`; the
 * offer is handed in as {@see Surroundings::$options}, read once at the boundary of rendering, never here (D-445).
 *
 * D-1552: with `art_feld` the row names its kind; the kind's record in its list says in `art_felder_von` whose fields it offers
 * (stock lot, purchase, process). A kind without that node offers the fields of `knoten_feld`, as before.
 *
 * ```mermaid
 * flowchart LR
 *   A["art_feld → the kind chosen in the row"] -->|"its record: art_felder_von"| F["that node's fields"]
 *   A -->|"none"| K["knoten_feld → the node chosen in the rule"] --> F
 *   F --> W["choice: field name, stored as relation id"]
 * ```
 *
 * @see docs/NewConcept/90-decision-log.md D-1541
 */
final class FieldChoiceRenderer extends RendererNode
{
    public const NAME = 'field-choice';

    public const NODE_FIELD = 'knoten_feld';

    /** D-1552: the kind of the filter row (a relation id); its term says whose fields are offered. */
    public const KIND_FIELD = 'art_feld';

    /** D-1552: at the list of kinds, the field naming the term of a record (a relation id). */
    public const KIND_KEY = 'art_schluessel';

    /** D-1552: at the list of kinds, the field naming the node whose fields the term offers (a relation id); empty = knoten_feld. */
    public const KIND_FIELDS_FROM = 'art_felder_von';

    /** The field of the record (or of the record holding it) whose chosen node offers its fields here — a relation id. */
    #[\Taxmod\Core\Model\NodeClass\Attribut]
    public int $knoten_feld = 0;

    /** D-1552, his word 21.11. «bei Filter gebe ich dann mit, ob es ein Feldfilter, ein Bestandsfilter, ein Einkaufsfilter ist». */
    #[\Taxmod\Core\Model\NodeClass\Attribut]
    public int $art_feld = 0;

    #[\Taxmod\Core\Model\NodeClass\Attribut]
    public int $art_schluessel = 0;

    #[\Taxmod\Core\Model\NodeClass\Attribut]
    public int $art_felder_von = 0;

    public function supports(): array
    {
        return [Purpose::Display, Purpose::Edit];
    }

    public function handles(): array
    {
        return [SimpleType::Int];
    }

    public function fits(Renderable $subject): bool
    {
        return true;
    }

    public function render(Renderable $subject, RenderContext $context): RenderResult
    {
        $offered = $context->surroundings->options;
        $now     = $context->value->int === null ? '' : (string) $context->value->int;

        if ($context->purpose === Purpose::Display || ! $context->editable) {
            return RenderResult::described($now === ''
                ? new ShownValue('', ValueKind::Chosen)
                : (array_key_exists($now, $offered) ? new ShownValue($offered[$now], ValueKind::Chosen) : new ShownValue('#' . $now, ValueKind::NotOffered)));
        }

        return RenderResult::described(Choosing::offer(
            $context->fieldName,
            $now !== '' && ! array_key_exists($now, $offered) ? [$now => '#' . $now] + $offered : $offered,
            $now === '' ? null : $now,
            $context->surroundings->mayBeNothing,
            $context->surroundings->formId,
            editable: $context->editable,
            // D-1550, his word 21.11. 08:28 «wenn ich jetzt am Knoten ausgewählt habe, dann müsste ja eigentlich ein Event geschickt werden».
            fieldsOf: ($context->settings[self::NODE_FIELD] ?? null)?->value->int ?? 0,
            kindField: ($context->settings[self::KIND_FIELD] ?? null)?->value->int ?? 0,
            kindKey: ($context->settings[self::KIND_KEY] ?? null)?->value->int ?? 0,
            kindFieldsFrom: ($context->settings[self::KIND_FIELDS_FROM] ?? null)?->value->int ?? 0,
        ));
    }
}
