<?php declare(strict_types=1);
/**
 * Die Zahlenschlüssel und der Müll darin — Zeile 85.
 *
 *     php scripts/migrate-numeric-settings.php            (Probelauf)
 *     php scripts/migrate-numeric-settings.php --write    (raeumt weg)
 *
 * ⚠️ **Hier wird nichts umgezogen, hier wird weggeräumt — auf sein Wort, Punkt für Punkt.**
 *
 * | Zeilen | was sie sagen | sein Urteil |
 * |---|---|---|
 * | `min`/`max`/`step` an `Integer` und vier Verwendungsstellen | die äussersten Werte des Typs selbst, Schritt 1 | *«erst mal wegräumen, wenn wir wieder was brauchen, holen wir das wieder und definieren es»* |
 * | `max = «int_max»` an `Parts List` | ein **Wort** in einem Zahlenschlüssel | *«das ist Müll»* |
 * | `factor`/`offset` an `Passiv` | eine Einheitenumrechnung an einer Bauteilkategorie | *«auch Müll, legen wir anders an mit Setting»* |
 * | `default = 300` an `Integer`, ohne Adresse | ein Vorgabewert ohne Feld | *«es gibt kein Feld mehr, das die 3 sagt»* — alte Welt, im neuen Modell ohne Ort |
 *
 * ⚠️ **Wegräumen heisst heute nicht mehr verlieren** ([D-536](../docs/NewConcept/90-decision-log.md)):
 * *jede Zeile wandert vorher in die Sicherung, und die Datensätze, die dabei entstünden, gäbe es
 * ohnehin nicht. Er: «wenn wir wieder was brauchen, holen wir das wieder».*
 *
 * ⚠️ *`factor` und `offset` an `Celsius` **bleiben** — das ist die Umrechnung nach Kelvin und die
 * einzige echte Aussage unter diesen Schlüsseln.*
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

use Taxmod\WordPress\Persistence\Schema;

global $wpdb;

$s     = Schema::table('settings');
$nodes = Schema::table('nodes');
$rels  = Schema::table('relations');

$zeilen = $wpdb->get_results(
    "SELECT s.id, s.setting_key, s.owner_id, s.path, s.value_int, s.value_decimal, s.value_text,
            n.name AS knoten, rel.name AS feld, von.name AS von
     FROM {$s} s
     LEFT JOIN {$nodes} n   ON n.id = s.owner_id
     LEFT JOIN {$rels} rel  ON rel.id = s.owner_id
     LEFT JOIN {$nodes} von ON von.id = rel.from_node_id
     WHERE s.setting_key IN ('min', 'max', 'step', 'factor', 'offset', 'default')
     ORDER BY s.setting_key, s.id",
    ARRAY_A
) ?: [];

$weg      = [];
$bleiben  = [];

foreach ($zeilen as $z) {
    $wo  = $z['knoten'] ?? (($z['von'] ?? '?') . '.' . ($z['feld'] ?? '?'));
    $key = (string) $z['setting_key'];

    // ⚠️ *`Celsius` ist die Ausnahme und wird ausdrücklich benannt, nicht übersehen.*
    if (in_array($key, ['factor', 'offset'], true) && $z['knoten'] === 'Celsius') {
        $bleiben[] = "{$key} an «Celsius» — die Umrechnung nach Kelvin";

        continue;
    }

    // ⚠️ *Die Vorgabewerte der Präfixe ziehen um und werden hier nicht angefasst: sie haben eine
    // Adresse. Nur die ohne Adresse ist die heimatlose.*
    if ($key === 'default' && ($z['path'] ?? '') !== '') {
        $bleiben[] = "default an «{$wo}» — hat eine Adresse, zieht um";

        continue;
    }

    $wert = $z['value_int'] ?? $z['value_decimal'] ?? $z['value_text'] ?? 'NULL';
    $weg[] = ['id' => (int) $z['id'], 'was' => sprintf('%-8s an %-26s = %s', $key, substr($wo, 0, 26), $wert)];
}

printf("%d Zeilen weg, %d bleiben\n\n", count($weg), count($bleiben));

foreach ($weg as $z) {
    echo "  weg     {$z['was']}\n";
}

foreach ($bleiben as $b) {
    echo "  bleibt  {$b}\n";
}

if (! $schreiben) {
    echo "\nProbelauf. Mit --write wegraeumen.\n";
    exit(0);
}

$liste = implode(',', array_map(static fn (array $z): int => $z['id'], $weg));
$entf  = (int) $wpdb->query("DELETE FROM {$s} WHERE id IN ({$liste})");

echo "\n{$entf} Zeilen entfernt. settings hat noch " . (int) $wpdb->get_var("SELECT COUNT(*) FROM {$s}") . ".\n";
