<?php declare(strict_types=1);
/**
 * Die Vorgabewerte werden Datensätze — Zeile 85, zweiter Teil.
 *
 *     php scripts/migrate-default-settings.php            (Probelauf)
 *     php scripts/migrate-default-settings.php --write    (schreibt und prüft nach)
 *
 * ⚠️ **Ein Vorgabewert ist ein Datensatz der Art `default`** ([D-524](../docs/NewConcept/90-decision-log.md)),
 * *am Knoten, an der Adresse des Feldes. Genau die Form, die `settings.path` schon benutzte: die 20
 * Exponenten der Präfixe lagen dort unter der Id von `Prefixes.exponent`.*
 *
 * ⚠️ **Und er darf dort stehen, obwohl das Feld nicht speichernd ist** ([D-538](../docs/NewConcept/90-decision-log.md)).
 * *«Nicht speichernd» heisst «landet nicht im **Benutzer**datensatz» — nicht «hat keinen Wert».
 * [D-026](../docs/NewConcept/90-decision-log.md): «at model level there are no values, only defaults».*
 *
 * ⚠️ *Zurückgelesen wird über den **echten Verbraucher** — `Rendering::nonPersistentValue()`, das
 * `path-check.php` schon seit Wochen für `kilo`, `mega`, `milli` und `yotta` festnagelt.*
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

use Taxmod\Core\Model\RecordKind;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Renderer\ShippedRenderers;
use Taxmod\Core\Service\DataEntry;
use Taxmod\Core\Service\ModelValues;
use Taxmod\Core\Service\Rendering;
use Taxmod\Core\Service\Settings;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\SeededTypeNodes;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRecordRepository;
use Taxmod\WordPress\Persistence\WpdbRelationRepository;
use Taxmod\WordPress\Persistence\WpdbSettingRepository;
use Taxmod\WordPress\SystemClock;

global $wpdb;

$nodes     = new WpdbNodeRepository();
$edges     = new WpdbRelationRepository();
$log       = new WpdbChangelog(new SystemClock());
$framework = new SeededFrameworkNodes($nodes, $edges, $log);
$records   = new WpdbRecordRepository();
$settings  = new Settings(new WpdbSettingRepository(), $nodes, $framework);
$model     = new ModelValues($records, $edges, $nodes, $framework);
$data      = new DataEntry($records, $edges, $nodes, $framework, new SystemClock());

$rendering = new Rendering(
    $nodes,
    $framework,
    $settings,
    ShippedRenderers::registry(),
    new SeededTypeNodes($nodes, $framework),
    null,
    null,
    $model
);

$zeilen = $wpdb->get_results(
    "SELECT id, owner_id, path, value_int, value_decimal, value_text, value_ref
     FROM " . Schema::table('settings') . " WHERE setting_key = 'default' ORDER BY id",
    ARRAY_A
) ?: [];

$plan     = [];
$probleme = [];

foreach ($zeilen as $z) {
    $besitzer = $nodes->find((int) $z['owner_id']);

    if ($besitzer === null) {
        $probleme[] = "Zeile {$z['id']}: der Besitzer {$z['owner_id']} ist kein Knoten";

        continue;
    }

    if (($z['path'] ?? '') === '') {
        $probleme[] = "Zeile {$z['id']}: eine Vorgabe ohne Adresse — für welches Feld?";

        continue;
    }

    $kante = null;

    foreach ($edges->fieldEdgesOf([...$besitzer->ancestorIds(), $besitzer->id]) as $eine) {
        if ($eine->id === (int) $z['path']) {
            $kante = $eine;
        }
    }

    if ($kante === null) {
        $probleme[] = "Zeile {$z['id']}: «{$besitzer->name}» hat kein Feld mit der Id {$z['path']}";

        continue;
    }

    $plan[] = [
        'settingId' => (int) $z['id'],
        'knoten'    => $besitzer,
        'kante'     => $kante,
        'wert'      => TypedValue::fromStorage(
            $z['value_int'] === null ? null : (int) $z['value_int'],
            $z['value_decimal'] === null ? null : (string) $z['value_decimal'],
            $z['value_text'] === null ? null : (string) $z['value_text'],
            null,
            $z['value_ref'] === null ? null : (int) $z['value_ref'],
        ),
    ];
}

printf("%d Vorgabewerte, %d ungeklaert\n", count($plan), count($probleme));

foreach ($probleme as $p) {
    echo "  ⚠ {$p}\n";
}

if ($probleme !== []) {
    fwrite(STDERR, "\nEs wird nichts geschrieben.\n");
    exit(1);
}

if (! $schreiben) {
    foreach ($plan as $p) {
        printf("  %-14s . %-22s = %s\n", $p['knoten']->name, $p['kante']->name, $p['wert']->describe());
    }

    echo "\nProbelauf. Mit --write ausfuehren.\n";
    exit(0);
}

$satzVon = [];

foreach ($plan as $p) {
    $id = $p['knoten']->id;

    // ⚠️ *Ein Datensatz der Art `default` je Knoten, wiederverwendet — ein zweiter wäre eine zweite
    // Vorgabe für dasselbe Modell.*
    $satzVon[$id] ??= $data->create($id, RecordKind::Default)->id;

    $data->putAt($satzVon[$id], [$p['kante']->id], $p['wert']);
}

echo "\n" . count($plan) . " Vorgaben geschrieben. Jetzt ueber den echten Verbraucher zuruecklesen:\n";

$falsch = [];

foreach ($plan as $p) {
    $gelesen = $rendering->nonPersistentValue($p['knoten'], $p['kante']);

    if ($gelesen === null || ! $gelesen->equals($p['wert'])) {
        $falsch[] = "{$p['knoten']->name}.{$p['kante']->name}: erwartet {$p['wert']->describe()}, gelesen "
            . ($gelesen?->describe() ?? 'nichts');
    }
}

if ($falsch !== []) {
    fwrite(STDERR, "\n" . count($falsch) . " lesen sich nicht zurueck — die alten bleiben stehen:\n");

    foreach ($falsch as $z) {
        fwrite(STDERR, "  {$z}\n");
    }

    exit(1);
}

echo '  alle ' . count($plan) . " gelesen und gleich\n";

$ids = implode(',', array_map(static fn (array $p): int => $p['settingId'], $plan));
$weg = (int) $wpdb->query("DELETE FROM " . Schema::table('settings') . " WHERE id IN ({$ids})");

echo "\n{$weg} alte Setting-Zeilen entfernt.\n";
