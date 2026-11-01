<?php declare(strict_types=1);

namespace Taxmod\WordPress\Import;

use Taxmod\Core\Import\OrderProposal;
use Taxmod\Core\Model\Type\MediaType;

/**
 * Loads the product pictures of a proposal into the media library before the order is taken, so a purchase keeps its
 * picture when the shop deletes it (D-1079). A picture loaded once is found again by its address, not loaded twice.
 *
 * ```mermaid
 * flowchart LR
 *   P["Position · image_url https://…"] --> K{"schon in der Mediathek?"}
 *   K -->|ja| M["media:&lt;Id&gt;"]
 *   K -->|nein| L["herunterladen · Mediathek"] --> M
 *   L -->|Fehler| A["Adresse bleibt stehen"]
 * ```
 *
 * @see docs/pakete/import/konzept.md
 */
final class SourcePictures
{
    public static function intoLibrary(OrderProposal $proposal): OrderProposal
    {
        $row = $proposal->toArray();
        $addresses = array_values(array_unique(array_filter(array_map(
            static fn (array $line): string => (string) ($line['image_url'] ?? ''),
            $row['lines'],
        ), static fn (string $address): bool => str_starts_with($address, 'https://') || str_starts_with($address, 'http://'))));

        if ($addresses === []) {
            return $proposal;
        }

        $known = self::loaded($addresses);

        foreach ($row['lines'] as $index => $line) {
            $address = (string) ($line['image_url'] ?? '');

            if (! in_array($address, $addresses, true)) {
                continue;
            }

            $known[$address] ??= self::load($address, (string) $line['title']);

            if ($known[$address] !== null) {
                $row['lines'][$index]['image_url'] = MediaType::LIBRARY_SCHEME . $known[$address];
            }
        }

        return OrderProposal::fromArray($row);
    }

    /**
     * The attachments already loaded from these addresses — one query for all (`CD-7`).
     *
     * @param list<string> $addresses
     * @return array<string, int>
     */
    private static function loaded(array $addresses): array
    {
        global $wpdb;

        $placeholders = implode(', ', array_fill(0, count($addresses), '%s'));
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- only placeholders are interpolated.
        $rows = $wpdb->get_results($wpdb->prepare("SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_source_url' AND meta_value IN ($placeholders)", ...$addresses), ARRAY_A);
        $found = [];

        foreach ((array) $rows as $found_row) {
            $found[(string) $found_row['meta_value']] = (int) $found_row['post_id'];
        }

        return $found;
    }

    /** The attachment id, or `null` when the shop does not hand the picture out — the address then stays. */
    private static function load(string $address, string $title): ?int
    {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $id = media_sideload_image(esc_url_raw($address), 0, sanitize_text_field($title), 'id');

        return is_wp_error($id) ? null : (int) $id;
    }
}
