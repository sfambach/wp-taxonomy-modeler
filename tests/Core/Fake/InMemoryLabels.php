<?php declare(strict_types=1);

namespace Taxmod\Tests\Core\Fake;

use Taxmod\Core\Model\Label;
use Taxmod\Core\Repository\LabelRepository;

/** Labels in an array, keyed the way the unique index keys them. */
final class InMemoryLabels implements LabelRepository
{
    /** @var array<string,Label> */
    private array $rows = [];

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
}
