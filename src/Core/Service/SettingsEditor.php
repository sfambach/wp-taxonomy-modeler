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
use Taxmod\Core\Model\SettingKey;
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
    public function put(Node $node, string $attribut, string $characters, ?Relation $edge = null): bool
    {
        $erklaert = $this->declarationOf($node, $attribut, $edge);
        $wert     = $this->parse($erklaert, $characters);

        if ($erklaert->type === AttributeType::Object) {
            return $this->putObject($node, $erklaert, $characters, $edge);
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
            $this->note($node, $attribut, $edge, $eigene->value, null);

            return true;
        }

        if ($eigene !== null) {
            if ($eigene->value->equals($wert)) {
                return false;
            }

            $this->settings->saveValue($eigene->withValue($wert), $eigene->version);
            $this->resolver->forget();
            $this->note($node, $attribut, $edge, $eigene->value, $wert);

            return true;
        }

        // ⚠️ *Was der Auflösung schon entspricht, bekommt keine Zeile (4.5.1) — es sei denn, es
        // steht an der Kante, wo eine eigene Zeile das Erbe bewusst festhält.*
        $geltend = $this->resolved($node, $edge)[$attribut] ?? null;

        if ($edge === null && $geltend !== null && $geltend->value->equals($wert)) {
            return false;
        }

        $this->settings->addValue($traeger['objectId'] !== null
            ? SettingsValue::inObject($traeger['objectId'], $erklaert->declaredBy, $attribut, $wert, $edge?->id)
            : SettingsValue::atNode($node->id, $erklaert->declaredBy, $attribut, $wert, $edge?->id));
        $this->resolver->forget();
        $this->note($node, $attribut, $edge, null, $wert);

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

                $this->settings->addValue($neu);
                $this->resolver->forget();
                $this->note($node, $attribut, $edge, $this->stateWord($zeile), $this->stateWord($neu));

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
        $this->note($node, $attribut, $edge, $this->stateWord($zeile), $this->stateWord($neu));

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
    private function putObject(Node $node, AttributeDeclaration $erklaert, string $name, ?Relation $edge): bool
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
        if ($klasse !== null && (($this->resolved($node, $edge)[$erklaert->name] ?? null)?->value->text ?? null) === $name) {
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
            $this->note($node, $erklaert->name, $edge, TypedValue::ofText($name), null);

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
        $this->note($node, $erklaert->name, $edge, null, TypedValue::ofText($name));

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

    /**
     * Die Erklärung eines Attributs — aus dem Vertrag des Knotens, oder aus dem des gewählten
     * Renderers, wenn der Knoten es nicht kennt (3.6.3).
     */
    private function declarationOf(Node $node, string $attribut, ?Relation $edge): AttributeDeclaration
    {
        $vertrag = Contracts::of($node->klasse);

        if (($erklaert = $vertrag->attribute($attribut)) !== null) {
            return $erklaert;
        }

        $klasse = $this->chosenRendererClass($node, $edge);

        if ($klasse !== null && ($erklaert = Contracts::ofValueClass($klasse)->attribute($attribut)) !== null) {
            return $erklaert;
        }

        throw SettingDoesNotApply::named($attribut);
    }

    /** @return class-string|null Die Klasse des gewählten Renderers, oder null. */
    private function chosenRendererClass(Node $node, ?Relation $edge): ?string
    {
        $name = ($this->resolved($node, $edge)[SettingKey::Renderer->value] ?? null)?->value->text;

        return $name === null || $name === '' ? null : $this->renderers->classFor($name);
    }

    /** @return array<string, \Taxmod\Core\Model\ResolvedSetting> */
    private function resolved(Node $node, ?Relation $edge): array
    {
        return $edge === null ? $this->resolver->forNode($node) : $this->resolver->forUseSite($edge, $node);
    }

    /**
     * Wem die Zeile gehört: dem Knoten — oder dem gewählten Objekt, wenn das Attribut dessen ist.
     *
     * @return array{nodeId: ?int, objectId: ?int}
     */
    private function carrierOf(Node $node, AttributeDeclaration $erklaert, ?Relation $edge): array
    {
        if (Contracts::of($node->klasse)->attribute($erklaert->name) !== null) {
            return ['nodeId' => $node->id, 'objectId' => null];
        }

        $rendererErklaert = Contracts::of($node->klasse)->attribute(SettingKey::Renderer->value);
        $amKnoten         = $rendererErklaert === null ? [] : $this->rowsAt(['nodeId' => $node->id, 'objectId' => null], $rendererErklaert, null);
        $anKante          = $rendererErklaert === null || $edge === null ? [] : $this->rowsAt(['nodeId' => $node->id, 'objectId' => null], $rendererErklaert, $edge);

        foreach ([...$anKante, ...$amKnoten] as $row) {
            if ($row->aktiv && $row->valueObjectId !== null) {
                return ['nodeId' => null, 'objectId' => $row->valueObjectId];
            }
        }

        throw SettingDoesNotApply::named($erklaert->name);
    }

    /**
     * Die Zeilen eines Attributs an einem Träger, am Knoten (`$edge` null) oder an dieser Kante.
     *
     * @param  array{nodeId: ?int, objectId: ?int} $traeger
     * @return list<SettingsValue>
     */
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
            AttributeType::Enum    => in_array($characters, $erklaert->enumCases(), true) ? TypedValue::ofText($characters) : throw SettingDoesNotApply::named($erklaert->name . ' = ' . $characters),
            AttributeType::NodeRef => TypedValue::ofReference($this->nodeNamed($erklaert, $characters)),
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

        return null;
    }

    private function note(Node $node, string $attribut, ?Relation $edge, ?TypedValue $vorher, ?TypedValue $nachher): void
    {
        $this->changelog?->record(
            $edge?->id ?? $node->id,
            $edge === null ? 'node' : 'relation',
            'setting ' . $attribut,
            $vorher?->rawValue(),
            $nachher?->rawValue(),
            null
        );
    }
}
