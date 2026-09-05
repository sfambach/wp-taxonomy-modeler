<?php declare(strict_types=1);
/**
 * Dass die Marke gepflegt bleibt — ein Knoten im Settings-Ast ohne `field_type = setting` faellt auf.
 *
 *     php scripts/dev/setting-kind-check.php [path/to/wordpress]
 *
 * ⚠️ **[D-606](../../docs/NewConcept/90-decision-log.md):** *«Was eine Einstellung ist, sagt die Marke
 * am Knoten, nicht der Ast und nicht die Kante.»* Sein Wort dazu: *«also dass die Settings-Knoten kein
 * Settings haben, ist Altlast.»* **Die Altlast ist mit {@see setting-kind-migrate.php} bereinigt; diese
 * Pruefung sorgt dafuer, dass sie nicht zurueckkommt** — ein neu angelegter Knoten im Ast ohne Marke
 * ist genau der Fall, der die Marke wieder unbrauchbar machen wuerde.
 *
 * ⚠️ **Sie prueft nicht das Umgekehrte.** *Ein markierter Knoten **ausserhalb** des Astes ist richtig
 * und nicht falsch — `read_only` liegt unter `Boolean`, weil es ein Boolean ist. Der Ast ist die
 * Herkunft der Marke, nicht ihre Grenze; eine Pruefung «markiert heisst im Ast» wuerde genau das
 * verbieten, was [D-606](../../docs/NewConcept/90-decision-log.md) moeglich machen wollte.*
 *
 * ⚠️ **Die zwei bekannten Ausnahmen sind gemessen, nicht gesetzt:** *`min` und `max` liegen im Ast,
 * tragen nichts, und **keine einzige Kante zeigt auf sie**. Sie gehoeren nach
 * [D-516](../../docs/NewConcept/90-decision-log.md) als Spezialisierungen unter `Integer`. Die Pruefung
 * laesst darum jeden **direkten** Astkind-Knoten durch, der weder Kantenziel ist noch selbst etwas
 * haelt — und meldet ihn, damit er nicht unbemerkt liegen bleibt.*
 *
 * @see docs/NewConcept/90-decision-log.md
 */

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
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\Core\Model\FieldType;
use Taxmod\WordPress\Persistence\Schema;

global $wpdb;

$ok  = 0;
$bad = 0;

function check(string $what, bool $passed, string $detail = ''): void
{
    global $ok, $bad;

    if ($passed) {
        $ok++;
        echo "  OK   $what\n";

        return;
    }

    $bad++;
    echo "  FAIL $what" . ($detail !== '' ? " — $detail" : '') . "\n";
}

$nodesTable = Schema::table('nodes');
$edgesTable = Schema::table('relations');

echo "\n== 1. Der Ast ist auffindbar ==\n";

$branch = $wpdb->get_row(
    "SELECT id, path FROM {$nodesTable} WHERE name = 'Settings' AND path NOT LIKE '%.%.%'"
);

check('die Astwurzel `Settings` steht direkt unter der Wurzel', $branch !== null);

if ($branch === null) {
    echo "\n$ok ok, $bad fehlgeschlagen\n";

    exit(1);
}

$rows = $wpdb->get_results($wpdb->prepare(
    "SELECT id, name, path, field_type FROM {$nodesTable} WHERE path LIKE %s ORDER BY path",
    $wpdb->esc_like($branch->path . '.') . '%'
), ARRAY_A);

check('und traegt Knoten', $rows !== [], (string) count($rows));

echo "\n== 2. Jeder Knoten im Ast traegt die Marke ==\n";

$depth   = substr_count((string) $branch->path, '.') + 2;
$fehlend = [];
$rest    = [];

foreach ($rows as $row) {
    $id   = (int) $row['id'];
    $kind = $row['field_type'];

    if ($kind === FieldType::Setting->value) {
        continue;
    }

    // Rest im Sinne von D-606: direktes Astkind, auf das keine Kante zeigt und das selbst keine
    // haelt. Zaehlt nicht als Fehler, wird aber genannt.
    if (substr_count((string) $row['path'], '.') + 1 === $depth) {
        $incoming = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$edgesTable} WHERE to_node_id = %d AND kind <> 'inheritance'",
            $id
        ));
        $outgoing = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$edgesTable} WHERE from_node_id = %d",
            $id
        ));

        if ($incoming === 0 && $outgoing === 0) {
            $rest[] = $row['name'] . " ({$id})";

            continue;
        }
    }

    $fehlend[] = $row['name'] . " ({$id})"
        . ($kind === null || $kind === '' ? '' : ", traegt statt dessen `{$kind}`");
}

check(
    'kein Knoten im Ast ohne `field_type = setting`',
    $fehlend === [],
    implode('; ', $fehlend)
);

if ($rest !== []) {
    printf("  HINWEIS Rest im Ast, auf den nichts zeigt: %s\n", implode('; ', $rest));
}

echo "\n== 3. Und die Marke ist ein echtes NULL, wo sie fehlt ==\n";

// ⚠️ *`$wpdb->prepare('%s', null)` schreibt eine **leere Zeichenkette**. `fromStorage()` liest beide
// als «niemand hat etwas gesagt», `WHERE kind IS NOT NULL` findet nur eine — ein Knoten waere
// gleichzeitig markiert und nicht markiert ([D-519](../../docs/NewConcept/90-decision-log.md)).*
$leer = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$nodesTable} WHERE field_type = ''");

check('kein Knoten traegt eine leere Sorte statt NULL', $leer === 0, $leer . ' Zeile(n)');

$fremd = array_values(array_filter(
    $wpdb->get_col("SELECT DISTINCT field_type FROM {$nodesTable} WHERE field_type IS NOT NULL"),
    static fn ($v): bool => FieldType::tryFrom((string) $v) === null
));

check('und keine Sorte, die der Code nicht kennt', $fremd === [], implode(', ', $fremd));

printf("\n%d ok, %d fehlgeschlagen\n", $ok, $bad);

exit($bad === 0 ? 0 : 1);
