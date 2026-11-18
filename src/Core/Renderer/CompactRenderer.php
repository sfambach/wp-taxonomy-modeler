<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

use Taxmod\Core\Description\CompactView;
use Taxmod\Core\Description\Hint;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\SimpleType;

/**
 * A node's fields drawn as tightly together as they will go — **one** renderer with a switch, not
 * two ([D-471](../../../docs/NewConcept/90-decision-log.md)).
 *
 * ⚠️ **Why one and not two.** [D-245](../../../docs/NewConcept/90-decision-log.md) carried *compact
 * horizontal* and *compact vertical* as two entries, from the owner's own words of 2026-08-23.
 * [D-471](../../../docs/NewConcept/90-decision-log.md) narrowed that: *«Zwei Renderer, die sich in
 * einer Achse unterscheiden, sind zwei Registrierungen, zwei Namen im `renderer`-Schlüssel und zwei
 * Stellen, an denen dieselbe Kompaktheit gepflegt wird. **Ein Umschalter ist dieselbe Aussage, an
 * einer Stelle.***
 *
 * ⚠️ **What it is for**, in the owner's words in [D-245](../../../docs/NewConcept/90-decision-log.md):
 * *«a node with several attributes that I want shown as compactly as possible together»* — so the
 * parts keep **the order they were handed in**. There is no regrouping here: {@see FormRenderer}
 * sorts by R75's four groups because a form is read top to bottom, and a compact line that
 * rearranged an author's fields would be a second, competing layout rule.
 *
 * ⚠️ **It lays out parts the descent drew; it does not draw them.** A renderer reaches out to
 * nothing ([D-159](../../../docs/NewConcept/90-decision-log.md)), so the container cannot be what
 * asks the registry — the members arrive in {@see Surroundings::$parts}, exactly as they do for
 * {@see FormRenderer}.
 *
 * ```mermaid
 * flowchart LR
 *   P["parts · already drawn"] --> O{"orientation"}
 *   O -->|horizontal| R["one row"]
 *   O -->|vertical| C["one column"]
 *   L["label"] -.->|on by default| R
 *   L -.-> C
 *   R --> H1["ein Fragezeichen am Ende · alle Hilfen der Zeile"]
 *   C --> H2["ein Fragezeichen je Feld"]
 * ```
 *
 * ⚠️ **Das Fragezeichen ist nicht überall gleich verteilt, und das ist
 * [D-662](../../../docs/NewConcept/90-decision-log.md).** *Waagerecht trennt die Felder **ein
 * Leerzeichen** — ein Zeichen je Feld wäre eines je Leerzeichen, und die Zeile wäre von Zeichen
 * zerhackt, die niemand braucht, bis er sie sucht. Also **ein** Zeichen am Ende, das alle Hilfen der
 * Zeile trägt. Senkrecht hat jedes Feld seine Zeile und behält sein eigenes.*
 *
 * ## The two properties, and the open question they hang on
 *
 * | Key | Values | Silence means |
 * |---|---|---|
 * | `orientation` | `horizontal`, `vertical` | `horizontal` |
 * | `label` | a switch | **on** |
 *
 * Both defaults are the owner's, verbatim in [D-471](../../../docs/NewConcept/90-decision-log.md):
 * *«**Standard ist Label an**, und bei horizontal/vertikal ist **Standard horizontal**.»*
 *
 * ⚠️ **They are read as *free* setting keys, and that choice is provisional.**
 * [OQ-120](../../../docs/NewConcept/91-open-questions.md) asks whether a renderer **declares** its
 * properties at all, and measures that the same thing is done two ways today: `SpinnerRenderer` and
 * `SliderRenderer` read **reserved** keys, `TextareaRenderer` reads the free keys `cols` and `rows`
 * that nobody declares. *This follows `TextareaRenderer`, because
 * [D-364](../../../docs/NewConcept/90-decision-log.md) already settled what a free key is for —
 * «`cols` and `rows` are free keys today and correctly so — no instance has *rows*; it is how one
 * renderer draws … **What changes is who writes them: whoever writes renderers, not whoever
 * models.**» An orientation is not a fact about a thing; no record answers it.* **If
 * [OQ-120](../../../docs/NewConcept/91-open-questions.md) answers that renderers declare their
 * properties, these two keys move and this class changes with them.**
 *
 * ⚠️ **The defaults live here, in the renderer, and that is also OQ-120's part 2.**
 * {@see SpinnerRenderer::step()} does the same thing today for `step`. *The alternative — seeding
 * the two keys on the model so silence never happens — would put a drawing instruction in the model
 * for every node that might ever be drawn compactly, and [OQ-120](../../../docs/NewConcept/91-open-questions.md)
 * is where that is being decided rather than here.*
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class CompactRenderer extends RendererNode
{
    // ⚠️ **Der Renderer erklärt seine Attribute selbst** (Anforderung 3.6.3; sein Gerüst:
    // *«CompactRenderer — withLabel, horizontal/vertical»*). Der Vertrag liest sie per Reflection;
    // die Namen sind die, unter denen {@see render()} sie schon immer gelesen hat.
    #[\Taxmod\Core\Model\NodeClass\Attribut]
    public bool $with_label = true;

    #[\Taxmod\Core\Model\NodeClass\Attribut]
    public Orientation $orientation = Orientation::Horizontal;

    public const NAME = 'compact';

    /**
     * The free setting key that flips the axis. See the class docblock on OQ-120.
     *
     * ⚠️ **Der Schlüssel, die zwei Worte und die Vorgabe stehen seit TASK-063 in
     * {@see Orientation}** — *weil {@see TableRenderer} denselben Umschalter bekommen hat und eine
     * abgeschriebene Vorgabe eine zweite Vorgabe ist. Hier bleiben nur die Namen stehen, damit die
     * Aufrufer sie weiter beim Renderer finden.*
     */
    public const ORIENTATION = Orientation::KEY;

    /**
     * The free setting key that switches the labels off. See the class docblock on OQ-120.
     *
     * ⚠️ **Es hiess hier `label` und im Modell `with_label`, und darum hat der Schalter nie
     * geschaltet.** *Gemessen am 2026-09-06: **5 Wertzeilen an der Kante `with_label`, null an einer
     * Kante `label`** — die Kante ist an `render with label` erklärt
     * ([D-647](../../../docs/NewConcept/90-decision-log.md): «`with_label` und `label_role` an `render
     * with label`»). **Der Name der Kante ist der Schlüssel**, also war dieser hier schlicht falsch
     * geschrieben und las lebenslang Schweigen.*
     */
    public const LABEL = 'with_label';

    public const HORIZONTAL = Orientation::Horizontal->value;
    public const VERTICAL   = Orientation::Vertical->value;

    public function supports(): array
    {
        return [Purpose::Display, Purpose::Edit];
    }

    /** @return list<SimpleType> Empty: it is chosen for what a subject **is**, like every container. */
    public function handles(): array
    {
        return [];
    }

    /**
     * ⚠️ **A node, for {@see FormRenderer::fits()}'s reason and no wider.** D-245 describes the
     * subject as *a node with several attributes*; what a compact rendering of an **relation** would
     * mean — the target's fields, or the relation's own — is not decided, and answering it here by
     * accident is how a concept acquires a rule nobody wrote.
     */
    public function fits(Renderable $subject): bool
    {
        return $subject instanceof Node;
    }

    public function render(Renderable $subject, RenderContext $context): RenderResult
    {
        $vertical  = $this->isVertical($context);
        $withLabel = $this->withLabel($context);

        $teile     = [];
        $usedRelations = [];
        // ⚠️ *Waagerecht wandern die Hilfen hierher und werden am Ende **ein** Zeichen
        // ([D-662](../../../docs/NewConcept/90-decision-log.md)); senkrecht bleibt jede bei ihrem Feld.*
        $gesammelt = [];

        foreach ($context->surroundings->parts as $part) {
            if ($part->isHidden()) {
                // R11: a hidden member takes no place at all — the same rule the form applies, and
                // the reason `isHidden()` lives on the part rather than being re-derived here.
                continue;
            }

            // ⚠️ *Eine versteckte Zeile nimmt auch ihre Hilfe mit — sie steht **über** dem `continue`
            // nicht, und das ist Absicht: ein Satz zu einem Feld, das niemand sieht, erklärt nichts.*
            if (! $vertical && $part->hint !== '') {
                $gesammelt[] = $part->hint;
            }

            $usedRelations = [...$usedRelations, ...$part->result->usedRelations];
            $teile[]   = ['name' => $part->relation->name, 'field' => $part->result->piece(), 'hint' => $vertical ? $part->hint : ''];
        }

        // ⚠️ **Angezeigt und ganz leer: ein leerer Wert statt eines leeren Gerüsts** ([D-869](../../../docs/NewConcept/90-decision-log.md)).
        // *Gemessen am 2026-09-19 an «CPUs»: ein leerer Einheitenwert zeichnete drei leere Teile in einem Flex-Rahmen, 534 Byte je Zelle —
        // bei 40 Spalten und 20 Zeilen der grösste Posten der Satztabelle. Zu sehen war nichts davon.*
        // Ob ein Feld etwas zeigt, beantwortet der Zeichner.
        return RenderResult::described(
            new CompactView(
                $teile,
                $vertical,
                $withLabel,
                $context->purpose === Purpose::Display && ! $withLabel,
                $gesammelt === [] ? null : Hint::combined($gesammelt)
            ),
            ...array_values(array_unique($usedRelations))
        );
    }

    /**
     * ⚠️ **Only the exact word `vertical` turns the axis**; everything else — silence, an empty
     * setting, a misspelling — is the default. *That reading, and the default it falls back to, live
     * in {@see Orientation} since TASK-063, because {@see TableRenderer} reads the same switch.*
     */
    private function isVertical(RenderContext $context): bool
    {
        return Orientation::fromContext($context)->isVertical();
    }

    /**
     * ⚠️ **Silence is *on*, which is why this is not a plain `asBool()`.** The pattern is
     * {@see RenderContext::mayEdit()}'s — read the switch, and where the chain is silent use the
     * default that was decided rather than PHP's falsy zero. *`?->` guards a null object here, not
     * a missing key.*
     */
    private function withLabel(RenderContext $context): bool
    {
        $switch = $context->setting(self::LABEL);

        if ($switch === null || $switch->isNothing()) {
            return true;
        }

        return $switch->asBool();
    }

    // ⚠️ **The label is the attribute's own name** — a field should read its label in the `form` role
    // ([D-196](../../../docs/NewConcept/90-decision-log.md)); until it arrives in the context, the relation's internal name stands, never
    // nothing ([D-020](../../../docs/NewConcept/90-decision-log.md)). ⚠️ **Senkrecht steht die Hilfe bei ihrem Feld, waagerecht nicht**
    // ([D-662](../../../docs/NewConcept/90-decision-log.md)): *«bei compact horizontal würde ich die Texte sammeln und in ein Fragezeichen am
    // Ende kombinieren»*.
}
