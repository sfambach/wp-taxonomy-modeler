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
        // ⚠️ **The array key includes the path, because the real table's unique key does**
        // ([D-409](../../../docs/NewConcept/90-decision-log.md)). *A fake that forgets an address the
        // SQL one keeps is a fake that passes where the real thing fails.*
        $this->rows[$setting->ownerId . "\0" . $setting->key . "\0" . $setting->path] = $setting;
    }

    public function forget(int $ownerId, string $key, string $path = ''): void
    {
        // The row disappears — that is the whole difference from storing nothing (D-266).
        unset($this->rows[$ownerId . "\0" . $key . "\0" . $path]);
    }

    public function count(): int
    {
        return count($this->rows);
    }
}
