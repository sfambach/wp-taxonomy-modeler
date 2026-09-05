<?php declare(strict_types=1);

namespace Taxmod\Tests\Core\Fake;

use Taxmod\Core\Model\NodeRecord;
use Taxmod\Core\Model\RelationRecord;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Repository\RecordRepository;

/** Records in an array, with their own counter — a separate id space, as D-164 requires. */
final class InMemoryRecords implements RecordRepository
{
    /** @var array<int,NodeRecord> */
    private array $records = [];

    /** @var array<string,RelationRecord> */
    private array $values = [];

    private int $lastId = 0;

    private int $lastValueId = 0;

    /**
     * Die Version je Wertzeile und je Datensatz.
     *
     * ⚠️ *Der Doppelgänger zählt sie mit, weil der echte Speicher es tut
     * ([D-536](../../../docs/NewConcept/90-decision-log.md)) und weil ein Kerntest sonst nicht zeigen
     * könnte, dass eine Wertänderung die **richtige** Version meldet
     * ([D-634](../../../docs/NewConcept/90-decision-log.md)). **Ein Doppelgänger, der die Version
     * erfindet, kann für sie nicht rot werden.**
     *
     * @var array<int,int>
     */
    private array $valueVersions = [];

    /** @var array<int,int> */
    private array $recordVersions = [];

    public function add(NodeRecord $record): int
    {
        $id = ++$this->lastId;

        // ⚠️ **Die Art muss mit, sonst ist jeder Satz hier ein Benutzersatz** (TASK-057). *Der Fake
        // liess sie fallen, und niemand merkte es: solange der Renderer in einer **Spalte** stand,
        // fragte kein Kerntest nach dem `default`-Satz eines Knotens. Die Kantenform tut es.*
        $this->records[$id]        = new NodeRecord($id, $record->nodeId, $record->nodeVersion, $record->createdAt, $record->recordType);
        $this->recordVersions[$id] = 1;

        return $id;
    }

    public function find(int $id): ?NodeRecord
    {
        return $this->records[$id] ?? null;
    }

    public function ofNode(int $nodeId): array
    {
        return array_values(array_filter(
            $this->records,
            static fn (NodeRecord $r): bool => $r->nodeId === $nodeId
        ));
    }

    /**
     * @param  list<int>                    $nodeIds
     * @return array<int, list<NodeRecord>>
     */
    public function ofNodes(array $nodeIds): array
    {
        $nachKnoten = [];

        foreach ($nodeIds as $id) {
            $nachKnoten[$id] = $this->ofNode($id);
        }

        return $nachKnoten;
    }

    /**
     * @param  list<int>                    $recordIds
     * @return array<int, list<RelationRecord>>
     */
    public function valuesOfMany(array $recordIds): array
    {
        $nachSatz = [];

        foreach ($recordIds as $id) {
            $nachSatz[$id] = $this->valuesOf($id);
        }

        return $nachSatz;
    }

    public function valuesOf(int $recordId): array
    {
        $meine = array_filter(
            $this->values,
            static fn (RelationRecord $v): bool => $v->recordId === $recordId
        );

        // ⚠️ *Dieselbe Ordnung wie in SQL — `position`, bei Gleichstand die Id
        // ([D-530](../../../docs/NewConcept/90-decision-log.md)). Ein Doppelgänger, der anders
        // sortiert, lässt einen Reihenfolgetest grün werden, den SQL rot machen würde.*
        uasort(
            $meine,
            static fn (RelationRecord $a, RelationRecord $b): int => [$a->position, $a->id ?? 0] <=> [$b->position, $b->id ?? 0]
        );

        return array_values($meine);
    }

    /**
     * ⚠️ *Dieselbe Antwortform wie SQL: je gehaltenen Satz **eine** Zeile, und die erste gewinnt — ein
     * Datensatz kann nicht an zwei Stellen hängen, und wenn doch, wäre das ein Fehler und keine Auswahl.*
     *
     * @param  list<int> $recordIds
     * @return array<int, RelationRecord>
     */
    public function holdersOf(array $recordIds): array
    {
        $gesucht = array_fill_keys(array_map('intval', $recordIds), true);
        $aus     = [];

        foreach ($this->values as $wert) {
            $ziel = $wert->value->reference;

            if ($ziel === null || ! isset($gesucht[$ziel]) || isset($aus[$ziel])) {
                continue;
            }

            $aus[$ziel] = $wert;
        }

        return $aus;
    }

    /**
     * ⚠️ *Einfügen oder genau eine Zeile ändern — **nie überschreiben**
     * ([D-530](../../../docs/NewConcept/90-decision-log.md)). Behielte dieser Doppelgänger den alten
     * Schlüssel `(recordId, path, locale)`, könnte kein Kerntest zeigen, dass drei Werte eines Feldes
     * nebeneinander stehen.*
     */
    public function putValue(RelationRecord $value): int
    {
        $id = $value->id ?? ++$this->lastValueId;

        $this->values[$id] = $value->id === null ? $value->stored($id) : $value;

        // ⚠️ *Wie im echten Speicher: eine neue Zeile beginnt bei 1, jedes Überschreiben zählt hoch.*
        $this->valueVersions[$id] = $value->id === null ? 1 : 1 + ($this->valueVersions[$id] ?? 1);

        return $this->valueVersions[$id];
    }

    public function forgetValue(int $recordId, string $path, string $locale): ?int
    {
        $version = null;

        foreach ($this->values as $id => $stored) {
            if ($stored->recordId === $recordId && $stored->path === $path && $stored->locale === $locale) {
                $version = max($version ?? 0, $this->valueVersions[$id] ?? 1);

                unset($this->values[$id], $this->valueVersions[$id]);
            }
        }

        return $version;
    }

    public function forgetValueById(int $id): ?int
    {
        $version = array_key_exists($id, $this->values) ? ($this->valueVersions[$id] ?? 1) : null;

        unset($this->values[$id], $this->valueVersions[$id]);

        return $version;
    }

    public function forgetRecord(int $id): ?int
    {
        $version = $this->recordVersions[$id] ?? null;

        foreach ($this->values as $vid => $wert) {
            if ($wert->recordId === $id) {
                unset($this->values[$vid], $this->valueVersions[$vid]);
            }
        }

        unset($this->records[$id], $this->recordVersions[$id]);

        return $version;
    }

    public function findByRelationValue(int $relationId, TypedValue $value): array
    {
        $found = [];

        foreach ($this->values as $stored) {
            if ($stored->relationId === $relationId && $stored->value->equals($value)) {
                $found[] = $stored->recordId;
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * ⚠️ *Der Doppelgänger muss dasselbe leisten wie die SQL-Fassung, sonst kann ein Kerntest für den
     * Mechanismus nicht rot werden — genau der Fehler, den `RecordedChanges` mit der Akt-Klammer
     * einmal gemacht hat.*
     *
     * @param  list<int> $nodeIds
     * @return array{records: int, values: int}
     */
    public function forgetNodes(array $nodeIds): array
    {
        $ids   = array_values(array_unique(array_map(intval(...), $nodeIds)));
        $gone  = ['records' => 0, 'values' => 0];

        foreach ($this->records as $id => $record) {
            if (! in_array($record->nodeId, $ids, true)) {
                continue;
            }

            foreach ($this->values as $key => $stored) {
                if ($stored->recordId === $id) {
                    unset($this->values[$key]);
                    ++$gone['values'];
                }
            }

            unset($this->records[$id]);
            ++$gone['records'];
        }

        return $gone;
    }

}

