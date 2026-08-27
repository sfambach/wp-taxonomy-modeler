<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

use Taxmod\Core\Model\Identity;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\SettingCategory;
use Taxmod\Core\Model\SettingKey;
use Taxmod\Core\Model\SettingShape;
use Taxmod\Core\Model\SimpleType;

/**
 * The settings of one subject — **one panel, used for a node and for an attribute alike**.
 *
 * The owner, seeing the attribute row grow its own settings list: *the settings under the attribute
 * have to look exactly like the settings in the node* — and then, having compared them: *take the
 * attribute view, it looks better.* So this is the attribute view, and the node's hand-built table
 * is gone.
 *
 * ```mermaid
 * flowchart LR
 *   S["a node · or an attribute"] --> P[this panel]
 *   C["configured · already drawn"] --> P
 *   A["acts · Set · Nothing · Reset"] --> P
 * ```
 *
 * ⚠️ **It exists because there were two panels and `R1` allows one.** *Everything that is displayed
 * goes through a renderer*, and a second list built beside the first is how the multiplicity control
 * came to post to a field nobody read ([D-376](../../../docs/NewConcept/90-decision-log.md)). The
 * owner spotted this one within a minute of it appearing.
 *
 * ⚠️ **And it closes a hole rather than only tidying:** the attribute's settings had **no way to be
 * written at all**. `persistent` ([D-378](../../../docs/NewConcept/90-decision-log.md)) is a setting
 * on the edge, and a flag one cannot set is a flag one cannot use.
 *
 * ⚠️ **The three acts arrive once and this greys them per row**, which is the renderer's job and not
 * the boundary's: *`Nothing` makes no sense for a choice — its empty option already is nothing — and
 * `Reset` only means something where the value was written here.* The boundary cannot know either
 * without re-deriving what {@see RenderedSetting} already carries.
 *
 * ⚠️ **Per row and not per page, for now.** The owner: *what is really saved is the **page**, not the
 * single value — later auto-save, switched on from the admin menu … for now leave it as it is.* So
 * the shape is deliberately provisional and recorded as such.
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class SettingsRenderer implements Renderer
{
    public const NAME = 'settings';

    /** The act that writes a row. Matched by value, because the words are the boundary's. */
    public const WRITE = 'put_setting';

    /** The act that writes *deliberately nothing* — not the same as leaving it inherited. */
    public const EMPTY = 'empty_setting';

    /** The act that makes it inherited again. */
    public const RESET = 'reset_setting';

    /**
     * The panel's form id, so a button elsewhere on the page can submit it.
     *
     * ⚠️ **`form="…"` is plain HTML and needs no scripting** — which is what makes the owner's *the
     * save button goes in the page header* buildable without a second mechanism.
     *
     * ⚠️ **Per subject, and a fixed id was a real bug caught by a check within a minute.** I wrote
     * *one panel per page, so one id is enough* — and every **attribute row** carries a panel of its
     * own ([D-381](../../../docs/NewConcept/90-decision-log.md)), so a page with three attributes had
     * the same `id` four times. *Duplicate ids do not warn; `form="…"` simply finds the first one, so
     * the head button would have saved whichever panel happened to be earliest in the document.*
     */
    public static function formFor(Renderable $subject): string
    {
        return 'taxmod-settings-' . $subject->id;
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function supports(): array
    {
        return [Purpose::Display, Purpose::Edit];
    }

    /** @return list<SimpleType> Empty: structural — it draws a subject's configuration, not a value. */
    public function handles(): array
    {
        return [];
    }

    public function fits(Renderable $subject): bool
    {
        return true;
    }

    public function render(Renderable $subject, RenderContext $context): RenderResult
    {
        // ⚠️ **Grouped by what a setting is *about*** ([D-385](../../../docs/NewConcept/90-decision-log.md)).
        // The owner had been uneasy about it before he could name it — *what still bothers me is that
        // presentation settings and «real» settings are together* — and then named it: *a category per
        // setting, and then grouped by category.*
        $grouped = [];

        foreach ($context->surroundings->configured as $key => $drawn) {
            $grouped[$drawn->group()][(string) $key] = $drawn;
        }

        $markup = '';

        // ⚠️ **The group whose name is a **type** goes first, then display, then the rules.** A
        // person opens an `int` node to see what an integer can be told, so its own settings lead;
        // *display* is next because it is chosen and rarely; the rules are last because they are true
        // of anything and therefore say least about **this** node.
        //
        // ⚠️ *Sorted here rather than by an enum's declaration order, because the groups are no longer
        // a fixed list: one of them is named after whatever type is being configured (D-390).*
        $order = array_keys($grouped);

        usort($order, static function (string $a, string $b): int {
            $rank = static fn (string $one): int => match ($one) {
                SettingCategory::Display->value => 1,
                SettingCategory::Rules->value   => 2,
                default                         => 0,
            };

            return $rank($a) <=> $rank($b) ?: strcmp($a, $b);
        });

        foreach ($order as $group) {
            // ⚠️ **Inside a group, switches are drawn apart from fields and first** — the owner:
            // *it would look better if all variables of the same type were drawn together, or by
            // order: bool first, it needs little space and can be multi-column, and then fields only
            // two-column (I mean the settings).* **Two different widths cannot share one grid**: a
            // grid gives every column the same width, so one text field forces the switches to be as
            // wide as it is, and `auto-fit` then either wastes the room or makes four columns of
            // nothing.
            //
            // ⚠️ *Read off the **shape**, not off a list of keys: a switch is a switch because its
            // value is yes-or-no, and a key added tomorrow lands in the right half by itself (`CD-9`).*
            $narrow = '';
            $wide   = '';

            foreach ($grouped[$group] as $key => $drawn) {
                $row = $this->row((string) $key, $drawn, $context);

                if ($drawn->shape === SettingShape::Switch) {
                    $narrow .= $row;

                    continue;
                }

                $wide .= $row;
            }

            if ($narrow === '' && $wide === '') {
                continue;
            }

            $markup .= '<div class="taxmod-settings-group taxmod-settings-' . RenderResult::escape($group) . '">'
                . '<div class="taxmod-settings-heading description">'
                . RenderResult::escape($this->word($context, $group))
                . '</div>'
                . ($narrow === '' ? '' : '<div class="taxmod-settings-switches">' . $narrow . '</div>')
                . ($wide === '' ? '' : '<div class="taxmod-settings-fields">' . $wide . '</div>')
                . '</div>';
        }

        if ($markup === '') {
            return RenderResult::of('');
        }

        // ⚠️ **One form around the whole panel** ([D-392](../../../docs/NewConcept/90-decision-log.md)),
        // because the owner moved the save button into the page head: *the save button goes in the
        // page header.* A button outside a form reaches it through `form="…"`, which is plain HTML and
        // needs no scripting — but only if the form has an **id**, which is why the panel carries one.
        $submits = $context->surroundings->submits;

        if ($context->purpose !== Purpose::Edit || $submits === null) {
            return RenderResult::of('<div class="taxmod-settings">' . $markup . '</div>');
        }

        return RenderResult::of(
            '<form method="post" id="' . RenderResult::escape(self::formFor($subject))
            . '" action="' . RenderResult::escape($submits->action) . '" class="taxmod-settings">'
            . ControlMarkup::hidden($submits) . $markup . '</form>'
        );
    }

    /**
     * One setting: its key, its control, where it came from.
     *
     * ⚠️ **Three states and they must look different** ([D-266](../../../docs/NewConcept/90-decision-log.md)):
     * written **here**, inherited from a link of the chain, and **nobody has said** — the last being
     * a key that *applies* to this subject but that nothing has written, which is why it appears at
     * all. *Collapsing the third into the second would make an empty control look like a deliberate
     * blank.*
     */
    private function row(string $key, RenderedSetting $drawn, RenderContext $context): string
    {
        // ⚠️ **No form of its own any more** ([D-392](../../../docs/NewConcept/90-decision-log.md)):
        // the owner moved saving to the page head — *the save button goes in the page header* — so the
        // whole panel is **one** form and a row is a row. *Every row carrying its own form was what
        // made a page-level save impossible, and it went the moment he asked for the toolbar.*
        return '<div class="taxmod-setting">'
            . '<code class="taxmod-setting-key">' . RenderResult::escape($key) . '</code>'
            . '<span class="taxmod-setting-value">' . $this->control($drawn) . '</span>'
            // ⚠️ **The origin is a mark and no longer a sentence.** *not defined* stood on every
            // unset row — which is most of them — and said what an empty control already says. The
            // owner: *«not defined» gone.* What is left is the one case a mark is needed for:
            // **inherited**, because overwriting an ancestor's value believing a field was blank is
            // the mistake this column exists to prevent.
            . '<span class="taxmod-setting-from description">'
            . $this->whereFrom($drawn, $context) . '</span>'
            . ($context->purpose === Purpose::Edit
                ? '<span class="taxmod-setting-acts">' . $this->acts($key, $drawn, $context) . '</span>'
                : '')
            . '</div>';
    }

    /**
     * The acts for this row, greyed where they mean nothing here.
     *
     * ⚠️ **Greyed rather than absent** ([D-370](../../../docs/NewConcept/90-decision-log.md)) — the
     * owner's rule for button rows, and it applies here for the same reason: *at three controls in
     * fixed places the eye learns a position*, while omission slides `Reset` left on every row that
     * was inherited.
     */
    private function acts(string $key, RenderedSetting $drawn, RenderContext $context): string
    {
        $buttons = '';

        foreach ($context->surroundings->actions as $control) {
            if (ControlMarkup::isAWord($control)) {
                continue;
            }

            // ⚠️ **Saving is not a row's business any more** ([D-392](../../../docs/NewConcept/90-decision-log.md)):
            // the owner moved it to the page head, so the whole panel writes at once and a row keeps
            // only the two acts that are genuinely about **one** setting.
            if ($control->value === self::WRITE) {
                continue;
            }

            $available = match ($control->value) {
                // ⚠️ **Not on a choice and not on a switch.** A choice already offers *nothing* as an
                // option; a **switch has no third state at all** — the owner: *with bool there is no
                // deleting, it is either 0 or 1.* Its control submits `0` or `1` and nothing else, so a
                // button writing *deliberately nothing* would write a state the control can never show.
                // *Two controls for one outcome, which is what R31b argues against for choices.*
                self::EMPTY => ! $drawn->shape->isAChoice() && $drawn->shape !== SettingShape::Switch,
                // Making it inherited again only means something where it was written here.
                self::RESET => $drawn->setting->setHere,
                default     => $control->available,
            };

            // ⚠️ **An act that cannot act is left out, not greyed.** The owner, pointing at the panel:
            // *delete still there* — then, asked what he wanted, ***exactly, reset not there when
            // nothing was set***. The row already knew; what it drew was `disabled` at `opacity:.35`,
            // which is *there* to a person looking at it.
            //
            // ⚠️ **This started as the reset button only, and he found the counterexample within the
            // hour**: *no, it **has** one, and that surprised me* — about the bin on a `bool`. **I had
            // argued the opposite one paragraph earlier** — that `empty` should stay greyed *because
            // its absence would say something* — and it does not: a switch has no third state, so
            // `empty` is impossible there **categorically**, not merely for now. *A greyed button that
            // can never become available is furniture, and the argument I made for `reset` was the
            // argument against my own exception.*
            //
            // ⚠️ *It applies to **acts** and never to controls. [R30](../../../docs/NewConcept/30-renderer.md)
            // wants a control whose choice is impossible **marked** rather than hidden, because a
            // missing field cannot be told from a forgotten one. A button is not a field: nobody
            // wonders what a button that is not there would have done.*
            if (! $available) {
                continue;
            }

            // ⚠️ **Named per row, because one form now holds them all.** With a form per row the key
            // rode in a hidden field; in one form a button has to say **which** setting it means, so
            // it submits as `empty[<key>]`. *That is also what lets the page-head save coexist with
            // them: three different acts, three different field names, one form.*
            $buttons .= ControlMarkup::button(
                new Control(
                    $control->name . '[' . $key . ']',
                    $control->value,
                    $control->label,
                    $control->title,
                    $available,
                    $control->destroys,
                    $control->icon
                ),
                $available
            );
        }

        return $buttons;
    }

    /**
     * The control, or an honest statement of why there is none.
     *
     * ⚠️ **Three reasons a row is undrawn and they are different things** — saying *raw* for all
     * three would hide which one applies, and the interesting one is the last: a `default` on a node
     * that is not a simple data type has **no type to borrow**, which is a fact about the model and
     * not a missing feature ([D-354](../../../docs/NewConcept/90-decision-log.md)).
     */
    private function control(RenderedSetting $drawn): string
    {
        if ($drawn->wasDrawn()) {
            return $drawn->result->markup;
        }

        return '<code class="taxmod-no-renderer">'
            . RenderResult::escape($drawn->setting->value->describe()) . '</code>';
    }

    /**
     * Where the winning value came from.
     *
     * ⚠️ **The words are the boundary's** (`AR-2`, [OQ-087](../../../docs/NewConcept/91-open-questions.md)),
     * so they travel as controls named `word:here` and `word:undefined`. An id is not a word and is
     * printed as one — *from #418* is diagnostic and stays legible without translation.
     */
    private function whereFrom(RenderedSetting $drawn, RenderContext $context): string
    {
        // ⚠️ **Nothing where nothing was said**, on the owner's ask: *«not defined» gone.* It stood
        // on almost every row and repeated what an empty control already says. *What stays is the one
        // case a mark prevents a mistake in — **inherited** — because overwriting an ancestor's value
        // believing the field was blank is exactly what this column is for.*
        if ($drawn->setting->fromOwnerId === 0) {
            return '';
        }

        // ⚠️ **`here` is gone, because materialising made it the answer for every row.** The owner:
        // *why does «here» stand behind the fields?* **Because since [D-423](../../../docs/NewConcept/90-decision-log.md)
        // it stands behind all of them** — every owner carries its own rows, so `setHere` is true
        // almost everywhere and the word marks nothing.
        //
        // ⚠️ *This column exists for the opposite case, and its own docblock says so: the one thing a
        // mark prevents a mistake in is **inherited**, because overwriting an ancestor's value
        // believing the field was blank is what it is there to stop. `here` is the ordinary state and
        // the ordinary state needs no label.* **Measured: 344 rows set here against 3 inherited.**
        if ($drawn->setting->setHere) {
            return '';
        }

        // ⚠️ **The id is gone, and it was a bare number on screen** ([D-363](../../../docs/NewConcept/90-decision-log.md)
        // forbids exactly that: *a bare number is the sort of thing that gets copied into a
        // spreadsheet as if it meant something*). The owner found it: *a strange override arrow? An
        // arrow up and `#641`.* **`#641` is the installation identity and `#4030` is `Base units`** —
        // both have names, and this drew neither.
        //
        // ⚠️ *This docblock used to defend the id as **diagnostic**. It is diagnostic to me and to
        // nobody else, which is the same mistake `→ 285` made one row up the working list.*
        //
        // ⚠️ **What replaces it is the arrow alone, carrying the word in its title** — *inherited*,
        // which is the whole of what a reader needs to not overwrite an ancestor's value believing
        // the field was blank. **Showing the owner's *name* instead needs it handed in**
        // ([D-159](../../../docs/NewConcept/90-decision-log.md): a renderer fetches nothing), and
        // that is a separate piece of wiring rather than a reason to keep printing a number.
        //
        // ⚠️ *And it is about to be rare: once settings are materialised
        // ([D-423](../../../docs/NewConcept/90-decision-log.md)) every owner carries its own row, so
        // `setHere` is true and this branch is reached only by nodes that predate the change.*
        return '<em title="' . RenderResult::escape($this->word($context, 'inherited')) . '">↑</em>';
    }

    /** A word the boundary translated, or the key itself where it did not send one. */
    private function word(RenderContext $context, string $key): string
    {
        foreach ($context->surroundings->actions as $control) {
            if ($control->name === 'word:' . $key) {
                return $control->label;
            }
        }

        return $key;
    }
}
