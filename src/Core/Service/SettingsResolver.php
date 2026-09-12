<?php declare(strict_types=1);

namespace Taxmod\Core\Service;

use Taxmod\Core\Converter\ConverterRegistry;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\NodeClass\AttributeDeclaration;
use Taxmod\Core\Model\NodeClass\AttributeType;
use Taxmod\Core\Model\NodeClass\Contract;
use Taxmod\Core\Model\NodeClass\Contracts;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\ResolvedSetting;
use Taxmod\Core\Model\Setting\ListEntry;
use Taxmod\Core\Model\Setting\SettingsValue;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Renderer\RendererRegistry;
use Taxmod\Core\Repository\NodeRepository;
use Taxmod\Core\Repository\SettingsRepository;
use Taxmod\Core\Validator\ValidatorRegistry;

/**
 * **Die Auflösung: Kante → Knoten → Vertrag** (Anforderung 5.7) — aus `settings_value`, nie aus einer
 * Einstellungskante.
 *
 * ```mermaid
 * flowchart LR
 *   K["Kante · Zeilen mit kante_id"] -->|"nichts?"| N["Knoten · Zeilen ohne kante_id"]
 *   N -->|"nichts?"| V["Vertrag · Vorgabe der Klasse"]
 *   V --> R["array‹name, ResolvedSetting›"]
 * ```
 *
 * ⚠️ **Sein Wort** ([D-712](../../../docs/NewConcept/90-decision-log.md)): *«vererbung von
 * knoten-settings in knoten ist grundsätzlich raus … jeder knoten hat eine klasse, klasse liefert
 * settings.»* Es gibt keine Stufe «Vater» mehr. Und zur Kante: *«ein knoten-datensatz, der zusätzlich
 * zum knoten noch die kante bekommt»* — eine Zeile mit `kante_id` überschreibt die ohne.
 *
 * ⚠️ **Was herauskommt, ist dieselbe Form wie bisher** — `array<string, ResolvedSetting>` nach
 * Attributname —, damit kein Renderer sich ändert: jeder liest weiter `RenderContext::setting()`.
 * *Der gewählte Renderer erscheint als sein Name unter `renderer`, seine eigenen Attribute
 * (`orientation`, `with_label`, …) liegen daneben, wie es {@see ModelValues::forChosenRenderer()}
 * vorher tat. Ein Knotenverweis wird zum Namen des Knotens ([D-105](../../../docs/NewConcept/90-decision-log.md)).*
 *
 * ⚠️ **Eine Liste zeichnet ihr erstes aktives Glied** (`INFERRED`): *der Vertrag erlaubt mehrere
 * Renderer, Konverter, Validatoren (3.6.1); was heute gezeichnet wird, ist einer. Der erste in der
 * Reihenfolge ist der gewählte — bis eine Zusage sagt, was die übrigen tun.*
 *
 * @see docs/einstellungen-anforderungen.md
 */
final class SettingsResolver
{
    /** @var array<int, array<string, ResolvedSetting>> Je Knoten, einmal je Anfrage. */
    private array $held = [];

    /** @var array<int, list<SettingsValue>> Zeilen je Knoten, vorgeladen (CD-7). */
    private array $rows = [];

    /** @var array<int, list<SettingsValue>> Zeilen je Objekt, vorgeladen. */
    private array $objectRows = [];

    /** @var array<int, \Taxmod\Core\Model\Setting\SettingsObject> */
    private array $objects = [];

    /** @var array<string, string> Klassenname → Name in der Registratur. */
    private array $names = [];

    /** @var array<int, Node> die Knoten, die schon durch die Hand gingen — kein `find` je Kante */
    private array $known = [];

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly NodeRepository $nodes,
        private readonly RendererRegistry $renderers,
        private readonly ?ConverterRegistry $converters = null,
        private readonly ?ValidatorRegistry $validators = null,
        /** ⚠️ *Nur für das Wort eines Feldverweises (D-752) — wer keinen mitgibt, sieht die Nummer.* */
        private readonly ?\Taxmod\Core\Repository\RelationRepository $relations = null,
    ) {
    }

    /**
     * Alles auf einmal laden, was ein Bildschirm braucht — eine Abfrage je Tabelle statt einer je
     * Zeile (`CD-7`).
     *
     * @param list<Node> $nodes
     */
    public function preload(array $nodes): void
    {
        $ids = [];

        foreach ($nodes as $node) {
            $this->known[$node->id] = $node;

            if (! isset($this->rows[$node->id])) {
                $ids[] = $node->id;
            }
        }

        if ($ids === []) {
            return;
        }

        foreach ($this->settings->valuesOfNodes($ids) as $nodeId => $rows) {
            $this->rows[$nodeId] = $rows;
        }

        $this->loadObjectsBehind(array_merge(...array_values(array_map(fn (int $id): array => $this->rows[$id] ?? [], $ids))));
    }

    /**
     * Die Einstellungen eines Knotens, aufgelöst: Knoten → Vertrag.
     *
     * @return array<string, ResolvedSetting>
     */
    public function forNode(Node $node): array
    {
        if (isset($this->held[$node->id])) {
            return $this->held[$node->id];
        }

        $this->preload([$node]);

        return $this->held[$node->id] = $this->resolve($node, null);
    }

    /**
     * Die Zielknoten mehrerer Kanten in einem Zug holen und ihre Zeilen laden — ein Formular mit
     * vierzehn Feldern kostet dieselbe Zahl Abfragen wie eines mit sieben (`CD-7`).
     *
     * @param list<Relation> $relations
     */
    public function preloadUseSites(array $relations): void
    {
        $fehlend = [];

        foreach ($relations as $relation) {
            if (! isset($this->known[$relation->toNodeId])) {
                $fehlend[$relation->toNodeId] = $relation->toNodeId;
            }
        }

        $this->preload($fehlend === [] ? [] : $this->nodes->byIds(array_values($fehlend)));
    }

    /**
     * Die Einstellungen an einer Verwendungsstelle: Kante → Zielknoten → Vertrag.
     *
     * @return array<string, ResolvedSetting>
     */
    public function forUseSite(Relation $relation, ?Node $target = null): array
    {
        $target ??= $this->known[$relation->toNodeId] ?? $this->nodes->find($relation->toNodeId);

        if ($target === null) {
            return [];
        }

        $this->preload([$target]);

        return $this->resolve($target, $relation);
    }

    /**
     * Was ein Knoten **selbst** gesetzt hat — nur die Zeilen ohne Kante, ohne Vorgabe.
     *
     * @return array<string, ResolvedSetting>
     */
    public function ownOf(Node $node): array
    {
        $this->preload([$node]);

        $aus = [];

        foreach ($this->rows[$node->id] ?? [] as $row) {
            if ($row->relationId === null && ! $row->holdsAnObject()) {
                $aus[$row->attribut] = new ResolvedSetting($row->attribut, $this->asWord($row->value), $node->id, true);
            }
        }

        return $aus;
    }

    /**
     * Die Glieder einer Listeneinstellung, wie die Maske sie zeigt — **auch die abgeschalteten**, mit
     * Schalter und Stelle. An der Kante liegen die Zeilen des Knotens darunter; eine Zeile mit Kante,
     * die dasselbe Glied nennt, sagt dort, ob es an ist und wo es steht (Z3, Z3a).
     *
     * @return list<ListEntry>
     */
    public function listOf(Node $node, string $attribut, ?Relation $edge = null): array
    {
        $erklaert = Contracts::of($node->klasse)->attribute($attribut);

        if ($erklaert === null || ! $erklaert->list) {
            return [];
        }

        $this->preload([$node]);

        $rows     = $this->rows[$node->id] ?? [];
        $amKnoten = $this->rowsFor($rows, $erklaert, null);
        $anKante  = $edge === null ? [] : $this->rowsFor($rows, $erklaert, $edge->id);
        $ueberlagert = [];

        foreach ($anKante as $row) {
            $ueberlagert[$this->identityOf($row)] = $row;
        }

        $aus = [];

        foreach ($amKnoten as $row) {
            $schluessel = $this->identityOf($row);
            $oben       = $ueberlagert[$schluessel] ?? null;
            unset($ueberlagert[$schluessel]);
            $aus[] = new ListEntry(
                (int) ($oben?->id ?? $row->id),
                $this->wordOf($row),
                $oben?->aktiv ?? $row->aktiv,
                $oben?->position ?? $row->position,
                $edge === null || $oben !== null,
                $row->value->reference
            );
        }

        foreach ($ueberlagert as $row) {
            $aus[] = new ListEntry((int) $row->id, $this->wordOf($row), $row->aktiv, $row->position, true, $row->value->reference);
        }

        usort($aus, static fn (ListEntry $a, ListEntry $b): int => [$a->position, $a->rowId] <=> [$b->position, $b->rowId]);

        return $aus;
    }

    /** Das Wort eines Glieds: der Name des Objekts in der Registratur, der Knotenname, sonst der Wert selbst. */
    private function wordOf(SettingsValue $row): string
    {
        if ($row->valueObjectId !== null) {
            $objekt = $this->objects[$row->valueObjectId] ?? null;

            return $objekt === null ? '' : ($this->nameOf($objekt->klasse) ?? Contracts::shortName($objekt->klasse));
        }

        return (string) $this->asWord($row->value)->rawValue();
    }

    /**
     * Die gewählten Objekte eines Knotens je Objektattribut — die Klasse des ersten aktiven Glieds:
     * `renderer` → ein Renderer, `umrechnung` → der Umrechnungssatz. An der Kante gilt deren Wahl.
     *
     * @return array<string, class-string>
     */
    public function chosenObjectClasses(Node $node, ?Relation $edge = null): array
    {
        $this->preload([$node]);

        $rows = $this->rows[$node->id] ?? [];
        $aus  = [];

        foreach (Contracts::of($node->klasse)->attributes as $name => $erklaert) {
            if ($erklaert->type !== AttributeType::Object) {
                continue;
            }

            $amKnoten  = $this->rowsFor($rows, $erklaert, null);
            $anKante   = $edge === null ? [] : $this->rowsFor($rows, $erklaert, $edge->id);
            $eintraege = $erklaert->list ? $this->listEntries($amKnoten, $anKante) : [$anKante[0] ?? $amKnoten[0] ?? null];
            $erstes    = $eintraege[0] ?? null;
            $objekt    = $erstes?->valueObjectId === null ? null : ($this->objects[$erstes->valueObjectId] ?? null);

            if ($objekt !== null && class_exists($objekt->klasse)) {
                $aus[$name] = $objekt->klasse;
            }
        }

        return $aus;
    }

    /** @return array<string, ResolvedSetting> */
    private function resolve(Node $node, ?Relation $edge): array
    {
        $vertrag = Contracts::of($node->klasse);
        $rows    = $this->rows[$node->id] ?? [];
        $aus     = [];

        foreach ($vertrag->attributes as $name => $erklaert) {
            $amKnoten = $this->rowsFor($rows, $erklaert, null);
            $anKante  = $edge === null ? [] : $this->rowsFor($rows, $erklaert, $edge->id);

            if ($erklaert->type === AttributeType::Object) {
                $aus = [...$aus, ...$this->resolveObject($erklaert, $amKnoten, $anKante, $node, $edge)];

                continue;
            }

            if ($erklaert->list) {
                // ⚠️ *Eine Liste einfacher Werte — heute nur `erlaubte_praefixe`. Sie wird als
                // Mehrfachverweis gereicht: der erste Eintrag unter dem Namen, alle unter `name[]`.*
                $eintraege = $this->listEntries($amKnoten, $anKante);

                if ($eintraege !== []) {
                    $aus[$name] = new ResolvedSetting($name, $this->asWord($eintraege[0]->value), $this->ownerOf($eintraege[0], $node, $edge), $eintraege[0]->relationId !== null || $edge === null);
                }

                continue;
            }

            $zeile = $anKante[0] ?? $amKnoten[0] ?? null;

            if ($zeile !== null) {
                $aus[$name] = new ResolvedSetting($name, $this->asWord($zeile->value), $this->ownerOf($zeile, $node, $edge), $edge === null ? $zeile->relationId === null : $zeile->relationId !== null);

                continue;
            }

            if ($erklaert->default !== null) {
                $aus[$name] = new ResolvedSetting($name, $this->asWord($erklaert->default), 0, false);
            }
        }

        // ⚠️ **Der Renderer ist nie leer** — sein Wort am 2026-09-11: *«renderer muss gesetzt sein darf nicht leer
        // sein».* *Wo niemand gewählt hat, gilt die Vorgabe: der Standard der Registratur für den Typ des Knotens,
        // das Formular für alles andere — als Vorgabe (kein Besitzer, nicht hier gesetzt), wie die Vorgaben des Vertrags.*
        if (! isset($aus['renderer']) && $vertrag->attribute('renderer') !== null) {
            $aus['renderer'] = new ResolvedSetting('renderer', TypedValue::ofText($this->defaultRendererName($node)), 0, false);
        }

        return $aus;
    }

    /** Der Renderer, der ohne Wahl zeichnet: der Typstandard, sonst das Formular. */
    private function defaultRendererName(Node $node): string
    {
        $typ = \Taxmod\Core\Model\Type\SpecialisedTypes::ofClass($node->klasse);

        return $typ === null ? \Taxmod\Core\Renderer\FormRenderer::NAME : $this->renderers->defaultFor($typ)->name();
    }

    /**
     * Ein Objektattribut: `renderer`, `converter`, `validator`, `umrechnung`.
     *
     * *Das erste aktive Glied ist das gewählte; sein Klassenname wird zum Namen in der Registratur
     * (`renderer` = `compact`), seine eigenen Zeilen kommen daneben — an der Kante überschrieben,
     * wo eine Zeile des Objekts die Kante nennt.*
     *
     * @param list<SettingsValue> $amKnoten
     * @param list<SettingsValue> $anKante
     * @return array<string, ResolvedSetting>
     */
    private function resolveObject(AttributeDeclaration $erklaert, array $amKnoten, array $anKante, Node $node, ?Relation $edge): array
    {
        $eintraege = $erklaert->list ? $this->listEntries($amKnoten, $anKante) : [$anKante[0] ?? $amKnoten[0] ?? null];
        $erstes    = $eintraege[0] ?? null;

        if ($erstes === null || $erstes->valueObjectId === null) {
            return [];
        }

        $objekt = $this->objects[$erstes->valueObjectId] ?? null;

        // ⚠️ *Ein Objekt, dessen Klasse mit einem Plugin verschwand, zeichnet nichts Eigenes — der
        // Typstandard gilt. Abgewiesen wird ein fremder Name beim Schreiben (D-360), nicht beim Lesen.*
        if ($objekt === null || ! class_exists($objekt->klasse)) {
            return [];
        }

        $aus  = [];
        $name = $this->nameOf($objekt->klasse) ?? Contracts::shortName($objekt->klasse);

        if ($name !== null) {
            $aus[$erklaert->name] = new ResolvedSetting($erklaert->name, TypedValue::ofText($name), $this->ownerOf($erstes, $node, $edge), $edge === null ? $erstes->relationId === null : $erstes->relationId !== null);
        }

        // ⚠️ *Die Attribute des Objekts, mit der Vorgabe seiner Klasse — und an der Kante
        // überschrieben, wo eine Zeile des Objekts die Kante nennt (5.4).*
        $innen = Contracts::ofValueClass($objekt->klasse);
        $rows  = $this->objectRows[$objekt->id] ?? [];

        foreach ($innen->attributes as $attribut => $erklaertInnen) {
            if ($erklaertInnen->type === AttributeType::Object) {
                continue;
            }

            $zeile = null;

            foreach ($rows as $row) {
                if ($row->attribut !== $attribut) {
                    continue;
                }

                if ($edge !== null && $row->relationId === $edge->id) {
                    $zeile = $row;

                    break;
                }

                if ($row->relationId === null) {
                    $zeile ??= $row;
                }
            }

            if ($zeile !== null) {
                $aus[$attribut] = new ResolvedSetting($attribut, $this->asWord($zeile->value), $zeile->relationId ?? $node->id, $edge === null ? $zeile->relationId === null : $zeile->relationId !== null);
            } elseif ($erklaertInnen->default !== null) {
                $aus[$attribut] = new ResolvedSetting($attribut, $this->asWord($erklaertInnen->default), 0, false);
            }
        }

        return $aus;
    }

    /**
     * Die Zeilen eines Attributs an einem Träger — am Knoten (`$edgeId` null) oder an dieser Kante.
     *
     * @param  list<SettingsValue> $rows
     * @return list<SettingsValue> in Reihenfolge der Stelle
     */
    private function rowsFor(array $rows, AttributeDeclaration $erklaert, ?int $edgeId): array
    {
        $aus = [];

        foreach ($rows as $row) {
            if ($row->klasse === $erklaert->declaredBy && $row->attribut === $erklaert->name && $row->relationId === $edgeId) {
                $aus[] = $row;
            }
        }

        usort($aus, static fn (SettingsValue $a, SettingsValue $b): int => [$a->position, $a->id] <=> [$b->position, $b->id]);

        return $aus;
    }

    /**
     * Die Liste, wie sie an der Kante gilt: die Glieder des Knotens, ergänzt um die der Kante, in
     * der Reihenfolge der Kante, ohne die dort abgeschalteten (5.5).
     *
     * @param  list<SettingsValue> $amKnoten
     * @param  list<SettingsValue> $anKante
     * @return list<SettingsValue>
     */
    private function listEntries(array $amKnoten, array $anKante): array
    {
        // ⚠️ *Ein am Knoten abgeschaltetes Glied zählt nicht — es sei denn, eine Zeile mit Kante nennt es
        // wieder an (Z3a: der Schalter der Kante gilt über dem des Knotens).*
        $amKnoten = array_values(array_filter($amKnoten, static fn (SettingsValue $row): bool => $row->aktiv));

        if ($anKante === []) {
            return $amKnoten;
        }

        // ⚠️ *Eine Kantenzeile zu einem geerbten Glied nennt dessen Objekt oder Wert und trägt
        // `aktiv` und `position`; eine Kantenzeile ohne Gegenstück am Knoten ist ein eigenes Glied.*
        $vomKnoten = [];

        foreach ($amKnoten as $row) {
            $vomKnoten[$this->identityOf($row)] = $row;
        }

        $aus = [];

        foreach ($anKante as $row) {
            $schluessel = $this->identityOf($row);

            if (isset($vomKnoten[$schluessel])) {
                if ($row->aktiv) {
                    $aus[] = $vomKnoten[$schluessel]->movedTo($row->position);
                }

                unset($vomKnoten[$schluessel]);

                continue;
            }

            if ($row->aktiv) {
                $aus[] = $row;
            }
        }

        $aus = [...array_values($vomKnoten), ...$aus];

        usort($aus, static fn (SettingsValue $a, SettingsValue $b): int => [$a->position, $a->id] <=> [$b->position, $b->id]);

        return $aus;
    }

    private function identityOf(SettingsValue $row): string
    {
        return $row->valueObjectId !== null ? 'o' . $row->valueObjectId : 'v' . $row->value->typeName() . ':' . $row->value->rawValue();
    }

    /** Wem eine Zeile «gehört» — der Kante, wenn sie eine nennt, sonst dem Knoten. */
    private function ownerOf(SettingsValue $row, Node $node, ?Relation $edge): int
    {
        return $row->relationId ?? $node->id;
    }

    /** Ein Knotenverweis wird zum Namen des Knotens — Renderer lesen Worte, keine Nummern (D-105, D-363). */
    private function asWord(TypedValue $value): TypedValue
    {
        if (! $value->isAReference() || $value->reference === null) {
            return $value;
        }

        // ⚠️ *Ein Feldverweis (D-752) heisst wie sein Feld; ohne Kantenspeicher bleibt die Nummer, gekennzeichnet.*
        if ($value->referenceSpace === \Taxmod\Core\Model\ReferenceSpace::Relation) {
            $feld = $this->relations?->byId($value->reference);

            return TypedValue::ofText($feld === null ? "#" . $value->reference : $feld->name);
        }

        $knoten = $this->nodes->find($value->reference);

        return $knoten === null ? $value : TypedValue::ofText($knoten->name);
    }

    /** @param list<SettingsValue> $rows */
    private function loadObjectsBehind(array $rows): void
    {
        $ids = [];

        foreach ($rows as $row) {
            if ($row->valueObjectId !== null && ! isset($this->objects[$row->valueObjectId])) {
                $ids[] = $row->valueObjectId;
            }
        }

        if ($ids === []) {
            return;
        }

        $ids = array_values(array_unique($ids));

        foreach ($this->settings->objectsByIds($ids) as $id => $objekt) {
            $this->objects[$id] = $objekt;
        }

        foreach ($this->settings->valuesOfObjects($ids) as $id => $objektZeilen) {
            $this->objectRows[$id] = $objektZeilen;
        }

        // ⚠️ *Ein Objekt darf ein Objekt halten (2c) — die nächste Stufe nachladen, bis nichts mehr kommt.*
        $this->loadObjectsBehind(array_merge(...array_values(array_map(fn (int $id): array => $this->objectRows[$id] ?? [], $ids))));
    }

    /** Der Name einer Wertklasse in ihrer Registratur — `CompactRenderer` → `compact`. */
    private function nameOf(string $class): ?string
    {
        if (array_key_exists($class, $this->names)) {
            return $this->names[$class];
        }

        foreach ($this->renderers->namesForNodes() as $name) {
            if ($this->renderers->classFor($name) === $class) {
                return $this->names[$class] = $name;
            }
        }

        foreach ($this->renderers->namesForSurfaces() as $name) {
            if ($this->renderers->classFor($name) === $class) {
                return $this->names[$class] = $name;
            }
        }

        foreach ($this->converters?->namesForNodes() ?? [] as $name) {
            if ($this->converters?->classFor($name) === $class) {
                return $this->names[$class] = $name;
            }
        }

        foreach ($this->validators?->names() ?? [] as $name) {
            if ($this->validators?->classFor($name) === $class) {
                return $this->names[$class] = $name;
            }
        }

        return $this->names[$class] = null;
    }

    /** Nur für Tests und Wächter: alles Gehaltene vergessen. */
    public function forget(): void
    {
        $this->held = $this->rows = $this->objectRows = $this->objects = $this->known = [];
    }

    /** Der Vertrag eines Knotens — die Maske zeichnet daraus, was es zu zeichnen gibt. */
    public function contractOf(Node $node): Contract
    {
        return Contracts::of($node->klasse);
    }
}
