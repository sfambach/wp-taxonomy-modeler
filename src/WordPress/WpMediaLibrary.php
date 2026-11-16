<?php declare(strict_types=1);

namespace Taxmod\WordPress;

use Taxmod\Core\Model\Type\MediaType;
use Taxmod\Core\Port\MediaFile;
use Taxmod\Core\Port\MediaLibrary;
use Taxmod\WordPress\Import\Documents;
use Taxmod\WordPress\Persistence\WpdbRecordRepository;

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

    /** @var array<string, string>|null Adresse ⇒ Statuswort eines eigenen Beitrags (D-1413); leer heisst veröffentlicht oder fremd. */
    private ?array $stati = null;

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

    public function document(string $key): ?MediaFile
    {
        return Documents::linkFor($key);
    }

    /**
     * ⚠️ *Wie die Dateien: beim ersten Mal jede eigene Adresse, die im Modell steht, in zwei Abfragen (Ids, dann Titelnamen) — nie eine je
     * Link (`CD-7`). Eine Adresse, die noch in keinem Wert stand, wird einzeln gefragt.*
     */
    public function postStateOf(string $address): string
    {
        // ⚠️ *Ein Besucher sieht nie «Entwurf» — das Wort nur für den, der Beiträge bearbeiten darf (Architektur, 16.11.).*
        if (! current_user_can('edit_posts')) {
            return '';
        }

        $address = trim($address);

        // ⚠️ *Nur Adressen des eigenen Rechners vorladen, nicht jeden Datenblatt- und Shop-Link (Ladezeit, Architektur 16.11.).*
        if ($this->stati === null) {
            $repository = new WpdbRecordRepository();
            $vorladen   = [];

            foreach (self::ownPrefixes() as $praefix) {
                $vorladen = array_merge($vorladen, $repository->textsStartingWith($praefix));
            }

            $this->stati = $this->postStates($vorladen);
        }

        if (! array_key_exists($address, $this->stati)) {
            $this->stati += $this->postStates([$address]);
        }

        return $this->stati[$address] ?? '';
    }

    /**
     * @param  array<int, string>    $adressen
     * @return array<string, string> Adresse ⇒ Statuswort, für jede gefragte Adresse.
     */
    private function postStates(array $adressen): array
    {
        $eigener = self::hostOf(home_url('/'));
        $ids     = [];
        $namen   = [];

        foreach ($adressen as $adresse) {
            $adresse = trim((string) $adresse);

            if ($adresse === '' || self::hostOf($adresse) !== $eigener) {
                continue;
            }

            parse_str((string) parse_url($adresse, PHP_URL_QUERY), $frage);
            $id = (int) ($frage['p'] ?? $frage['page_id'] ?? 0);

            if ($id > 0) {
                $ids[$adresse] = $id;
                continue;
            }

            $weg  = trim((string) parse_url($adresse, PHP_URL_PATH), '/');
            $name = $weg === '' ? '' : sanitize_title(basename($weg));

            if ($name !== '' && ! str_starts_with($weg, 'wp-')) {
                $namen[$adresse] = $name;
            }
        }

        $beitraege = [];

        if ($ids !== []) {
            _prime_post_caches(array_values(array_unique($ids)), false, false);

            foreach ($ids as $adresse => $id) {
                $beitraege[$adresse] = get_post($id);
            }
        }

        if ($namen !== []) {
            $gefunden = get_posts([
                'post_name__in'          => array_values(array_unique($namen)),
                'post_type'              => 'any',
                'post_status'            => 'any',
                'posts_per_page'         => -1,
                'suppress_filters'       => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
            ]);
            $jeName = [];

            foreach ($gefunden as $post) {
                $jeName[$post->post_name][] = $post;
            }

            // *Ein Name, den mehrere Beiträge tragen, sagt nichts Sicheres — dann lieber kein Wort als ein falsches.*
            foreach ($namen as $adresse => $name) {
                $beitraege[$adresse] = count($jeName[$name] ?? []) === 1 ? $jeName[$name][0] : null;
            }
        }

        $stati = array_fill_keys(array_map('strval', $adressen), '');

        foreach ($beitraege as $adresse => $post) {
            if ($post instanceof \WP_Post && $post->post_type !== 'attachment' && $post->post_status !== 'publish') {
                $stati[(string) $adresse] = (string) (get_post_status_object($post->post_status)?->label ?? $post->post_status);
            }
        }

        return $stati;
    }

    /** @return list<string> Der Anfang jeder Adresse auf diesen Rechner: http und https, mit und ohne «www.». */
    private static function ownPrefixes(): array
    {
        $rechner = self::hostOf(home_url('/'));
        $praefixe = [];

        foreach (['https://', 'http://'] as $schema) {
            foreach (['', 'www.'] as $www) {
                $praefixe[] = $schema . $www . $rechner . '/';
            }
        }

        return $praefixe;
    }

    private static function hostOf(string $adresse): string
    {
        return (string) preg_replace('/^www\./', '', strtolower((string) parse_url($adresse, PHP_URL_HOST)));
    }

    /** @return array<int, MediaFile> */
    private function alle(): array
    {
        $werte = (new WpdbRecordRepository())->textsStartingWith(MediaType::LIBRARY_SCHEME);
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

            $dateien[$id] = $url === '' ? null : new MediaFile($id, $url, (string) $post->post_title, $vorschau, trim(wp_strip_all_tags((string) $post->post_excerpt)));
        }

        return $dateien;
    }
}
