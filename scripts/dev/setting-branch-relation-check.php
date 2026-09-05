<?php declare(strict_types=1);
/**
 * Jede Kante, die in den Einstellungsast zeigt, ist eine Einstellungskante.
 *
 *     php scripts/dev/setting-branch-relation-check.php [path/to/wordpress]
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

// ⚠️ *Seit TASK-019 traegt jeder Knoten eine Beschriftungszeile ([D-580](../../docs/NewConcept/90-decision-log.md)) —
// und dieser Lauf raeumt Knoten mit rohem SQL weg, also am Ende hinter sich her. **Es faellt nur,
// worauf weder ein Knoten noch eine Kante zeigt.***
register_shutdown_function(static fn (): int => \Taxmod\WordPress\Persistence\Schema::forgetOrphanLabels());

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
        'SELECT id, from_node_id, to_node_id, kind, name FROM ' . Schema::table('relations_named')
    );

    $treffer = [];
    $gezaehlt = 0;

    foreach ($zeilen as $zeile) {
        // ⚠️ *Hier stand die Ausnahme für Vererbungskanten — **es gibt keine mehr** (TASK-018,
        // [D-581](../../NewConcept/90-decision-log.md)). Der Ast wird von `nodes.parent_node_id`
        // gebaut, und diese Tabelle trägt nur noch Felder und Einstellungen.*

        $ziel = $nodes->find((int) $zeile->to_node_id);

        if (! $ziel instanceof Node || $framework->branchOf($ziel) !== Branch::Settings) {
            continue;
        }

        $gezaehlt++;

        if ($zeile->kind !== RelationKind::Setting->value) {
            $treffer[] = [
                'id'   => (int) $zeile->id,
                'name' => (string) $zeile->name,
                'from' => (int) $zeile->from_node_id,
                'to'   => (int) $zeile->to_node_id,
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
            'DELETE FROM ' . Schema::table('relations') . ' WHERE from_node_id = %d OR to_node_id = %d',
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

    // ⚠️ **Der Gegenfall wird jetzt **gesagt** und nicht mehr geerbt** (TASK-053,
    // [D-618](../../docs/NewConcept/90-decision-log.md)). *Vorher stand hier: «`addField()` leitet
    // die Art aus dem Zielast ab — und `Branch::Settings->relationKind()` sagt `aggregation`, nicht
    // `setting`; der Gegenfall entsteht auf dem gewoehnlichen Weg.» **Der gewoehnliche Weg ist nicht
    // mehr das Ableiten**, also haengt die Abweichung nicht laenger an einem Verhalten, das gerade
    // abgeloest wurde — sie wird benannt.*
    $kante = $editor->addField($besitzer->id, $ziel->id, '__astkante_feld', RelationKind::Aggregation);

    check(
        'die angegebene Art kommt an der Kante an',
        $kante->kind === RelationKind::Aggregation,
        'die Kante traegt ' . $kante->kind->value
    );

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
    echo "\n== 3b. Die Art wird angegeben — der Weg ueber die Maske (TASK-053) ==\n";

    // ⚠️ **Ueber die Maske und nicht ueber den Kern.** *Ein Waechter, der `addField(..., $art)` ruft,
    // prueft die Haelfte, die er selbst mitbringt. Die Frage aus D-618 ist, ob die **Seite** die Art
    // ueberhaupt fragt — und ob das, was sie schickt, an der Kante ankommt. `move-mask-check` und
    // `renderer-choice-mask-check` gehen denselben Weg.*
    wp_set_current_user(1);

    $rc     = new ReflectionClass(\Taxmod\WordPress\Plugin::class);
    $plugin = $rc->newInstanceWithoutConstructor();
    $rc->getProperty('file')->setValue($plugin, dirname(__DIR__, 2) . '/wp-taxonomy-modeler.php');
    $screen = $plugin->screen();

    $_GET['page']        = 'taxmod-nodes';
    $_GET['taxmod_node'] = (string) $besitzer->id;

    $markup = $screen->render();

    check(
        'die Seite zeichnet einen Waehler fuer die Kantenart',
        (bool) preg_match('/<select[^>]*name="relation_kind"/', $markup),
        'kein Steuerelement namens relation_kind'
    );

    foreach (RelationKind::cases() as $art) {
        check(
            "sie bietet «{$art->value}» an",
            (bool) preg_match('/<option value="' . $art->value . '"/', $markup)
        );
    }

    // ⚠️ *Genau drei, weil es seit [D-639](../../docs/NewConcept/90-decision-log.md) genau drei
    // Werte mit je einer Klasse gibt. Ein vierter waere ein Wert ohne Klasse. **In diesem Waehler
    // gezaehlt und nicht auf der Seite** — die Seite traegt weitere Auswahllisten.*
    preg_match('/<select[^>]*name="relation_kind".*?<\/select>/s', $markup, $waehler);

    check(
        'und keinen vierten',
        preg_match_all('/<option value="/', $waehler[0] ?? '') === count(RelationKind::cases()),
        (string) preg_match_all('/<option value="/', $waehler[0] ?? '')
    );

    // ⚠️ **Der Satz, der nie wahr war, ist weg** ([D-618](../../docs/NewConcept/90-decision-log.md)).
    // *«‹Kind› is not a choice — it follows from where the target sits in the tree.» Er stand unter
    // beiden Feldtabellen. Solange er dasteht, sagt die Oberflaeche das Gegenteil des Aktes.*
    check(
        'die Oberflaeche behauptet nicht mehr, die Art sei keine Wahl',
        ! str_contains($markup, 'is not a choice'),
        'der Satz steht noch da'
    );

    // ⚠️ *Ein Steuerelement ausserhalb des Formulars schickt lautlos nichts mit — dann kaeme keine
    // Art an, der Kern nutzte seinen Rueckfall, und der Waechter saehe den Unterschied nicht.*
    $vorWaehler = substr($markup, 0, (int) strpos($markup, 'name="relation_kind"'));

    check(
        'der Waehler steckt im selben Formular wie der Anlegen-Knopf',
        substr_count($vorWaehler, '<form') - substr_count($vorWaehler, '</form>') === 1,
        'Formulartiefe ' . (substr_count($vorWaehler, '<form') - substr_count($vorWaehler, '</form>'))
    );

    // ── Abschicken, und nachsehen, was an der Kante steht ────────────────────────────────────────
    //
    // ⚠️ **Das Ziel liegt im Einstellungsast, die Angabe sagt `composition`.** *Genau der Fall, den
    // die Ableitung nicht bauen konnte: der Ast haette `aggregation` gesagt. Kommt `composition` an,
    // hat die Angabe gewonnen und nicht der Ast.*
    $lief = false;
    $fang = static function () use (&$lief): string {
        $lief = true;

        throw new RuntimeException('redirect');
    };

    $_POST = $_REQUEST = [
        'do'            => 'add_field',
        'id'            => (string) $besitzer->id,
        'field_target'  => (string) $ziel->id,
        'name'          => '__astkante_maske',
        'relation_kind' => 'composition',
        '_taxmod_nonce' => wp_create_nonce('taxmod_node_' . $besitzer->id),
    ];

    add_filter('wp_redirect', $fang, 1);

    try {
        $screen->handlePost();
    } catch (RuntimeException) {
        // Erwartet: der Akt ist durch und wollte weiterleiten.
    } finally {
        remove_filter('wp_redirect', $fang, 1);

        $_POST = $_REQUEST = [];
    }

    check('der Akt ist durchgelaufen', $lief);

    // Frisch nachgelesen, nicht aus dem Gedaechtnis.
    $ausMaske = $wpdb->get_row($wpdb->prepare(
        'SELECT id, kind FROM ' . Schema::table('relations_named') . ' WHERE name = %s',
        '__astkante_maske'
    ));

    check('die Maske hat eine Kante angelegt', $ausMaske !== null);

    if ($ausMaske !== null) {
        check(
            'sie traegt die angegebene Art und nicht die des Zielastes',
            $ausMaske->kind === RelationKind::Composition->value,
            'sie traegt ' . (string) $ausMaske->kind
        );

        // ⚠️ *Und damit ist sie eine Abweichung — die Regel aus Abschnitt 1 muss sie sehen. **Das ist
        // die Naht zwischen den beiden Haelften dieser Aufgabe:** wer die Art frei angeben darf, kann
        // eine Kante in den Ast legen, die keine Einstellungskante ist, und genau dafuer gibt es
        // diesen Waechter.*
        check(
            'und faellt der Regel aus Abschnitt 1 auf',
            in_array((int) $ausMaske->id, array_column(abweichungen($nodes, $framework), 'id'), true),
            'die Regel hat sie durchgelassen'
        );

        // ⚠️ *Die Kante selbst raeumt `$abbauen()` mit ihren beiden Knoten weg; ihre Beschriftung
        // faellt am Ende des Laufs als Waise ({@see forgetOrphanLabels()} oben).*
    }
} finally {
    $abbauen();
}

echo "\n== 4. Weggeraeumt ==\n";

$rest = $wpdb->get_var(
    "SELECT COUNT(*) FROM " . Schema::table('nodes_named') . " WHERE name LIKE '__astkante%'"
);

check('kein eigener Knoten bleibt stehen', (int) $rest === 0, "{$rest} stehen noch da");

echo "\n{$bad} fehlgeschlagen, {$ok} in Ordnung\n";

exit($bad === 0 ? 0 : 1);
