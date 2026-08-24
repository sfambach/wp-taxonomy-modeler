<?php declare(strict_types=1);

namespace Taxmod\Tests\Core\Fake;

use Taxmod\Core\Model\Record;
use Taxmod\Core\Model\RecordValue;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Repository\RecordRepository;

/** Records in an array, with their own counter — a separate id space, as D-164 requires. */
final class InMemoryRecords implements RecordRepository
{
    /** @var array<int,Record> */
    private array $records = [];

    /** @var array<string,RecordValue> */
    private array $values = [];

    private int $lastId = 0;

    public function add(Record $record): int
    {
        $id = ++$this->lastId;

        $this->records[$id] = new Record($id, $record->modelId, $record->modelVersion, $record->createdAt);

        return $id;
    }

    public function find(int $id): ?Record
    {
        return $this->records[$id] ?? null;
    }

    public function ofModel(int $modelId): array
    {
        return array_values(array_filter(
            $this->records,
            static fn (Record $r): bool => $r->modelId === $modelId
        ));
    }

    public function valuesOf(int $recordId): array
    {
        return array_values(array_filter(
            $this->values,
            static fn (RecordValue $v): bool => $v->recordId === $recordId
        ));
    }

    public function putValue(RecordValue $value): void
    {
        $this->values[$this->key($value->recordId, $value->path, $value->locale)] = $value;
    }

    public function forgetValue(int $recordId, string $path, string $locale): void
    {
        unset($this->values[$this->key($recordId, $path, $locale)]);
    }

    public function findByEdgeValue(int $edgeId, TypedValue $value): array
    {
        $found = [];

        foreach ($this->values as $stored) {
            if ($stored->edgeId === $edgeId && $stored->value->equals($value)) {
                $found[] = $stored->recordId;
            }
        }

        return array_values(array_unique($found));
    }

    private function key(int $recordId, string $path, string $locale): string
    {
        return $recordId . "\0" . $path . "\0" . $locale;
    }
}
