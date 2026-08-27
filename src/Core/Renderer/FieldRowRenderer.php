<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

use Taxmod\Core\Model\Identity;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\SettingKey;
use Taxmod\Core\Model\SimpleType;

/**
 * One attribute as its own row — **the first renderer whose subject is an edge**.
 *
 * The owner asked for two things in one breath: *the name of the attribute should be changeable —
 * is that already a renderer for attributes?* It was not: the attribute table was hand-built
 * markup, which `R1` forbids — *anything that shows model data goes through the renderer contract.*
 *
 * ```mermaid
 * flowchart LR
 *   E["the edge · name · kind"] --> A[this]
 *   T["refersTo · what it points at"] --> A
 *   C["configured · multiplicity"] --> A
 *   S["sections · its settings panel"] --> A
 *   K["actions · save · remove"] --> A
 * ```
 *
 * ⚠️ **That the subject may be an edge was in the contract from the start** and had never been
 * used. {@see Renderer::fits()} takes {@see Renderable} — *it said `Node|Relation` until 2026-08-28,
 * which was the union [D-091](../../../docs/NewConcept/90-decision-log.md) had asked to be a type all
 * along* — because
 * [C11](../../../docs/NewConcept/10-domain-core.md) gives both their identity from one space and
 * [D-091](../../../docs/NewConcept/90-decision-log.md) resolves a `renderer` setting on either —
 * *so an attribute row is not a new mechanism, it is the half of an old one nothing had exercised.*
 *
 * ⚠️ **The name is editable only where the attribute is declared.** An inherited attribute belongs
 * to the ancestor that declared it, and renaming it from a descendant would rename it for every
 * other user of it — silently, which is the worst way to be wrong. The renderer does not work that
 * out: the boundary states it as `editable`, the same way it states what a control may do.
 *
 * ⚠️ **The kind is shown and never offered.** It is read off the branch the target sits in
 * ([D-161](../../../docs/NewConcept/90-decision-log.md)), so there is nothing to choose; a control
 * for it would imply otherwise.
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class FieldRowRenderer implements Renderer
{
    public const NAME = 'field-row';

    /**
     * ⚠️ **`field-row` and not `field`, and the collision is worth recording.** *[D-459](../../../docs/NewConcept/90-decision-log.md)
     * renames «attribute» to «field», and {@see FieldRenderer} already answers to `field` — that one
     * draws **one value** as a plain input, a field in the HTML sense. This one draws **one field of
     * the model as a row**. Two different things that the old vocabulary kept apart by accident.*
     *
     * ⚠️ *Measured before choosing: `field` is stored in **4** setting rows and `attribute` in **none**,
     * so this token could move and the other could not. **A renamed token that somebody had chosen
     * would be a renderer nobody can find any more** — refused at the write by [D-360](../../../docs/NewConcept/90-decision-log.md),
     * which is the check that would have caught it, one screen too late.*
     */

    /**
     * Where the handed-in settings panel is looked for in {@see Surroundings::$sections}.
     *
     * ⚠️ **The panel is handed in, never built here, and that is the whole point.** ~~A renderer
     * cannot call another renderer — it has no registry ([D-159](../../../docs/NewConcept/90-decision-log.md))~~
     * — **that claim was never decided; see {@see \Taxmod\Core\Service\Rendering::recordAsBlock()}.**
     * The reason that survives is the one below: **the panel must look identical wherever it appears**,
     * so it is drawn once and placed, not rebuilt per site.
     * The descent draws {@see SettingsRenderer} once and this places it. *For a few minutes
     * this class built a list of its own, and the owner caught it immediately: `the settings under
     * the attribute have to look exactly like the settings in the node`.*
     */
    public const SETTINGS = 'settings';

    /**
     * The row's own form id, so a control in another cell can submit through it.
     *
     * ⚠️ **A row is a `<tr>` and HTML forbids a form wrapping table cells**, so the acts build their
     * form in one `<td>` and the multiplicity sits in another — **outside it**. That is not cosmetic:
     * the select submitted nothing at all, and a multiplicity change looked like a save that did
     * nothing. *The owner found it on `Bauteilliste`'s `Position` going from `0..1` to `0..*`.*
     */
    public static function formFor(Relation $edge): string
    {
        return 'taxmod-attribute-' . $edge->id;
    }

    public function name(): string
    {
        return self::NAME;
    }

    /**
     * ⚠️ **Both, and the distinction is the name.** The row *shows* an attribute — the target, the
     * kind, where it comes from — and the one thing on it that is a **value being edited** is the
     * name, so `Purpose::Edit` is what turns that from text into a field (R9a). Search is declined:
     * an attribute is model structure, not data one looks for.
     */
    public function supports(): array
    {
        return [Purpose::Display, Purpose::Edit];
    }

    /** @return list<SimpleType> Empty: structural, chosen for what the subject **is**. */
    public function handles(): array
    {
        return [];
    }

    public function fits(Renderable $subject): bool
    {
        return $subject instanceof Relation;
    }

    public function render(Renderable $subject, RenderContext $context): RenderResult
    {
        if (! $subject instanceof Relation) {
            return RenderResult::of('');
        }

        $cells = $this->nameCell($subject, $context)
            // ⚠️ **The target arrives as a name, not as an id to look up** — resolving it is a
            // query and one per row is `CD-7`'s loop, which is why `refersTo` exists at all.
            . $this->cell($context->surroundings->refersTo ?? '—', 'taxmod-attribute-target')
            . $this->cell($subject->kind->value, 'taxmod-attribute-kind', true)
            . $this->origin($context)
            . $this->cell($this->multiplicity($context), 'taxmod-attribute-many', false, true)
            . $this->cell($this->controls($context->surroundings, self::formFor($subject)), 'taxmod-attribute-acts', false, true);

        return RenderResult::of(
            '<tr class="taxmod-attribute">' . $cells . '</tr>'
            . $this->settingsRow($context)
        );
    }

    /**
     * The settings of this use site, one disclosure below the row.
     *
     * The owner, looking at the finished table: *what we still do not have are the settings on the
     * attribute — at least I do not see them … the ones that are not edge ones.* He was right: the
     * row drew the multiplicity and nothing else, so `persistent`, `renderer`, `read_only`, `default`
     * and the ranges had no home on an edge at all — and a `persistent` nobody can see is a flag
     * nobody can use ([D-378](../../../docs/NewConcept/90-decision-log.md)).
     *
     * ⚠️ **Folded shut, and that is not tidiness.** An attribute has a dozen applying keys and most
     * are unset; open by default would bury the table the row belongs to. *The same argument
     * [D-128](../../../docs/NewConcept/90-decision-log.md) made about parked attributes: one click
     * away, not gone.*
     */
    private function settingsRow(RenderContext $context): string
    {
        $panel = $context->surroundings->sections[self::SETTINGS] ?? null;

        if ($panel === null || $panel->body === '') {
            return '';
        }

        return '<tr class="taxmod-attribute-settings"><td colspan="6" style="padding:0 0 .6em">'
            . '<details' . ($panel->collapsed ? '' : ' open') . '><summary style="cursor:pointer">'
            . RenderResult::escape($panel->title)
            . '</summary>' . $panel->body . '</details></td></tr>';
    }

    /**
     * The name — a field where it may be changed, text where it may not.
     *
     * ⚠️ **The rename travels in the same form as the acts.** A separate form per name would give
     * every row two submit targets and no way to tell which one Enter reaches; one form, and the
     * save button is simply one of the controls the boundary handed in.
     */
    private function nameCell(Relation $subject, RenderContext $context): string
    {
        if ($context->purpose !== Purpose::Edit || ! $context->editable) {
            return $this->cell(
                '<strong>' . RenderResult::escape($subject->name) . '</strong>',
                'taxmod-attribute-name',
                false,
                true
            );
        }

        $field = '<input type="text" class="taxmod-attribute-rename"'
            . ' name="' . RenderResult::escape($context->fieldName) . '"'
            . ' value="' . RenderResult::escape($subject->name) . '"'
            // ⚠️ **Required, and the rule is the model's**: an attribute cannot be nameless
            // (`Relation::renamedTo()` refuses it), so the browser says the same thing the core
            // says rather than a second, softer rule of its own — the mistake `int` made once.
            . ' required style="width:100%">';

        return $this->cell($field, 'taxmod-attribute-name', false, true);
    }

    /**
     * Where the attribute comes from — declared here, or inherited.
     *
     * ⚠️ **Read off `editable` rather than handed in separately.** They are the same fact seen
     * twice: an attribute is editable here exactly when this node declares it. Two fields carrying
     * one truth is the duplication `CD` forbids, and they would drift.
     */
    private function origin(RenderContext $context): string
    {
        return $this->cell(
            $context->editable
                ? $this->word($context, 'own')
                : '<em>' . $this->word($context, 'inherited') . '</em>',
            'taxmod-attribute-from',
            false,
            true
        );
    }

    /**
     * The multiplicity, as the settings side already drew it.
     *
     * ⚠️ **Not built here.** It is an ordinary setting on the edge
     * ([D-351](../../../docs/NewConcept/90-decision-log.md)) and the descent draws settings; a
     * second select built in this class would be the same control twice, and the two would come
     * apart the moment R28–R32's greying lands in one of them.
     *
     * ⚠️ *It is the one key kept **out** of the panel below and shown in the row instead, because it
     * is the only edge-only key there is (D-351) — the thing that makes a use site a use site.*
     */
    private function multiplicity(RenderContext $context): string
    {
        $drawn = $context->surroundings->configured[SettingKey::Multiplicity->value] ?? null;

        if ($drawn === null || ! $drawn->wasDrawn()) {
            // ⚠️ *Nothing is nothing* — an em dash rather than an empty cell, so the column still
            // reads as a column.
            return '<span class="taxmod-nothing">—</span>';
        }

        return $drawn->result->markup;
    }

    /**
     * A word the boundary translated, or the key itself.
     *
     * ⚠️ **The core cannot make a word** ([OQ-087](../../../docs/NewConcept/91-open-questions.md)):
     * the text domain is the boundary's (`AR-2`), so *own* and *inherited* arrive as controls' words
     * do. Until that question is answered the key stands in, visibly — a wrong word noticed beats a
     * wrong word guessed.
     */
    private function word(RenderContext $context, string $key): string
    {
        foreach ($context->surroundings->actions as $control) {
            if ($control->name === 'word:' . $key) {
                return RenderResult::escape($control->label);
            }
        }

        return RenderResult::escape($key);
    }

    private function cell(string $inner, string $class, bool $code = false, bool $trusted = false): string
    {
        $body = $trusted ? $inner : RenderResult::escape($inner);

        return '<td class="' . $class . '">' . ($code ? '<code>' . $body . '</code>' : $body) . '</td>';
    }

    /**
     * The acts, built from what the boundary described — the same arrangement as the tree row.
     *
     * ⚠️ **One form for the whole row**, so the name field submits with the save button. The words
     * `word:own`, `word:inherited` and the rest travel in the same list and are **not** buttons; they
     * are skipped here. *That is a shortcut around OQ-087 and it is ugly — noted rather than dressed
     * up.*
     */
    private function controls(Surroundings $surroundings, string $formId): string
    {
        if ($surroundings->submits === null) {
            return '';
        }

        $fields = '';

        foreach ($surroundings->submits->hidden as $name => $value) {
            $fields .= '<input type="hidden" name="' . RenderResult::escape($name)
                . '" value="' . RenderResult::escape($value) . '">';
        }

        $buttons = '';

        foreach ($surroundings->actions as $control) {
            if (ControlMarkup::isAWord($control)) {
                continue;
            }

            // ⚠️ **Greyed, not gone** (D-370), and red only where something is taken away — the
            // same two rules as the tree row, because the owner asked for one look everywhere.
            // ⚠️ **Composed in one place** ({@see ControlMarkup}) — greyed rather than gone (D-370),
            // red only where something is taken away, and an icon-only button marked so no surface
            // has to guess. *There were four copies of this and the borderless rule reached one of
            // them, which is how boxes came back around the icons everywhere else.*
            $buttons .= ControlMarkup::button($control);
        }

        return '<form method="post" id="' . RenderResult::escape($formId) . '"'
            . ' action="' . RenderResult::escape($surroundings->submits->action) . '"'
            . ' class="taxmod-acts">' . $fields . $buttons . '</form>';
    }
}
