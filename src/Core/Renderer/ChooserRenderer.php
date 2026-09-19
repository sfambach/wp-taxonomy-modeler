<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

use Taxmod\Core\Model\ResolvedSetting;
use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Model\TypedValue;

/**
 * Der Wähler — ein Baum der Kandidaten, im Fluss oder als Dialog.
 *
 * ⚠️ **Ein Renderer mit Schalter, nicht zwei** ([D-727](../../../docs/NewConcept/90-decision-log.md)). *Sein
 * Wort am 2026-09-12: «ich glaube das wir chooser dialog, chooser inline zusammenfassen sollten und einen
 * schalter für dialoge einführen, default aus».* Bis dahin standen `chooser-dialog` und `chooser-inline` als
 * zwei Klassen (D-108, D-244: «deliberately two renderers rather than one with a switch») — das ist damit
 * ersetzt. Die Knotenseite verlangt den Dialog für ihre eigenen Wähler über {@see self::asDialog()}.
 *
 * ⚠️ *Den Baum reicht der Aufrufer in `sections[candidates]`; eine flache Ebene zeichnet
 * {@see \Taxmod\Core\Service\Rendering::chooserMarkup()} als Auswahlfeld, bevor es hierher kommt.*
 *
 * @see docs/einstellungen-anforderungen.md §3.6.3
 */
final class ChooserRenderer extends RendererNode
{
    /** Welches Label des Gewählten gezeigt wird — eine Rolle ([D-728](../../../docs/NewConcept/90-decision-log.md)). */
    #[\Taxmod\Core\Model\NodeClass\Attribut(refersTo: \Taxmod\Core\Model\NodeClass\Constant::class, from: \Taxmod\Core\Model\NodeClass\Anchor::Roles)]
    public ?int $label_role = null;

    /** ⚠️ *«einen schalter für dialoge einführen, default aus».* */
    #[\Taxmod\Core\Model\NodeClass\Attribut]
    public bool $dialog = false;

    /** ⚠️ *«chooser sollte auch display size haben» — die Breite des Auswahlfelds und des Baums, in Zeichen.* */
    #[\Taxmod\Core\Model\NodeClass\Attribut]
    public int $display_size = 20;

    public const NAME = 'chooser';

    public const DIALOG = 'dialog';

    public const CANDIDATES = 'candidates';

    public const TRIGGER = 'trigger';

    public const CONFIRM = 'confirm';

    /**
     * Die Angabe, mit der ein Aufrufer den Dialog verlangt — die Wähler der Knotenseite (Elternknoten, Ziel
     * eines Felds), die kein Knoten einstellt.
     *
     * @return array<string, ResolvedSetting>
     */
    public static function asDialog(): array
    {
        return [self::DIALOG => new ResolvedSetting(self::DIALOG, TypedValue::ofBool(true), 0, true)];
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function supports(): array
    {
        return [Purpose::Edit];
    }

    public function handles(): array
    {
        return [SimpleType::NodeRef];
    }

    public function needsSomethingToChooseFrom(): bool
    {
        return true;
    }

    public function fits(Renderable $subject): bool
    {
        return true;
    }

    public function render(Renderable $subject, RenderContext $context): RenderResult
    {
        $tree = $context->surroundings->sections[self::CANDIDATES] ?? null;

        if ($tree === null || $tree->body === '') {
            return RenderResult::of(
                '<span class="taxmod-unsatisfiable">' . RenderResult::escape($tree?->title ?? '') . '</span>'
            );
        }

        if ($context->setting(self::DIALOG)?->asBool() !== true) {
            return RenderResult::of(
                '<div class="taxmod-chooser taxmod-chooser-open"' . $this->width($context) . '>'
                . '<div class="taxmod-chooser-tree">' . $tree->body . '</div>'
                . '</div>'
            );
        }

        $switch = 'taxmod-dialog-' . $subject->id . '-' . preg_replace('/[^a-z0-9_-]/i', '', $context->fieldName);

        if ($context->surroundings->refersTo !== null) {
            $current = RenderResult::escape($context->surroundings->refersTo);
        } elseif ($context->value->reference !== null) {
            // ⚠️ *Ein Verweis, dessen Ziel keinen Namen mehr hat, bleibt sichtbar — als Nummer, gekennzeichnet.*
            $current = '<span class="taxmod-dangling">'
                . RenderResult::escape('#' . (string) $context->value->reference)
                . '</span>';
        } else {
            // ⚠️ *Der Öffner trägt dann kein lesbares Wort — der Name des Gegenstands steht für den Vorleser dabei
            // (`icon-button-check`: «has no name a screen reader can read», gemessen am 2026-09-12 an `einheit`).*
            $current = '<span class="taxmod-nothing">—</span>'
                . '<span class="screen-reader-text">' . RenderResult::escape($subject->label()) . '</span>';
        }

        return $this->overlay($switch, $current, $context->surroundings->sections[self::TRIGGER] ?? null, $tree, $context);
    }

    /** Die Breite aus `display_size` — nur, wo eine steht. */
    private function width(RenderContext $context): string
    {
        $zeichen = $context->setting('display_size')?->int;

        return $zeichen === null || $zeichen <= 0 ? '' : ' style="width:' . $zeichen . 'ch"';
    }

    /** Der Dialog — die eine Form aus {@see DialogMarkup}; der Fuss ist das Bestätigen des Aufrufers, wo er eines gibt. */
    private function overlay(string $switch, string $current, ?Section $trigger, Section $tree, RenderContext $context): RenderResult
    {
        $confirm = $context->surroundings->sections[self::CONFIRM] ?? null;
        $koerper = $tree->body;

        // ⚠️ **Teilt die Seite die Körper** ([D-866](../../../docs/NewConcept/90-decision-log.md)), steht der Baum einmal als Vorlage da — gemessen
        // am 2026-09-19 an «CPUs»: 25 gleiche Einheitenbäume zu je 12 KB, seit die CPUs elf Einheitenwert-Felder haben. Der Baum kommt fertig
        // gezeichnet, mit Feldname, Formular und Wahl in jedem Knopf; neutral heisst: ohne diese drei. Im Platzhalter steht die aktuelle Wahl als
        // gewählter, verborgener Knopf, damit ein Speichern ohne Skript nichts verliert.
        if ($context->surroundings->sharedBodies !== null && str_contains($koerper, 'type="radio"')) {
            $wahl = preg_match('/<input type="radio"[^>]*\bchecked\b[^>]*>/', $koerper, $gewaehlt) === 1 ? $gewaehlt[0] : '';
            $name = '';
            $form = '';
            $wert = '';
            $neutral = (string) preg_replace_callback(
                '/<input type="radio"[^>]*>/',
                static fn (array $knopf): string => (string) preg_replace(['/ name="[^"]*"/', '/ form="[^"]*"/', '/ checked\b/'], '', $knopf[0]),
                $koerper
            );

            if (preg_match('/<input type="radio"[^>]* name="([^"]*)"[^>]*>/', $koerper, $einer) === 1) {
                $name = $einer[1];
                $form = preg_match('/ form="([^"]*)"/', $einer[0], $f) === 1 ? $f[1] : '';
                $wert = preg_match('/ value="([^"]*)"/', $wahl, $w) === 1 ? $w[1] : '';
            }

            // *Nur Knöpfe eines Satzformulars — der eine Auswahlbaum der Seite (D-815) liest seine Knöpfe selbst und bleibt ganz.*
            if ($form !== '') {
                // *Die Zeilen tragen den Feldnamen in ihrer Kennung («taxmod-choice-<Feld>-<Knoten>»); in der Vorlage steht dafür «{feld}», das
                // Skript setzt ihn beim Einsetzen wieder ein.*
                $neutral = str_replace(
                    'id="taxmod-choice-' . preg_replace('/[^a-z0-9_-]/i', '', html_entity_decode($name)) . '-',
                    'id="taxmod-choice-{feld}-',
                    $neutral
                );
                $koerper = '<span class="taxmod-record-stub" data-taxmod-body="' . $context->surroundings->sharedBodies->share($neutral) . '"'
                    . ' data-taxmod-name="' . $name . '" data-taxmod-form="' . $form . '" data-taxmod-value="' . $wert . '">'
                    . ($wahl === '' ? '' : (string) preg_replace('/^<input /', '<input hidden ', $wahl))
                    . '</span>';
            }
        }

        return RenderResult::of(DialogMarkup::of(
            $switch,
            $trigger === null || $trigger->body === '' ? $current : $trigger->body,
            $current,
            '<span class="taxmod-chooser-tree">' . $koerper . '</span>',
            $confirm?->body ?? '',
            ok: (string) ($context->surroundings->dialogWords['ok'] ?? ''),
            cancel: (string) ($context->surroundings->dialogWords['cancel'] ?? '')
        ));
    }
}
