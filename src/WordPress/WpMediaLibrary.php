<?php declare(strict_types=1);

namespace Taxmod\WordPress;

use Taxmod\Core\Model\Type\MediaType;
use Taxmod\Core\Port\MediaFile;
use Taxmod\Core\Port\MediaLibrary;
use Taxmod\WordPress\Persistence\Schema;

/**
 * Die eine Stelle, an der eine gespeicherte Mediathek-Id (`media:<Id>`) nach WordPress gefragt wird ([D-865](../../docs/NewConcept/90-decision-log.md)).
 *
 * ⚠️ **Einmal alles, nicht einmal je Bild** (`CD-7`). *Beim ersten Aufruf liest sie jede Id, die irgendein Wert im Modell trägt, und
 * füllt den Zwischenspeicher von WordPress für alle zugleich; danach beantworten `wp_get_attachment_url()` und
 * `wp_get_attachment_image_url()` aus dem Speicher. Eine Seite mit vierzig Bildern kostet so eine Handvoll Abfragen, nicht vierzig.*
 *
 * @see \Taxmod\Core\Port\MediaLibrary
 */
final class WpMediaLibrary implements MediaLibrary
{
    /** @var array<int, MediaFile|null>|null Id ⇒ Datei; `null` als Wert heisst: gefragt, keine da. */
    private ?array $bekannt = null;

    public function filesFor(array $ids): array
    {
        if ($this->bekannt === null) {
            $this->bekannt = $this->alle();
        }

        $antwort = [];

        foreach ($ids as $id) {
            if (isset($this->bekannt[$id])) {
                $antwort[$id] = $this->bekannt[$id];
            } elseif (! array_key_exists($id, $this->bekannt)) {
                // *Eine Id, die noch in keinem gespeicherten Wert stand — frisch in der Maske gewählt. Selten, darum einzeln.*
                $this->bekannt += $this->lesen([$id]);

                if (isset($this->bekannt[$id])) {
                    $antwort[$id] = $this->bekannt[$id];
                }
            }
        }

        return $antwort;
    }

    /** @return array<int, MediaFile> */
    private function alle(): array
    {
        global $wpdb;

        $werte = $wpdb->get_col($wpdb->prepare(
            'SELECT DISTINCT value_text FROM ' . Schema::table('relation_records') . ' WHERE value_text LIKE %s',
            $wpdb->esc_like(MediaType::LIBRARY_SCHEME) . '%'
        ));
        $ids = [];

        foreach ((array) $werte as $wert) {
            $id = MediaType::libraryIdOf((string) $wert);

            if ($id !== null) {
                $ids[] = $id;
            }
        }

        return $this->lesen($ids);
    }

    /**
     * @param  list<int>             $ids
     * @return array<int, MediaFile|null> *`null` merkt sich eine Id, zu der es keine Datei gibt — sie wird nicht noch einmal gefragt.*
     */
    private function lesen(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));

        if ($ids === []) {
            return [];
        }

        // *Posts und ihre Metadaten in zwei Abfragen für alle, danach liest WordPress aus dem Speicher.*
        _prime_post_caches($ids, false, true);

        $dateien = [];

        foreach ($ids as $id) {
            $post = get_post($id);

            if (! $post instanceof \WP_Post || $post->post_type !== 'attachment') {
                $dateien[$id] = null;
                continue;
            }

            $url      = (string) wp_get_attachment_url($id);
            $vorschau = wp_attachment_is_image($id) ? (string) wp_get_attachment_image_url($id, 'thumbnail') : '';

            $dateien[$id] = $url === '' ? null : new MediaFile($id, $url, (string) $post->post_title, $vorschau);
        }

        return $dateien;
    }
}
