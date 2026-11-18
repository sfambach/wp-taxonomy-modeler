<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

use Taxmod\Core\Description\CurrentChoice;
use Taxmod\Core\Description\DialogBox;
use Taxmod\Core\Description\SharedBody;
use Taxmod\Core\Description\TreeChoice;
use Taxmod\Core\Description\TreeView;
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

    /** The opener's class when it shows the choice itself, not a trigger of its own. */
    public const SHOWS_CHOICE = 'taxmod-chooser-shows';

    public const CANDIDATES = 'candidates';

    public const TRIGGER = 'trigger';

    public const CONFIRM = 'confirm';

    /**
     * The switches already handed out in this request. A page with several dialogs choosing the same field (Lager's
     * «Umbuchen» on every lot row, report 2026-11-13 «auswahl ziel/nach nicht möglich») would otherwise give each the
     * same id, and every opener and «OK» would work the first dialog's switch.
     *
     * @var array<string, int>
     */
    private static array $issued = [];

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

    /** The first keeps the plain id; a repeat gets «-2», «-3» … */
    private static function unique(string $switch): string
    {
        $count = self::$issued[$switch] = (self::$issued[$switch] ?? 0) + 1;

        return $count === 1 ? $switch : $switch . '-' . $count;
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

        if ($tree === null || ! $tree->shows()) {
            return RenderResult::described(TreeChoice::nothingToChoose($tree?->title ?? ''));
        }

        if ($context->setting(self::DIALOG)?->asBool() !== true) {
            $zeichen = $context->setting('display_size')?->int;

            return RenderResult::described(TreeChoice::open($tree->piece ?? $tree->body, $zeichen === null || $zeichen <= 0 ? null : $zeichen));
        }

        $switch = self::unique('taxmod-dialog-' . $subject->id . '-' . preg_replace('/[^a-z0-9_-]/i', '', $context->fieldName));

        // ⚠️ *Ein Verweis, dessen Ziel keinen Namen mehr hat, bleibt sichtbar — als Nummer, gekennzeichnet.*
        $current = match (true) {
            $context->surroundings->refersTo !== null => CurrentChoice::named($context->surroundings->refersTo),
            $context->value->reference !== null       => CurrentChoice::dangling($context->value->reference),
            default                                   => CurrentChoice::nothing($subject->label()),
        };

        return $this->overlay($switch, $current, $context->surroundings->sections[self::TRIGGER] ?? null, $tree, $context);
    }

    /** Der Dialog — die eine Form aus {@see DialogBox}; der Fuss ist das Bestätigen des Aufrufers, wo er eines gibt. */
    private function overlay(string $switch, CurrentChoice $current, ?Section $trigger, Section $tree, RenderContext $context): RenderResult
    {
        $confirm = $context->surroundings->sections[self::CONFIRM] ?? null;

        $eigener = $trigger !== null && $trigger->shows();

        return RenderResult::described(new DialogBox(
            $switch,
            $eigener ? $trigger->body : $current,
            $current,
            TreeChoice::inDialog($this->shared($tree, $context) ?? $tree->piece ?? $tree->body),
            $confirm?->body ?? '',
            // *Ein Öffner ohne eigenen Auslöser zeigt die Wahl selbst; das Skript schreibt eine neue Wahl hinein. Sein Befund am 2026-11-04:
            // «nach auswahl wird platzart nicht angezeigt aber gesetzt wird es».*
            $eigener ? 'button taxmod-icon-button taxmod-dialog-open' : 'taxmod-dialog-open ' . self::SHOWS_CHOICE,
            ok: (string) ($context->surroundings->dialogWords['ok'] ?? ''),
            cancel: (string) ($context->surroundings->dialogWords['cancel'] ?? ''),
            // *Ohne eigenen Auslöser ist der Öffner das ausgegraute Feld, rechts daneben der Baum (D-1163).*
            tree: $eigener ? null : (string) ($context->surroundings->dialogWords['tree'] ?? '')
        ));
    }

    /**
     * Der Platzhalter, wenn die Seite die Körper teilt; `null`, wenn nicht.
     *
     * ⚠️ **Teilt die Seite die Körper** ([D-866](../../../docs/NewConcept/90-decision-log.md)), steht der Baum einmal als Vorlage da — gemessen
     * am 2026-09-19 an «CPUs»: 25 gleiche Einheitenbäume zu je 12 KB, seit die CPUs elf Einheitenwert-Felder haben. Neutral heisst: ohne
     * Feldname, Formular und Wahl. Im Platzhalter steht die aktuelle Wahl als gewählter, verborgener Knopf, damit ein Speichern ohne Skript
     * nichts verliert.
     */
    private function shared(Section $tree, RenderContext $context): ?SharedBody
    {
        $baum  = $tree->piece;
        $einer = $baum instanceof TreeView ? $baum->firstPick() : null;

        // *Nur Knöpfe eines Satzformulars — der eine Auswahlbaum der Seite (D-815) liest seine Knöpfe selbst und bleibt ganz.*
        if ($context->surroundings->sharedBodies === null || $einer === null || $einer->name === '' || $einer->form === '') {
            return null;
        }

        $wahl = $baum->chosen();

        // *Die Zeilen tragen den Feldnamen in ihrer Kennung («taxmod-choice-<Feld>-<Knoten>»); in der Vorlage steht dafür «{feld}», das
        // Skript setzt ihn beim Einsetzen wieder ein.*
        $neutral = $baum->neutral('taxmod-choice-' . preg_replace('/[^a-z0-9_-]/i', '', $einer->name) . '-');

        return new SharedBody(
            $context->surroundings->sharedBodies->share($neutral),
            $einer->name,
            $einer->form,
            $wahl === null ? '' : (string) $wahl->value,
            $wahl === null ? [] : [$wahl->unseen()]
        );
    }
}
