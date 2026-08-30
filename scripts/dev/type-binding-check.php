<?php declare(strict_types=1);
/**
 * Die Bindung der einfachen Datentypen — über die **Id**, nicht über den Namen.
 *
 *     php scripts/dev/type-binding-check.php [path/to/wordpress]
 *
 * ⚠️ **[D-510](../../docs/NewConcept/90-decision-log.md):** *«Der Code findet seine gesäten Knoten
 * über die Id, nicht über den Namen. Ein Name ist eine Beschriftung und darf sich ändern.»* Und der
 * Grund ist an dem Tag gemessen worden: **eine Prüfung suchte einen Knoten namens `int` — er heisst
 * `Integer`.** Sie lief nie und hat drei Tage lang einen Widerspruch konserviert.
 *
 * ⚠️ **Was diese Prüfung misst und was sie nicht misst.** *Sie misst die Bindung: dass die Optionen
 * dastehen, dass ein Doppelgänger nicht antwortet, und dass der Notnagel eine fehlende Option
 * nachträgt. **Sie misst nicht**, dass eine frische Installation die Zahlengrenzen bekommt — die
 * stehen auf dieser Installation seit vor der Umbenennung, und sie zu löschen, um es zu messen, wäre
 * ein Datenverlust am Modell des Eigentümers. Was davon abgesichert ist, ist der Nachschlag selbst.*
 *
 * ⚠️ **Sie schreibt in die Datenbank, und zwar zweierlei.** *Die elf Optionen, die es tragen —
 * das ist der Auftrag. Und einen Schmierknoten `__tb …` unter `Data Types`, den sie am Ende wieder
 * wegräumt, mitsamt dem, was ein abgestürzter Vorlauf liegengelassen hat.*
 *
 * @see docs/NewConcept/50-wordpress-persistence.md
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

use Taxmod\WordPress\Persistence\WpdbRecordRepository;
use Taxmod\Core\Service\ModelValues;
use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Renderer\ShippedRenderers;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\Core\Service\Rendering;
use Taxmod\Core\Service\Settings;
use Taxmod\WordPress\Persistence\BaseScaffold;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\SeededTypeNodes;
use Taxmod\WordPress\Persistence\TableIdentityAllocator;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRelationRepository;
use Taxmod\WordPress\Persistence\WpdbSettingRepository;
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
$edges     = new WpdbRelationRepository();
$log       = new WpdbChangelog(new SystemClock());
$framework = new SeededFrameworkNodes($nodes, $edges, new TableIdentityAllocator(), $log);
$editor    = new ModelEditor($nodes, $edges, new TableIdentityAllocator(), $framework, $log);
$settings  = new Settings(new WpdbSettingRepository(), $nodes, $framework);
$types     = new SeededTypeNodes($nodes, $framework);
$rendering = new Rendering($nodes, $framework, $settings, ShippedRenderers::registry(), $types,
    model: new ModelValues(new WpdbRecordRepository(), new WpdbRelationRepository(), new WpdbNodeRepository(), $framework)
);

$dataTypes = $framework->rootOf(Branch::DataTypes);

/** @var array<int, \Taxmod\Core\Model\Node> $children */
$children = [];

foreach ($editor->childrenOf($dataTypes->id) as $child) {
    $children[$child->id] = $child;
}

echo "\n== 1. Jeder Typ hat seine Id notiert, und sie zeigt auf einen Knoten unter Data Types ==\n";

// ⚠️ *Der erste Zugriff trägt fehlende Optionen über den Notnagel nach — genau das ist der Weg, auf
// dem eine bestehende Installation ohne Datenverlust umsteigt.*
foreach (SimpleType::cases() as $type) {
    $id = $types->nodeId($type);

    check(
        $type->value . ' → ' . SeededTypeNodes::optionFor($type),
        $id !== null && isset($children[$id]),
        $id === null ? 'keine Option' : "Id $id ist kein Kind von Data Types"
    );
}

echo "\n== 2. Die Option steht auch wirklich in der Datenbank, nicht nur im Gedächtnis ==\n";

foreach (SimpleType::cases() as $type) {
    $stored = (int) get_option(SeededTypeNodes::optionFor($type), 0);

    check($type->value . ' ist gespeichert', $stored > 0 && $stored === $types->nodeId($type), (string) $stored);
}

echo "\n== 3. Und der Knoten hinter der Id ist der, den man erwartet ==\n";

// ⚠️ *Hier **darf** der Name geprüft werden, und nur hier: die Frage ist nicht «wie finde ich den
// Knoten», sondern «hat die Saat den richtigen erwischt». Der Name ist die Gegenprobe, nicht der
// Schlüssel.*
foreach (SimpleType::cases() as $type) {
    $id   = $types->nodeId($type);
    $node = $id === null ? null : ($children[$id] ?? null);

    check(
        $type->value . ' zeigt auf «' . $type->nodeName() . '»',
        $node !== null && SimpleType::fromNodeName($node->name) === $type,
        $node === null ? 'nicht da' : $node->name
    );
}

echo "\n== 4. Rückrichtung: der Knoten sagt, welcher Typ er ist ==\n";

foreach (SimpleType::cases() as $type) {
    $id = $types->nodeId($type);

    check($type->value . ' zurück', $id !== null && $types->typeOf($id) === $type);
}

echo "\n== 5. Ein Doppelgänger unter Data Types ist nicht der Typ (D-022, D-510) ==\n";

// ⚠️ **Das ist die Messung, die ohne den Bau rot wird.** *Ein Knoten, der nur den **Namen** eines
// Typs trägt, hat unter der alten Bindung geantwortet — und [D-022](../../docs/NewConcept/90-decision-log.md)
// sagt, dass Knotennamen absichtlich nicht eindeutig sind. Am Schmierknoten und nie am gesäten, damit
// nichts am Modell des Eigentümers hängt, falls dieser Lauf stirbt.*
$doppel = $editor->createNode('__tb Platzhalter', $dataTypes->id);

// ⚠️ **Aufräumen auch dann, wenn dieser Lauf stirbt** — und das ist nicht Vorsicht, sondern gemessen.
// *Der erste Entwurf räumte nur am Ende und nur nach `__tb%` auf. Drei Läufe starben vorher oder nach
// der Umbenennung, und es lagen **sechs Knoten namens `Integer`** unter `Data Types`. `package7-check`
// fiel daran um, und die Saat band sich an einen von ihnen. **Ein Schmierknoten, der wie echte
// Modellstruktur heisst, ist nicht wiederzufinden** — also muss er weg, bevor irgendetwas anderes
// schiefgehen kann.*
register_shutdown_function(static function () use ($nodes, $edges, $doppel): void {
    $rest = $nodes->find($doppel->id);

    if ($rest !== null) {
        $edges->purgeEdgesTouching($rest->id);
        $nodes->purgeSubtree($rest);
        fwrite(STDERR, "  (Aufräumen beim Beenden: Knoten {$rest->id} «{$rest->name}» entfernt)\n");
    }
});

check('frisch angelegt hat er keinen Typ', $rendering->typeOfNode($doppel) === null);

$doppel = $editor->rename($doppel->id, SimpleType::Int->nodeName());

check(
    'und «' . SimpleType::Int->nodeName() . '» zu heissen macht ihn nicht dazu',
    $rendering->typeOfNode($doppel) === null,
    (string) $rendering->typeOfNode($doppel)?->value
);

// ⚠️ *Ohne `?? null` stirbt diese Prüfung an einer Option, die ins Leere zeigt, statt sie zu melden —
// **und genau das ist beim Bauen zweimal passiert.** Eine Prüfung, die an einem kaputten Zustand
// abstürzt, sagt nichts über ihn; sie muss ihn überleben, um ihn zu berichten.*
$seededInt = $types->nodeId(SimpleType::Int);
$intNode   = $seededInt === null ? null : ($children[$seededInt] ?? null);

check(
    'der gesäte Integer antwortet unverändert',
    $intNode !== null && $rendering->typeOfNode($intNode) === SimpleType::Int,
    $intNode === null ? "Option zeigt auf $seededInt — dort ist kein Kind von Data Types" : ''
);

check(
    'und beide heissen jetzt gleich — der Name kann es also nicht gewesen sein',
    $intNode !== null && $intNode->name === $nodes->byId($doppel->id)->name,
    $nodes->byId($doppel->id)->name
);

echo "\n== 6. Der Notnagel trägt eine fehlende Option nach (D-510) ==\n";

// ⚠️ **Ohne den Rückfall wäre ein Upgrade ein Datenverlust** — eine bestehende Installation hat die
// Optionen nicht. *Gemessen wird an `color`, indem die Option gelöscht und ein **frisches** Exemplar
// gefragt wird; danach steht sie wieder da, mit demselben Wert. Netto ändert sich nichts.*
$colourBefore = $types->nodeId(SimpleType::Color);

delete_option(SeededTypeNodes::optionFor(SimpleType::Color));

check('die Option ist weg', get_option(SeededTypeNodes::optionFor(SimpleType::Color)) === false);

$fresh = new SeededTypeNodes($nodes, $framework);

check(
    'der Name findet den Knoten trotzdem',
    $fresh->nodeId(SimpleType::Color) === $colourBefore,
    (string) $fresh->nodeId(SimpleType::Color)
);

check(
    'und die Option ist nachgetragen, damit der Notnagel nicht wieder gebraucht wird',
    (int) get_option(SeededTypeNodes::optionFor(SimpleType::Color), 0) === $colourBefore
);

echo "\n== 6b. Auch die Saat schlägt Id zuerst nach (D-510) ==\n";

// ⚠️ **Diese Messung hat sich selbst erzwungen.** *Der erste Entwurf von `BaseScaffold::import()`
// baute `$taken[$child->name] = $child` — und `childrenOf()` liefert nach Position, also gewann der
// **letzte** Gleichnamige. Mit sechs Leichen namens `Integer` im Baum band sich die Saat an eine von
// ihnen, notierte deren Id, und legte beim nächsten Lauf einen weiteren `Integer` an. **Zwei Knoten,
// wo einer sein soll, und die Option zeigte auf den falschen.* Gemessen am Doppelgänger, der zu
// diesem Zeitpunkt noch steht: die Saat darf ihn nicht ansehen.*
$vorher    = $types->nodeId(SimpleType::Int);
$kinder    = count($editor->childrenOf($dataTypes->id));
$scaffold  = new \Taxmod\WordPress\Persistence\BaseScaffold($editor, $framework, $types, $settings);
$angelegt  = $scaffold->import();

check('die Saat legt neben dem Doppelgänger nichts Neues an', $angelegt === [], implode(', ', $angelegt));

check(
    'und die Kinderzahl von Data Types ist unverändert',
    count($editor->childrenOf($dataTypes->id)) === $kinder,
    count($editor->childrenOf($dataTypes->id)) . ' statt ' . $kinder
);

check(
    'und die Id von «int» steht noch auf demselben Knoten',
    $types->nodeId(SimpleType::Int) === $vorher,
    $types->nodeId(SimpleType::Int) . ' statt ' . $vorher
);

check('der Doppelgänger ist nicht zum Typ geworden', $types->typeOf($doppel->id) === null);

echo "\n== 7. Die Prüfung räumt hinter sich auf ==\n";

// ⚠️ **Nach der Id dieses Laufs **und** nach Namen, und das erste ist hier nicht optional.** *Der
// erste Entwurf fegte nur nach `__tb%` — aber Abschnitt 5 **benennt den Knoten um**, also hiess er
// `Integer`, als die Aufräumung nach ihm suchte. Sie fand nichts, meldete «kein Schmierknoten bleibt
// liegen» und war grün, während sechs Knoten namens `Integer` unter `Data Types` lagen. Gemessen:
// **`package7-check` fiel daran um**, weil es seine Typen über den Namen sucht. Eine Aufräumung, die
// nach dem Namen greift, darf den Namen nicht selbst ändern.*
$eigene = [$doppel->id];

$stale = $wpdb->get_col('SELECT id FROM ' . Schema::table('nodes') . ' WHERE name LIKE "\\_\\_tb%" ORDER BY LENGTH(path) DESC');

foreach ([...$eigene, ...array_map('intval', $stale)] as $id) {
    $node = $nodes->find((int) $id);

    if ($node !== null) {
        $edges->purgeEdgesTouching($node->id);
        $nodes->purgeSubtree($node);
    }
}

check('der Doppelgänger ist fort', $nodes->find($doppel->id) === null);

check('und kein Schmierknoten aus einem früheren Lauf bleibt liegen',
    $wpdb->get_var('SELECT COUNT(*) FROM ' . Schema::table('nodes') . ' WHERE name LIKE "\\_\\_tb%"') === '0');

// ⚠️ *Und die Gegenprobe zur Aufräumung selbst: **unter `Data Types` steht jeder Typ genau einmal.**
// Genau diese Zeile hätte die sechs Leichen gemeldet, und sie ist der Grund, dass sie hier steht.*
$doppelt = [];

foreach ($editor->childrenOf($dataTypes->id) as $child) {
    $type = SimpleType::fromNodeName($child->name);

    if ($type !== null) {
        $doppelt[$type->value] = ($doppelt[$type->value] ?? 0) + 1;
    }
}

$mehrfach = array_keys(array_filter($doppelt, static fn (int $n): bool => $n > 1));

check('kein Typname liegt zweimal unter Data Types', $mehrfach === [], implode(', ', $mehrfach));

echo "\n" . ($bad === 0 ? "Alles grün: $ok\n" : "$bad fehlgeschlagen, $ok in Ordnung\n");

exit($bad === 0 ? 0 : 1);
