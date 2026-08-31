<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

use Taxmod\Core\Model\Identity;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\SettingKey;

/**
 * What every renderer of one typed value does identically, so that none of them says it twice.
 *
 * ⚠️ **The three things below are not per-type decisions and must not be re-answered per type:**
 * `hide` closes the field, *nothing* is drawn as nothing, and whether an input may be offered at
 * all is {@see RenderContext::mayEdit()}'s answer — the model's `read_only` and the caller's
 * circumstance, either of which closes it (D-312, D-218).
 *
 * ⚠️ **A subclass adds a control, never a rule.** The moment one of them decides for itself what
 * *nothing* looks like, there are two answers to a question the reader has to trust.
 *
 * @see docs/NewConcept/30-renderer.md
 */
abstract class TypedFieldRenderer implements Renderer
{
    /**
     * @return list<Purpose>
     *
     * ⚠️ **Search is declined by every renderer in this slice, and declining is the mechanism
     * rather than a gap** (D-217): a search rendering is a **condition** feeding a query builder
     * (D-165), and there is no query builder yet. A renderer that claimed the purpose would hand
     * back a control nothing can execute, which is worse than an attribute that is honestly not
     * yet searchable.
     */
    public function supports(): array
    {
        return [Purpose::Display, Purpose::Edit];
    }

    /**
     * ⚠️ **The type answers eligibility, not the structure** — {@see Renderer::handles()} is the
     * registry key (R14a). `fits()` stays for the structural questions a later renderer needs to
     * ask: a table renderer only fits a multi-valued edge (D-098).
     */
    public function fits(Renderable $subject): bool
    {
        return true;
    }

    final public function render(Renderable $subject, RenderContext $context): RenderResult
    {
        // The edge whose value went into this rendering — metadata a caller cannot recover from
        // the markup afterwards (D-021).
        $used = $subject instanceof Relation ? [$subject->id] : [];

        // ⚠️ **The `hide` check that stood here is gone, and its absence is the decision**
        // ([D-457](../../../docs/NewConcept/90-decision-log.md), [D-450](../../../docs/NewConcept/90-decision-log.md)).
        // *`hide` is a **column** on the identity now, not a setting, and it is an **abort**: the
        // descent stops before it asks a renderer at all. A renderer that returned an empty string
        // had already been asked — and its children had already been drawn.*
        //
        // ⚠️ *What that removes is not a line but a class of fault: as a setting, `hide` on a **type**
        // reached every field of that type through the chain, and the check here is where it landed.
        // **Measured twice** (OQ-101, and again 2026-08-27).*
        return new RenderResult(
            $context->mayEdit() ? $this->input($context) : $this->display($context),
            $used
        );
    }

    /** The value as a reader sees it. */
    abstract protected function display(RenderContext $context): string;

    /** A control to change it by. */
    abstract protected function input(RenderContext $context): string;

    /**
     * The stored value as characters, or an empty string for *nothing*.
     *
     * ⚠️ **Nothing is drawn as nothing** — never a dash, never a zero. A missing value means
     * *not answered* (D-232), and a placeholder would hide that from the reader for good.
     *
     * ⚠️ **This is the one place a converter takes effect, and that is why it is `final`.** *The
     * descent runs the converter in effect and hands the characters in
     * ([D-445](../../../docs/NewConcept/90-decision-log.md)); every typed field already came through
     * here for its characters, so `2k7`, `XII` and `FF` arrive without a single renderer knowing
     * converters exist. **Sixteen renderers would have been sixteen chances to forget** — `CD-7`'s
     * reasoning applied to a mapping instead of to a query.*
     *
     * ⚠️ *Nothing still wins over a converter: a mapping of a value that is not there would be a
     * reading of an unanswered question.*
     */
    final protected function outputValue(RenderContext $context): string
    {
        if ($context->value->isNothing()) {
            return '';
        }

        return $context->shown ?? $context->value->describe();
    }

    /**
     * Der Wert für ein Steuerelement, **das der Browser selbst deutet** — immer der gespeicherte.
     *
     * ⚠️ **Der Unterschied zu {@see self::outputValue()} ist Datenverlust, und er war gebaut.** *Der
     * Eigentümer hat danach gefragt: «wenn ich einen Konverter habe, müssten die Werte anders
     * dargestellt werden — wenn ich römisch habe, in römischen Werten.» **Das stimmt für die Ziffer,
     * die ein Mensch liest, und nicht für das Attribut `value`.** Gemessen: der Konverter `roman`
     * macht aus `12` die Zeichen `XII`, und `<input type="range" value="XII">` ist für den Browser
     * kein Wert — er setzt den Griff in die Mitte. **Beim nächsten Speichern wäre die Mitte der neue
     * Wert.***
     *
     * ⚠️ **Die Trennlinie:** *ein `range`, `number`, `color`, `date` oder `email` bekommt den
     * gespeicherten Wert; ein freies Textfeld bekommt, was ein Mensch liest — dort ist die Notation
     * der Sinn der Sache und der Konverter liest sie wieder ein (`isInvertible()`).*
     *
     * ⚠️ *Die **Anzeige** (nicht die Eingabe) nimmt weiter {@see self::outputValue()}: was ein Leser
     * sieht, ist die Notation. Der Schieber zeigt darum die Ziffer neben der Bahn in der Notation und
     * trägt den gespeicherten Wert in der Bahn.*
     *
     * ⚠️ *Offen bleibt, was beim **Zurückschreiben** gilt, wenn beide Notationen im Spiel sind — siehe
     * [OQ-142](../../../docs/NewConcept/91-open-questions.md). Gemessen: `roman->written('12')`
     * **verweigert** und verdreht nicht, also ist der schlechteste Fall eine sichtbare Absage.*
     */
    final protected function controlValue(RenderContext $context): string
    {
        return $context->value->isNothing() ? '' : $context->value->describe();
    }

    /**
     * A numeric setting as characters — an integer or an exact decimal, whichever was written.
     *
     * ⚠️ **A decimal never becomes a float on the way through** (D-057). It is carried as the
     * string it was stored as, because the only thing being done with it here is putting it in an
     * attribute.
     */
    final protected function numberSetting(RenderContext $context, string $key): ?string
    {
        $value = $context->setting($key);

        if ($value === null || $value->isNothing()) {
            return null;
        }

        return match (true) {
            $value->int !== null     => (string) $value->int,
            $value->decimal !== null => $value->decimal,
            default                  => null,
        };
    }

    final protected function createHtmlAttribute(string $name, ?string $value): string
    {
        return $value === null || $value === ''
            ? ''
            : ' ' . $name . '="' . RenderResult::escape($value) . '"';
    }

    /** What a `<span>` holding a read value looks like, in one place. */
    final protected function createHtmlValueSpan(string $markup): string
    {
        return '<span class="taxmod-value">' . $markup . '</span>';
    }
}
