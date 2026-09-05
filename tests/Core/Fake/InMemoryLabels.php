<?php declare(strict_types=1);

namespace Taxmod\Tests\Core\Fake;

use Taxmod\Core\Model\IdentitySpace;
use Taxmod\Core\Model\Label;
use Taxmod\Core\Repository\LabelRepository;

/**
 * Labels in an array, keyed the way the unique index keys them.
 *
 * ⚠️ *Der Raum gehört seit Fassung 31 in den Schlüssel (`INF-035`) — genau wie in der Tabelle. **Ein
 * Doppel, das ihn wegliesse, könnte den Fehler nicht nachstellen, gegen den die Spalte gebaut ist.***
 */
final class InMemoryLabels implements LabelRepository
{
    /** @var array<string,Label> */
    private array $rows = [];

    /** Wie oft geschrieben wurde — siehe {@see InMemorySettings::$writes}. */
    public int $writes = 0;

    public function forOwners(array $ownerIds, IdentitySpace $ownerKind): array
    {
        $found = [];

        foreach ($this->rows as $label) {
            if ($label->ownerKind === $ownerKind && in_array($label->ownerId, $ownerIds, true)) {
                $found[] = $label;
            }
        }

        return $found;
    }

    /** ⚠️ *Die Version zählt die Ablage, nicht der Aufrufer — wie in der Tabelle (D-634).* */
    public function put(Label $label): void
    {
        ++$this->writes;

        $key = $this->key($label->ownerId, $label->ownerKind, $label->path, $label->roleId, $label->number, $label->locale);

        $version = isset($this->rows[$key]) ? $this->rows[$key]->version + 1 : 1;

        $this->rows[$key] = new Label(
            $label->ownerId,
            $label->ownerKind,
            $label->path,
            $label->roleId,
            $label->number,
            $label->locale,
            $label->text,
            $version,
        );
    }

    public function forget(int $ownerId, IdentitySpace $ownerKind, string $path, int $roleId, string $number, string $locale): void
    {
        unset($this->rows[$this->key($ownerId, $ownerKind, $path, $roleId, $number, $locale)]);
    }

    private function key(int $ownerId, IdentitySpace $ownerKind, string $path, int $roleId, string $number, string $locale): string
    {
        return implode("\0", [$ownerId, $ownerKind->value, $path, $roleId, $number, $locale]);
    }

    public function forgetOwners(array $ownerIds, IdentitySpace $ownerKind): int
    {
        $gone = 0;

        foreach ($this->rows as $key => $row) {
            if ($row->ownerKind === $ownerKind && in_array($row->ownerId, $ownerIds, true)) {
                unset($this->rows[$key]);
                $gone++;
            }
        }

        return $gone;
    }
}
