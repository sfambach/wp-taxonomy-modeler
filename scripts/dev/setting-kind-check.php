<?php declare(strict_types=1);
/**
 * Dass jeder Knoten im Settings-Ast weiter als Einstellung erkannt wird — **jetzt aus der Kante**.
 *
 *     php scripts/dev/setting-kind-check.php [path/to/wordpress]
 *
 * ⚠️ **Die Pruefung ist mit [D-621](../../docs/NewConcept/90-decision-log.md) umgezogen und nicht
 * entschaerft** (`PR-9`). *Sie fragte `nodes.field_type` ab — die Spalte ist gefallen, weil «die Kante
 * sagt, was etwas hier ist — nicht der Knoten und nicht der Ast». **Die Zusage bleibt Wort fuer Wort
 * dieselbe** und wird nur anders beantwortet: ueber {@see \Taxmod\Core\Repository\NodeRepository::resolvedFieldTypes()},
 * also die eingehenden Kanten und, wo keine ist, die Kante ueber dem naechsten Vorfahren.*
 *
 * ⚠️ **Der Ast bestimmt nichts mehr, und diese Pruefung behauptet es auch nicht.**
 * *[D-621](../../docs/NewConcept/90-decision-log.md): er bleibt «Ordnung und Sprungziel», er verliert
 * das Bestimmen. **Hier steht darum eine Beobachtung und keine Regel:** die Knoten im Ast werden heute
 * ueber die Kanten erreicht, und wenn eine Kante wegfaellt, faellt genau das auf.*
 *
 * ⚠️ **Sie prueft nicht das Umgekehrte.** *Ein Einstellungsknoten **ausserhalb** des Astes ist richtig
 * und nicht falsch — `read_only` liegt unter `Boolean`, weil es ein Boolean ist.*
 *
 * ⚠️ **Die bekannten Ausnahmen sind gemessen, nicht gesetzt:** *ein **direktes** Astkind, auf das keine
 * Kante zeigt und das selbst keine haelt, ist Rest im Sinne von
 * [D-606](../../docs/NewConcept/90-decision-log.md) — er wird genannt, nicht gezaehlt.*
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
// ⚠️ *Namensabfragen gehen ueber die Sicht — `nodes.name` gibt es seit TASK-019 nicht mehr
// ([D-580](../../docs/NewConcept/90-decision-log.md)).*
$nodesNamed = Schema::table('nodes_named');
$relationsTable = Schema::table('relations');

echo "\n== 1. Der Ast ist auffindbar ==\n";

$branch = $wpdb->get_row(
    "SELECT id, path FROM {$nodesNamed} WHERE name = 'Settings' AND path NOT LIKE '%.%.%'"
);

check('die Astwurzel `Settings` steht direkt unter der Wurzel', $branch !== null);

if ($branch === null) {
    echo "\n$ok ok, $bad fehlgeschlagen\n";

    exit(1);
}

$rows = $wpdb->get_results($wpdb->prepare(
    "SELECT id, name, path FROM {$nodesNamed} WHERE path LIKE %s ORDER BY path",
    $wpdb->esc_like($branch->path . '.') . '%'
), ARRAY_A);

check('und traegt Knoten', $rows !== [], (string) count($rows));

echo "\n== 2. Jeder Knoten im Ast wird als Einstellung erkannt ==\n";

// ⚠️ *Eine Abfrage fuer alle zusammen (`CD-7`) — der Lauf ueber die Kanten ist gebuendelt.*
$sorten = (new \Taxmod\WordPress\Persistence\WpdbNodeRepository())
    ->resolvedFieldTypes(array_map(static fn (array $r): int => (int) $r['id'], $rows));

$depth   = substr_count((string) $branch->path, '.') + 2;
$fehlend = [];
$rest    = [];

foreach ($rows as $row) {
    $id   = (int) $row['id'];
    $kind = ($sorten[$id] ?? null)?->value;

    if ($kind === FieldType::Setting->value) {
        continue;
    }

    // Rest im Sinne von D-606: direktes Astkind, auf das keine Kante zeigt und das selbst keine
    // haelt. Zaehlt nicht als Fehler, wird aber genannt.
    if (substr_count((string) $row['path'], '.') + 1 === $depth) {
        $incoming = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$relationsTable} WHERE to_node_id = %d AND kind <> 'inheritance'",
            $id
        ));
        $outgoing = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$relationsTable} WHERE from_node_id = %d",
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
    'kein Knoten im Ast, den die Kante nicht als Einstellung ausweist',
    $fehlend === [],
    implode('; ', $fehlend)
);

if ($rest !== []) {
    printf("  HINWEIS Rest im Ast, auf den nichts zeigt: %s\n", implode('; ', $rest));
}

// ⚠️ **Der dritte Abschnitt ist mit der Spalte gefallen** ([D-621](../../docs/NewConcept/90-decision-log.md)).
// *Er hielt fest, dass `field_type` ein echtes `NULL` traegt und keine leere Zeichenkette
// ([D-519](../../docs/NewConcept/90-decision-log.md)) und keinen Wert, den der Code nicht kennt.
// **Beides ist gegenstandslos, weil es die Spalte nicht mehr gibt** — und was an ihre Stelle tritt,
// bewacht `field-type-gone-check.php`: dass sie nicht zurueckkommt, und dass die Kantenart nur die
// drei bekannten Werte traegt ([D-639](../../docs/NewConcept/90-decision-log.md)). **Entschaerft ist
// hier nichts; die Frage hat einen anderen Ort.**

printf("\n%d ok, %d fehlgeschlagen\n", $ok, $bad);

exit($bad === 0 ? 0 : 1);
