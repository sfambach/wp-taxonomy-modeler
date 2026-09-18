<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

/**
 * One button, composed from a {@see Control} — **the only place that does it**.
 *
 * ⚠️ **It exists because there were four copies and they had already drifted.** The tree row, the
 * attribute row, the settings panel and the labels panel each built the same `<button>` from the same
 * fields, and the owner found the consequence: *in the node renderer you have put boxes around the
 * icons again — away with them … it seems to be the case throughout, no boxes around the icons.* The
 * borderless rule had been written for the tree, and the other three grew afterwards.
 *
 * ```mermaid
 * flowchart LR
 *   B["the boundary · a Control"] --> M[this] --> R["four renderers place it"]
 * ```
 *
 * ⚠️ **Not a base class and not a trait, because a renderer is not a kind of button.** A static
 * composer keeps the renderers free of an inheritance they would then all share for one method — and
 * it is `RenderResult::escape()` all the way down, so nothing here knows a surface.
 *
 * ⚠️ **An icon-only button says so in a class**, rather than the stylesheet guessing from its
 * contents. *The alternative was `:has(> .dashicons:only-child)`, which works and hides the rule in
 * the paint; the renderer already knows, so it states it.*
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class ControlMarkup
{
    /** Marks a button whose whole content is an icon, so the surface can strip its box. */
    public const ICON_ONLY = 'taxmod-icon-button';

    /**
     * Marks a drawn character standing in for an icon, so it takes the same size as a Dashicon.
     *
     * ⚠️ **Measured: the diskette was 13px beside 17px Dashicons in the same row** — the glyph span had
     * no class, so the only size it could get was the inherited `font-size` of an admin page. *And
     * WordPress's emoji script then replaces the character with an `<img class="emoji">` sized `1em`,
     * which inherits the same wrong number. Naming the span is what lets one rule size both faces.*
     */
    public const GLYPH_FACE = 'taxmod-icon-glyph';

    /** Die Klasse jedes Speichern-Knopfs ([D-860](../../../docs/NewConcept/90-decision-log.md)). */
    public const SAVE = 'taxmod-save';

    /**
     * @param bool|null $available Overrides the control's own answer, for a row that decides per
     *                            row — the settings panel greys `Reset` where nothing was set here.
     */
    public static function button(Control $control, ?bool $available = null): string
    {
        $usable = $available ?? $control->available;

        // ⚠️ *Ein Bedienelement mit Dialog öffnet erst und handelt im Fuss (D-730).*
        if ($control->opens !== null) {
            return self::dialogButton($control, $usable);
        }

        // ⚠️ **«Icon-only» is about the *shape*, not about which font drew it.** Measured on the real
        // page after the first version of this: the save button lost `taxmod-icon-button` and would
        // have got its box back among 405 flat neighbours — *because a glyph button is icon-only in
        // every way that matters to the layout, and only `face()` cares which of the two it is.*
        $bare = $control->icon !== '' || $control->glyph !== '';
        $icon = $control->icon !== '';

        // ⚠️ **One place builds every button, and it took four hand-written ones to get here.** The
        // owner: *every time new buttons appear they look odd again — is there a button renderer?* *He
        // is right about the symptom and about the cure: `Add`, the page save, `Move here` and
        // `add_attribute` were written out on the screen, so each one carried whatever classes whoever
        // wrote it remembered — which is how the save button ended up with `button-primary` **and** the
        // class that exists to take a background away.*
        //
        // ⚠️ *`leads` and `form` arrive as **facts** and become markup here, the same division
        // `destroys` already had: the surface says which act a person came for and which panel a button
        // submits; what that looks like is decided once, in this method.*
        // ⚠️ **An icon button is never the prominent one, and that is enforced here rather than
        // remembered.** *`button-primary` paints a solid background; `taxmod-icon-button` exists to
        // take one away. The two together are what made the diskette blue among flat neighbours — so
        // the combination is now unrepresentable instead of merely discouraged.*
        return '<button class="button' . ($control->leads && ! $bare ? ' button-primary' : '')
            . ($bare ? ' ' . self::ICON_ONLY : '')
            // *Der Speichern-Knopf ist erkennbar, damit die Seite ihn bei automatischem Speichern ausblenden kann (D-860).*
            . ($control->glyph === Control::SAVE_GLYPH ? ' ' . self::SAVE : '') . '"'
            . ($control->form === '' ? '' : ' form="' . RenderResult::escape($control->form) . '"')
            . ' name="' . RenderResult::escape($control->name) . '"'
            . ' value="' . RenderResult::escape($control->value) . '"'
            // ⚠️ **Ein Symbolknopf zeigt beim Darüberfahren seinen Namen** ([D-847](../../../docs/NewConcept/90-decision-log.md)) — sein Wort:
            // *«knöpfe grundsätzlich ein icon verwenden wenn es eins gibt und rechts vom feld. Tooltip knopf beschreibung/name».* Ohne eigenen
            // Titel ist es die Beschriftung, die das Symbol ersetzt.
            . (($control->title !== '' ? $control->title : ($bare ? $control->label : '')) === '' ? '' : ' title="' . RenderResult::escape($control->title !== '' ? $control->title : $control->label) . '"')
            . ($usable ? '' : ' disabled')
            // ⚠️ **Red only where something is taken away**, and the fact arrives as `destroys`
            // rather than as a colour — so the one control that must never be clicked by accident
            // looks the same on every surface.
            . ' style="color:' . ($control->destroys ? '#b32d2e' : '#1d2327')
            . ($usable ? '' : ';opacity:.35') . '">'
            // ⚠️ **An icon replaces the label but never the accessible name** — the label is what a
            // screen reader is left with, and a button with neither is a shape with no meaning.
            . self::face($control, $icon)
            . '</button>';
    }

    /**
     * What a button shows: a Dashicon, else a chosen character, else its words.
     *
     * ⚠️ **An icon replaces the label but never the accessible name** — the label is what a screen
     * reader is left with, and a button with neither is a shape with no meaning.
     *
     * ⚠️ *The middle rung is the page save and its `💾`: the icon font has no diskette and
     * `dashicons-saved` is a tick, which the owner spotted. So the glyph is drawn and the label still
     * travels as the name — the alternative was an emoji **as** the label, which would have made
     * «floppy disk» the name of the save button.*
     */
    /**
     * Ein Bedienelement mit Dialog: der Öffner trägt das Gesicht des Knopfs, der Akt sitzt am Bestätigen im Fuss,
     * daneben der Abbruch — ein Label, das den Schalter wieder löst ([D-730](../../../docs/NewConcept/90-decision-log.md)).
     *
     * ⚠️ *Die Felder des Dialogs stehen im selben Formular wie der Öffner; darum braucht keines ein `form="…"`.*
     */
    private static function dialogButton(Control $control, bool $usable): string
    {
        $dialog = $control->opens ?? throw new \LogicException('Kein Dialog.');
        $icon   = $control->icon !== '';
        $ok     = new Control($control->name, $control->value, $dialog->confirm, '', $usable, $control->destroys, '', $control->form, true);

        return DialogMarkup::of(
            $dialog->id,
            self::face($control, $icon),
            RenderResult::escape($dialog->title),
            '<span class="taxmod-dialog-body">' . $dialog->body . '</span>',
            self::button($ok) . '<label class="button taxmod-dialog-cancel" for="' . RenderResult::escape($dialog->id) . '">' . RenderResult::escape($dialog->cancel) . '</label>',
            ($icon || $control->glyph !== '') ? 'button taxmod-icon-button taxmod-dialog-open' : 'button taxmod-dialog-open'
        );
    }

    private static function face(Control $control, bool $icon): string
    {
        // ⚠️ *Beide Zweige schrieben ihr Symbol selbst hin, in **zwei verschiedenen Techniken**,
        // innerhalb derselben Methode. Jetzt fragen sie {@see IconMarkup} — dieselbe Stelle, die
        // auch die sechs übrigen Fundorte benutzen.*
        if ($icon) {
            return IconMarkup::dashicon($control->icon, $control->label);
        }

        if ($control->glyph !== '') {
            return IconMarkup::glyph($control->glyph, $control->label);
        }

        return RenderResult::escape($control->label);
    }

    /**
     * Every hidden field a submission carries.
     *
     * ⚠️ *The other half of the same duplication — four renderers spelling out the same loop over
     * `Submission::$hidden`.*
     *
     * @param string $formId The form these fields belong to when they cannot sit inside it, for
     *                       HTML's own `form="…"`. Empty where they are nested in it.
     *
     * ⚠️ **A hidden field outside its form submits nothing, and it does so silently** — the same seam
     * {@see \Taxmod\Core\Renderer\Surroundings::$formId} was built for, pointing the third way: a
     * **panel** whose fields belong to a form drawn further down the page
     * ([D-392](../../../docs/NewConcept/90-decision-log.md)). *Without it the locale would arrive as
     * the neutral one on every page save, which writes the right text against the wrong language.*
     */
    public static function hidden(Submission $submits, string $formId = ''): string
    {
        $fields = '';

        foreach ($submits->hidden as $name => $value) {
            $fields .= RenderResult::htmlTag('input', [
                'type'  => 'hidden',
                'name'  => $name,
                'value' => $value,
                // An empty attribute is left out rather than written empty ({@see RenderResult::htmlTag()}),
                // so the nested case needs no branch of its own.
                'form'  => $formId,
            ]);
        }

        return $fields;
    }

    /**
     * Ein `<form>` mit seinen verborgenen Feldern und seinen Knöpfen — **an einer Stelle**.
     *
     * ⚠️ **Weil es diese drei Zeilen schon zweimal gab.** *{@see FieldRowRenderer} baut sie in ihrer
     * Aktionszelle, {@see RecordRenderer} um seinen Block. Eine dritte Abschrift für die Datensatz-Zeile
     * wäre die vierte Gelegenheit, eine Regel zu vergessen — genau das ist mit `ControlMarkup::button()`
     * schon passiert: **vier Abschriften, und die randlose Regel erreichte eine davon**, weshalb überall
     * sonst die Kästen um die Bilder zurückkamen.*
     *
     * ⚠️ *Die `id` ist nötig, weil ein `<tr>` kein `<form>` umschliessen darf: die Wertfelder der Zeile
     * stehen in anderen Zellen und nennen dieses Formular über `form="…"`.*
     *
     * @param list<Control> $controls Worte darin werden übersprungen ({@see self::isAWord()}).
     */
    public static function actsForm(string $formId, Submission $submits, array $controls): string
    {
        $buttons = '';

        foreach ($controls as $control) {
            if (self::isAWord($control)) {
                continue;
            }

            $buttons .= self::button($control);
        }

        // ⚠️ *`multipart`, damit ein Medienfeld in der Zeile eine Datei mitschicken kann (D-793) — für die übrigen Felder ändert es nichts.*
        return '<form method="post" enctype="multipart/form-data" id="' . RenderResult::escape($formId) . '"'
            . ' action="' . RenderResult::escape($submits->action) . '"'
            . ' class="taxmod-acts">'
            . self::hidden($submits)
            . $buttons
            . '</form>';
    }

    /**
     * Whether this control is a word travelling with the buttons rather than a button.
     *
     * ⚠️ *A shortcut around [OQ-087](../../../docs/NewConcept/91-open-questions.md) — the core cannot
     * make a word, so the boundary sends words in the same list. It had to be skipped in four places
     * and was forgotten in one of them, which is how «here» and «not defined» appeared as buttons.*
     */
    public static function isAWord(Control $control): bool
    {
        return str_starts_with($control->name, 'word:');
    }
}
