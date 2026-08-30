<?php declare(strict_types=1);
/**
 * Die `read_only`-Zeilen, die nur die Vorgabe wiederholen — Zeile 85.
 *
 *     php scripts/migrate-readonly-settings.php            (Probelauf)
 *     php scripts/migrate-readonly-settings.php --write    (entfernt, was nichts sagt)
 *
 * ⚠️ **Hier zieht nichts um, hier fällt nur weg.** *Gemessen am 2026-08-30 sagen **alle 147** Zeilen
 * `read_only = false` — und `false` ist auch die Antwort, wenn keine Zeile da ist
 * (`SettingKey::ReadOnly->declaredDefault()`). **Sie tragen keine Aussage.***
 *
 * ⚠️ **Und das ist dieselbe Krankheit, die [D-505](../docs/NewConcept/90-decision-log.md) gemessen
 * hat:** *«von 326 Settings-Zeilen sind 191 reine Kopien des Elternwerts … diese Zeilen behaupten eine
 * Entscheidung, die niemand getroffen hat.»*
 *
 * ⚠️ **`read_only` selbst bleibt** ([D-538](../docs/NewConcept/90-decision-log.md)): *ein
 * schreibgeschützter Wert **landet** im Datensatz und ist nur unveränderlich — anders als eine
 * Einstellung, die gar nicht erst dort landet. Der Schlüssel sagt etwas Eigenes; **diese Zeilen nicht**.*
 *
 * ⚠️ *Jede Zeile wird **einzeln** gegen die Vorgabe geprüft, bevor sie geht. Trägt eine doch eine
 * Aussage, bleibt der ganze Lauf stehen.*
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

use Taxmod\Core\Model\SettingKey;
use Taxmod\WordPress\Persistence\Schema;

global $wpdb;

$vorgabe = SettingKey::ReadOnly->defaultSwitch();

echo 'Die Vorgabe von read_only ist ' . ($vorgabe ? 'true' : 'false') . "\n";

$zeilen = $wpdb->get_results(
    "SELECT id, owner_id, path, value_int FROM " . Schema::table('settings') . "
     WHERE setting_key = 'read_only' ORDER BY id",
    ARRAY_A
) ?: [];

$weg     = [];
$behalten = [];

foreach ($zeilen as $z) {
    // ⚠️ *Ein `null` ist **nicht** dasselbe wie die Vorgabe: es hiesse «hier steht eine Zeile, die
    // nichts sagt», und das ist ein dritter Zustand. Sie bleibt stehen und wird gemeldet.*
    if ($z['value_int'] === null) {
        $behalten[] = "Zeile {$z['id']}: der Wert ist NULL, nicht die Vorgabe";

        continue;
    }

    if (((int) $z['value_int'] === 1) !== $vorgabe) {
        $behalten[] = "Zeile {$z['id']}: sagt " . ((int) $z['value_int'] === 1 ? 'true' : 'false') . ', also etwas anderes';

        continue;
    }

    if (($z['path'] ?? '') !== '') {
        $behalten[] = "Zeile {$z['id']}: hat eine Adresse ({$z['path']}) — die will erst verstanden werden";

        continue;
    }

    $weg[] = (int) $z['id'];
}

printf("\n%d Zeilen wiederholen nur die Vorgabe, %d sagen etwas\n", count($weg), count($behalten));

foreach ($behalten as $b) {
    echo "  ⚠ {$b}\n";
}

if ($behalten !== []) {
    fwrite(STDERR, "\nEs bleibt alles stehen, bis diese geklaert sind.\n");
    exit(1);
}

if (! $schreiben) {
    echo "\nProbelauf. Mit --write entfernen.\n";
    exit(0);
}

$liste  = implode(',', $weg);
$entf   = (int) $wpdb->query("DELETE FROM " . Schema::table('settings') . " WHERE id IN ({$liste})");
$uebrig = (int) $wpdb->get_var("SELECT COUNT(*) FROM " . Schema::table('settings'));

echo "\n{$entf} Zeilen entfernt. settings hat noch {$uebrig}.\n";
