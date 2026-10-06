<?php declare(strict_types=1);

namespace Taxmod\Tests\Core\Fake;

use Taxmod\Core\Exception\ConcurrentChange;
use Taxmod\Core\Model\ReferenceSpace;
use Taxmod\Core\Model\Setting\SettingsObject;
use Taxmod\Core\Model\Setting\SettingsValue;
use Taxmod\Core\Repository\SettingsRepository;

/**
 * Die zwei Einstellungstabellen in zwei Arrays — mit einem Schatten, damit ein Test «Löschen ist
 * Wandern» messen kann.
 *
 * ⚠️ **A fake, not a mock**, wie {@see InMemoryNodes}: er speichert wirklich und ordnet wirklich.
 */
final class InMemorySettings implements SettingsRepository
{
    /** @var array<int, SettingsObject> */
    private array $objects = [];

    /** @var array<int, SettingsValue> */
    private array $values = [];

    /** @var list<SettingsValue> Was gewandert ist, in der Reihenfolge des Wanderns. */
    public array $shadow = [];

    public function addObject(SettingsObject $object): SettingsObject
    {
        $object = $object->withAssignedId($this->objects === [] ? 1 : max(array_keys($this->objects)) + 1);

        return $this->objects[$object->id] = $object;
    }

    public function findObject(int $id): ?SettingsObject
    {
        return $this->objects[$id] ?? null;
    }

    public function objectsByIds(array $ids): array
    {
        return array_intersect_key($this->objects, array_flip($ids));
    }

    public function addValue(SettingsValue $value): SettingsValue
    {
        $id = $this->values === [] ? 1 : max(array_keys($this->values)) + 1;

        return $this->values[$id] = $value->stored($id);
    }

    public function saveValue(SettingsValue $value, int $expectedVersion): void
    {
        $current = $this->values[$value->id ?? 0] ?? null;

        if ($current === null || $current->version !== $expectedVersion) {
            throw ConcurrentChange::on($value->id ?? 0, $expectedVersion, $current?->version ?? 0);
        }

        $this->shadow[]            = $current;
        $this->values[$value->id] = $value;
    }

    public function findValue(int $id): ?SettingsValue
    {
        return $this->values[$id] ?? null;
    }

    public function valuesOfNodes(array $nodeIds): array
    {
        $aus = array_fill_keys($nodeIds, []);

        foreach ($this->ordered() as $value) {
            if ($value->nodeId !== null && isset($aus[$value->nodeId])) {
                $aus[$value->nodeId][] = $value;
            }
        }

        return $aus;
    }

    public function valuesOfObjects(array $objectIds): array
    {
        $aus = array_fill_keys($objectIds, []);

        foreach ($this->ordered() as $value) {
            if ($value->objectId !== null && isset($aus[$value->objectId])) {
                $aus[$value->objectId][] = $value;
            }
        }

        return $aus;
    }

    public function valuesReferring(array $nodeIds): array
    {
        $gesucht = array_flip($nodeIds);
        $aus     = [];

        foreach ($this->ordered() as $value) {
            if ($value->value->reference !== null && isset($gesucht[$value->value->reference])) {
                $aus[] = $value;
            }
        }

        return $aus;
    }

    public function valuesAtRelations(array $relationIds): array
    {
        $gesucht = array_flip($relationIds);

        return array_values(array_filter(
            $this->ordered(),
            static fn (SettingsValue $value): bool => ($value->relationId !== null && isset($gesucht[$value->relationId]))
                || ($value->value->referenceSpace === ReferenceSpace::Relation && $value->value->reference !== null && isset($gesucht[$value->value->reference]))
        ));
    }

    public function valuesNamingObjects(array $objectIds): array
    {
        $gesucht = array_flip($objectIds);

        return array_values(array_filter(
            $this->ordered(),
            static fn (SettingsValue $value): bool => $value->valueObjectId !== null && isset($gesucht[$value->valueObjectId])
        ));
    }

    public function valuesNamed(string $attribut): array
    {
        return array_values(array_filter(
            $this->ordered(),
            static fn (SettingsValue $value): bool => $value->attribut === $attribut && $value->nodeId !== null
        ));
    }

    public function forgetValue(int $id): ?int
    {
        $value = $this->values[$id] ?? null;

        if ($value === null) {
            return null;
        }

        $this->shadow[] = $value;
        unset($this->values[$id]);

        return $value->version;
    }

    public function forgetObject(int $id): ?int
    {
        $object = $this->objects[$id] ?? null;

        if ($object === null) {
            return null;
        }

        foreach ($this->values as $valueId => $value) {
            if ($value->objectId === $id) {
                $this->forgetValue($valueId);
            }
        }

        unset($this->objects[$id]);

        return $object->version;
    }

    public function countValues(): int
    {
        return count($this->values);
    }

    public function countObjects(): int
    {
        return count($this->objects);
    }

    /** @return list<SettingsValue> Wie die SQL-Fassung liest: nach Adresse, dann Stelle, dann Id. */
    private function ordered(): array
    {
        $zeilen = array_values($this->values);

        usort($zeilen, static fn (SettingsValue $a, SettingsValue $b): int =>
            [$a->klasse, $a->attribut, $a->position, $a->id] <=> [$b->klasse, $b->attribut, $b->position, $b->id]);

        return $zeilen;
    }
}
