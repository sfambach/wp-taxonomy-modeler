<?php declare(strict_types=1);
/**
 * Die Vererbung ist eine Spalte — `nodes.parent_node_id` mit `nodes.sort_order` (TASK-018).
 *
 *     php scripts/dev/inheritance-column-check.php [path/to/wordpress]
 *
 * ⚠️ **[D-581](../../docs/NewConcept/90-decision-log.md), sein Satz:** *«Vererbung ist so
 * unterschiedlich zu Relation, eigentlich würde hier eine `parent_node_id` im Knoten reichen, um das
 * abzubilden, und wäre selektionstechnisch billiger.»*
 *
 * ⚠️ **Dieser Lauf prüft die Struktur an gezählten Zahlen und nicht an Namen.** *Namen bewegen sich
 * aus anderen Gründen; sie hätten die Prüfung weich gemacht. Was gleich bleiben muss, ist: **wie
 * viele Knoten, wie viele Einordnungen, wie viele Wurzeln, wie tief, und welches Kind auf welcher
 * Stelle unter welchem Vater**.*
 *
 * Geprüft wird sechserlei:
 *
 * 1. **Die drei Spalten stehen**, lebend und im Schatten, und der Schlüssel `one_place` geht über
 *    `(parent_node_id, sort_order)`.
 * 2. **Die Kantentabelle trägt keine Vererbung mehr** — nicht eine lebende Zeile.
 * 3. **Genau eine Wurzel, kein Zyklus, kein Kind ohne Vater.** *Die drei Gründe, aus denen die
 *    Wanderung abgebrochen hätte — sie gelten auch danach.*
 * 4. **Der Pfad stimmt mit der Spalte überein.** *`nodes.path` ist abgeleitet und nie eine zweite
 *    Wahrheit ([D-014](../../docs/NewConcept/90-decision-log.md)); wovon er abgeleitet ist, hat sich
 *    geändert, dass er nachgezogen sein muss, nicht.*
 * 5. **Die Wanderung ist umkehrbar.** *Zu jeder Einordnung steht die abgelöste Vererbungskante als
 *    gelöschte Zeile in `relations_history`, mit demselben Vater, derselben Stelle und demselben
 *    `hide`. **Ohne diese Zusage wäre der Umzug eine Einbahnstrasse.***
 * 6. **Die Zahlen von damals sind die von heute.** *Die Wanderung hat ihre gemessene Gestalt in
 *    `taxmod_task018_shape` hinterlegt; hier wird sie noch einmal gemessen und verglichen.*
 *
 * ⚠️ *Er legt nichts an und räumt nichts weg — er liest.*
 *
 * @see docs/pakete/modelltabellen/package.md
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

use Taxmod\WordPress\Persistence\Schema;

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
    echo "  FAIL $what" . ($detail === '' ? '' : " — $detail") . "\n";
}

$nodes     = Schema::table('nodes');
$schatten  = Schema::table('nodes_history');
$relations = Schema::table('relations');
$kanten    = Schema::table('relations_history');

$spalte = static function (string $tabelle, string $name) use ($wpdb): bool {
    return (int) $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
        $tabelle,
        $name
    )) === 1;
};

echo "1 · Die drei Spalten stehen\n";

foreach (['parent_node_id', 'sort_order', 'hide'] as $name) {
    check("nodes.{$name}", $spalte($nodes, $name));
    check("nodes_history.{$name}", $spalte($schatten, $name));
}

// ⚠️ *Der Schatten bekommt die Spalten und **nicht** den Schlüssel — dort darf dieselbe Stelle
// mehrfach vorkommen, wie schon bei TASK-012.*
$schluessel = $wpdb->get_results($wpdb->prepare(
    'SELECT SEQ_IN_INDEX, COLUMN_NAME, NON_UNIQUE FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s
     ORDER BY SEQ_IN_INDEX',
    $nodes,
    'one_place'
), ARRAY_A) ?: [];

check(
    'eindeutig über (parent_node_id, sort_order)',
    count($schluessel) === 2
        && $schluessel[0]['COLUMN_NAME'] === 'parent_node_id'
        && $schluessel[1]['COLUMN_NAME'] === 'sort_order'
        && (int) $schluessel[0]['NON_UNIQUE'] === 0,
    implode(',', array_column($schluessel, 'COLUMN_NAME'))
);

echo "\n2 · Die Kantentabelle trägt keine Vererbung mehr\n";

$uebrig = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$relations} WHERE kind = %s",
    'inheritance'
));

check('keine lebende Vererbungskante', $uebrig === 0, (string) $uebrig);

echo "\n3 · Eine Wurzel, kein Zyklus, kein Kind ohne Vater\n";

$vater = [];
$alle  = [];
$stellen = [];

foreach ($wpdb->get_results("SELECT id, parent_node_id, sort_order, path, hide FROM {$nodes}", ARRAY_A) ?: [] as $zeile) {
    $id     = (int) $zeile['id'];
    $alle[$id] = $zeile;

    if ($zeile['parent_node_id'] !== null) {
        $vater[$id] = (int) $zeile['parent_node_id'];
        $stellen[(int) $zeile['parent_node_id']][] = (int) $zeile['sort_order'];
    }
}

$wurzeln = count($alle) - count($vater);

check('genau eine Wurzel', $wurzeln === 1, (string) $wurzeln);

$verwaist = 0;

foreach ($alle as $id => $zeile) {
    if ($zeile['parent_node_id'] === null && str_contains((string) $zeile['path'], '.')) {
        $verwaist++;
    }
}

check('kein Knoten mit Vorfahren im Pfad und ohne Vater', $verwaist === 0, (string) $verwaist);

$fehlend = 0;

foreach ($vater as $kind => $wer) {
    if (! isset($alle[$wer])) {
        $fehlend++;
    }
}

check('kein Vater, den es nicht gibt', $fehlend === 0, (string) $fehlend);

$zyklen = 0;
$tiefen = [];

foreach (array_keys($alle) as $id) {
    $tiefe   = 0;
    $laeufer = $id;
    $gesehen = [];

    while (isset($vater[$laeufer])) {
        if (isset($gesehen[$laeufer])) {
            $zyklen++;

            break;
        }

        $gesehen[$laeufer] = true;
        $laeufer           = $vater[$laeufer];
        $tiefe++;
    }

    $tiefen[$tiefe] = ($tiefen[$tiefe] ?? 0) + 1;
}

ksort($tiefen);

check('kein Zyklus', $zyklen === 0, (string) $zyklen);

$doppelt = 0;

foreach ($stellen as $liste) {
    $doppelt += count($liste) - count(array_unique($liste));
}

check('keine Stelle unter einem Vater zweimal vergeben', $doppelt === 0, (string) $doppelt);

printf(
    "       gemessen: %d Knoten, %d Einordnungen, Tiefen %s\n",
    count($alle),
    count($vater),
    json_encode($tiefen)
);

echo "\n4 · Der Pfad stimmt mit der Spalte überein\n";

$falsch = [];

foreach ($alle as $id => $zeile) {
    $kette   = [$id];
    $laeufer = $id;
    $runden  = 0;

    while (isset($vater[$laeufer]) && $runden++ < 1000) {
        $laeufer = $vater[$laeufer];
        array_unshift($kette, $laeufer);
    }

    if (implode('.', $kette) !== (string) $zeile['path']) {
        $falsch[] = $id;
    }
}

check('jeder Pfad folgt der Spalte', $falsch === [], implode(',', array_slice($falsch, 0, 10)));

echo "\n5 · Die Wanderung ist umkehrbar\n";

// ⚠️ **Über die Kanten-Id und nicht über das Kind, und der Unterschied ist gemessen:** *ein Knoten
// kann **mehrere** alte Vererbungskanten im Schatten haben — Knoten 3642 hat zwei, aus zwei
// verschiedenen Umzügen. Wer nach «der letzten Version zu diesem Kind» fragt, greift die falsche und
// meldet einen Verlust, den es nicht gibt.*
$abgeloest = $wpdb->get_results($wpdb->prepare(
    "SELECT h.id, h.to_node_id, h.from_node_id, h.sort_order, h.hide
     FROM {$kanten} h
     INNER JOIN (
         SELECT id, MAX(version) AS version FROM {$kanten} WHERE kind = %s GROUP BY id
     ) neuste ON neuste.id = h.id AND neuste.version = h.version
     WHERE h.kind = %s AND h.deleted = 1
       AND NOT EXISTS (SELECT 1 FROM {$relations} l WHERE l.id = h.id)",
    'inheritance',
    'inheritance'
), ARRAY_A) ?: [];

$deckung = [];

foreach ($abgeloest as $h) {
    $kind = (int) $h['to_node_id'];

    if (! isset($alle[$kind])) {
        continue;
    }

    if ((int) $alle[$kind]['parent_node_id'] === (int) $h['from_node_id']
        && (int) $alle[$kind]['sort_order'] === (int) $h['sort_order']
        && (int) $alle[$kind]['hide'] === (int) $h['hide']
    ) {
        $deckung[$kind] = true;
    }
}

check(
    'zu jeder Einordnung liegt ihre abgeloeste Kante im Schatten',
    count($deckung) === count($vater),
    count($deckung) . ' von ' . count($vater)
);

echo "\n6 · Die Zahlen von damals sind die von heute\n";

$damals = get_option('taxmod_task018_shape', null);

if (! is_array($damals) || ! isset($damals['shape'])) {
    // ⚠️ *Eine Installation, die nie gewandert ist, hat nichts zu vergleichen — das ist kein Fehler,
    // sondern ein anderer Fall, und er wird gesagt statt gruen gefaerbt.*
    echo "  --   keine Wanderung aufgezeichnet — diese Installation war nie auf Kanten\n";
} else {
    $heute = [
        'nodes'  => count($alle),
        'edges'  => count($vater),
        'roots'  => $wurzeln,
        'depths' => $tiefen,
    ];

    $war = $damals['shape'];

    foreach (['nodes', 'edges', 'roots'] as $name) {
        check(
            "«{$name}» wie bei der Wanderung",
            (int) ($war[$name] ?? -1) === $heute[$name],
            ($war[$name] ?? '?') . ' → ' . $heute[$name]
        );
    }

    check(
        'die Tiefenverteilung wie bei der Wanderung',
        json_encode($war['depths'] ?? null) === json_encode($heute['depths']),
        json_encode($war['depths'] ?? null) . ' → ' . json_encode($heute['depths'])
    );
}

echo "\n" . ($bad === 0 ? "Alles gruen: $ok\n" : "$bad fehlgeschlagen, $ok in Ordnung\n");

exit($bad === 0 ? 0 : 1);
