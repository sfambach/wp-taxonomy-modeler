<?php declare(strict_types=1);

namespace Taxmod\WordPress\Persistence;

use Taxmod\Core\Model\Setting;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Repository\SettingRepository;

/**
 * Settings in a table of our own, one row per owner and key.
 *
 * ⚠️ **`owner_id` is one column over nodes, edges and the installation identity** (D-090,
 * D-339). That is what lets an edge carry a setting of its own, and it is the reason the whole
 * override mechanism needs no second construct: an override is the same thing wherever it sits
 * (D-087).
 *
 * @see docs/NewConcept/50-wordpress-persistence.md
 */
final class WpdbSettingRepository implements SettingRepository
{
    public function forOwners(array $ownerIds): array
    {
        global $wpdb;

        if ($ownerIds === []) {
            return [];
        }

        $places = implode(',', array_fill(0, count($ownerIds), '%d'));

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT owner_id, setting_key, value_int, value_decimal, value_text, value_date, value_ref
                 FROM ' . Schema::table('settings') . "
                 WHERE owner_id IN ({$places})",
                array_map(intval(...), $ownerIds)
            ),
            ARRAY_A
        );

        return array_map($this->hydrate(...), $rows ?: []);
    }

    public function ownedBy(int $ownerId): array
    {
        return $this->forOwners([$ownerId]);
    }

    public function put(Setting $setting): void
    {
        global $wpdb;

        $value = $setting->value;

        // `replace` keys on the UNIQUE (owner_id, setting_key), so one setting stays one row —
        // sparse storage means what is written is what differs, never a full copy (D-015).
        $wpdb->replace(
            Schema::table('settings'),
            [
                'owner_id'      => $setting->ownerId,
                'setting_key'   => $setting->key,
                'value_int'     => $value->int,
                'value_decimal' => $value->decimal,
                'value_text'    => $value->text,
                'value_date'    => $value->date,
                'value_ref'     => $value->reference,
            ],
            ['%d', '%s', '%d', '%s', '%s', '%s', '%d']
        );
    }

    public function forget(int $ownerId, string $key): void
    {
        global $wpdb;

        // ⚠️ The row **disappears** — that is what makes it inherited again, and what makes it
        // different from a row holding nothing (D-266).
        $wpdb->delete(
            Schema::table('settings'),
            ['owner_id' => $ownerId, 'setting_key' => $key],
            ['%d', '%s']
        );
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): Setting
    {
        return new Setting(
            (int) $row['owner_id'],
            (string) $row['setting_key'],
            TypedValue::fromStorage(
                $row['value_int'] === null ? null : (int) $row['value_int'],
                $row['value_decimal'] === null ? null : (string) $row['value_decimal'],
                $row['value_text'] === null ? null : (string) $row['value_text'],
                $row['value_date'] === null ? null : (string) $row['value_date'],
                $row['value_ref'] === null ? null : (int) $row['value_ref'],
            )
        );
    }
}
