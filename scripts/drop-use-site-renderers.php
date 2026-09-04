<?php declare(strict_types=1);
/**
 * Die vier Renderer an **Verwendungsstellen** entfernen — Altlast aus dem Umzug.
 *
 *     php scripts/drop-use-site-renderers.php            (Probelauf)
 *     php scripts/drop-use-site-renderers.php --write    (wirklich)
 *
 * ⚠️ **Auf sein Wort, und er hat es an den Daten gesehen:** *«also dass dies Altlasten sind ist ok …
 * entferne mal die Altlasten»* — nachdem ich vier Kanten-Datensätze als «überschrieben» bezeichnet
 * hatte und er widersprach: *«du sagst überschrieben, ich sehe aber nichts — das ist wahrscheinlich ein
 * alter Datensatz, weil so weit, dass wir an Kanten überschreiben, sind wir ja noch nicht.»*
 *
 * ⚠️ **Er hatte recht, und die Messung war eindeutig:** *alle 31 Teile wurden in **derselben Sekunde**
 * angelegt, `2026-08-30 13:15:17` — ein Umzugslauf von mir, kein Klick. Vier davon landeten an
 * Verwendungsstellen, weil die alte `settings`-Tabelle als Besitzer sowohl einen Knoten als **auch**
 * eine Kante kennen konnte; `migrate-renderer-settings.php` hat das wörtlich übernommen.*
 *
 * ⚠️ **Warum sie weg müssen und nicht bleiben dürfen:** *sie wirken. `Passiv.Tolerance` sagt `field`,
 * während sein Zielknoten `Integer` `spinner` sagt — und niemand hat das je eingestellt. **Eine Angabe,
 * die wirkt und die niemand gesetzt hat, ist schlimmer als keine.** Bedienen können wir
 * Verwendungsstellen ohnehin noch nicht.*
 *
 * ⚠️ *Der Teil-Satz geht mit: er hängt an nichts anderem und wäre danach ein Satz ohne Besitzer.*
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

use Taxmod\Core\Model\SettingKey;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRelationRepository;
use Taxmod\WordPress\SystemClock;

global $wpdb;

$schreiben = in_array('--write', $argv, true);

$nodes     = new WpdbNodeRepository();
$edges     = new WpdbRelationRepository();
$framework = new SeededFrameworkNodes($nodes, $edges, new WpdbChangelog(new SystemClock()));

$traeger = $framework->settingEdgeId(SettingKey::Renderer);

if ($traeger === 0) {
    fwrite(STDERR, "Die Traegerkante steht nicht aufgeschrieben — nichts getan.\n");
    exit(1);
}

$v = Schema::table('record_values');
$s = Schema::table('records');
$n = Schema::table('nodes');
$r = Schema::table('relations');

// ⚠️ *Nur die zweistufigen: `path` mit einem Punkt. Die einstufigen gehören dem Knoten und bleiben.*
$zeilen = $wpdb->get_results($wpdb->prepare(
    "SELECT w.id, w.path, w.record_id, w.value_ref FROM {$v} w
     WHERE w.edge_id = %d AND w.path LIKE %s",
    $traeger,
    '%.%'
), ARRAY_A) ?: [];

echo count($zeilen) . " Renderer an Verwendungsstellen:\n";

foreach ($zeilen as $z) {
    $besitzer = (string) $wpdb->get_var($wpdb->prepare(
        "SELECT n.name FROM {$s} s INNER JOIN {$n} n ON n.id = s.node_id WHERE s.id = %d",
        (int) $z['record_id']
    ));

    $stufen = explode('.', (string) $z['path']);
    $kante  = (string) $wpdb->get_var($wpdb->prepare("SELECT name FROM {$r} WHERE id = %d", (int) $stufen[0]));
    $wert   = (string) $wpdb->get_var($wpdb->prepare(
        "SELECT n.name FROM {$v} w INNER JOIN {$n} n ON n.id = w.value_ref WHERE w.record_id = %d LIMIT 1",
        (int) $z['value_ref']
    ));

    printf("  %-16s . %-14s = %-16s (Kanten-Datensatz #%s, Teil #%s)\n", $besitzer, $kante, "«{$wert}»", $z['id'], $z['value_ref']);
}

if ($zeilen === []) {
    echo "Nichts zu tun.\n";

    exit(0);
}

if (! $schreiben) {
    echo "\nProbelauf. Mit --write entfernen.\n";

    exit(0);
}

$weg = 0;

foreach ($zeilen as $z) {
    $teil = (int) $z['value_ref'];

    // ⚠️ *Erst der Inhalt des Teils, dann der Teil, dann der Kanten-Datensatz, der auf ihn zeigte —
    // andernfalls bliebe für einen Augenblick ein Verweis auf einen Satz, den es nicht mehr gibt.*
    foreach ([
        "DELETE FROM {$v} WHERE record_id = %d",
        "DELETE FROM {$s} WHERE id = %d",
    ] as $sql) {
        $ok = $wpdb->query($wpdb->prepare($sql, $teil));

        if ($ok === false || $wpdb->last_error !== '') {
            fwrite(STDERR, "Fehler bei Teil {$teil}: {$wpdb->last_error}\n");

            exit(1);
        }
    }

    $ok = $wpdb->query($wpdb->prepare("DELETE FROM {$v} WHERE id = %d", (int) $z['id']));

    if ($ok === false || $wpdb->last_error !== '') {
        fwrite(STDERR, "Fehler bei Zeile {$z['id']}: {$wpdb->last_error}\n");

        exit(1);
    }

    ++$weg;
}

$rest = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$v} WHERE edge_id = %d AND path LIKE %s",
    $traeger,
    '%.%'
));

$punkte = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$v} WHERE path LIKE '%.%'");

printf("\n%d entfernt. Renderer an Verwendungsstellen: %d. Zeilen mit Punkt-Pfad insgesamt: %d.\n", $weg, $rest, $punkte);

exit($rest === 0 ? 0 : 1);
