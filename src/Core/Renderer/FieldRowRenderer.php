<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

use Taxmod\Core\Model\Identity;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\SettingKey;
use Taxmod\Core\Model\SimpleType;

/**
 * One attribute as its own row — **the first renderer whose subject is an relation**.
 *
 * The owner asked for two things in one breath: *the name of the attribute should be changeable —
 * is that already a renderer for attributes?* It was not: the attribute table was hand-built
 * markup, which `R1` forbids — *anything that shows model data goes through the renderer contract.*
 *
 * ```mermaid
 * flowchart LR
 *   E["the relation · name · kind"] --> A[this]
 *   T["refersTo · what it points at"] --> A
 *   C["configured · multiplicity"] --> A
 *   S["sections · its settings panel"] --> A
 *   K["actions · save · remove"] --> A
 * ```
 *
 * ⚠️ **That the subject may be an relation was in the contract from the start** and had never been
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
final class FieldRowRenderer extends RendererNode
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

    /**
     * The row's own form id, so a control in another cell can submit through it.
     *
     * ⚠️ **A row is a `<tr>` and HTML forbids a form wrapping table cells**, so the acts build their
     * form in one `<td>` and the multiplicity sits in another — **outside it**. That is not cosmetic:
     * the select submitted nothing at all, and a multiplicity change looked like a save that did
     * nothing. *The owner found it on `Bauteilliste`'s `Position` going from `0..1` to `0..*`.*
     */
    public static function formFor(Relation $relation): string
    {
        return 'taxmod-field-' . $relation->id;
    }

    /** Unter diesem Namen erwartet die Zeile den gezeichneten **Wert** ihrer Angabe. */
    public const VALUE = 'value';

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
            . $this->cell($this->targetCell($context), 'taxmod-field-target', false, true)
            . $this->cell($subject->kind->value, 'taxmod-field-kind', true)
            . $this->origin($context)
            . $this->cell($this->multiplicity($context), 'taxmod-field-many', false, true)
            . $this->valueCell($context)
            . $this->cell($this->controls($context->surroundings, self::formFor($subject)), 'taxmod-field-acts', false, true);

        $klasse = 'taxmod-field' . ($context->surroundings->locked ? ' taxmod-field-locked' : '');

        return RenderResult::of(
            '<tr class="' . $klasse . '">' . $cells . '</tr>'
        );
    }

    /**
     * Die Kennzeichnung «gesperrt» samt Grund — [D-608](../../../docs/NewConcept/90-decision-log.md).
     *
     * ⚠️ **Beides ist verlangt und keins genügt allein.** *Sein Wort: «muss aber ne Tooltip-Begründung
     * da sein, und ‹gesperrt› muss sichtbar sein.» **«Gesperrt» sichtbar** sagt, dass die Zeile nicht
     * bedienbar ist; **der Hinweistext** sagt warum. Ohne den Text wäre es eine Sperre ohne Grund.*
     *
     * ⚠️ **Weggelassen wird die Zeile ausdrücklich nicht.** *Das war der Mangel, den D-608 behebt: wer
     * fragt «warum hat `read_only` kein `read_only`», fand keine Antwort im Modell — nur in einer
     * Entscheidung, die er suchen muss. Dieselbe Haltung wie in
     * [D-604](../../../docs/NewConcept/90-decision-log.md): was der Benutzer wissen muss, steht dort,
     * wo er hinschaut.*
     *
     * ⚠️ **Die zwei Worte kommen vom Rand** (`AR-2`, [OQ-087](../../../docs/NewConcept/91-open-questions.md)),
     * wie «own» und «inherited» — *bis der Rand sie mitgibt, steht der Schlüssel selbst da, sichtbar.
     * Ein bemerktes falsches Wort schlägt ein geratenes.*
     */
    private function lockMark(RenderContext $context): string
    {
        if (! $context->surroundings->locked) {
            return '';
        }

        return ' ' . RenderResult::htmlTag('span', [
            'class' => 'taxmod-locked',
            'title' => $this->plainWord($context, 'locked-reason'),
        ]) . '&#128274; ' . $this->word($context, 'locked') . '</span>';
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
                'taxmod-field-name',
                false,
                true
            );
        }

        // ⚠️ **Die Regel ist die des Modells** — *ein Feld kann nicht namenlos sein
        // (`Relation::renamedTo()` verweigert es), also sagt der Browser dasselbe wie der Kern und
        // nicht eine zweite, weichere Regel.*
        //
        // ⚠️ **Aber `aria-required` und nicht `required`, seit die Zeile im Seitenformular hängt.**
        // *Genau diese Blockade hat er schon einmal gefunden: «habe die Multiplizität auf `0..1`
        // gesetzt und wollte dann die Seite speichern — da kam dieser Fehler.» **Ein `required` in
        // einer von sechzig Zeilen sperrt das Speichern der ganzen Seite**, und der Kern verweigert
        // einen leeren Namen ohnehin — dort, wo die Regel wohnt.*
        $field = RenderResult::htmlTag('input', [
            'type'     => 'text',
            'class'    => 'taxmod-field-rename',
            'name'     => $context->fieldName,
            'value'    => $subject->name,
            'aria-required' => 'true',
            'style'    => 'width:100%',
            // ⚠️ **Ohne dies schickt das Feld nichts** — derselbe Fehler, den {@see self::formFor()}
            // für den Auswahlkasten beschreibt, eine Zelle weiter und ungeheilt geblieben. *Der
            // Eigentümer hat ihn zum zweiten Mal gefunden: «Änderungen in Namen … werden nicht mehr
            // übernommen.» **Gemessen: `attribute renamed` stand 0 mal im ganzen Changelog**, während
            // «wie oft» längst ankam — die Umbenennung hat nie gegriffen, nicht seit heute.*
            //
            // ⚠️ **Das Formular der Seite, wo es eines gibt** — *«Save in Fields sollte eigentlich auch
            // über die Seite gehen». Leer heisst «keins» (die Vorschau hat keines), und dann bleibt es
            // beim Formular der Zeile.*
            'form'     => $context->surroundings->formId === '' ? self::formFor($subject) : $context->surroundings->formId,
        ]);

        return $this->cell($field, 'taxmod-field-name', false, true);
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
            ($context->editable
                ? $this->word($context, 'own')
                : '<em>' . $this->word($context, 'inherited') . '</em>')
            // ⚠️ *Hier und nicht in einer eigenen Spalte: die Sperre **ist** eine Aussage über die
            // Herkunft — die Kante kommt von oben und hält an diesem Knoten an
            // ([D-607](../../../docs/NewConcept/90-decision-log.md)).*
            . $this->lockMark($context),
            'taxmod-field-from',
            false,
            true
        );
    }

    /**
     * The multiplicity, as the settings side already drew it.
     *
     * ⚠️ **Not built here.** It is an ordinary setting on the relation
     * ([D-351](../../../docs/NewConcept/90-decision-log.md)) and the descent draws settings; a
     * second select built in this class would be the same control twice, and the two would come
     * apart the moment R28–R32's greying lands in one of them.
     *
     * ⚠️ *It is the one key kept **out** of the panel below and shown in the row instead, because it
     * is the only relation-only key there is (D-351) — the thing that makes a use site a use site.*
     */
    /**
     * Der **Wert** der Angabe — die Spalte, ohne die eine Einstellung nicht einzustellen ist.
     *
     * ⚠️ **Auf sein Wort, und das Konzept sagt es wörtlich:** *«der Eingabemechanismus existiert
     * bereits: **die Einstellungsseite**. Was sich ändert, ist nur, wie sie zu verstehen ist — hier
     * schreibt der Autor Feldwerte am Modell»* ([02-field-and-setting.md](../../../docs/NewConcept/02-field-and-setting.md)).
     *
     * ⚠️ **Und er hat mich dabei zu Recht gestellt.** *Ich hatte einen eigenen Renderer-Wähler oben auf
     * die Seite gesetzt; er: «ich verstehe nicht, warum es nicht im Setting Display Option angezeigt
     * wird, das ist genau dafür da, und das ist glaube ich das, was du am Konzept vorbei machst».
     * **Ein zweites Steuerelement für dieselbe Angabe ist genau das, was `R1` verbietet** — und die
     * Zeile, die es hätte tragen sollen, hatte einfach keine Wertspalte.*
     *
     * ⚠️ *Gezeichnet hat es der gewöhnliche Abstieg, nicht diese Klasse: bei `Display Option` steigt er
     * in den Teil hinein und liefert dessen Felder (`render`, `converter`), bei `read_only` einen
     * Schalter. **Ein Behälter fasst keinen Wert an** ([D-366](../../../docs/NewConcept/90-decision-log.md)).*
     */
    // WICHTIG: Die Zelle fehlt ganz, wenn die Spalte fehlt -- auf sein Wort: "die ganze Spalte
    // Value muss weg". `isset()` statt `??` unterscheidet «kein Wert zu zeigen» (Gedankenstrich,
    // die Spalte gibt es) von «diese Zeilen haben gar keine Wertspalte» (kein `<td>` ueberhaupt),
    // sonst wuerde jede Feldzeile eine leere Zelle in eine Tabelle ohne den Kopf dazu schreiben.
    private function valueCell(RenderContext $context): string
    {
        if (! isset($context->surroundings->sections[self::VALUE])) {
            return '';
        }

        // ⚠️ **Gesperrt heisst nicht bedienbar** ([D-608](../../../docs/NewConcept/90-decision-log.md)):
        // *ein Eingabefeld in einer Zeile, die «gesperrt» sagt, wäre die Sperre nur behauptet. Die
        // Zeile bleibt stehen, ihr Wert ist keiner — der Gedankenstrich, den die Spalte schon kennt.*
        if ($context->surroundings->locked) {
            return $this->cell('<span class="taxmod-nothing">—</span>', 'taxmod-field-value', false, true);
        }

        $gezeichnet = $context->surroundings->sections[self::VALUE];

        if (trim($gezeichnet->body) === '') {
            // ⚠️ *Ein Gedankenstrich und keine leere Zelle — sonst liest die Spalte nicht als Spalte.*
            return $this->cell('<span class="taxmod-nothing">—</span>', 'taxmod-field-value', false, true);
        }

        return $this->cell($gezeichnet->body, 'taxmod-field-value', false, true);
    }

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

    /**
     * Dasselbe Wort **unescaped** — für ein Attribut, das {@see RenderResult::htmlTag()} selbst escapt.
     *
     * ⚠️ *Zweimal escapen ist der Fehler, der aus einem Apostroph `&amp;#039;` macht — in einem
     * Tooltip liest das jeder.*
     */
    private function plainWord(RenderContext $context, string $key): string
    {
        foreach ($context->surroundings->actions as $control) {
            if ($control->name === 'word:' . $key) {
                return $control->label;
            }
        }

        return $key;
    }

    /**
     * What the attribute points at — a link where the surface said where the node is.
     *
     * ⚠️ **A renderer may build the anchor; it may not invent the URL.** *`$this` is the
     * {@see FieldRowRenderer}: wrapping what it drew is ordinary markup and keeps the shape of the
     * row here (`R1`), while the address is a boundary fact handed in through
     * {@see Surroundings::$href} (`CD-1`) — the same division {@see TreeNodeRenderer} makes for a
     * tree row.*
     *
     * ⚠️ *Spelled through {@see RenderResult::htmlTag()}, which is the one place that knows how an
     * element is written ([D-465](../../../docs/NewConcept/90-decision-log.md)) — and it escapes the
     * URL on the way in, so the cell can be handed on as trusted markup.*
     */
    private function targetCell(RenderContext $context): string
    {
        $name = $context->surroundings->refersTo ?? '—';
        $href = $context->surroundings->href;

        // WICHTIG: Der Auswahldialog dieser Zeile, wenn der Rand einen mitgegeben hat -- TASK-029,
        // auf sein Wort: "Points at ist der type und type muss aenderbar sein". Er kommt als
        // fertiges Markup herein, weil er eine URL und eine Nonce braucht (CD-1).
        $wahl = $context->surroundings->sections['target-chooser']->body ?? '';

        // WICHTIG: Dieselbe Erscheinung wie beim Anlegen eines Feldes -- auf sein Wort:
        // "existierendes Feld sollte genauso wie beim Erstellen von Feldern aussehen, Feld
        // nicht eingebbar und rechts daneben der Baumknopf". Dieselbe Klasse wie das gesperrte
        // Feld dort ({@see NodesScreen::fieldForm()}), damit beide gleich aussehen -- ein blosser
        // Text neben dem Knopf sah dagegen wie ein anderes Bedienelement aus.
        //
        // WICHTIG: In einer eigenen Zeile mit Flex-Layout, sonst zeigt `flex:1` ins Leere --
        // eine Tabellenzelle ist kein Flex-Behaelter. Auf sein Wort: "typ felder breiter und
        // button rechts davon", statt darunter, wenn der Name lang ist.
        if ($href === null || $name === '—') {
            return '<span style="display:flex;align-items:center;gap:.3em">'
                . RenderResult::htmlTag('input', [
                    'type'     => 'text',
                    'class'    => 'taxmod-chosen',
                    'readonly' => true,
                    'tabindex' => '-1',
                    'value'    => $name,
                    'style'    => 'flex:1;min-width:8em',
                ]) . $wahl
                . '</span>';
        }

        return '<span style="display:flex;align-items:center;gap:.3em">'
            . RenderResult::htmlTag('a', ['href' => $href, 'class' => 'taxmod-field-target-link taxmod-chosen', 'style' => 'flex:1;min-width:8em'])
            . RenderResult::escape($name)
            . '</a>'
            . $wahl
            . '</span>';
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
            $fields .= RenderResult::htmlTag('input', [
                'type'  => 'hidden',
                'name'  => $name,
                'value' => $value,
            ]);
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
