<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

use Taxmod\Core\Model\SimpleType;

/**
 * Ein Benutzerverweis: **gezeichnet wird der Name, gespeichert die Id**
 * ([D-649](../../../docs/NewConcept/90-decision-log.md)).
 *
 * ⚠️ **Sein Wort:** *«also im Grunde könnten wir einen Renderer dafür bauen, der als readonly Feld
 * rendert und im Frontend mit dem Benutzernamen gefüllt wird.»*
 *
 * ⚠️ **Die Id und nicht der Name, und das stand schon im Kern** ([D-171](../../../docs/NewConcept/90-decision-log.md),
 * `P4d`): *«a reference to a WordPress user. **Stored as text**, like every opaque key of a foreign
 * system — the core sees a string and knows nothing of WordPress.» **Ein Name ändert sich**; nach
 * Anzeigenamen zu unterscheiden ist überall verboten.*
 *
 * ```mermaid
 * flowchart LR
 *   D["Datensatz · Wert 17"] --> K["dieser Renderer · beschreibt"]
 *   R["der Rand · Users::namesFor"] -->|"Name gereicht"| K
 *   K --> A["gezeichnet: der Name"]
 * ```
 *
 * ⚠️ **Der Name wird gereicht, nie geholt** ([D-159](../../../docs/NewConcept/90-decision-log.md),
 * `CD-1`). *Der Kern beschreibt «Benutzerverweis, Wert 17»; wer daraus einen Namen macht, ist der
 * Rand — er ist der Einzige, der WordPress fragen darf. **Dieser Renderer ruft `get_userdata()`
 * nicht**, und ein Wächter hält das fest.*
 *
 * ⚠️ **Er nimmt dieselbe Naht wie {@see ReferenceRenderer}** — {@see Surroundings::$refersTo}. *Ein
 * eigenes Feld daneben hiesse dieselbe Aussage zweimal führen: «wie heisst das, worauf dieser Wert
 * zeigt». Dass das Ziel hier in einem fremden System liegt, ändert die Frage nicht, nur den, der sie
 * beantwortet.*
 *
 * ⚠️ **Die Hälfte, die gesperrt ist, und warum sie es sichtbar ist**
 * ([D-650](../../../docs/NewConcept/90-decision-log.md)): *bei `user_ref` bestimmt `read_only`,
 * **woher der Wert kommt** — gesperrt heisst «der angemeldete Benutzer», bedienbar heisst «aus einer
 * Liste wählen». **Die Liste muss vom Rand kommen** und den Rand-Wähler gibt es erst mit dem grossen
 * Umbau ([D-624](../../../docs/NewConcept/90-decision-log.md)). Also steht dort eine Sperre mit
 * Begründung statt einer erfundenen Bedienung — dieselbe Haltung wie
 * [D-608](../../../docs/NewConcept/90-decision-log.md): «‹gesperrt› muss sichtbar sein» und «muss ne
 * Tooltip-Begründung da sein».*
 *
 * ⚠️ **Die Regel selbst steht nicht hier**, sondern in {@see \Taxmod\Core\Model\Type\UserRefType} —
 * *auf seine Frage «soll nur an `user_ref` so sein, müsste ja auch eine eigene Klasse sein,
 * stimmts?». Dieser Renderer liest sie ab; er trifft sie nicht.*
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class UserRefRenderer extends TypedFieldRenderer
{
    public const NAME = 'user';

    public function name(): string
    {
        return self::NAME;
    }

    public function handles(): array
    {
        return [SimpleType::UserRef];
    }

    /**
     * Was ein Leser sieht: der Name, den der Rand gereicht hat.
     *
     * ⚠️ *Dies ist auch der Weg des **gesperrten** Feldes, denn {@see RenderContext::mayEdit()}
     * antwortet dort «nein» — und gesperrt heisst bei diesem Typ «der angemeldete Benutzer»
     * ([D-650](../../../docs/NewConcept/90-decision-log.md)).*
     */
    protected function display(RenderContext $context): string
    {
        if ($context->value->isNothing()) {
            // ⚠️ *Nichts wird als nichts gezeichnet — nie als Strich, nie als `0`
            // ([D-232](../../../docs/NewConcept/90-decision-log.md)).*
            return $this->createHtmlValueSpan('');
        }

        $name = $context->surroundings->refersTo;

        if ($name === null || $name === '') {
            // ⚠️ **Eine Id, zu der kein Name kam, wird als Fehlstelle gezeichnet und nicht als Zahl**
            // — dieselbe Entscheidung wie bei einem hängenden Knotenverweis
            // ([D-363](../../../docs/NewConcept/90-decision-log.md), {@see ReferenceRenderer::display()}).
            // *Ein roher Schlüssel auf dem Bildschirm ist das, was jemand in eine Tabelle kopiert, als
            // bedeutete er etwas.*
            return '<span class="taxmod-value taxmod-dangling">'
                . RenderResult::escape('#' . $this->storedId($context))
                . '</span>';
        }

        return $this->createHtmlValueSpan(RenderResult::escape($name));
    }

    /**
     * Die Hälfte «aus einer Liste wählen» — **sichtbar gesperrt, mit Begründung**.
     *
     * ⚠️ **Sie wird nicht erfunden.** *Sein Wort: «die Liste müsste aber auch aus dem Frontend
     * kommen» ([D-650](../../../docs/NewConcept/90-decision-log.md)). Eine Liste, die der Kern sich
     * selbst zusammenstellt, wäre ein Wähler ohne Wahlmöglichkeiten — genau die «leere Hülle», an der
     * {@see RendererRegistry::chosenFor()} schon einmal hängengeblieben ist.*
     *
     * ⚠️ **Der gespeicherte Wert fährt verborgen mit.** *Ein gesperrtes Steuerelement schickt nichts,
     * und ein Feld, das beim nächsten Speichern leer zurückkommt, hätte den Wert verloren — derselbe
     * Verlust, den {@see ColorRenderer} für den Farbwähler beschreibt.*
     */
    protected function input(RenderContext $context): string
    {
        $stored = $this->storedId($context);

        return RenderResult::htmlTag('input', [
            'type'     => 'text',
            'class'    => 'taxmod-locked',
            'value'    => $context->surroundings->refersTo ?? ($stored === '' ? '' : '#' . $stored),
            'disabled' => true,
            // ⚠️ *Das Wort kommt vom Rand (`AR-2`, [OQ-087](../../../docs/NewConcept/91-open-questions.md));
            // bis es das tut, steht der Schlüssel selbst da — sichtbar falsch schlägt geraten.*
            'title'    => $this->reason($context),
        ]) . RenderResult::htmlTag('input', [
            'type'  => 'hidden',
            'name'  => $context->fieldName,
            'form'  => $context->surroundings->formId,
            'value' => $stored,
        ]);
    }

    /**
     * Warum hier nichts zu bedienen ist.
     *
     * ⚠️ *Ein Schlüssel und kein Satz: übersetzt wird am Rand (`AR-2`). **Ohne Grund wäre es eine
     * Sperre, die niemand einordnen kann** — der Mangel, den [D-608](../../../docs/NewConcept/90-decision-log.md)
     * behoben hat.*
     */
    private function reason(RenderContext $context): string
    {
        foreach ($context->surroundings->actions as $control) {
            if ($control->name === 'word:user-ref-picker-missing') {
                return $control->label;
            }
        }

        return 'user-ref-picker-missing';
    }

    /** Die Id, so wie sie im Datensatz steht — Text, wie [D-171](../../../docs/NewConcept/90-decision-log.md) sagt. */
    private function storedId(RenderContext $context): string
    {
        return $context->value->isNothing() ? '' : $context->value->describe();
    }
}
