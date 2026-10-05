<?php declare(strict_types=1);
/**
 * Jeder Knoten trägt eine Klasse — und die Klasse kommt beim Anlegen vom Vater (TASK-092).
 *
 *     php scripts/dev/klasse-check.php [path/to/wordpress]
 *
 * ⚠️ **Schritt 1 des Bauplans** ([`einstellungen-bauplan.md`](../../docs/einstellungen-bauplan.md)),
 * die Beschlüsse [D-716](../../docs/NewConcept/90-decision-log.md), [D-719](../../docs/NewConcept/90-decision-log.md),
 * [D-723](../../docs/NewConcept/90-decision-log.md). Geprüft wird viererlei:
 *
 * 1. **Die Spalte gibt es**, lebend, im Schatten und in der Sicht.
 * 2. **Jeder lebende Knoten nennt eine Klasse aus dem Inventar** — keine leere, keine fremde.
 * 3. **Die ausgelieferten Knoten tragen die Klasse aus K3**, die er bestätigt hat.
 * 4. **Auf der Wiese `__kl`:** ein Kind bekommt die Vorwahl des Vaters; eine erlaubte Klasse wird
 *    genommen; eine nicht erlaubte wird abgewiesen; die Klasse überlebt ein Umbenennen; die Kopie
 *    hat die Klasse des Originals; der Baum schreibt die Klasse an und zeichnet das Icon der Klasse.
 *
 * @see docs/einstellungen-anforderungen.md
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

define('WP_ADMIN', true);
define('WP_USE_THEMES', false);
require $root . '/wp-load.php';

// ⚠️ **Kein Wächter schreibt in das Modell des Eigentümers** — die Klammer dreht am Ende
// alles zurück, auch nach einem Abbruch. Siehe `lib/no-write.php` und `tests/README.md`.
require __DIR__ . '/lib/no-write.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

wp_set_current_user(1);

use Taxmod\Core\Exception\ClassNotAllowedUnder;
use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\NodeClass\{Category, Choice, Constant, Contracts, Unit, UnitValue};
use Taxmod\Core\Model\Type\{IntType, SpecialisedTypes};
use Taxmod\Core\Service\ModelEditor;
use Taxmod\WordPress\Persistence\{Schema, SeededFrameworkNodes, UnitScaffold, WpdbChangelog, WpdbNodeRepository, WpdbRelationRepository};
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

function hatSpalte(string $tabelle, string $spalte): bool
{
    global $wpdb;

    return $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$tabelle} LIKE %s", $spalte)) !== null;
}

$nodes   = Schema::table('nodes');
$shadow  = Schema::table('nodes_history');
$benannt = Schema::table('nodes_named');

echo "1 · Die Spalte steht, lebend, im Schatten und in der Sicht\n";

check('nodes.klasse', hatSpalte($nodes, 'klasse'));
check('nodes_history.klasse', hatSpalte($shadow, 'klasse'), 'der Schatten zieht mit');
check('nodes_named.klasse', hatSpalte($benannt, 'klasse'), 'die Sicht ist nach der Spalte neu gebaut');

if ($bad > 0) {
    echo "\nOhne die Spalte hat der Rest nichts zu prüfen.\n";

    exit(1);
}

echo "\n2 · Jeder lebende Knoten nennt eine Klasse aus dem Inventar\n";

$zeilen = $wpdb->get_results("SELECT id, name, klasse FROM {$benannt} ORDER BY id", ARRAY_A) ?: [];
$fremd  = [];

foreach ($zeilen as $zeile) {
    if (! Contracts::isKnown((string) $zeile['klasse'])) {
        $fremd[] = $zeile['id'] . ' «' . $zeile['name'] . '» → «' . $zeile['klasse'] . '»';
    }
}

check('jeder der ' . count($zeilen) . ' Knoten trägt eine Klasse aus dem Inventar', $fremd === [], implode(' · ', array_slice($fremd, 0, 5)));
check('das Inventar hat ' . count(Contracts::all()) . ' Klassen: fünf und die ' . count(SpecialisedTypes::CLASSES) . ' Typen', count(Contracts::all()) === 5 + count(SpecialisedTypes::CLASSES));

echo "\n3 · Die ausgelieferten Knoten tragen die Klasse aus K3\n";

$klasseVon = static function (int $id) use ($wpdb, $nodes): string {
    return (string) $wpdb->get_var($wpdb->prepare("SELECT klasse FROM {$nodes} WHERE id = %d", $id));
};

$log       = new WpdbChangelog(new SystemClock());
$knoten    = new WpdbNodeRepository();
$kanten    = new WpdbRelationRepository();
$framework = new SeededFrameworkNodes($knoten, $kanten, $log);
$editor    = new ModelEditor($knoten, $kanten, $framework, $log);

check('Root ist eine Kategorie', $klasseVon($framework->root()->id) === Category::class);
check('Model ist eine Kategorie', $klasseVon($framework->rootOf(Branch::Model)->id) === Category::class);
check('Constants ist eine Kategorie', $klasseVon($framework->rootOf(Branch::Constants)->id) === Category::class);

$integer = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$nodes} WHERE implemented_by = %s ORDER BY id LIMIT 1", IntType::class));
check('Integer ist seine eigene Typklasse', $integer !== 0 && $klasseVon($integer) === IntType::class);

$typenOhne = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$nodes} WHERE implemented_by IN ('" . implode("','", array_map('esc_sql', SpecialisedTypes::CLASSES)) . "') AND klasse <> implemented_by"
);
check('jeder der elf Typknoten ist seine Typklasse', $typenOhne === 0, $typenOhne . ' weichen ab');

$prefixes = (int) (UnitScaffold::nodeId('Prefixes') ?? 0);
$base     = (int) (UnitScaffold::nodeId('Base units') ?? 0);
$kilo     = (int) (UnitScaffold::nodeId('kilo') ?? 0);
$gramm    = (int) (UnitScaffold::nodeId('Gramm') ?? 0);
$celsius  = (int) (UnitScaffold::nodeId('Celsius') ?? 0);
$ew       = (int) (UnitScaffold::unitValueId() ?? 0);
$roles    = (int) get_option('taxmod_roles_id', 0);

check('Prefixes ist eine Auswahl', $prefixes !== 0 && $klasseVon($prefixes) === Choice::class);
check('kilo ist eine Konstante', $kilo !== 0 && $klasseVon($kilo) === Constant::class);
check('Base units ist eine Auswahl', $base !== 0 && $klasseVon($base) === Choice::class);
check('Gramm ist ein Einheitswert', $gramm !== 0 && $klasseVon($gramm) === Unit::class);
check('Celsius ist ein Einheitswert', $celsius !== 0 && $klasseVon($celsius) === Unit::class);
check('Einheitenwert ist ein Einheitenwert', $ew !== 0 && $klasseVon($ew) === UnitValue::class);
check('Label roles ist eine Auswahl', $roles !== 0 && $klasseVon($roles) === Choice::class);

$rollenFremd = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$nodes} WHERE parent_node_id = %d AND klasse <> %s", $roles, Constant::class));
check('jede Rolle ist eine Konstante', $rollenFremd === 0);

echo "\n4 · Auf der Wiese: die Klasse kommt vom Vater\n";

$wiese = $editor->createNode('__kl Wiese', $framework->rootOf(Branch::Model)->id);
check('ein Kind unter Model ist eine Kategorie (die Vorwahl)', $wiese->klasse === Category::class && $klasseVon($wiese->id) === Category::class);

$praefix = $editor->createNode('__kl präfix', $prefixes);
check('ein Kind unter Prefixes ist eine Konstante (die Vorwahl der Auswahl)', $praefix->klasse === Constant::class);

$eigene = $editor->createNode('__kl Auswahl', $wiese->id, Choice::class);
check('eine gewählte, erlaubte Klasse wird genommen', $eigene->klasse === Choice::class && $klasseVon($eigene->id) === Choice::class);

$abgewiesen = false;

try {
    $editor->createNode('__kl falsch', $prefixes, IntType::class);
} catch (ClassNotAllowedUnder) {
    $abgewiesen = true;
}

check('ein Integer unter Prefixes wird abgewiesen', $abgewiesen);

$unterInteger = $editor->createNode('__kl Zahl', $integer);
check('ein Kind unter Integer ist ein Integer', $unterInteger->klasse === IntType::class);

$umbenannt = $editor->rename($eigene->id, '__kl Auswahl 2');
check('ein Umbenennen lässt die Klasse stehen', $umbenannt->klasse === Choice::class && $klasseVon($eigene->id) === Choice::class);

$kopie = $editor->duplicate($eigene->id);
check('die Kopie hat die Klasse des Originals', $kopie->klasse === Choice::class);

$geladen = $knoten->byId($eigene->id);
check('frisch gelesen trägt der Knoten seine Klasse', $geladen->klasse === Choice::class);

echo "\n5 · Der Baum schreibt die Klasse an und zeichnet ihr Icon\n";

$r      = new ReflectionClass(\Taxmod\WordPress\Plugin::class);
$plugin = $r->newInstanceWithoutConstructor();
$r->getProperty('file')->setValue($plugin, dirname(__DIR__, 2) . '/wp-taxonomy-modeler.php');

$_GET['taxmod_collapsed'] = 'none';
$_GET['taxmod_node']      = (string) $eigene->id;

$markup = $plugin->screen()->render();
$zeile  = substr($markup, (int) strpos($markup, 'id="taxmod-node-' . $eigene->id . '"'), 600);

check('die Zeile der Auswahl nennt «Choice»', str_contains($zeile, 'taxmod-tree-class') && str_contains($zeile, \Taxmod\WordPress\Admin\NodesScreen::className(Choice::class)));
check('die Zeile trägt das Icon der Klasse', str_contains($zeile, 'dashicons-' . Contracts::of(Choice::class)->icon));
check('der Wähler bietet unter der Auswahl die Konstante vorgewählt an', preg_match('/<select name="klasse"[^>]*>.*?<option value="' . preg_quote(Constant::class, '/') . '" selected>/s', $markup) === 1);

printf("\n%d ok, %d fehlgeschlagen\n", $ok, $bad);
echo $bad === 0 ? "all green\n" : "$bad FEHLER\n";

exit($bad === 0 ? 0 : 1);
