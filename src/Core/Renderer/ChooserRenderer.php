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

    private function foot(RenderContext $context): string
    {
        $confirm = $context->surroundings->sections[self::CONFIRM] ?? null;

        if ($confirm === null || $confirm->body === '') {
            return '';
        }

        return '<span class="taxmod-dialog-foot">' . $confirm->body . '</span>';
    }

    /** Der Dialog: ein Schalter-Häkchen, das die Fläche öffnet und schliesst — ohne Skript. */
    private function overlay(string $switch, string $current, ?Section $trigger, Section $tree, RenderContext $context): RenderResult
    {
        return RenderResult::of(
            '<span class="taxmod-chooser">'
            . RenderResult::htmlTag('input', [
                'type'  => 'checkbox',
                'class' => 'taxmod-dialog-switch',
                'id'    => $switch,
            ])
            . '<label class="button taxmod-icon-button taxmod-dialog-open" for="' . RenderResult::escape($switch) . '">'
            . ($trigger === null || $trigger->body === '' ? $current : $trigger->body)
            . '</label>'
            . '<span class="taxmod-dialog">'
            . '<label class="taxmod-dialog-shade" for="' . RenderResult::escape($switch) . '"></label>'
            . '<span class="taxmod-dialog-panel">'
            . '<span class="taxmod-dialog-head">'
            . '<span class="taxmod-chooser-current">' . $current . '</span>'
            . '<label class="taxmod-dialog-close" for="' . RenderResult::escape($switch) . '">&times;</label>'
            . '</span>'
            . '<span class="taxmod-chooser-tree">' . $tree->body . '</span>'
            . $this->foot($context)
            . '</span></span></span>'
        );
    }
}
