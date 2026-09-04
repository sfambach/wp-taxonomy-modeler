<?php declare(strict_types=1);
/**
 * `min`, `max` und `exponent` aus `Integer` heraus — sie sind nicht mehr nötig.
 *
 *     php scripts/drop-integer-helpers.php            (Probelauf)
 *     php scripts/drop-integer-helpers.php --write    (raeumt weg)
 *
 * ⚠️ **Auf sein Wort, und er hat es mehrfach gesagt:** *«schmeiss mal min, max, exponent aus Integer
 * raus. Das braucht man nicht mehr. Und selbst wenn wir's noch brauchen, ist unser Konzept danach so,
 * dass wir's nicht mehr brauchen. **Jedes Mal stosse ich wieder darauf und ich bin's echt leid.**»*
 *
 * ⚠️ **Warum sie überhaupt entstanden sind:** *[D-516](../docs/NewConcept/90-decision-log.md) machte
 * eine Angabe zu einem **Kindknoten ihres Typs**, damit die Marke «ist eine Einstellung» einen Ort
 * hatte. **[D-526](../docs/NewConcept/90-decision-log.md) hat die Marke an die Kante gelegt** — damit
 * fiel ihr einziger Zweck weg, und übrig blieben drei Knoten, die bei jeder Messung im Weg standen.*
 *
 * ⚠️ **Und sie standen nicht nur im Weg, sie waren gefährlich:** *sie sind der Grund, warum `Integer`
 * drei Kinder hat. Nach der Regel «ein Ziel mit sichtbaren Kindern ist eine Auswahl» wäre `Integer`
 * eine Auswahlliste aus seinen eigenen Einstellungen geworden, **sobald ihre Marke an die Kante
 * gewandert wäre**. Mit ihnen verschwindet diese Falle.*
 *
 * ⚠️ *`Prefixes.exponent` trägt 20 Vorgabewerte und **bleibt**: das Feld wird auf `Integer` selbst
 * umgehängt ([D-516](../docs/NewConcept/90-decision-log.md)s Umkehrung) und danach **wieder als
 * Einstellungskante markiert** — denn ein Umhängen liest die Art am Ast neu ([D-497](../docs/NewConcept/90-decision-log.md)),
 * und der Ast gibt `setting` nie zurück.*
 */

$schreiben = in_array('--write', $argv, true);
$root      = getenv('WP_ROOT') ?: null;

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

use Taxmod\Core\Service\ModelEditor;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRecordRepository;
use Taxmod\WordPress\Persistence\WpdbRelationRepository;
use Taxmod\WordPress\SystemClock;

global $wpdb;

$nodes  = new WpdbNodeRepository();
$edges  = new WpdbRelationRepository();
$log    = new WpdbChangelog(new SystemClock());
$fw     = new SeededFrameworkNodes($nodes, $edges, $log);
$editor = new ModelEditor($nodes, $edges, $fw, $log, records: new WpdbRecordRepository());

$n = Schema::table('nodes');
$r = Schema::table('relations');

$integer = (int) $wpdb->get_var("SELECT id FROM {$n} WHERE name = 'Integer' AND path LIKE '1.406.408.%' LIMIT 1");

if ($integer === 0) {
    fwrite(STDERR, "Kein Knoten «Integer» unter Data Types.\n");
    exit(1);
}

$kinder = $wpdb->get_results(
    "SELECT k.id, k.name FROM {$n} k
     WHERE k.path LIKE (SELECT CONCAT(path, '.%') FROM {$n} WHERE id = {$integer})
       AND k.name IN ('min', 'max', 'exponent')",
    ARRAY_A
) ?: [];

echo 'Integer = ' . $integer . ', zu entfernende Kinder: ' . count($kinder) . "\n\n";

$plan = [];

foreach ($kinder as $k) {
    $felder = $wpdb->get_results($wpdb->prepare(
        "SELECT rel.id, rel.name, rel.kind, rel.from_id, von.name AS von
         FROM {$r} rel JOIN {$n} von ON von.id = rel.from_id
         WHERE rel.to_id = %d AND rel.kind <> 'inheritance'",
        (int) $k['id']
    ), ARRAY_A) ?: [];

    foreach ($felder as $f) {
        $werte = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . Schema::table('record_values') . ' WHERE edge_id = %d',
            (int) $f['id']
        ));

        $plan[] = ['kind' => $k, 'feld' => $f, 'werte' => $werte];

        printf(
            "  %-10s <- feld %-7s %s.%-12s %s, %d Werte  =>  %s\n",
            $k['name'],
            $f['id'],
            $f['von'],
            $f['name'],
            $f['kind'],
            $werte,
            $werte > 0 ? 'UMHAENGEN auf Integer' : 'Feld entfernen'
        );
    }

    if ($felder === []) {
        printf("  %-10s kein Feld zeigt darauf  =>  nur der Knoten geht\n", $k['name']);
    }
}

if (! $schreiben) {
    echo "\nDanach gehen die drei Knoten in den Muell und der Muell wird geleert.\n";
    echo "Probelauf. Mit --write wegraeumen.\n";
    exit(0);
}

echo "\n";

foreach ($plan as $p) {
    $feldId  = (int) $p['feld']['id'];
    $ownerId = (int) $p['feld']['from_id'];

    if ($p['werte'] > 0) {
        $editor->retargetField($ownerId, $feldId, $integer);

        // ⚠️ **Wieder markieren, weil das Umhängen die Art neu abliest** ([D-497](../docs/NewConcept/90-decision-log.md)).
        // *Der Ast gibt `setting` nie zurück — sie ist die einzige Art, die kein Ast vergibt
        // ([D-526](../docs/NewConcept/90-decision-log.md)), also muss sie hier wieder gesetzt werden.*
        if ($p['feld']['kind'] === 'setting') {
            $editor->markAsSetting($ownerId, $feldId, true);
        }

        echo "  umgehaengt   {$p['feld']['von']}.{$p['feld']['name']} -> Integer"
            . ($p['feld']['kind'] === 'setting' ? ' (wieder Einstellung)' : '') . "\n";

        continue;
    }

    $editor->removeField($ownerId, $feldId);
    echo "  Feld weg     {$p['feld']['von']}.{$p['feld']['name']}\n";
}

foreach ($kinder as $k) {
    try {
        $editor->moveToTrash((int) $k['id']);
        echo "  in den Muell {$k['name']}\n";
    } catch (\Throwable $e) {
        echo '  ⚠ ' . $k['name'] . ': ' . $e->getMessage() . "\n";
    }
}

$gone = $editor->clearTrash();

echo "\nMuell geleert: ";

foreach ($gone as $was => $wieviel) {
    echo "{$was} {$wieviel}  ";
}

echo "\n\nKinder von Integer jetzt: "
    . (int) $wpdb->get_var("SELECT COUNT(*) FROM {$n} WHERE path LIKE (SELECT CONCAT(path, '.%') FROM {$n} WHERE id = {$integer})")
    . "\n";
