<?php declare(strict_types=1);
/**
 * «render label roles» und «render with label» werden einer.
 *
 *     php scripts/merge-render-label-nodes.php            (Probelauf)
 *     php scripts/merge-render-label-nodes.php --write    (wirklich)
 *
 * ⚠️ **Auf sein Wort:** *«render with label sollte das gleiche sein wie render label roles — also die
 * Renderer von label with roles zu render with label schieben und die Einstellung zur Wahl des Labels
 * in den render with label schieben.»*
 *
 * ⚠️ **Er hat recht, und der Fehler war meiner.** *Ich habe am selben Tag **zwei** Zwischenknoten
 * angelegt, wo einer gemeint war: einer trägt `with_label` und hat `form`, `compact`, `table`; der
 * andere trägt `label_role` und hat `reference`, `chooser-inline`, `chooser-dialog`. **Damit hat kein
 * einziger Renderer beide Angaben** — ein Formular kann seine Beschriftung ein- und ausschalten, aber
 * nicht sagen, welche; und ein Wähler umgekehrt. Genau die Trennung, die niemand wollte.*
 *
 * ⚠️ *Der leer geräumte Knoten geht in den **Papierkorb** und wird nicht gelöscht — er lässt sich von
 * dort zurückholen, und [D-340](../docs/NewConcept/90-decision-log.md) sagt, dass Identitäten
 * ohnehin bleiben.*
 *
 * ⚠️ **Reihenfolge:** *hier bewegt sich nur Bestand, kein Leser und kein Wächter ändern sich — die
 * Regel «Wächter, Leser, Daten» ist damit erfüllt und nicht übergangen. Was danach anders aussieht,
 * hält `label-role-check.php` fest.*
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

use Taxmod\WordPress\Persistence\Schema;

global $wpdb;

$schreiben = in_array('--write', $argv, true);

$r = Schema::table('relations');
$n = Schema::table('nodes');

function knoten(string $name): ?array
{
    global $wpdb, $n;

    $row = $wpdb->get_row($wpdb->prepare("SELECT id, name FROM {$n} WHERE name = %s LIMIT 1", $name), ARRAY_A);

    return $row === null ? null : ['id' => (int) $row['id'], 'name' => $row['name']];
}

$quelle = knoten('render label roles');
$ziel   = knoten('render with label');

if ($quelle === null || $ziel === null) {
    fwrite(STDERR, "Einer der beiden Knoten fehlt — nichts zu tun.\n");
    exit(1);
}

echo "Quelle: «{$quelle['name']}» #{$quelle['id']}\n";
echo "Ziel:   «{$ziel['name']}» #{$ziel['id']}\n\n";

// Was bewegt wird: die Vererbungskanten der Kinder und die eigenen Felder.
$kinder = $wpdb->get_results($wpdb->prepare(
    "SELECT e.id, k.name FROM {$r} e INNER JOIN {$n} k ON k.id = e.to_node_id
     WHERE e.from_node_id = %d AND e.kind = 'inheritance' ORDER BY e.position",
    $quelle['id']
), ARRAY_A) ?: [];

$felder = $wpdb->get_results($wpdb->prepare(
    "SELECT rel.id, rel.name FROM {$r} rel WHERE rel.from_node_id = %d AND rel.kind <> 'inheritance'",
    $quelle['id']
), ARRAY_A) ?: [];

// ⚠️ *Hinter die vorhandenen Kinder, nicht davor — die Reihenfolge unter dem Ziel ist eine Aussage
// ([D-407](../docs/NewConcept/90-decision-log.md)), und ein Umzug soll sie nicht umsortieren.*
$naechste = 1 + (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COALESCE(MAX(position), 0) FROM {$r} WHERE from_node_id = %d AND kind = 'inheritance'",
    $ziel['id']
));

echo count($kinder) . " Kinder ziehen um:\n";

foreach ($kinder as $k) {
    echo "  {$k['name']}\n";
}

echo "\n" . count($felder) . " Felder ziehen um:\n";

foreach ($felder as $f) {
    echo "  {$f['name']}\n";
}

if (! $schreiben) {
    echo "\nProbelauf. Mit --write ausfuehren.\n";

    exit(0);
}

$bewegt = 0;

foreach ($kinder as $k) {
    $getan = $wpdb->query($wpdb->prepare(
        "UPDATE {$r} SET from_node_id = %d, position = %d, version = version + 1 WHERE id = %d",
        $ziel['id'],
        $naechste++,
        (int) $k['id']
    ));

    // ⚠️ **Nicht auf 0 Zeilen vertrauen** — `$wpdb` gibt für einen Fehler und für «nichts geändert»
    // dasselbe zurück. *Deshalb wird `last_error` mitgelesen.*
    if ($getan === false || $wpdb->last_error !== '') {
        fwrite(STDERR, "Kante {$k['id']} nicht bewegt: {$wpdb->last_error}\n");

        exit(1);
    }

    ++$bewegt;
}

foreach ($felder as $f) {
    $getan = $wpdb->query($wpdb->prepare(
        "UPDATE {$r} SET from_node_id = %d, version = version + 1 WHERE id = %d",
        $ziel['id'],
        (int) $f['id']
    ));

    if ($getan === false || $wpdb->last_error !== '') {
        fwrite(STDERR, "Feld {$f['id']} nicht bewegt: {$wpdb->last_error}\n");

        exit(1);
    }

    ++$bewegt;
}

// ⚠️ *Die Pfade der umgezogenen Kinder stimmen jetzt nicht mehr: `path` ist materialisiert
// ([D-441](../docs/NewConcept/90-decision-log.md)). Der Editor zieht sie beim Verschieben nach — hier
// wird es von Hand gemacht, für die Kinder **und** alles darunter.*
$alterPfad = (string) $wpdb->get_var($wpdb->prepare("SELECT path FROM {$n} WHERE id = %d", $quelle['id']));
$neuerPfad = (string) $wpdb->get_var($wpdb->prepare("SELECT path FROM {$n} WHERE id = %d", $ziel['id']));

$vonStamm = $alterPfad . $quelle['id'] . '.';
$zuStamm  = $neuerPfad . $ziel['id'] . '.';

$pfade = $wpdb->query($wpdb->prepare(
    "UPDATE {$n} SET path = CONCAT(%s, SUBSTRING(path, %d)), version = version + 1
     WHERE path LIKE %s",
    $zuStamm,
    strlen($vonStamm) + 1,
    $wpdb->esc_like($vonStamm) . '%'
));

if ($pfade === false || $wpdb->last_error !== '') {
    fwrite(STDERR, "Pfade nicht nachgezogen: {$wpdb->last_error}\n");

    exit(1);
}

echo "\n{$bewegt} Kanten bewegt, {$pfade} Pfade nachgezogen.\n";

// Der leere Knoten in den Papierkorb.
$trash = (int) $wpdb->get_var("SELECT id FROM " . Schema::table('nodes') . " WHERE name = 'Trash' LIMIT 1");

if ($trash === 0) {
    echo "Kein Papierkorb gefunden — «{$quelle['name']}» bleibt stehen, leer.\n";

    exit(0);
}

$trashPfad = (string) $wpdb->get_var($wpdb->prepare("SELECT path FROM {$n} WHERE id = %d", $trash));

$wpdb->query($wpdb->prepare(
    "UPDATE {$r} SET from_node_id = %d, version = version + 1 WHERE to_node_id = %d AND kind = 'inheritance'",
    $trash,
    $quelle['id']
));

$wpdb->query($wpdb->prepare(
    "UPDATE {$n} SET path = %s, version = version + 1 WHERE id = %d",
    $trashPfad . $trash . '.',
    $quelle['id']
));

echo "«{$quelle['name']}» liegt im Papierkorb und laesst sich zurueckholen.\n";
