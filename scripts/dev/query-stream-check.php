<?php declare(strict_types=1);

/**
 * Eine gestreamte Lesung, die mitten im Strom abbricht, scheitert laut (Review 2026-11-20 zu D-1489).
 *
 * ⚠️ *`mysqli_fetch_assoc` antwortet `false`, wenn die Verbindung im Strom verloren geht; die Schleife von `Query::each` nahm
 * das wie die letzte Zeile, und wer die Zeilen faltete — das Lager sein Buch —, rechnete still mit einem Teil davon.* Geprüft
 * wird am echten Abbruch: eine zweite Verbindung beendet die erste, während sie strömt.
 *
 * Gegenprobe: derselbe Strom ohne Abbruch liefert jede Zeile und wirft nichts. Ohne mysqli (SQLite) strömt nichts, dann gibt
 * es hier nichts zu prüfen.
 *
 * Usage: php scripts/dev/query-stream-check.php
 *
 * @see docs/NewConcept/90-decision-log.md D-1489
 */

define('WP_ADMIN', true);
define('WP_USE_THEMES', false);

require (getenv('WP_ROOT') ?: 'C:/Devel/Wordpress') . '/wp-load.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\WordPress\Persistence\Query;

global $wpdb;

if (! ($wpdb->dbh ?? null) instanceof \mysqli) {
    echo "\nkein mysqli: hier strömt nichts, nichts zu prüfen\n\nall green\n";

    exit(0);
}

$failed = 0;
$say    = static function (bool $ok, string $what) use (&$failed): void {
    printf("  %-4s %s\n", $ok ? 'ok' : 'FAIL', $what);
    $failed += $ok ? 0 : 1;
};

// Gross genug, dass die Antwort nicht schon ganz im Puffer der Verbindung liegt, wenn sie endet: 4000 Zeilen zu je 20 000 Zeichen.
$zeilen = 4000;
$strom  = 'SELECT REPEAT(\'x\', 20000) AS wert FROM information_schema.COLUMNS a CROSS JOIN information_schema.COLUMNS b LIMIT ' . $zeilen;

echo "\n== Gegenprobe: ein Strom ohne Abbruch ==\n";
$gelesen = 0;
$fehler  = null;

try {
    Query::each('Strom ohne Abbruch lesen', $strom, static function (array $zeile) use (&$gelesen): void {
        $gelesen++;
    });
} catch (\Throwable $e) {
    $fehler = $e;
}

$say($gelesen === $zeilen, sprintf('jede Zeile kommt an (%d von %d)', $gelesen, $zeilen));
$say($fehler === null, 'und nichts wird geworfen');

echo "\n== der Strom bricht ab ==\n";
$zweite  = new \mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
$faden   = (int) $wpdb->dbh->thread_id;
$gelesen = 0;
$fehler  = null;

try {
    Query::each('Strom mit Abbruch lesen', $strom, static function (array $zeile) use (&$gelesen, $zweite, $faden): void {
        if (++$gelesen === 1) {
            $zweite->query('KILL ' . $faden);
        }
    });
} catch (\Throwable $e) {
    $fehler = $e;
}

$say($gelesen < $zeilen, sprintf('nur ein Teil der Zeilen kam an (%d von %d)', $gelesen, $zeilen));
$say($fehler instanceof \RuntimeException, 'die Lesung scheitert laut');
$say($fehler !== null && str_contains($fehler->getMessage(), 'Strom mit Abbruch lesen'), 'und nennt, was sie gerade las');

$zweite->close();
$wpdb->db_connect(false);

echo "\n== danach liest dieselbe Stelle wieder ==\n";
$gelesen = 0;
Query::each('Strom nach dem Abbruch lesen', 'SELECT 1 AS wert', static function (array $zeile) use (&$gelesen): void {
    $gelesen++;
});
$say($gelesen === 1, 'eine neue Verbindung strömt wie zuvor');

printf("\n%s\n", $failed === 0 ? 'all green' : sprintf('%d FEHLER', $failed));

exit($failed === 0 ? 0 : 1);
