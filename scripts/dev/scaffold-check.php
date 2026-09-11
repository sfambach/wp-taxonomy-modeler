<?php declare(strict_types=1);
/**
 * Base scaffold acceptance check — the simple data types, against a real database.
 *
 *     php scripts/dev/scaffold-check.php [path/to/wordpress]
 *
 * The owner's test: the simple types are there to build models with, and a type he throws away
 * stays thrown away.
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
use Taxmod\Core\Model\RelationKind;
use Taxmod\Core\Model\SettingShape;
use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\WordPress\Persistence\BaseScaffold;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\SeededTypeNodes;
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
$relations     = new WpdbRelationRepository();
$framework = new SeededFrameworkNodes($nodes, $relations, new WpdbChangelog(new SystemClock()));
$editor    = new ModelEditor($nodes, $relations, $framework, new WpdbChangelog(new SystemClock()));
$types     = new SeededTypeNodes($nodes, $framework);
$scaffold  = new BaseScaffold($editor, $framework, $types);

$dataTypes = $framework->rootOf(Branch::DataTypes);

echo "\n== 1. Every simple type is there ==\n";
$scaffold->import();

$present = [];

// ⚠️ **Found by the id the seed wrote down, not by the node's name** ([D-510](../../docs/NewConcept/90-decision-log.md)).
// *This is the line that has to change, not only the code under test: keying by name here would leave
// the check green whatever the binding did — and a check that is green for the wrong reason is worse
// than none. The nodes are called `Integer` and `Text`; the enum value stayed `int` and `text`, so
// every `$present['int']` below keeps meaning what it always meant.*
$byId = [];

foreach ($editor->childrenOf($dataTypes->id) as $child) {
    $byId[$child->id] = $child;
}

foreach (SimpleType::cases() as $type) {
    $id = $types->nodeId($type);

    if ($id !== null && isset($byId[$id])) {
        $present[$type->value] = $byId[$id];
    }
}

foreach (SimpleType::cases() as $type) {
    check($type->value, isset($present[$type->value]));
}

echo "\n== 2. They sit in the Data Types branch, so the kind follows by itself ==\n";
$text = $present['text'] ?? null;
check('text is under Data Types', $text !== null && $framework->branchOf($text) === Branch::DataTypes);
check('and a Data Types target is reached by composition',
    Branch::DataTypes->relationKind() === RelationKind::Composition);
check('and has no records of its own — the value sits in the holder\'s record',
    Branch::DataTypes->holdsData() === false);

echo "\n== 3. An attribute pointing at one gets its kind without being asked ==\n";
$thing = $editor->createNode('__sc thing', $framework->rootOf(Branch::Model)->id);
$relation  = $editor->addField($thing->id, $present['int']->id, '__sc count');
check('the kind is composition', $relation->kind === RelationKind::Composition, $relation->kind->value);

echo "\n== 4. Imported once, then hands off (D-119) ==\n";
$before = (int) get_option(BaseScaffold::OPTION, 0);
check('the scaffold records that it was delivered', $before >= 1, "option = $before");

$colour = $present['color'] ?? null;

if ($colour !== null) {
    $editor->moveToTrash($colour->id);
    $again = $scaffold->importOnce();
    check('a type the owner threw away is not put back', $again === [], implode(', ', $again));

    $parked = $nodes->byId($colour->id);
    check('and it is still in the trash where he put it',
        str_starts_with($parked->path, $framework->trash()->path . '.'), $parked->path);

    // Put it back so the check leaves the model as it found it.
    $editor->restore($colour->id);
}

echo "\n== 5. The check cleans up after itself ==\n";

// ⚠️ **By name and not only by this run's id** — the same self-healing `package7-check` already has,
// and it is here because two runs of mine died before this line on 2026-08-26 and left a `__sc thing`
// each. *A cleanup that only knows the ids of the run it is in reports the **previous** run's litter
// as its own failure, which is the least useful thing a check can say.*
foreach ($wpdb->get_col('SELECT id FROM ' . Schema::table('nodes_named') . ' WHERE name LIKE "\\_\\_sc%" ORDER BY id DESC' /* tiefste zuerst: ein Kind hat immer die groessere Id als sein Vater; `LENGTH(path)` ging mit der Spalte (TASK-001) */) as $stale) {
    $node = $nodes->find((int) $stale);

    if ($node !== null) {
        $relations->purgeRelationsTouching($node->id);
        $nodes->purgeSubtree($node);
    }
}

// ⚠️ *The loop above already takes this run's node — it matches the same pattern. Looking it up
// again unguarded is how the first version of this cleanup threw `NodeNotFound` on its own success.*
$still = $nodes->find($thing->id);

if ($still !== null) {
    $relations->purgeRelationsTouching($still->id);
    $nodes->purgeSubtree($still);
}
$wpdb->query('DELETE FROM ' . Schema::table('relations') . ' WHERE id IN (SELECT id FROM (SELECT id FROM ' . Schema::table('relations_named') . ' WHERE name LIKE "__sc%") x)');
$wpdb->query('DELETE FROM ' . Schema::table('changelog') . ' WHERE after_state LIKE "%__sc%"');
$left = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Schema::table('nodes_named') . ' WHERE name LIKE "__sc%"');
check('scratch nodes are gone', $left === 0, "$left left");

$colourNow = $colour === null ? null : $nodes->byId($colour->id);
check('and color is back under Data Types',
    $colourNow === null || str_starts_with($colourNow->path, $dataTypes->path . '.'),
    $colourNow?->path ?? '—');

// ⚠️ **[D-401](../../docs/NewConcept/90-decision-log.md): every switch has one declared default, and
// it lives on the key.** Before this, three keys had three homes — `persistent` was written onto the
// installation identity by hand, `hide` and `read_only` were invented inside readers as `?? false`, and
// that is how the data layer came to read `persistent` as **on** while the switch drew it **off**.
echo "\n== 6. Every switch declares its default in one place (D-401) ==\n";

// ⚠️ **Die zweite Haelfte dieser Zusicherung ist mit der Tabelle gefallen** (D-579): *gefragt wurde,
// ob eine **Zeile** an der Installation die Vorgabe eines Schalters wiederholt. Es gibt keine Zeilen
// mehr, also kann keine sie wiederholen — **die Vorgabe lebt nur noch am Schluessel**, und genau das
// ist es, was hier jetzt gemessen wird.*

// ⚠️ **Umgedreht ein zweites Mal, am 2026-09-11, und wieder als sichtbarer Teil einer
// Konzeptänderung** ([`PR-9`](../../CLAUDE.md)): *der letzte Schalter, `read_only`, ist eine Spalte
// der Kante geworden ([D-714](../../docs/NewConcept/90-decision-log.md)). **Kein Schlüssel ist mehr
// ein Schalter**, und die Vorgabe eines Schalters lebt an der Spalte (`DEFAULT 0`), nicht am
// Schlüssel. Was hier bleibt, ist die Gegenprobe.*
// ⚠️ **Hier standen fünf Zusagen zur Aufzählung SettingKey** *— seit Schritt 7 des Bauplans (2026-09-11): Renderer, Konverter und Validatoren sind
// Objekte programmierter Klassen, keine Knoten; die Einstellungskanten und der Ast `Settings` sind in den Schatten
// gewandert ([D-712](../../docs/NewConcept/90-decision-log.md), [D-718](../../docs/NewConcept/90-decision-log.md)).*
$vertragInt = \Taxmod\Core\Model\NodeClass\Contracts::of(\Taxmod\Core\Model\Type\IntType::class);
check('kein Attribut des Vertrags heisst read_only — read_only ist eine Spalte der Kante (D-714)', $vertragInt->attribute('read_only') === null);
check('und keines heisst multiplicity (D-713)', $vertragInt->attribute('multiplicity') === null);
check('min, max, step und display_size erklärt der Vertrag von Integer', $vertragInt->attribute('min') !== null && $vertragInt->attribute('max') !== null && $vertragInt->attribute('step') !== null && $vertragInt->attribute('display_size') !== null);

// ⚠️ **And the point of the whole thing, measured at a node rather than at the installation**: a node
// with no row of its own resolves all three, because the installation is the first link of the chain
// ([D-079](../../docs/NewConcept/90-decision-log.md)) — so no reader ever needs a fallback.
// ⚠️ *Hier stand die Probe am Knoten — je Schalter eine Antwort. Ohne Schalter gibt es nichts zu
// fragen; die Antwort eines Felds auf «nur lesbar» steht in seiner Spalte und wird in
// `kantenspalten-check` gemessen.*

// ============================================================================
// Umgezogen am 2026-09-06: die zwei Zusagen aus `package1-check.php`, die
// sonst nirgends stehen
// ============================================================================
//
// ⚠️ **`package1-check.php` ist am 2026-09-06 gestrichen**
// ([`waechter-bestand.md`](../../docs/pakete/modelltabellen/waechter-bestand.md), auf sein Wort
// «checks mein ja»): *«Anlegen, umbenennen, Papierkorb» steht im Kernlauf und in `cleartrash`, seine
// Tabellen stehen in `shadow-shape`, `label-space` und `id-space`, sein Weg in `path-check`.*
// **Zwei Zusagen standen nirgends sonst** — *dass der Papierkorb unter der Wurzel haengt und dass die
// Wurzel geschuetzt ist. Sie handeln von den Geruestknoten, also stehen sie hier.* `PR-9`: umgezogen,
// nicht entschaerft.

echo "\n== 7. Die Geruestknoten selbst (umgezogen aus package1) ==\n";

$wurzel     = $framework->root();
$papierkorb = $framework->trash();

check(
    'trash sits under the root',
    $papierkorb->path === $wurzel->path . '.' . $papierkorb->id,
    $papierkorb->path
);

// ⚠️ *Ohne diese Zusage koennte ein Akt die Wurzel in den Papierkorb legen — und mit ihr das ganze
// Modell. Der Kernlauf prueft die Verweigerung an einem Fake; hier steht sie an den Knoten, die
// wirklich da sind.*
check('root is protected', $framework->isProtected($wurzel));

echo "\n---- $ok passed, $bad failed ----\n";
exit($bad === 0 ? 0 : 1);
