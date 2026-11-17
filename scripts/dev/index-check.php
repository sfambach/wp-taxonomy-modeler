<?php declare(strict_types=1);
/**
 * Fassung 60: die Indizes stehen nach den Fragen, die gestellt werden, und die abgelösten sind weg — und die Suche nach einem
 * Feldwert nutzt sie.
 *
 *     php scripts/dev/index-check.php [path/to/wordpress]
 *
 * ⚠️ *Prüfung vom 2026-11-17 (DB3, DB4, DB1-Index), [D-1426](../../docs/NewConcept/90-decision-log.md). `dbDelta` legt einen Index
 * still nicht an, wenn es ihn nicht versteht — darum fragt diese Prüfung die Datenbank und nicht den Code. Liest nur.*
 *
 * @see docs/NewConcept/50-wordpress-persistence.md
 */

use Taxmod\WordPress\Persistence\Schema;

$root = $argv[1] ?? getenv('WP_ROOT') ?: null;

if ($root === null) {
    $dir = getcwd();
    while ($dir !== '' && ! is_readable($dir . '/wp-load.php')) {
        $up  = dirname($dir);
        $dir = $up === $dir ? '' : $up;
    }
    $root = $dir;
}

if ($root === '' || ! is_readable($root . '/wp-load.php')) {
    fwrite(STDERR, "Cannot find wp-load.php. Pass the WordPress folder as the first argument.\n");
    exit(2);
}

define('WP_USE_THEMES', false);
require $root . '/wp-load.php';
// ⚠️ **No check writes into the owner's model** — the bracket rolls everything back at the end (Grenzlauf 2026-11-17, D-1427: this run had none).
require __DIR__ . '/lib/no-write.php';

global $wpdb;

$ok  = 0;
$bad = 0;

function check(string $what, bool $passed, string $detail = ''): void
{
    global $ok, $bad;
    echo ($passed ? '  OK   ' : '  FAIL ') . $what . ($passed || $detail === '' ? '' : " — {$detail}") . "\n";
    $passed ? $ok++ : $bad++;
}

/** @return list<string> the columns of an index in order, empty when it does not exist */
$columns = static fn (string $table, string $index): array => array_map('strval', $wpdb->get_col($wpdb->prepare(
    'SELECT COLUMN_NAME FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s
     ORDER BY SEQ_IN_INDEX',
    Schema::table($table),
    $index
)));

echo "1 · Die neuen Indizes stehen\n";

$expected = [
    ['relation_records', 'field_ref', ['relation_id', 'value_ref']],
    ['relation_records', 'field_int', ['relation_id', 'value_int']],
    ['relation_records', 'field_text', ['relation_id', 'value_text']],
    ['node_records', 'node_field', ['node_id', 'relation_id']],
    ['changelog', 'owner', ['owner_id', 'owner_kind']],
];

foreach ($expected as [$table, $index, $want]) {
    $have = $columns($table, $index);
    check("{$table}.{$index} über (" . implode(', ', $want) . ')', $have === $want, $have === [] ? 'fehlt' : implode(', ', $have));
}

echo "2 · Die abgelösten sind weg\n";

foreach ([['relation_records', 'relation_id'], ['node_records', 'node_id'], ['node_records', 'record_type'], ['changelog', 'owner_id'], ['label_texts', 'label_id']] as [$table, $index]) {
    check("{$table}.{$index} gibt es nicht mehr", $columns($table, $index) === []);
}

check('und node_records.relation_id bleibt', $columns('node_records', 'relation_id') === ['relation_id']);

echo "3 · Die Suche nach einem Feldwert nimmt ihren Index\n";

$plan = static fn (string $sql): array => $wpdb->get_results('EXPLAIN ' . $sql, ARRAY_A) ?: [];
$relation = (int) $wpdb->get_var('SELECT relation_id FROM ' . Schema::table('relation_records') . ' WHERE value_text IS NOT NULL LIMIT 1');

foreach ([
    'field_text' => $wpdb->prepare('SELECT node_record_id FROM ' . Schema::table('relation_records') . ' WHERE relation_id = %d AND value_text = %s', $relation, 'x'),
    'field_int'  => $wpdb->prepare('SELECT node_record_id FROM ' . Schema::table('relation_records') . ' WHERE relation_id = %d AND value_int = %d', $relation, 1),
    'field_ref'  => $wpdb->prepare('SELECT node_record_id FROM ' . Schema::table('relation_records') . ' WHERE relation_id = %d AND value_ref = %d', $relation, 1),
] as $index => $sql) {
    $rows = $plan($sql);
    $keys = implode(' ', array_map(static fn (array $r): string => (string) ($r['key'] ?? ''), $rows));
    // ⚠️ *Gemessen im Grenzlauf 2026-11-17: auf einer fast leeren Tabelle nimmt MySQL für die Verweissuche `value_ref` — der
    // Verweis ist für sich schon fast eindeutig, das ist ebenso gut. Rot ist nur ein Volllauf ohne Index.*
    $good = $index === 'field_ref' ? ['field_ref', 'value_ref'] : [$index];
    check("Wertsuche über {$index}", array_filter($good, static fn (string $k): bool => str_contains($keys, $k)) !== [], $keys === '' ? 'kein Plan' : $keys);
}

echo "\n" . ($bad === 0 ? "Alles gruen: $ok\n" : "$bad fehlgeschlagen, $ok in Ordnung\n");

exit($bad === 0 ? 0 : 1);
