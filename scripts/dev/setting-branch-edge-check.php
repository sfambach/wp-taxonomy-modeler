<?php declare(strict_types=1);
/**
 * Jede Kante, die in den Einstellungsast zeigt, ist eine Einstellungskante.
 *
 *     php scripts/dev/setting-branch-edge-check.php [path/to/wordpress]
 *
 * ⚠️ **Er haelt einen Zustand fest, er stellt ihn nicht her**
 * ([D-618](../../docs/NewConcept/90-decision-log.md), TASK-053 Teil 3). *Gemessen am 2026-09-04
 * stimmte der Satz im Bestand, und beim Bau dieses Waechters am 2026-09-05 wieder: **vier Kanten
 * zeigen in den Ast, alle vier tragen `setting`.** Findet er eine Abweichung, meldet er sie —
 * **geaendert wird nichts**, weil eine Kante, die dort hinzeigt und keine Einstellung ist, entweder
 * ein Fehler im Schreiber oder eine Entscheidung des Eigentuemers ist. Beides gehoert gesehen, nicht
 * stillschweigend begradigt.*
 *
 * ⚠️ **Vererbungskanten sind ausgenommen, und zwar nicht als Ausnahme, sondern als anderer
 * Gegenstand:** *sie sind der **Ast selbst**. `Root --> Settings --> Renderer --> slider` sind
 * Vererbungskanten mit einem Ziel im Ast; sie zu `setting` zu erklaeren hiesse, den Baum in seine
 * eigenen Werte zu verwandeln.*
 *
 * ⚠️ **Der Gegenfall traegt hier mehr als die Zusage.** *Vier richtige Kanten sind auch dann vier
 * richtige Kanten, wenn die Pruefung gar nichts prueft. Der Waechter legt sich darum eine eigene
 * Abweichung an — ein `__astkante`-Knoten im Ast, ein Feld darauf — und verlangt, dass sie **rot**
 * wird; erst danach markiert er sie und verlangt, dass sie gruen wird. Am Ende raeumt er beides weg
 * ([D-613](../../docs/NewConcept/90-decision-log.md), [D-614](../../docs/NewConcept/90-decision-log.md)).*
 *
 * ⚠️ *Kein Knotenname wird gesucht. Der Ast kommt aus {@see SeededFrameworkNodes::branchOf()},
 * also aus den Optionen — genau der Punkt, den TASK-049 fuer zehn aeltere Waechter noch offen hat.*
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

use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\RelationKind;
use Taxmod\Core\Service\ModelEditor;
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

$nodes     = new WpdbNodeRepository();
$kanten    = new WpdbRelationRepository();
$log       = new WpdbChangelog(new SystemClock());
$framework = new SeededFrameworkNodes($nodes, $kanten, $log);
$editor    = new ModelEditor($nodes, $kanten, $framework, $log);

/**
 * Die Regel selbst, an einer einzigen Stelle: die Kanten, die in den Ast zeigen und keine
 * Einstellungskante sind.
 *
 * @return list<array{id:int,name:string,from:int,to:int,kind:string,ziel:string}>
 */
function abweichungen(
    WpdbNodeRepository $nodes,
    SeededFrameworkNodes $framework,
    ?callable $gefunden = null
): array {
    global $wpdb;

    $zeilen = $wpdb->get_results(
        'SELECT id, from_id, to_id, kind, name FROM ' . Schema::table('relations')
    );

    $treffer = [];
    $gezaehlt = 0;

    foreach ($zeilen as $zeile) {
        // ⚠️ *Der Ast ist der Ast: eine Vererbungskante **baut** ihn und ist keine Einstellung.*
        if ($zeile->kind === RelationKind::Inheritance->value) {
            continue;
        }

        $ziel = $nodes->find((int) $zeile->to_id);

        if (! $ziel instanceof Node || $framework->branchOf($ziel) !== Branch::Settings) {
            continue;
        }

        $gezaehlt++;

        if ($zeile->kind !== RelationKind::Setting->value) {
            $treffer[] = [
                'id'   => (int) $zeile->id,
                'name' => (string) $zeile->name,
                'from' => (int) $zeile->from_id,
                'to'   => (int) $zeile->to_id,
                'kind' => (string) $zeile->kind,
                'ziel' => $ziel->name,
            ];
        }
    }

    if ($gefunden !== null) {
        $gefunden($gezaehlt);
    }

    return $treffer;
}

echo "\n== 1. Der Bestand des Eigentuemers ==\n";

$imAst = 0;
$offen = abweichungen($nodes, $framework, function (int $n) use (&$imAst): void {
    $imAst = $n;
});

echo "  gemessen: {$imAst} Kanten zeigen in den Einstellungsast (Vererbung nicht gezaehlt)\n";

check(
    'jede von ihnen ist eine Einstellungskante',
    $offen === [],
    implode('; ', array_map(
        static fn (array $a): string => "Kante {$a['id']} «{$a['name']}» {$a['from']} -> {$a['to']} ({$a['ziel']}) traegt «{$a['kind']}»",
        $offen
    ))
);

// ⚠️ *Ohne diese Zeile waere «null Abweichungen» auch dann wahr, wenn der Ast leer ist oder die
// Optionen auf nichts zeigen — die Zusage haette keinen Gegenstand mehr und niemand merkte es.*
check('und es gibt ueberhaupt welche zu pruefen', $imAst > 0, 'keine einzige Kante zeigt in den Ast');

echo "\n== 2. Der Gegenfall — findet die Regel eine Abweichung, die es gibt? ==\n";

/** @var list<int> $gebaut */
$gebaut = [];

$abbauen = function () use (&$gebaut, $wpdb): void {
    foreach (array_reverse($gebaut) as $id) {
        $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . Schema::table('relations') . ' WHERE from_id = %d OR to_id = %d',
            $id,
            $id
        ));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('nodes') . ' WHERE id = %d', $id));
    }

    $gebaut = [];
};

try {
    $astwurzel = $framework->rootOf(Branch::Settings);

    $ziel = $editor->createNode('__astkante Ziel', $astwurzel->id);
    $gebaut[] = $ziel->id;

    $besitzer = $editor->createNode('__astkante Besitzer', $framework->rootOf(Branch::Model)->id);
    $gebaut[] = $besitzer->id;

    // ⚠️ **Genau das ist der Fall aus D-618:** *`addField()` leitet die Art heute aus dem Zielast ab
    // — und `Branch::Settings->relationKind()` sagt `aggregation`, nicht `setting`. Der Gegenfall
    // muss darum nicht gebastelt werden; er entsteht auf dem gewoehnlichen Weg.*
    $kante = $editor->addField($besitzer->id, $ziel->id, '__astkante_feld');

    $gefunden = abweichungen($nodes, $framework);
    $ids      = array_column($gefunden, 'id');

    check(
        'eine Kante in den Ast, die keine Einstellungskante ist, faellt auf',
        in_array($kante->id, $ids, true),
        'die Regel hat sie durchgelassen — dann sagt Abschnitt 1 nichts'
    );

    echo "\n== 3. Und laesst sie die richtige Kante in Ruhe? ==\n";

    $editor->markAsSetting($besitzer->id, $kante->id, true);

    $danach = array_column(abweichungen($nodes, $framework), 'id');

    check(
        'dieselbe Kante als Einstellungskante geht durch',
        ! in_array($kante->id, $danach, true),
        'sie wird weiter gemeldet — dann meldet Abschnitt 1 jede Kante'
    );
} finally {
    $abbauen();
}

echo "\n== 4. Weggeraeumt ==\n";

$rest = $wpdb->get_var(
    "SELECT COUNT(*) FROM " . Schema::table('nodes') . " WHERE name LIKE '__astkante%'"
);

check('kein eigener Knoten bleibt stehen', (int) $rest === 0, "{$rest} stehen noch da");

echo "\n{$bad} fehlgeschlagen, {$ok} in Ordnung\n";

exit($bad === 0 ? 0 : 1);
