<?php declare(strict_types=1);

namespace Taxmod\Core\Service;

use Taxmod\Core\Converter\ConverterRegistry;
use Taxmod\Core\Exception\SettingDoesNotApply;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\NodeClass\AttributeDeclaration;
use Taxmod\Core\Model\NodeClass\AttributeType;
use Taxmod\Core\Model\NodeClass\Contracts;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\Setting\SettingsObject;
use Taxmod\Core\Model\Setting\SettingsValue;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Renderer\RendererRegistry;
use Taxmod\Core\Repository\Changelog;
use Taxmod\Core\Repository\NodeRepository;
use Taxmod\Core\Repository\SettingsRepository;

/**
 * **Schreiben in das Einstellungsmodell** — Schritt 5 des Bauplans: eine Zeile je gesetztem Wert, ein
 * Objekt je komplexem Wert, und an der Kante eine Zeile mit `kante_id` (Anforderung 4.5, 5.2, 5.4).
 *
 * ```mermaid
 * flowchart LR
 *   M["Maske · attribut = 'text'"] --> E["SettingsEditor · Vertrag sagt den Typ"]
 *   E -->|"einfach"| Z["settings_value · Zeile"]
 *   E -->|"Objekt"| O["settings_object + Zeile, die es nennt"]
 *   E -->|"an der Kante"| K["dieselbe Zeile, mit kante_id"]
 * ```
 *
 * ⚠️ **Nur Gesetztes wird gespeichert** (4.5.1): *ein Wert, der der Auflösung schon entspricht und
 * keine eigene Zeile hat, schreibt keine — sonst hielte eine Kopie der Vorgabe jede spätere
 * Vorgabe fern. Ein leerer Wert nimmt die Zeile heraus: Löschen ist Wandern (4.6).*
 *
 * ⚠️ **Die Adresse kommt aus dem Vertrag** (3.3): *die Maske schickt `max`, der Vertrag sagt
 * `IntType.max` — bei einem Objektattribut die Klasse des gewählten Objekts. Ein Name, den der
 * Vertrag nicht kennt, wird abgewiesen: {@see SettingDoesNotApply}.*
 *
 * @see docs/einstellungen-anforderungen.md
 */
final class SettingsEditor
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly NodeRepository $nodes,
        private readonly SettingsResolver $resolver,
        private readonly RendererRegistry $renderers,
        private readonly ?ConverterRegistry $converters = null,
        private readonly ?Changelog $changelog = null,
        /** ⚠️ *Damit «validator = range» einen Namen findet (Zeile 8, D-760) — wie `renderers` und `converters`.* */
        private readonly ?\Taxmod\Core\Validator\ValidatorRegistry $validators = null,
    ) {
    }

    /** Ob der Vertrag dieses Attribut an diesem Knoten kennt — am Knoten selbst oder am gewählten Renderer. */
    public function knows(Node $node, string $attribut, ?Relation $edge = null): bool
    {
        try {
            $this->declarationOf($node, $attribut, $edge);

            return true;
        } catch (SettingDoesNotApply) {
            return false;
        }
    }

    /**
     * Einen Wert setzen, wie die Maske ihn schickt — als Zeichen — am Knoten oder an dieser Kante.
     *
     * @return bool Ob etwas geschrieben wurde.
     */
    public function put(
        Node $node,
        string $attribut,
        string $characters,
        ?Relation $edge = null,
        /**
         * ⚠️ *Hier festhalten, auch wenn der Wert dem geerbten gleicht — der Haken «override» an einer gesperrten Zeile (D-798). Sein
         * Befund: «override renderer does not save». Ohne ihn blieb `compact` geerbt, und die Einstellungen darunter liessen sich an
         * der Kante nie ändern.*
         */
        bool $holdHere = false,
    ): bool {
        $erklaert = $this->declarationOf($node, $attribut, $edge);
        $wert     = $this->parse($erklaert, $characters);

        if ($erklaert->type === AttributeType::Object) {
            return $this->putObject($node, $erklaert, $characters, $edge, $holdHere);
        }

        $traeger = $this->carrierOf($node, $erklaert, $edge);
        $rows    = $this->rowsAt($traeger, $erklaert, $edge);
        $eigene  = $rows[0] ?? null;

        if ($wert === null) {
            if ($eigene === null) {
                return false;
            }

            $this->settings->forgetValue($eigene->id);
            $this->resolver->forget();
            $this->note($node, $attribut, $edge, $eigene->value, null, $eigene->version);

            return true;
        }

        if ($eigene !== null) {
            if ($eigene->value->equals($wert)) {
                return false;
            }

            $this->settings->saveValue($eigene->withValue($wert), $eigene->version);
            $this->resolver->forget();
            $this->note($node, $attribut, $edge, $eigene->value, $wert, $eigene->version + 1);

            return true;
        }

        // ⚠️ *Was der Auflösung schon entspricht, bekommt keine Zeile (4.5.1) — es sei denn, es
        // steht an der Kante, wo eine eigene Zeile das Erbe bewusst festhält.*
        $geltend = $this->resolved($node, $edge)[$attribut] ?? null;

        if ($edge === null && $geltend !== null && $geltend->value->equals($wert)) {
            return false;
        }

        $neu = $this->settings->addValue($traeger['objectId'] !== null
            ? SettingsValue::inObject($traeger['objectId'], $erklaert->declaredBy, $attribut, $wert, $edge?->id)
            : SettingsValue::atNode($node->id, $erklaert->declaredBy, $attribut, $wert, $edge?->id));
        $this->resolver->forget();
        $this->note($node, $attribut, $edge, null, $wert, $neu->version);

        return true;
    }

    /**
     * Ein Glied einer Liste schalten oder umstellen (Schritt 6 des Bauplans, Z3/Z3a).
     *
     * Am Knoten trifft es die eigene Zeile. An der Kante trifft es die Zeile mit Kante — und ist das
     * Glied dort nur geerbt, entsteht eine Zeile mit Kante, die dasselbe Glied nennt und nur sagt, ob es
     * an ist und wo es steht. *Sein Wort: «haken raus nicht mehr aktiv» — abschalten ist nicht löschen.*
     *
     * @return bool Ob etwas geschrieben wurde.
     */
    /**
     * Die Mitglieder einer Verweisliste auf einmal setzen — eine Kaskade von Schaltern, je Kandidat einer
     * ([D-732](../../../docs/NewConcept/90-decision-log.md), sein Wort: «eine schalter kaskade netter … als jedes
     * einzeln aus der liste auszuwählen»).
     *
     * *Gewünscht und nicht da: ein neues Glied. Da und nicht gewünscht: das Glied wird «nicht aktiv», nie gelöscht
     * (Z3a). Da, nicht aktiv, gewünscht: wieder «aktiv».*
     *
     * @param  list<int> $wanted Die Knoten, die an sein sollen.
     * @return int Wie viele Glieder sich geändert haben.
     */
    /**
     * Nach einem Klassenwechsel: jede Zeile am Knoten, deren Adresse `Klasse.Attribut` der neue Vertrag nicht erklärt,
     * fällt — *sein Wort: «die einstellungen die nicht übereinstimmen gehen dabei verloren»* ([D-733](../../../docs/NewConcept/90-decision-log.md)).
     * Ein Objekt, das nur diese Zeile hielt, fällt mit.
     *
     * @return int Wie viele Zeilen gefallen sind.
     */
    public function dropWhatDoesNotApply(Node $node): int
    {
        $vertrag = Contracts::of($node->klasse);
        $weg     = 0;

        foreach ($this->settings->valuesOfNodes([$node->id])[$node->id] ?? [] as $row) {
            $erklaert = $vertrag->attribute($row->attribut);

            if ($erklaert !== null && $erklaert->declaredBy === $row->klasse) {
                continue;
            }

            $this->settings->forgetValue($row->id);

            if ($row->valueObjectId !== null) {
                $this->settings->forgetObject($row->valueObjectId);
            }

            $this->note($node, $row->attribut, null, $row->valueObjectId === null ? $row->value : null, null, $row->version);
            $weg++;
        }

        if ($weg > 0) {
            $this->resolver->forget();
        }

        return $weg;
    }

    public function setMembers(Node $node, string $attribut, array $wanted, ?Relation $edge = null): int
    {
        $erklaert = $this->declarationOf($node, $attribut, $edge);

        // ⚠️ *Knoten oder Felder — eine Liste von Verweisen, mit Haken gewählt (D-732, D-752).*
        if (! $erklaert->list || ! in_array($erklaert->type, [AttributeType::NodeRef, AttributeType::RelationRef], true)) {
            throw SettingDoesNotApply::named($attribut . ' is no list of references');
        }

        $wanted     = array_values(array_unique(array_map(intval(...), $wanted)));
        $vorhanden  = [];
        $geaendert  = 0;

        foreach ($this->resolver->listOf($node, $attribut, $edge) as $glied) {
            if ($glied->reference !== null) {
                $vorhanden[$glied->reference] = $glied;
            }
        }

        foreach ($vorhanden as $verweis => $glied) {
            $soll = in_array($verweis, $wanted, true);

            if ($glied->aktiv !== $soll && $this->setListEntry($node, $attribut, $glied->rowId, $soll, null, $edge)) {
                $geaendert++;
            }
        }

        foreach ($wanted as $verweis) {
            if (isset($vorhanden[$verweis])) {
                continue;
            }

            $wert = $erklaert->type === AttributeType::RelationRef ? TypedValue::ofRelationReference($verweis) : TypedValue::ofReference($verweis);
            $neu  = $this->settings->addValue(SettingsValue::atNode($node->id, $erklaert->declaredBy, $erklaert->name, $wert, $edge?->id));
            $this->resolver->forget();
            $this->note($node, $attribut, $edge, null, $neu->value, $neu->version);
            $geaendert++;
        }

        return $geaendert;
    }

    public function setListEntry(Node $node, string $attribut, int $rowId, ?bool $aktiv, ?int $position, ?Relation $edge = null): bool
    {
        $erklaert = $this->declarationOf($node, $attribut, $edge);

        if (! $erklaert->list) {
            throw SettingDoesNotApply::named($attribut . ' is no list');
        }

        $zeile = $this->settings->findValue($rowId);

        if ($zeile === null || $zeile->nodeId !== $node->id || $zeile->attribut !== $erklaert->name || $zeile->klasse !== $erklaert->declaredBy) {
            throw SettingDoesNotApply::named($attribut . ' #' . $rowId);
        }

        if ($zeile->relationId !== $edge?->id) {
            if ($edge === null || $zeile->relationId !== null) {
                throw SettingDoesNotApply::named($attribut . ' #' . $rowId . ' belongs to another edge');
            }

            $vorhanden = null;

            foreach ($this->rowsAt(['nodeId' => $node->id, 'objectId' => null], $erklaert, $edge) as $row) {
                if ($this->sameEntry($row, $zeile)) {
                    $vorhanden = $row;
                }
            }

            if ($vorhanden === null) {
                $neu = $zeile->valueObjectId !== null
                    ? SettingsValue::objectAtNode($node->id, $erklaert->declaredBy, $erklaert->name, $zeile->valueObjectId, $edge->id, $position ?? $zeile->position, $aktiv ?? $zeile->aktiv)
                    : SettingsValue::atNode($node->id, $erklaert->declaredBy, $erklaert->name, $zeile->value, $edge->id, $position ?? $zeile->position, $aktiv ?? $zeile->aktiv);

                if ($neu->aktiv === $zeile->aktiv && $neu->position === $zeile->position) {
                    return false;
                }

                $gespeichert = $this->settings->addValue($neu);
                $this->resolver->forget();
                $this->note($node, $attribut, $edge, $this->stateWord($zeile), $this->stateWord($neu), $gespeichert->version);

                return true;
            }

            $zeile = $vorhanden;
        }

        $neu = $zeile;

        if ($aktiv !== null) {
            $neu = $neu->withAktiv($aktiv);
        }

        if ($position !== null) {
            $neu = $neu->movedTo($position);
        }

        if ($neu->aktiv === $zeile->aktiv && $neu->position === $zeile->position) {
            return false;
        }

        $this->settings->saveValue($neu, $zeile->version);
        $this->resolver->forget();
        $this->note($node, $attribut, $edge, $this->stateWord($zeile), $this->stateWord($neu), $zeile->version + 1);

        return true;
    }

    /** Zwei Zeilen nennen dasselbe Glied: dasselbe Objekt, oder denselben Wert. */
    private function sameEntry(SettingsValue $a, SettingsValue $b): bool
    {
        return $a->valueObjectId !== null
            ? $a->valueObjectId === $b->valueObjectId
            : $b->valueObjectId === null && $a->value->equals($b->value);
    }

    /** Schalter und Stelle eines Glieds, als Wort für das Buch. */
    private function stateWord(SettingsValue $row): TypedValue
    {
        return TypedValue::ofText(($row->aktiv ? 'on' : 'off') . ' @' . $row->position);
    }

    /**
     * Ein Objektattribut setzen — die Maske schickt den Namen der Wertklasse (`compact`), leer
     * heisst «keines mehr».
     *
     * ⚠️ *Am Knoten ersetzt die Wahl das erste Glied der Liste. An der Kante wird das geerbte Glied
     * abgeschaltet und ein eigenes davorgesetzt (5.5.1, 5.5.3) — die Zeilen des Knotens bleiben.*
     */
    private function putObject(Node $node, AttributeDeclaration $erklaert, string $name, ?Relation $edge, bool $holdHere = false): bool
    {
        $name   = trim($name);
        $klasse = $name === '' ? null : $this->classOf($erklaert, $name);

        if ($name !== '' && $klasse === null) {
            throw SettingDoesNotApply::named($erklaert->name . ' = ' . $name);
        }

        $amKnoten = $this->rowsAt(['nodeId' => $node->id, 'objectId' => null], $erklaert, null);
        $anKante  = $edge === null ? [] : $this->rowsAt(['nodeId' => $node->id, 'objectId' => null], $erklaert, $edge);
        $erstes   = $edge === null ? ($amKnoten[0] ?? null) : ($anKante[0] ?? null);

        // ⚠️ **Was schon gilt, wird nicht noch einmal gesetzt** (Schritt 6 des Bauplans). *Die Maske schickt den
        // Wähler mit jedem Speichern; stünde hier ein Ersetzen, wischte es an der Kante die Schalter und Stellen
        // der Glieder weg — gemessen am 2026-09-11: zwei Zeilen fort, zwei neue, das umgeordnete Glied wieder hinten.*
        // ⚠️ *Ausser an der Kante mit dem Haken «override»: dann ist «dasselbe wie geerbt» eine bewusste eigene Zeile, auf der die
        // Einstellungen des Renderers danach für diese Stelle geändert werden (D-798). Steht die Zeile schon hier, bleibt es beim Nichtstun.*
        $geltend = $this->resolved($node, $edge)[$erklaert->name] ?? null;

        if ($klasse !== null && ($geltend?->value->text ?? null) === $name && ! ($holdHere && $edge !== null && $anKante === [])) {
            return false;
        }

        if ($erstes !== null && $erstes->valueObjectId !== null && $klasse !== null
            && $this->settings->findObject($erstes->valueObjectId)?->klasse === $klasse) {
            return false;
        }

        if ($klasse === null) {
            if ($erstes === null) {
                return false;
            }

            $this->settings->forgetValue($erstes->id);

            if ($erstes->valueObjectId !== null && $erstes->relationId === null) {
                $this->settings->forgetObject($erstes->valueObjectId);
            }

            $this->resolver->forget();
            $this->note($node, $erklaert->name, $edge, TypedValue::ofText($name), null, $erstes->version);

            return true;
        }

        $objekt = $this->settings->addObject(SettingsObject::create($klasse));

        if ($edge === null) {
            // ⚠️ *Das erste Glied wird ersetzt: alte Zeile und altes Objekt wandern.*
            if ($erstes !== null) {
                $this->settings->forgetValue($erstes->id);

                if ($erstes->valueObjectId !== null) {
                    $this->settings->forgetObject($erstes->valueObjectId);
                }
            }

            $this->settings->addValue(SettingsValue::objectAtNode($node->id, $erklaert->declaredBy, $erklaert->name, $objekt->id, null, $erstes?->position ?? 1));
        } else {
            // ⚠️ *An der Kante: das geerbte erste Glied aus, das eigene davor (5.5.1, 5.5.3).*
            foreach ($anKante as $alt) {
                $this->settings->forgetValue($alt->id);

                if ($alt->valueObjectId !== null && ! $this->objectUsedAtNode($amKnoten, $alt->valueObjectId)) {
                    $this->settings->forgetObject($alt->valueObjectId);
                }
            }

            $geerbt = $amKnoten[0] ?? null;

            if ($geerbt !== null && $geerbt->valueObjectId !== null) {
                $this->settings->addValue(SettingsValue::objectAtNode($node->id, $erklaert->declaredBy, $erklaert->name, $geerbt->valueObjectId, $edge->id, max(1, $geerbt->position), false));
            }

            $this->settings->addValue(SettingsValue::objectAtNode($node->id, $erklaert->declaredBy, $erklaert->name, $objekt->id, $edge->id, 0));
        }

        $this->resolver->forget();
        $this->note($node, $erklaert->name, $edge, null, TypedValue::ofText($name), $objekt->version);

        return true;
    }

    /** @param list<SettingsValue> $amKnoten */
    private function objectUsedAtNode(array $amKnoten, int $objectId): bool
    {
        foreach ($amKnoten as $row) {
            if ($row->valueObjectId === $objectId) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, \Taxmod\Core\Model\ResolvedSetting> */
    private function resolved(Node $node, ?Relation $edge): array
    {
        return $edge === null ? $this->resolver->forNode($node) : $this->resolver->forUseSite($edge, $node);
    }

    /**
     * Die Zeilen eines Attributs an einem Träger, am Knoten (`$edge` null) oder an dieser Kante.
     *
     * @param  array{nodeId: ?int, objectId: ?int} $traeger
     * @return list<SettingsValue>
     */
    /**
     * Die Erklärung eines Attributs: aus dem Vertrag des Knotens — oder aus der Klasse eines gewählten
     * Objekts (des Renderers, des Umrechnungssatzes), denn dessen Attribute heissen wie dort (3.3).
     */
    private function declarationOf(Node $node, string $attribut, ?Relation $edge): AttributeDeclaration
    {
        $vertrag = Contracts::of($node->klasse);

        if (($erklaert = $vertrag->attribute($attribut)) !== null) {
            return $erklaert;
        }

        foreach ($this->resolver->chosenObjectClasses($node, $edge) as $klasse) {
            if (($erklaert = Contracts::ofValueClass($klasse)->attribute($attribut)) !== null) {
                return $erklaert;
            }
        }

        throw SettingDoesNotApply::named($attribut);
    }

    /**
     * Wer die Zeile trägt: der Knoten — oder das gewählte Objekt, dessen Klasse das Attribut erklärt.
     *
     * @return array{nodeId: ?int, objectId: ?int}
     */
    private function carrierOf(Node $node, AttributeDeclaration $erklaert, ?Relation $edge): array
    {
        if (Contracts::of($node->klasse)->attribute($erklaert->name) !== null) {
            return ['nodeId' => $node->id, 'objectId' => null];
        }

        foreach (Contracts::of($node->klasse)->attributes as $objektAttribut) {
            if ($objektAttribut->type !== AttributeType::Object) {
                continue;
            }

            $amKnoten = $this->rowsAt(['nodeId' => $node->id, 'objectId' => null], $objektAttribut, null);
            $anKante  = $edge === null ? [] : $this->rowsAt(['nodeId' => $node->id, 'objectId' => null], $objektAttribut, $edge);

            foreach ([...$anKante, ...$amKnoten] as $row) {
                if (! $row->aktiv || $row->valueObjectId === null) {
                    continue;
                }

                if ($this->settings->findObject($row->valueObjectId)?->klasse === $erklaert->declaredBy) {
                    return ['nodeId' => null, 'objectId' => $row->valueObjectId];
                }

                break;
            }
        }

        throw SettingDoesNotApply::named($erklaert->name);
    }

    private function rowsAt(array $traeger, AttributeDeclaration $erklaert, ?Relation $edge): array
    {
        $alle = $traeger['objectId'] !== null
            ? ($this->settings->valuesOfObjects([$traeger['objectId']])[$traeger['objectId']] ?? [])
            : ($this->settings->valuesOfNodes([$traeger['nodeId']])[$traeger['nodeId']] ?? []);

        $aus = [];

        foreach ($alle as $row) {
            if ($row->klasse === $erklaert->declaredBy && $row->attribut === $erklaert->name && $row->relationId === $edge?->id) {
                $aus[] = $row;
            }
        }

        usort($aus, static fn (SettingsValue $a, SettingsValue $b): int => [$a->position, $a->id] <=> [$b->position, $b->id]);

        return $aus;
    }

    /**
     * Zeichen aus der Maske → Wert des Typs, den der Vertrag nennt (3.1). Leer heisst «nichts».
     *
     * @throws SettingDoesNotApply bei einem Enum-Fall, den es nicht gibt, oder einem Verweis auf nichts
     */
    private function parse(AttributeDeclaration $erklaert, string $characters): ?TypedValue
    {
        $characters = trim($characters);

        if ($characters === '' && $erklaert->type !== AttributeType::Bool) {
            return null;
        }

        return match ($erklaert->type) {
            AttributeType::Bool    => TypedValue::ofBool($characters === '1' || $characters === 'true' || $characters === 'on'),
            AttributeType::Int     => preg_match('/^-?\d+$/', $characters) === 1 ? TypedValue::ofInt((int) $characters) : throw SettingDoesNotApply::named($erklaert->name . ' = ' . $characters),
            AttributeType::Decimal => preg_match('/^-?\d+(\.\d+)?$/', $characters) === 1 ? TypedValue::ofDecimal($characters) : throw SettingDoesNotApply::named($erklaert->name . ' = ' . $characters),
            AttributeType::Text    => TypedValue::ofText($characters),
            // ⚠️ *Ein Datum kommt in jeder Genauigkeit der Werte an (D-737) und wird wie eines gelesen; abgelegt wird der Zeitstempel als Text.*
            AttributeType::Date    => TypedValue::ofText((string) (new \Taxmod\Core\Model\Type\DateTimeType())->valueFrom($characters)->date),
            AttributeType::Enum    => in_array($characters, $erklaert->enumCases(), true) ? TypedValue::ofText($characters) : throw SettingDoesNotApply::named($erklaert->name . ' = ' . $characters),
            AttributeType::NodeRef => TypedValue::ofReference($this->nodeNamed($erklaert, $characters)),
            AttributeType::RelationRef => ctype_digit($characters) ? TypedValue::ofRelationReference((int) $characters) : throw SettingDoesNotApply::named($erklaert->name . ' = ' . $characters),
            AttributeType::Object  => null,
        };
    }

    /** Der Knoten der Verweisklasse mit diesem Namen — die Maske schickt Worte (D-105). */
    private function nodeNamed(AttributeDeclaration $erklaert, string $name): int
    {
        if (ctype_digit($name) && $this->nodes->find((int) $name)?->klasse === $erklaert->refersTo) {
            return (int) $name;
        }

        foreach ($erklaert->refersTo === null ? [] : $this->nodes->ofClass($erklaert->refersTo) as $knoten) {
            if ($knoten->name === $name) {
                return $knoten->id;
            }
        }

        throw SettingDoesNotApply::named($erklaert->name . ' → ' . $name);
    }

    /** @return class-string|null */
    private function classOf(AttributeDeclaration $erklaert, string $name): ?string
    {
        if ($erklaert->objectClass === \Taxmod\Core\Renderer\Renderer::class) {
            return $this->renderers->classFor($name);
        }

        if ($erklaert->objectClass === \Taxmod\Core\Converter\Converter::class) {
            return $this->converters?->classFor($name);
        }

        if ($erklaert->objectClass === \Taxmod\Core\Validator\Validator::class) {
            return $this->validators?->knows($name) === true ? $this->validators->classFor($name) : null;
        }

        // ⚠️ *Eine feste Wertklasse — der Umrechnungssatz — hat keine Registratur: ihr Name ist ihr Kurzname.*
        if (! interface_exists($erklaert->objectClass) && class_exists($erklaert->objectClass) && strcasecmp($name, Contracts::shortName($erklaert->objectClass)) === 0) {
            return $erklaert->objectClass;
        }

        return null;
    }

    /** Ins Buch, mit der Version der Zeile, die der Akt erzeugt hat (D-536). */
    private function note(Node $node, string $attribut, ?Relation $edge, ?TypedValue $vorher, ?TypedValue $nachher, ?int $version = null): void
    {
        $this->changelog?->record(
            $edge?->id ?? $node->id,
            $edge === null ? 'node' : 'relation',
            'setting ' . $attribut,
            $vorher?->rawValue(),
            $nachher?->rawValue(),
            $version
        );
    }
}
