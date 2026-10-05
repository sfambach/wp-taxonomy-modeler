<?php declare(strict_types=1);
/**
 * Die Pfade nachziehen, die der Zusammenzug falsch geschrieben hat.
 *
 *     php scripts/fix-merged-paths.php            (Probelauf)
 *     php scripts/fix-merged-paths.php --write    (wirklich)
 *
 * ⚠️ **Mein Fehler, und er ist gemessen.** *`scripts/merge-render-label-nodes.php` hat angenommen,
 * `path` seien die **Vorfahren mit Punkt am Ende** — tatsächlich enthält er die **eigene Id** und hat
 * **keinen** Punkt am Ende: `form` ist `1.40768.43495.55659.43511`. Also traf das `LIKE` nichts
 * («0 Pfade nachgezogen»), und der Papierkorb-Pfad wurde zu `1.22.` statt `1.2.55520`.*
 *
 * ⚠️ **Was daran lehrreich ist:** *das Skript hat gemeldet, dass es null Zeilen geändert hat, und der
 * Lauf ging trotzdem als Erfolg durch. **Null ist kein Fehler und sieht wie einer aus** — dieselbe
 * Falle wie bei `$wpdb`. Eine Zahl, die man ausgibt und nicht prüft, ist keine Prüfung.*
 *
 * ⚠️ *Dieses Skript rechnet den Pfad aus der **Vererbungskette** neu aus, statt mit Zeichenketten zu
 * rechnen — dann kann es keine zweite falsche Annahme über das Format geben.*
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

$n = Schema::table('nodes');
$r = Schema::table('relations');

/** Der Elternteil eines Knotens, über seine Vererbungskante. */
function elternVon(int $id): ?int
{
    global $wpdb, $r;

    $von = $wpdb->get_var($wpdb->prepare(
        "SELECT from_node_id FROM {$r} WHERE to_node_id = %d AND kind = 'inheritance' LIMIT 1",
        $id
    ));

    return $von === null ? null : (int) $von;
}

/** Der Pfad, wie er sein muss: die Kette von der Wurzel bis einschliesslich hierher. */
function pfadFuer(int $id): string
{
    $kette = [$id];
    $lauf  = $id;

    // ⚠️ *Mit Bremse: ein Zyklus in der Vererbung wäre ein anderer Fehler, und dieses Skript soll
    // dabei nicht endlos laufen.*
    for ($i = 0; $i < 64; $i++) {
        $eltern = elternVon($lauf);

        if ($eltern === null) {
            break;
        }

        $kette[] = $eltern;
        $lauf    = $eltern;
    }

    return implode('.', array_reverse($kette));
}

// ⚠️ *Alle Knoten prüfen und nicht nur die vier bewegten: **wenn das Format einmal falsch verstanden
// wurde, weiss ich nicht, wo sonst noch etwas schief steht.** Der Lauf ist billig und sagt die Wahrheit.*
$alle = $wpdb->get_results("SELECT id, name, path FROM {$n}", ARRAY_A) ?: [];

$falsch = [];

foreach ($alle as $z) {
    $id   = (int) $z['id'];
    $soll = pfadFuer($id);

    if ($soll !== (string) $z['path']) {
        $falsch[] = ['id' => $id, 'name' => $z['name'], 'ist' => (string) $z['path'], 'soll' => $soll];
    }
}

echo count($alle) . " Knoten geprueft, " . count($falsch) . " mit falschem Pfad:\n\n";

foreach (array_slice($falsch, 0, 30) as $f) {
    printf("  #%-7s %-24s ist «%s»  soll «%s»\n", $f['id'], substr($f['name'], 0, 24), $f['ist'], $f['soll']);
}

if (count($falsch) > 30) {
    echo '  … und ' . (count($falsch) - 30) . " weitere\n";
}

if ($falsch === []) {
    echo "Nichts zu tun.\n";

    exit(0);
}

if (! $schreiben) {
    echo "\nProbelauf. Mit --write ausfuehren.\n";

    exit(0);
}

$geschrieben = 0;

foreach ($falsch as $f) {
    $getan = $wpdb->query($wpdb->prepare(
        "UPDATE {$n} SET path = %s, version = version + 1 WHERE id = %d",
        $f['soll'],
        $f['id']
    ));

    if ($getan === false || $wpdb->last_error !== '') {
        fwrite(STDERR, "Knoten {$f['id']} nicht geschrieben: {$wpdb->last_error}\n");

        exit(1);
    }

    ++$geschrieben;
}

// ⚠️ **Nachgezaehlt und nicht gemeldet.** *Der Fehler, den dieses Skript aufräumt, war genau eine
// gemeldete Zahl, die niemand geprüft hat.*
$nachher = 0;

foreach ($wpdb->get_results("SELECT id, path FROM {$n}", ARRAY_A) ?: [] as $z) {
    if (pfadFuer((int) $z['id']) !== (string) $z['path']) {
        ++$nachher;
    }
}

echo "\n{$geschrieben} Pfade geschrieben, danach noch {$nachher} falsch.\n";

exit($nachher === 0 ? 0 : 1);
