<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

use Taxmod\Core\Model\Node;

/**
 * Ein Renderer als **die Klasse seines Knotens**.
 *
 * ⚠️ **Auf sein Wort** ([D-620](../../../docs/NewConcept/90-decision-log.md)): *«die Renderer werden
 * Knoten, deswegen hätte ich eigentlich erwartet, dass die Renderer selbst Knoten sind, weil wir sie
 * ja auch einfach zuweisen — das ist ein bisschen untergegangen.»* **Sie waren schon Knoten im
 * Modell** — `checkbox`, `form`, `chooser-dialog` liegen unter `Settings > Renderer` und tragen ihre
 * Klasse in `nodes.implemented_by` (TASK-008) — **nur im Code kam so ein Knoten als schlichtes `Node`
 * an.** Jetzt kommt er als seine Renderer-Klasse ({@see Node::fromStorage()}).
 *
 * ```mermaid
 * flowchart LR
 *   Z["Zeile · implemented_by = CheckboxRenderer"] --> H["fromStorage"]
 *   H --> K["ein CheckboxRenderer, der ein Knoten ist"]
 * ```
 *
 * ⚠️ **Ein Exemplar ohne Id ist der Steckbrief, kein Knoten** — dieselbe Trennung wie bei
 * {@see \Taxmod\Core\Model\Type\SpecialisedType}. *`new CheckboxRenderer()` bleibt, was es war: das
 * Ding, das die Registratur hält und das zeichnet. **Zeichnen hängt an der Klasse und nicht an der
 * Zeile**, also antwortet der geladene Knoten genauso — es gibt keine zweite Wahrheit, nur zwei Wege
 * zu derselben.*
 *
 * ⚠️ *Der Knotenname **ist** {@see Renderer::name()} — der Name, unter dem die Saat den Knoten anlegt.
 * Kein benutzersichtbarer Text (`AR-2`): eine Marke, die verglichen und nie übersetzt wird.*
 *
 * ⚠️ **Auch ein Oberflächen-Renderer erbt von hier**, obwohl ihn niemand sät
 * ({@see RendererRegistry::addForSurfaces()}). *Die Klasse zu spalten hiesse, die Antwort auf «wird
 * dieser gesät» an zwei Stellen zu führen — die Registratur trifft sie schon.*
 *
 * @see docs/NewConcept/30-renderer.md
 */
abstract class RendererNode extends Node implements Renderer
{
    /**
     * ⚠️ *Die Vorgabe ist «nein», weil sie für achtzehn von einundzwanzig Renderern stimmt: ein
     * Textfeld, ein Schalter, eine Tabelle brauchen keine Menge. **Wer eine braucht, sagt es** —
     * heute die drei Auswahl-Renderer ({@see Renderer::needsSomethingToChooseFrom()}).*
     */
    public function needsSomethingToChooseFrom(): bool
    {
        return false;
    }

    /**
     * ⚠️ **Alles hat eine Voreinstellung, damit `new CheckboxRenderer()` weiter der Steckbrief ist** —
     * und alles steht in der Reihenfolge von {@see Node::fromStorage()}, damit eine geladene Zeile hier
     * ankommt.
     */
    public function __construct(
        int $id = 0,
        int $version = 0,
        ?string $name = null,
        string $path = '',
        ?string $implementedBy = null,
        ?int $parentNodeId = null,
        int $sortOrder = 0,
        bool $hide = false,
        string $klasse = '',
    ) {
        // ⚠️ *Die Renderer-Knoten fallen mit Schritt 7 des Bauplans ([D-718](../../../docs/NewConcept/90-decision-log.md));
        // bis dahin sind sie Kategorien wie jeder Knoten ohne Funktion.*
        parent::__construct(
            $id,
            $version,
            $name ?? $this->name(),
            $path,
            $implementedBy,
            $parentNodeId,
            $sortOrder,
            $hide,
            $klasse,
        );
    }
}
