<?php declare(strict_types=1);

/**
 * Der Satz-Block im Vergleich: der Hauptsatz ist markiert und steht an seinem Platz, die Ausgabe ist gepuffert, und jede Änderung am
 * Modell baut sie neu.
 *
 *     php scripts/dev/record-block-check.php [path/to/wordpress]
 *
 * ⚠️ **[D-919](../../docs/NewConcept/90-decision-log.md) und [D-920](../../docs/NewConcept/90-decision-log.md).** *Gesucht wird die
 * Gestalt, kein Name: drei Sätze eines Knotens mit Werten für die eigenen Felder, und ein Satz, auf den ein anderer Satz zeigt, der
 * selbst auf einen dritten zeigt (der Fall der Messwerte: Testaufbau ← Messwert → Messgrösse).*
 */

$wordpress = $argv[1] ?? (getenv('WP_ROOT') ?: 'C:/Devel/Wordpress');

define('WP_USE_THEMES', false);

require rtrim($wordpress, '/') . '/wp-load.php';

// ⚠️ **Kein Wächter schreibt in das Modell des Eigentümers** — die Klammer dreht am Ende alles zurück.
require __DIR__ . '/lib/no-write.php';
require __DIR__ . '/../../vendor/autoload.php';

use Taxmod\WordPress\Plugin;
use Taxmod\WordPress\RecordBlock;

global $wpdb;
wp_set_current_user(0);

$p = $wpdb->prefix . 'taxmod_';

$rc     = new ReflectionClass(Plugin::class);
$plugin = $rc->newInstanceWithoutConstructor();
$rc->getProperty('file')->setValue($plugin, dirname(__DIR__, 2) . '/wp-taxonomy-modeler.php');

$block = new RecordBlock(static fn () => $plugin->screen(), dirname(__DIR__, 2) . '/wp-taxonomy-modeler.php');

$fehler = 0;
$pruefe = static function (bool $gut, string $was) use (&$fehler): void {
    printf("  %s %s\n", $gut ? 'ok  ' : 'FAIL', $was);
    $fehler += $gut ? 0 : 1;
};

// *Die Zahl der Abfragen eines Aufrufs — ein Treffer im Puffer kostet nur den Stand des Modells.*
$abfragen = static function (callable $tun) use ($wpdb): array {
    $vorher = $wpdb->num_queries;
    $aus    = $tun();

    return [$aus, $wpdb->num_queries - $vorher];
};

$drei = $wpdb->get_col(
    "SELECT r.id FROM {$p}node_records r
      WHERE r.record_type = 'user' AND r.relation_id = 0
        AND r.node_id = (SELECT r2.node_id FROM {$p}node_records r2 WHERE r2.record_type = 'user' AND r2.relation_id = 0
                          GROUP BY r2.node_id HAVING COUNT(*) >= 3 ORDER BY r2.node_id DESC LIMIT 1)
      ORDER BY r.id ASC LIMIT 3"
);

if (count($drei) < 3) {
    $pruefe(false, 'kein Knoten mit drei Sätzen — der Wächter prüft nichts');
    exit(1);
}

[$a, $b, $c] = array_map('intval', $drei);

$mitte = ['display' => RecordBlock::COMPARISON, 'record' => $c, 'records' => "{$a}, {$b}", 'position' => 'middle'];
$html  = $block->render($mitte);

preg_match_all('#<th class="taxmod-comparison-value taxmod-comparison-(main|other)"#', $html, $koepfe);
$pruefe((array_count_values($koepfe[1])["main"] ?? 0) === 1, "genau eine Spalte ist der Hauptsatz #{$c}");
$pruefe($koepfe[1] === ['other', 'main', 'other'], 'in der Mitte: Vergleich, Hauptsatz, Vergleich');

$vorn = $block->render(['position' => 'front'] + $mitte);
preg_match_all('#<th class="taxmod-comparison-value taxmod-comparison-(main|other)"#', $vorn, $koepfe);
$pruefe($koepfe[1] === ['main', 'other', 'other'], 'vorn: der Hauptsatz zuerst');

[, $zweiter] = $abfragen(static fn (): string => $block->render($mitte));
$pruefe($zweiter === 1, "der zweite Aufruf kommt aus dem Puffer ({$zweiter} Abfrage)");

// ⚠️ *Eine Änderung am Modell ist eine Zeile im Änderungsbuch — die Klammer nimmt sie am Ende zurück.*
$wpdb->insert("{$p}changelog", ['owner_id' => $c, 'owner_kind' => 'record', 'at' => current_time('mysql', true), 'what' => '__record-block-check']);
[$neu, $danach] = $abfragen(static fn (): string => $block->render($mitte));
$pruefe($danach > 1 && $neu === $html, "nach einer Änderung wird neu gebaut ({$danach} Abfragen), gleiches Ergebnis");

$verweis = $wpdb->get_row(
    "SELECT v.relation_id AS via, v.value_ref AS spalte, k.relation_id AS zeile, k.value_ref AS ziel
       FROM {$p}relation_records v
       JOIN {$p}node_records r ON r.id = v.node_record_id AND r.record_type = 'user'
       JOIN {$p}relation_records k ON k.node_record_id = v.node_record_id AND k.value_ref_kind = 'record' AND k.relation_id <> v.relation_id
      WHERE v.value_ref_kind = 'record' ORDER BY v.id DESC LIMIT 1"
);

if ($verweis === null) {
    $pruefe(false, 'kein Satz, der auf zwei andere zeigt — die Zeilen aus Verweisen sind ungeprüft');
} else {
    $html = $block->render([
        'display' => RecordBlock::COMPARISON,
        'record'  => (int) $verweis->spalte,
        'via'     => (int) $verweis->via,
        'rowKey'  => (int) $verweis->zeile,
    ]);

    $pruefe(substr_count($html, '<tr') >= 2, "Zeilen aus den Sätzen, die über #{$verweis->via} auf #{$verweis->spalte} zeigen");
}

exit($fehler === 0 ? 0 : 1);
