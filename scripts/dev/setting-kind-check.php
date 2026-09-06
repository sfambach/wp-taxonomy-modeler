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

// ⚠️ **Kein Wächter schreibt in das Modell des Eigentümers** — die Klammer dreht am Ende
// alles zurück, auch nach einem Abbruch. Siehe `lib/no-write.php` und `tests/README.md`.
require __DIR__ . '/lib/no-write.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\FieldType;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRelationRepository;
use Taxmod\WordPress\SystemClock;

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

// ⚠️ *Der Ast wird ueber seine **Rolle** geholt und nicht ueber den Namen `Settings`
// ([D-613](../../docs/NewConcept/90-decision-log.md): «Kein Waechter sucht einen Knoten ueber seinen
// Namen»). **Die Zusage ist dieselbe geblieben** — der Ast steht direkt unter der Wurzel und traegt
// Knoten —, nur die Adresse ist die, die der Kode ohnehin benutzt.*
$astKnoten = (new SeededFrameworkNodes(
    new WpdbNodeRepository(),
    new WpdbRelationRepository(),
    new WpdbChangelog(new SystemClock())
))->rootOf(Branch::Settings);

// ⚠️ *Der Weg ist seit Fassung 35 keine Spalte mehr (TASK-001); «direkt unter der Wurzel» heisst
// jetzt, was es immer hiess — der Vater hat selbst keinen Vater.*
$speicher = new WpdbNodeRepository();
$branch   = $speicher->find($astKnoten->id);

check(
    'die Astwurzel des Einstellungsastes steht direkt unter der Wurzel',
    $branch !== null && $branch->parentNodeId !== null && $speicher->byId($branch->parentNodeId)->parentNodeId === null
);

if ($branch === null) {
    echo "\n$ok ok, $bad fehlgeschlagen\n";

    exit(1);
}

// ⚠️ *«Alles unter dieser Astwurzel» fragt jetzt der Speicher — dieselbe Antwort wie im Kode, den
// dieser Lauf prueft, statt eines `LIKE` auf eine gefallene Spalte (TASK-001).*
$unten   = array_values(array_diff($speicher->subtreeIds($branch->id), [$branch->id]));
$plaetze = implode(',', array_fill(0, max(1, count($unten)), '%d'));

$rows = $unten === [] ? [] : $wpdb->get_results($wpdb->prepare(
    "SELECT id, name FROM {$nodesNamed} WHERE id IN ({$plaetze}) ORDER BY id",
    ...$unten
), ARRAY_A);

// ⚠️ *Die Tiefe kommt aus dem gerechneten Weg des geladenen Knotens, nicht aus einer Spalte.*
$wegeImAst = [];

foreach ($speicher->byIds($unten) as $einer) {
    $wegeImAst[$einer->id] = $einer->path;
}

check('und traegt Knoten', $rows !== [], (string) count($rows));

echo "\n== 2. Jeder Knoten im Ast wird als Einstellung erkannt ==\n";

// ⚠️ *Eine Abfrage fuer alle zusammen (`CD-7`) — der Lauf ueber die Kanten ist gebuendelt.*
$sorten = (new \Taxmod\WordPress\Persistence\WpdbNodeRepository())
    ->resolvedFieldTypes(array_map(static fn (array $r): int => (int) $r['id'], $rows));

$depth   = substr_count($branch->path, '.') + 2;
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
    if (substr_count($wegeImAst[$id] ?? '', '.') + 1 === $depth) {
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
