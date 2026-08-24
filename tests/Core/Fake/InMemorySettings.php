<?php declare(strict_types=1);

namespace Taxmod\Tests\Core\Fake;

use Taxmod\Core\Model\Setting;
use Taxmod\Core\Repository\SettingRepository;

/** Settings in an array, sparse and forgettable, the way the SQL one is. */
final class InMemorySettings implements SettingRepository
{
    /** @var array<string,Setting> keyed by owner and key */
    private array $rows = [];

    public function forOwners(array $ownerIds): array
    {
        $found = [];

        foreach ($this->rows as $setting) {
            if (in_array($setting->ownerId, $ownerIds, true)) {
                $found[] = $setting;
            }
        }

        return $found;
    }

    public function ownedBy(int $ownerId): array
    {
        return $this->forOwners([$ownerId]);
    }

    public function put(Setting $setting): void
    {
        $this->rows[$setting->ownerId . "\0" . $setting->key] = $setting;
    }

    public function forget(int $ownerId, string $key): void
    {
        // The row disappears — that is the whole difference from storing nothing (D-266).
        unset($this->rows[$ownerId . "\0" . $key]);
    }

    public function count(): int
    {
        return count($this->rows);
    }
}
