<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

use Taxmod\Core\Description\Describes;
use Taxmod\Core\Description\FieldRow;
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

    /**
     * Unter diesem Namen erwartet die Zeile den Haken **«hier überschreibe ich»** — als erste Spalte.
     *
     * ⚠️ **Sein Wort am 2026-09-10:** *«das override tickfeld mal an den anfang der setting zeile und als
     * richtige spalte».* *Vorher hing der Haken hinter dem Steuerelement in der Wertspalte; jetzt steht er
     * vorn, in einer eigenen Zelle, und die Wertspalte trägt nur den Wert und die Herkunft. Ist der
     * Abschnitt da, aber leer, bleibt die Zelle leer — die Spalte gibt es dann trotzdem, damit die
     * Tabelle ihren Kopf behält.*
     */
    public const OVERRIDE = 'override';

    /**
     * Unter diesem Namen erwartet die Zeile die **Einstellungen ihrer Kante** — aufgeklappt.
     *
     * ⚠️ **Fehlt der Abschnitt, ist der Bereich zu — und dann ist er auch nicht gelesen**
     * ([D-666](../../../docs/NewConcept/90-decision-log.md)). *Sein Wort: «Standard ist nicht
     * ausgeklappt — das heisst auch nicht gelesen; erst beim Ausklappen wird der Bereich gefuellt.»
     * **Darum ist das hier keine Stilfrage**: ein `display:none` hätte den Server schon auflösen und
     * mitschicken lassen, und jede Einstellungszeile kostet eine Kette über Kante, Zielknoten und
     * dessen Vorfahren ([D-602](../../../docs/NewConcept/90-decision-log.md)).*
     */
    public const SETTINGS = 'settings';

    /**
     * Der Wert des Knopfes, der die Einstellungen dieser Zeile auf- und zuklappt.
     *
     * ⚠️ **Er steht in der Namenszelle und nicht in der Icon-Reihe** — *sein Wort: «kleiner Pfeil
     * links unter der Zeile aufklappen wie beim Baum». Der Rand baut den Knopf, dieser Renderer
     * entscheidet, **wo** er steht — und dafür muss er ihn unter den übrigen wiedererkennen. **Der
     * Wert ist die einzige Angabe, die beide Seiten teilen**, und darum steht er hier und nicht
     * zweimal als Zeichenkette.*
     */
    public const FOLD = 'toggle_field_settings';

    /**
     * Die Kennung der aufgeklappten Zeile, damit der Rand sie wiederfindet.
     *
     * ⚠️ *Der skriptfreie Weg braucht sie nicht — er zeichnet die ganze Seite neu. **Der Weg mit
     * Skript braucht genau sie**: er holt den Bereich nach und muss wissen, wohin damit
     * ([D-627](../../../docs/NewConcept/90-decision-log.md)).*
     */
    public static function settingsRowFor(Relation $relation): string
    {
        return 'taxmod-field-settings-' . $relation->id;
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
            return RenderResult::nothing();
        }

        $u       = $context->surroundings;
        $haken   = $u->sections[self::OVERRIDE] ?? null;
        $wert    = $u->sections[self::VALUE] ?? null;
        $bereich = $u->sections[self::SETTINGS] ?? null;
        $teil    = static fn (?Section $s): Describes|string|null => $s === null ? null : ($s->piece ?? $s->body);
        $gemalt  = static fn (?RenderedSetting $d): ?Describes => $d !== null && $d->wasDrawn() ? $d->result->piece() : null;
        $art     = $u->configured['kind'] ?? null;

        // ⚠️ *Acht Spalten seit D-735 (nur lesen, eindeutig — «unique (eindeutig) und readonly direkt an der kante», je ein Schalter in
        // der Zeile); sein Befund: die Klappleiste rutschte nach links.* ⚠️ *Der Name des Ziels kommt aufgelöst (`refersTo`): eine Abfrage
        // je Zeile wäre die Schleife von `CD-7`.* ⚠️ **Gesperrt heisst nicht bedienbar, und die Zeile bleibt stehen** ([D-608](../../../docs/NewConcept/90-decision-log.md),
        // [D-604](../../../docs/NewConcept/90-decision-log.md)): kein Wert, und das Schloss sagt warum. ⚠️ *Eigen oder geerbt
        // ([D-607](../../../docs/NewConcept/90-decision-log.md)); ein Behälter fasst keinen Wert an ([D-366](../../../docs/NewConcept/90-decision-log.md)).*
        // ⚠️ *Die Klappleiste: nur das Zeichen, links, ohne Rahmen ([D-782](../../../docs/NewConcept/90-decision-log.md)).* ⚠️ *Knöpfe
        // ausgegraut statt weg (D-370); die Adresse des Ziels kommt vom Rand (`CD-1`, [D-465](../../../docs/NewConcept/90-decision-log.md)),
        // und das Formular der Zeile heisst nach ihrem Feld ([D-627](../../../docs/NewConcept/90-decision-log.md)).*
        $fold = null;
        $acts = [];

        foreach ($u->actions as $control) {
            if ($control->value === self::FOLD) {
                $fold ??= $control;
            } elseif (! $control->isAWord()) {
                $acts[] = $control;
            }
        }

        return RenderResult::described(new FieldRow(
            $subject->id,
            $subject->name,
            $context->purpose === Purpose::Edit && $context->editable ? $context->fieldName : null,
            $u->formId === '' ? self::formFor($subject) : $u->formId,
            $u->refersTo ?? '—',
            $u->href,
            $teil($u->sections['target-chooser'] ?? null) ?? '',
            $gemalt($art),
            $subject->kind->value,
            $context->editable,
            $this->word($context, 'own'),
            $this->word($context, 'inherited'),
            $u->locked,
            $this->word($context, 'locked'),
            $this->word($context, 'locked-reason'),
            $gemalt($u->configured[\Taxmod\Core\Model\EdgeColumn::MULTIPLICITY] ?? null),
            $gemalt($u->configured[\Taxmod\Core\Model\EdgeColumn::READ_ONLY] ?? null),
            $gemalt($u->configured[\Taxmod\Core\Model\EdgeColumn::UNIQUE] ?? null),
            $teil($haken),
            $wert !== null,
            $wert === null || $u->locked || ! $wert->shows() ? null : $teil($wert),
            $u->submits === null ? '' : self::formFor($subject),
            $u->submits?->action ?? '',
            $u->submits?->hidden ?? [],
            $acts,
            $fold,
            8 + ($wert === null ? 0 : 1) + ($haken === null ? 0 : 1),
            self::settingsRowFor($subject),
            $bereich === null || ! $bereich->shows() ? null : $teil($bereich)
        ));
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
