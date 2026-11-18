<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

use Taxmod\Core\Description\Carried;
use Taxmod\Core\Description\SettingOrigin;
use Taxmod\Core\Description\SettingRow;
use Taxmod\Core\Description\SettingsPanel;
use Taxmod\Core\Model\Identity;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\SettingCategory;
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
 * on the relation, and a flag one cannot set is a flag one cannot use.
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
final class SettingsRenderer extends RendererNode
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

        $gruppen = [];

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
            // ⚠️ **Unter der Kategorie der Abschnitt, wofür die Einstellungen sind** — sein Wort: *«über den einstellungen sollte immer stehen
            // für was sie sind also in dem fall nicht nur rules sondern auch rules for jump field … rules als kategorie und dann darunter jump
            // field»*. Die Abschnitte stehen in der Reihenfolge, in der ihre erste Einstellung kommt.
            $abschnitte = [];

            foreach ($grouped[$group] as $key => $drawn) {
                $abschnitte[$drawn->section][(string) $key] = $drawn;
            }

            $abschnittsListe = [];

            foreach ($abschnitte as $abschnitt => $zeilen) {
                $narrow = [];
                $wide   = [];

                foreach ($zeilen as $key => $drawn) {
                    // ⚠️ *Nachbarn mit demselben Band stehen in einer Gruppe — `min`, `max`, `step` nebeneinander (D-736).*
                    if ($drawn->shape === SettingShape::Switch) {
                        $narrow[] = $this->row($key, $drawn, $context);
                    } else {
                        $wide[] = $this->row($key, $drawn, $context);
                    }
                }

                if ($narrow === [] && $wide === []) {
                    continue;
                }

                $abschnittsListe[] = [
                    'heading'  => $abschnitt === '' ? null : $this->word($context, 'section:' . $abschnitt),
                    'switches' => $narrow,
                    'fields'   => $wide,
                ];
            }

            if ($abschnittsListe === []) {
                continue;
            }

            $gruppen[] = ['group' => $group, 'heading' => $this->word($context, $group), 'sections' => $abschnittsListe];
        }

        if ($gruppen === []) {
            return RenderResult::of('');
        }

        // ⚠️ **One form around the whole panel** ([D-392](../../../docs/NewConcept/90-decision-log.md)),
        // because the owner moved the save button into the page head: *the save button goes in the
        // page header.* A button outside a form reaches it through `form="…"`, which is plain HTML and
        // needs no scripting — but only if the form has an **id**, which is why the panel carries one.
        $submits = $context->surroundings->submits;

        if ($context->purpose !== Purpose::Edit || $submits === null) {
            return RenderResult::described(new SettingsPanel($gruppen));
        }

        return RenderResult::described(new SettingsPanel($gruppen, self::formFor($subject), $submits->action, Carried::of($submits)));
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
    private function row(string $key, RenderedSetting $drawn, RenderContext $context): SettingRow
    {
        // ⚠️ **No form of its own any more** ([D-392](../../../docs/NewConcept/90-decision-log.md)):
        // the owner moved saving to the page head — *the save button goes in the page header* — so the
        // whole panel is **one** form and a row is a row.
        // ⚠️ **Geerbt heisst gesperrt, bis jemand «hier überschreibe ich» sagt — und im Konflikt sagt
        // es das System selbst** ([D-689](../../../docs/NewConcept/90-decision-log.md)). *Sein Wort:
        // «sperren finde ich gut, aktiv sagen hier überschreibe ich, muss automatisch gemacht werden
        // wenn der renderer (oder anderes) für kind nicht mehr zulässig ist». Der Haken ist das
        // Sagen; ohne ihn schreibt der Rand die Zeile nicht, was auch immer im Steuerelement steht.
        // **Kein Skript und kein zweiter Seitenaufruf**: das Stilblatt sperrt, solange der Haken fehlt.*
        // ⚠️ *Der Haken zuerst, als eigene Spalte (sein Wort am 2026-09-10); nur der Haken, das Wort im Titel und für den Vorleser — «There
        // are two overlapped texts» (D-782).* ⚠️ **Ein Fragezeichen, das sagt, was die Einstellung tut** ([D-795](../../../docs/NewConcept/90-decision-log.md)),
        // über dem Wert (D-796).
        $gesperrt    = $drawn->overrideName !== '' && $drawn->setting->isLocked();
        $automatisch = $drawn->overrideName !== '' && $drawn->setting->automatic;
        [$herkunft, $text] = $this->whereFrom($drawn, $context);

        return new SettingRow(
            $key,
            // ⚠️ **Three reasons a row is undrawn and they are different things** — a `default` on a node that is not a simple data type
            // has **no type to borrow**, which is a fact about the model and not a missing feature ([D-354](../../../docs/NewConcept/90-decision-log.md)).
            $drawn->wasDrawn() ? $drawn->result->piece() : null,
            $drawn->setting->value->describe(),
            $this->hintFor($context, $key),
            $herkunft,
            $text,
            $gesperrt,
            $automatisch,
            $gesperrt || $automatisch ? $drawn->overrideName : '',
            $this->word($context, 'override'),
            $context->surroundings->formId,
            $context->purpose === Purpose::Edit ? $this->acts($key, $drawn, $context) : null,
            $drawn->shape === SettingShape::Switch ? null : $drawn->band
        );
    }

    /**
     * The acts for this row, greyed where they mean nothing here.
     *
     * ⚠️ **Greyed rather than absent** ([D-370](../../../docs/NewConcept/90-decision-log.md)) — the
     * owner's rule for button rows, and it applies here for the same reason: *at three controls in
     * fixed places the eye learns a position*, while omission slides `Reset` left on every row that
     * was inherited.
     *
     * @return list<Control>
     */
    private function acts(string $key, RenderedSetting $drawn, RenderContext $context): array
    {
        $buttons = [];

        foreach ($context->surroundings->actions as $control) {
            if (($control)->isAWord()) {
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
            $buttons[] = new Control(
                    $control->name . '[' . $key . ']',
                    $control->value,
                    $control->label,
                    $control->title,
                    $available,
                    $control->destroys,
                    $control->icon
                );
        }

        return $buttons;
    }

    /**
     * Ein Satz des Randes mit einem Platz für einen Namen — oder, wo der Rand keinen schickte, der Name allein.
     */
    private function sentence(RenderContext $context, string $key, string $name): string
    {
        $vorlage = $this->word($context, $key);

        return str_contains($vorlage, '%s') ? sprintf($vorlage, $name) : trim($vorlage . ' ' . $name);
    }

    /**
     * Where the winning value came from.
     *
     * ⚠️ **The words are the boundary's** (`AR-2`, [OQ-087](../../../docs/NewConcept/91-open-questions.md)).
     *
     * @return array{0: SettingOrigin, 1: string}
     */
    private function whereFrom(RenderedSetting $drawn, RenderContext $context): array
    {
        // ⚠️ **In Worten, wo der Name da ist** ([D-689](../../../docs/NewConcept/90-decision-log.md)):
        // *«geerbt von Integer» statt eines Pfeils mit Tooltip, und bei einer automatischen Wahl der
        // Grund — was ersetzt wurde und dass es hier nicht zulässig ist ([D-688](../../../docs/NewConcept/90-decision-log.md)).
        // Der Pfeil bleibt nur dort, wo der Abstieg keinen Namen mitgeben konnte.*
        if ($drawn->setting->automatic) {
            return [SettingOrigin::Automatic, $this->sentence($context, 'automatic', $drawn->setting->insteadOf)];
        }

        if ($drawn->fromOwnerName !== '' && $drawn->setting->isLocked()) {
            return [SettingOrigin::InheritedFrom, $this->sentence($context, 'inherited_from', $drawn->fromOwnerName)];
        }

        // ⚠️ **Nothing where nothing was said**, on the owner's ask: *«not defined» gone.* And **`here` is gone, because materialising
        // made it the answer for every row** ([D-423](../../../docs/NewConcept/90-decision-log.md)) — *measured: 344 rows set here against
        // 3 inherited.* What stays is the one case a mark prevents a mistake in: **inherited**, because overwriting an ancestor's value
        // believing the field was blank is what this column is for.
        if ($drawn->setting->fromOwnerId === 0 || $drawn->setting->setHere) {
            return [SettingOrigin::Unmarked, ''];
        }

        // ⚠️ **The arrow alone, carrying the word in its title** — the id it showed was a bare number on screen, which
        // [D-363](../../../docs/NewConcept/90-decision-log.md) forbids; naming the owner needs it handed in (D-159). ⚠️ **Dieselbe Marke wie
        // im Abstieg**: seit die Teile gefallen sind ([D-684](../../../docs/NewConcept/90-decision-log.md)) die einzige Stelle, die «geerbt» zeigt.
        return [SettingOrigin::Inherited, $this->word($context, 'inherited')];
    }

    /** Die Erklärung einer Einstellung, wie der Rand sie schickt — leer, wo er keine hat (dann steht kein Fragezeichen). */
    private function hintFor(RenderContext $context, string $key): string
    {
        foreach ($context->surroundings->actions as $control) {
            if ($control->name === 'word:hint:' . $key) {
                return $control->label;
            }
        }

        return '';
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
