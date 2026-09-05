<?php declare(strict_types=1);
/**
 * Die Renderer-Einstellungen werden Felder — Zeile 85, erster Teil.
 *
 *     php scripts/migrate-renderer-settings.php            (Probelauf, schreibt nichts)
 *     php scripts/migrate-renderer-settings.php --write    (schreibt und prüft nach)
 *
 * ⚠️ **[D-529](../docs/NewConcept/90-decision-log.md): die `settings`-Tabelle fällt.** *Ein Renderer
 * wird zu dem, was er im neuen Modell ist: ein **Teil** unter dem Feld `Root.renderer`, dessen Feld
 * `render` auf einen Knoten unter `Renderer` zeigt.*
 *
 * ⚠️ **Die vier Zeilen an Kanten sind der Grund, warum der zweistufige Pfad zuerst gebaut wurde.**
 * *«Der Renderer **dieses Feldes**» ist eine Adresse mit zwei Stufen — sie zu schreiben war bis heute
 * nicht möglich, ohne einen Behälter zu erfinden, den der Eigentümer zu Recht abgelehnt hat.*
 *
 * ⚠️ **Es wird nichts gelöscht, was nicht vorher zurückgelesen wurde.** *Erst wandern alle Zeilen,
 * dann wird jede über ihre neue Adresse gelesen und mit der alten verglichen, und **nur wenn alle
 * stimmen**, verschwinden die alten. Ein Umzug, der zur Hälfte gelingt, ist schlimmer als keiner.*
 */

$schreiben = in_array('--write', $argv, true);

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

use Taxmod\Core\Model\RecordKind;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Service\DataEntry;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRecordRepository;
use Taxmod\WordPress\Persistence\WpdbRelationRepository;
use Taxmod\WordPress\SystemClock;

global $wpdb;

$nodes     = new WpdbNodeRepository();
$edges     = new WpdbRelationRepository();
$log       = new WpdbChangelog(new SystemClock());
$framework = new SeededFrameworkNodes($nodes, $edges, $log);
$records   = new WpdbRecordRepository();
$data      = new DataEntry($records, $edges, $nodes, $framework, new SystemClock());

/** Die zwei Kanten, ohne die nichts geht. */
$rendererFeld = (int) $wpdb->get_var(
    "SELECT r.id FROM " . Schema::table('relations') . " r
     JOIN " . Schema::table('nodes') . " n ON n.id = r.from_node_id
     WHERE n.name = 'Root' AND r.name = 'renderer' LIMIT 1"
);

$renderFeld = (int) $wpdb->get_var(
    "SELECT r.id FROM " . Schema::table('relations') . " r
     JOIN " . Schema::table('nodes') . " n ON n.id = r.from_node_id
     WHERE n.name = 'DisplayOption' AND r.name = 'render' LIMIT 1"
);

if ($rendererFeld === 0 || $renderFeld === 0) {
    fwrite(STDERR, "Die Felder «Root.renderer» oder «DisplayOption.render» fehlen — nichts zu tun.\n");
    exit(1);
}

echo "Feld «Root.renderer» = Kante {$rendererFeld}, Feld «DisplayOption.render» = Kante {$renderFeld}\n\n";

/** Name eines Renderers → sein Knoten. */
$rendererPfad = (string) $wpdb->get_var(
    "SELECT path FROM " . Schema::table('nodes') . " WHERE name = 'Renderer' LIMIT 1"
);

$nachName = [];

foreach ($wpdb->get_results($wpdb->prepare(
    'SELECT id, name FROM ' . Schema::table('nodes') . ' WHERE path LIKE %s',
    $wpdb->esc_like($rendererPfad . '.') . '%'
), ARRAY_A) ?: [] as $z) {
    $nachName[(string) $z['name']] = (int) $z['id'];
}

$zeilen = $wpdb->get_results(
    "SELECT id, owner_id, value_text FROM " . Schema::table('settings') . "
     WHERE setting_key = 'renderer' ORDER BY id",
    ARRAY_A
) ?: [];

$plan     = [];
$probleme = [];

foreach ($zeilen as $z) {
    $name = (string) ($z['value_text'] ?? '');

    if (! isset($nachName[$name])) {
        $probleme[] = "Zeile {$z['id']}: kein Knoten für den Renderer «{$name}»";

        continue;
    }

    $ownerId = (int) $z['owner_id'];
    $knoten  = $nodes->find($ownerId);

    if ($knoten !== null) {
        $plan[] = ['settingId' => (int) $z['id'], 'besitzer' => $ownerId, 'kette' => [$rendererFeld], 'renderer' => $nachName[$name], 'name' => $name, 'wo' => $knoten->name];

        continue;
    }

    // Eine Kante: der Datensatz gehört ihrem Besitzerknoten, die Adresse nennt die Kante.
    $kante = $wpdb->get_row($wpdb->prepare(
        'SELECT from_node_id, name FROM ' . Schema::table('relations') . ' WHERE id = %d',
        $ownerId
    ));

    if ($kante === null) {
        $probleme[] = "Zeile {$z['id']}: Besitzer {$ownerId} ist weder Knoten noch Kante";

        continue;
    }

    $von = $nodes->find((int) $kante->from_node_id);

    $plan[] = [
        'settingId' => (int) $z['id'],
        'besitzer'  => (int) $kante->from_node_id,
        'kette'     => [$ownerId, $rendererFeld],
        'renderer'  => $nachName[$name],
        'name'      => $name,
        'wo'        => ($von->name ?? '?') . '.' . $kante->name,
    ];
}

printf("%d Zeilen, davon %d an Verwendungsstellen\n", count($plan), count(array_filter($plan, static fn (array $p): bool => count($p['kette']) > 1)));

foreach ($probleme as $p) {
    echo "  ⚠ {$p}\n";
}

if ($probleme !== []) {
    fwrite(STDERR, "\nEs gibt ungeklärte Zeilen — es wird nichts geschrieben.\n");
    exit(1);
}

if (! $schreiben) {
    echo "\nProbelauf. Was geschrieben würde:\n";

    foreach ($plan as $p) {
        printf("  %-32s -> %s\n", substr($p['wo'], 0, 32), $p['name']);
    }

    echo "\nMit --write ausführen.\n";
    exit(0);
}

/** Je Besitzerknoten ein Datensatz, wiederverwendet. */
$satzVon = [];
$gebaut  = [];

foreach ($plan as $p) {
    $satzVon[$p['besitzer']] ??= $data->create($p['besitzer'], RecordKind::Default)->id;

    $teil = $data->createPartAt($satzVon[$p['besitzer']], $p['kette']);
    $data->put($teil->id, $renderFeld, TypedValue::ofReference($p['renderer']));

    $gebaut[] = ['plan' => $p, 'satz' => $satzVon[$p['besitzer']], 'teil' => $teil->id];
}

echo "\n" . count($gebaut) . " Teile angelegt. Jetzt zurücklesen:\n";

$falsch = [];

foreach ($gebaut as $g) {
    $werte = $data->valuesAt($g['teil'], [$renderFeld]);
    $ist   = $werte[0]->value->reference ?? null;

    if ($ist !== $g['plan']['renderer']) {
        $falsch[] = "{$g['plan']['wo']}: erwartet {$g['plan']['renderer']}, gelesen " . ($ist ?? 'nichts');
    }
}

if ($falsch !== []) {
    fwrite(STDERR, "\n" . count($falsch) . " Zeilen lesen sich nicht zurück — die alten bleiben stehen:\n");

    foreach ($falsch as $z) {
        fwrite(STDERR, "  {$z}\n");
    }

    exit(1);
}

echo "  alle " . count($gebaut) . " gelesen und gleich\n";

$ids = implode(',', array_map(static fn (array $g): int => $g['plan']['settingId'], $gebaut));
$weg = (int) $wpdb->query("DELETE FROM " . Schema::table('settings') . " WHERE id IN ({$ids})");

echo "\n{$weg} alte Setting-Zeilen entfernt.\n";
