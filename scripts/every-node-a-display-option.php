<?php declare(strict_types=1);
/**
 * Jeder Knoten bekommt seine eigene DisplayOption — die Regel wahr machen.
 *
 *     php scripts/every-node-a-display-option.php            (Probelauf)
 *     php scripts/every-node-a-display-option.php --write    (wirklich)
 *
 * ⚠️ **Die Regel steht im Konzept** ([02-field-and-setting.md](../docs/NewConcept/02-field-and-setting.md)):
 * *«`renderer` ist eine **Komposition** — jeder Knoten bekommt seine eigene DisplayOption.»* Und der
 * Eigentümer hat den Auftrag gegeben: *«die Regel jeder Knoten muss einen Renderer haben haben wir
 * festgelegt, sorge bitte dafür, dass sie auch stimmt.»*
 *
 * ⚠️ **Gemessen war sie nicht wahr:** *von 88 Knoten, die die Trägerkante erben, hatten **27** ihre
 * eigene DisplayOption und **61** keine. Dazu kommt seine frühere Feststellung: die Multiplizität ist
 * `1..*`, also **muss** mindestens ein Teil da sein — «nicht `0..*`».*
 *
 * ⚠️ **Der Teil entsteht leer, und das ist die eigentliche Entscheidung dieses Skripts.** *Man könnte
 * in jeden den Renderer schreiben, der heute gilt — und würde damit **61 geerbte Vorgaben zu
 * ausdrücklichen Angaben einfrieren**. Danach würde eine Änderung an `Integer` nicht mehr auf seine
 * Kinder wirken, weil jedes seinen eigenen Wert trägt. **Ein leerer Teil sagt «hier ist nichts gesetzt»
 * und lässt die Kette arbeiten; ein gefüllter lügt über die Absicht.***
 *
 * ⚠️ *Ausgenommen sind der Papierkorb und der Settings-Ast — dort wird die Trägerkante seit
 * [D-545](../docs/NewConcept/90-decision-log.md) nicht mehr geerbt, also gibt es nichts anzulegen.*
 *
 * ⚠️ *Es geht über {@see \Taxmod\Core\Service\DataEntry::createPart()} und nicht mit rohem SQL: dort
 * sitzen die Wächter, und dort erbt der Teil die Art seines Besitzers.*
 */

$root = getenv('WP_ROOT') ?: null;

if ($root === null) {
    $dir = getcwd();
    while ($dir !== '' && ! is_readable($dir . '/wp-load.php')) {
        $up  = dirname($dir);
        $dir = $up === $dir ? '' : $up;
    }
    $root = $dir;
}

if ($root === '' || ! is_readable($root . '/wp-load.php')) {
    fwrite(STDERR, "Cannot find wp-load.php.\n");
    exit(2);
}

define('WP_USE_THEMES', false);
require $root . '/wp-load.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Taxmod\Core\Exception\NotYetStorable;
use Taxmod\Core\Model\RecordKind;
use Taxmod\Core\Model\SettingKey;
use Taxmod\Core\Service\DataEntry;
use Taxmod\Core\Service\Settings;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\TableIdentityAllocator;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRecordRepository;
use Taxmod\WordPress\Persistence\WpdbRelationRepository;
use Taxmod\WordPress\Persistence\WpdbSettingRepository;
use Taxmod\WordPress\SystemClock;

global $wpdb;

$schreiben = in_array('--write', $argv, true);

$nodes     = new WpdbNodeRepository();
$edges     = new WpdbRelationRepository();
$log       = new WpdbChangelog(new SystemClock());
$framework = new SeededFrameworkNodes($nodes, $edges, new TableIdentityAllocator(), $log);
$records   = new WpdbRecordRepository();
$data      = new DataEntry($records, $edges, $nodes, $framework, new SystemClock(), new Settings(new WpdbSettingRepository(), $nodes, $framework));

$traeger = $framework->settingEdgeId(SettingKey::Renderer);

if ($traeger === 0) {
    fwrite(STDERR, "Die Traegerkante steht nicht aufgeschrieben — nichts getan.\n");
    exit(1);
}

$n = Schema::table('nodes');
$s = Schema::table('records');
$v = Schema::table('record_values');

$trash     = (int) $wpdb->get_var("SELECT id FROM {$n} WHERE name = 'Trash' LIMIT 1");
$trashPfad = (string) $wpdb->get_var($wpdb->prepare("SELECT path FROM {$n} WHERE id = %d", $trash));

$fehlen = [];

foreach ($wpdb->get_results("SELECT id, name, path FROM {$n} ORDER BY id", ARRAY_A) ?: [] as $z) {
    $id = (int) $z['id'];

    if ($id === $trash || str_starts_with((string) $z['path'], $trashPfad . '.')) {
        continue;
    }

    $knoten = $nodes->find($id);

    if ($knoten === null) {
        continue;
    }

    $erbt = false;

    foreach ($edges->fieldEdgesOf($framework->inheritanceOwnersOf($knoten)) as $e) {
        if ($e->id === $traeger) {
            $erbt = true;
        }
    }

    if (! $erbt) {
        continue;
    }

    $hat = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$v} w INNER JOIN {$s} r ON r.id = w.record_id
         WHERE r.node_id = %d AND w.path = %s",
        $id,
        (string) $traeger
    ));

    if ($hat === 0) {
        $fehlen[] = ['id' => $id, 'name' => (string) $z['name']];
    }
}

echo count($fehlen) . " Knoten ohne eigene DisplayOption:\n";

foreach (array_slice($fehlen, 0, 12) as $f) {
    printf("  #%-7s %s\n", $f['id'], $f['name']);
}

if (count($fehlen) > 12) {
    echo '  … und ' . (count($fehlen) - 12) . " weitere\n";
}

if ($fehlen === []) {
    echo "Die Regel stimmt schon.\n";

    exit(0);
}

if (! $schreiben) {
    echo "\nProbelauf. Mit --write anlegen.\n";

    exit(0);
}

$angelegt = 0;
$probleme = [];

foreach ($fehlen as $f) {
    // ⚠️ *Der `default`-Satz des Knotens — auf Modellebene gibt es keine Werte, nur Vorgaben
    // ([D-026](../docs/NewConcept/90-decision-log.md)).*
    $satzId = 0;

    foreach ($records->ofNode($f['id']) as $satz) {
        if ($satz->kind === RecordKind::Default) {
            $satzId = $satz->id;

            break;
        }
    }

    try {
        if ($satzId === 0) {
            $satzId = $data->create($f['id'], RecordKind::Default)->id;
        }

        $data->createPart($satzId, $traeger);
        ++$angelegt;
    } catch (NotYetStorable $e) {
        $probleme[] = "{$f['name']} (#{$f['id']}): " . $e->getMessage();
    }
}

// ⚠️ **Nachgezaehlt und nicht gemeldet** — *die Lehre aus dem Umzug, der «0 Pfade nachgezogen» sagte
// und trotzdem als Erfolg durchlief.*
$ohne = 0;

foreach ($fehlen as $f) {
    $hat = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$v} w INNER JOIN {$s} r ON r.id = w.record_id
         WHERE r.node_id = %d AND w.path = %s",
        $f['id'],
        (string) $traeger
    ));

    if ($hat === 0) {
        ++$ohne;
    }
}

printf("\n%d angelegt, danach fehlen noch %d.\n", $angelegt, $ohne);

foreach (array_slice($probleme, 0, 8) as $p) {
    echo "  verweigert: {$p}\n";
}

exit($ohne === 0 ? 0 : 1);
