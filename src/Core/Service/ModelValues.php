<?php declare(strict_types=1);

namespace Taxmod\Core\Service;

use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\RecordKind;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\ResolvedSetting;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Repository\FrameworkNodes;
use Taxmod\Core\Repository\NodeRepository;
use Taxmod\Core\Repository\RecordRepository;
use Taxmod\Core\Repository\RelationRepository;

/**
 * Was der Autor am Modell gesagt hat — gelesen aus Datensätzen statt aus der Settings-Tabelle.
 *
 * ⚠️ **Die Brücke für [D-529](../../../docs/NewConcept/90-decision-log.md).** *Die Settings-Tabelle
 * fällt, und die Angaben ziehen einzeln um. **Solange beides existiert, muss der Leser beide Stellen
 * kennen** — und die neue gewinnt, weil ein Umzug sonst nichts ändern würde.*
 *
 * ⚠️ **Diese Klasse ist der Grund, warum der Umzug am 2026-08-30 zurückgedreht wurde.** *Die Daten
 * wanderten zuerst, der Leser blieb — jeder Knoten hätte seinen Renderer verloren, und **alle 305
 * Randprüfungen wären grün geblieben**. Seither ist die Reihenfolge festgelegt: **Wächter, Leser,
 * Daten.***
 *
 * ⚠️ *Sie antwortet vorläufig nur zum **Renderer**, weil nur der umgezogen ist. Die übrigen Angaben
 * kommen hier dazu, sobald sie wandern — jede in einer eigenen Methode, damit sichtbar bleibt, welche
 * schon an der neuen Stelle liegt.*
 *
 * ```mermaid
 * flowchart LR
 *   N["Knoten"] --> R["sein Datensatz"]
 *   R -->|"Pfad = renderer-Kante"| T["Teil: DisplayOption"]
 *   T -->|"Feld render"| K["Knoten unter Renderer"]
 *   K --> M["sein Name ist die Antwort"]
 * ```
 *
 * @see docs/NewConcept/02-field-and-setting.md
 */
final class ModelValues
{
    /** Die zwei Kanten, über die ein Renderer gefunden wird — einmal gesucht, dann gemerkt. */
    private ?int $rendererEdge = null;

    private ?int $renderEdge = null;

    private bool $gesucht = false;

    public function __construct(
        private readonly RecordRepository $records,
        private readonly RelationRepository $relations,
        private readonly NodeRepository $nodes,
        private readonly FrameworkNodes $framework,
    ) {
    }

    /**
     * Die Angaben, die dieser **Knoten** am Modell trägt.
     *
     * @return array<string,ResolvedSetting>
     */
    public function forNode(Node $node): array
    {
        $name = $this->rendererNameAt($this->recordsOf($node->id), []);

        return $name === null
            ? []
            : ['renderer' => new ResolvedSetting('renderer', TypedValue::ofText($name), $node->id, true)];
    }

    /**
     * Die Angaben, die diese **Verwendungsstelle** am Modell trägt.
     *
     * ⚠️ *Der Datensatz gehört dem **Besitzer** der Kante, und die Adresse nennt die Kante — kein
     * Datensatz an der Kante. Der Eigentümer, als ich einen erfinden wollte: «wir haben alle Mittel,
     * einer Kanten-Knoten-Kombination in jeglicher Schachtelung Daten zuzuweisen».*
     *
     * @return array<string,ResolvedSetting>
     */
    public function forUseSite(Relation $edge): array
    {
        $name = $this->rendererNameAt($this->recordsOf($edge->fromId), [$edge->id]);

        return $name === null
            ? []
            : ['renderer' => new ResolvedSetting('renderer', TypedValue::ofText($name), $edge->id, true)];
    }

    /**
     * Der Name des Renderers unter dieser Adresse, oder `null`.
     *
     * @param list<int> $vorlauf Kanten vor der Renderer-Kante — leer für den Knoten selbst.
     */
    private function rendererNameAt(array $recordIds, array $vorlauf): ?string
    {
        $this->findEdges();

        if ($this->rendererEdge === null || $this->renderEdge === null || $recordIds === []) {
            return null;
        }

        $pfad = implode('.', [...$vorlauf, $this->rendererEdge]);

        foreach ($recordIds as $recordId) {
            foreach ($this->records->valuesOf($recordId) as $wert) {
                if ($wert->path !== $pfad || $wert->value->reference === null) {
                    continue;
                }

                // ⚠️ *Eine Stufe tiefer: der Teil trägt das Feld `render`, und dessen Verweis ist ein
                // **Knoten** unter `Renderer` — sein Name ist der Renderer ([D-511](../../../docs/NewConcept/90-decision-log.md)).*
                foreach ($this->records->valuesOf($wert->value->reference) as $imTeil) {
                    if ($imTeil->edgeId !== $this->renderEdge || $imTeil->value->reference === null) {
                        continue;
                    }

                    $knoten = $this->nodes->find($imTeil->value->reference);

                    if ($knoten !== null) {
                        return $knoten->name;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Der **Vorgabewert**, den das Modell für dieses Feld an diesem Knoten nennt.
     *
     * ⚠️ **Nur aus Datensätzen der Art `default`** ([D-524](../../../docs/NewConcept/90-decision-log.md)).
     * *Ein Benutzerdatensatz an derselben Adresse wäre ein Wert und keine Vorgabe — und
     * [D-026](../../../docs/NewConcept/90-decision-log.md) sagt es scharf: «at model level there are
     * no values, only defaults».*
     *
     * ⚠️ *Die Adresse ist der Pfad der Kante am Datensatz des **Knotens** — genau die Form, die
     * `settings.path` schon benutzte: 20 Exponenten lagen dort unter der Id des Feldes
     * `Prefixes.exponent`.*
     */
    public function defaultFor(Node $node, Relation $edge): ?TypedValue
    {
        $pfad = (string) $edge->id;

        foreach ($this->records->ofNode($node->id) as $record) {
            if ($record->kind !== RecordKind::Default) {
                continue;
            }

            foreach ($this->records->valuesOf($record->id) as $wert) {
                if ($wert->path === $pfad && ! $wert->value->isNothing()) {
                    return $wert->value;
                }
            }
        }

        return null;
    }

    /** @return list<int> */
    private function recordsOf(int $nodeId): array
    {
        $ids = [];

        foreach ($this->records->ofNode($nodeId) as $record) {
            $ids[] = $record->id;
        }

        return $ids;
    }

    /**
     * Die zwei Kanten finden — über die **Feldnamen** ab der Wurzel, nicht über Knotennamen.
     *
     * ⚠️ **Der Unterschied ist wichtig:** *die Saat findet rund 80 Knoten über ihren Namen, und das
     * steht als [Zeile 80](../../../docs/NewConcept/97-implementation-plan.md#the-working-list) auf
     * der Liste, weil ein umbenannter Knoten die Saat blind macht. **Ein Feldname ist etwas anderes:**
     * er ist die Angabe selbst — «das Feld `renderer` der Wurzel» —, und ihn umzubenennen heisst, eine
     * andere Angabe zu meinen.*
     *
     * ⚠️ *Genau **einmal** gesucht, weil sonst jede gezeichnete Zeile zwei Abfragen kostete (`CD-7`).
     * Fehlt eine der beiden Kanten, antwortet diese Klasse «nichts» und der alte Weg trägt weiter —
     * **kein Absturz, solange der Umzug läuft**.*
     */
    private function findEdges(): void
    {
        if ($this->gesucht) {
            return;
        }

        $this->gesucht = true;

        $wurzel = $this->framework->root();
        $ziel   = null;

        foreach ($this->relations->fieldEdgesOf([$wurzel->id]) as $edge) {
            if ($edge->name === 'renderer') {
                $this->rendererEdge = $edge->id;
                $ziel               = $edge->toId;
            }
        }

        if ($ziel === null) {
            return;
        }

        $traeger = $this->nodes->find($ziel);

        if ($traeger === null) {
            return;
        }

        foreach ($this->relations->fieldEdgesOf([...$traeger->ancestorIds(), $traeger->id]) as $edge) {
            if ($edge->name === 'render') {
                $this->renderEdge = $edge->id;
            }
        }
    }
}
