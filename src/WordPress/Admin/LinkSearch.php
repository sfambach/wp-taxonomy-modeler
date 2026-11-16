<?php declare(strict_types=1);

namespace Taxmod\WordPress\Admin;

/**
 * Die Suche des WordPress-Linkdialogs auf Taxmod-Seiten ([D-1413](../../../docs/NewConcept/90-decision-log.md)): auch nicht
 * veröffentlichte Beiträge, gesucht nur im Titel, und der Status steht in der Trefferzeile.
 *
 * ⚠️ *Gemessen am 2026-11-16: WordPress sucht dort nur Veröffentlichtes und in Titel **und Inhalt** — «hat» fand jeden Beitrag, in
 * dessen Text das deutsche Wort «hat» steht (Arduino, ESP01 …), und nicht den Entwurf «mt32-pi MIDI HAT». Sein Wort: «link sollte
 * auch unveröffentlichte beiträge zeigen, muss ersichtlich sein sowohl in der suche als auch dann im objekt».*
 *
 * ```mermaid
 * flowchart LR
 *   D["Linkdialog · admin.js setzt taxmod_link=1"] --> A["admin-ajax · wp-link-ajax"]
 *   A -->|"wp_link_query_args"| Q["alle Stati · nur Titel"]
 *   Q -->|"wp_link_query"| T["Treffer: «Entwurf · Datum»"]
 * ```
 *
 * *Nur wenn das Skript der Taxmod-Seite fragt (`taxmod_link`); der Dialog im klassischen Editor bleibt, wie WordPress ihn baut.*
 */
final class LinkSearch
{
    public const FLAG = 'taxmod_link';

    private const STATI = ['publish', 'future', 'draft', 'pending', 'private'];

    public static function wire(): void
    {
        add_filter('wp_link_query_args', [self::class, 'queryArgs']);
        add_filter('wp_link_query', [self::class, 'results'], 10, 2);
    }

    /**
     * @param  array<string, mixed> $args
     * @return array<string, mixed>
     */
    public static function queryArgs(array $args): array
    {
        if (! self::asked()) {
            return $args;
        }

        $args['post_status'] = self::STATI;

        // *Nur im Titel: ein Linkziel sucht man bei seinem Namen; im Text findet ein deutsches Wort fast jeden Beitrag.*
        if (isset($args['s']) && trim((string) $args['s']) !== '') {
            $args['search_columns'] = ['post_title'];
        }

        return $args;
    }

    /**
     * @param  array<int, array<string, mixed>>|false $results
     * @param  array<string, mixed>                    $query
     * @return array<int, array<string, mixed>>|false
     */
    public static function results(array|false $results, array $query = []): array|false
    {
        if (! self::asked() || ! is_array($results)) {
            return $results;
        }

        $ids = array_map(static fn (array $treffer): int => (int) ($treffer['ID'] ?? 0), $results);
        _prime_post_caches(array_values(array_filter($ids)), false, false);

        foreach ($results as $i => $treffer) {
            $status = get_post_status((int) ($treffer['ID'] ?? 0));

            if ($status === false || $status === 'publish') {
                continue;
            }

            $wort = (string) (get_post_status_object($status)?->label ?? $status);
            $info = trim((string) ($treffer['info'] ?? ''));
            $results[$i]['info'] = $info === '' ? $wort : $wort . ' · ' . $info;
        }

        return $results;
    }

    private static function asked(): bool
    {
        // phpcs:ignore WordPress.Security.NonceVerification -- WordPress prüft die Nonce des Linkdialogs (`_ajax_linking_nonce`) vor diesem Filter.
        return ! empty($_POST[self::FLAG]) && current_user_can('edit_posts');
    }
}
