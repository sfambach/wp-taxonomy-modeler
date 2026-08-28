<?php declare(strict_types=1);

namespace Taxmod\Tests\Core\Fake;

use Taxmod\Core\Model\Label;
use Taxmod\Core\Repository\LabelRepository;

/** Labels in an array, keyed the way the unique index keys them. */
final class InMemoryLabels implements LabelRepository
{
    /** @var array<string,Label> */
    private array $rows = [];

    /** Wie oft geschrieben wurde — siehe {@see InMemorySettings::$writes}. */
    public int $writes = 0;

    public function forOwners(array $ownerIds): array
    {
        $found = [];

        foreach ($this->rows as $label) {
            if (in_array($label->ownerId, $ownerIds, true)) {
                $found[] = $label;
            }
        }

        return $found;
    }

    public function put(Label $label): void
    {
        ++$this->writes;

        $this->rows[$this->key($label->ownerId, $label->path, $label->roleId, $label->number, $label->locale)] = $label;
    }

    public function forget(int $ownerId, string $path, int $roleId, string $number, string $locale): void
    {
        unset($this->rows[$this->key($ownerId, $path, $roleId, $number, $locale)]);
    }

    private function key(int $ownerId, string $path, int $roleId, string $number, string $locale): string
    {
        return implode("\0", [$ownerId, $path, $roleId, $number, $locale]);
    }

    public function forgetOwners(array $ownerIds): int
    {
        $gone = 0;

        foreach ($this->rows as $key => $row) {
            if (in_array($row->ownerId, $ownerIds, true)) {
                unset($this->rows[$key]);
                $gone++;
            }
        }

        return $gone;
    }
}
