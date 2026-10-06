<?php declare(strict_types=1);

/**
 * Die zweite Hälfte von [D-621](../../docs/NewConcept/90-decision-log.md): `nodes.field_type` fällt.
 *
 * ⚠️ **Sein Wort, das den Rückbau eingefordert hat:** *«aber der Rueckbau am Knoten gehoert doch
 * fachlich dazu, wie kannst du das dann stehen lassen?»* *TASK-032 hatte nur die Kantenseite gebaut.*
 *
 * ⚠️ **Was an die Stelle der Spalte tritt, und es ist keine Erfindung:** *«die Kante sagt, was etwas
 * hier ist — nicht der Knoten und nicht der Ast.» **Ein Knoten ist selbst eine Einstellung, wenn jede
 * eingehende Kante eine Einstellungskante ist**; einen, den nur Vererbung erreicht, beantwortet die
 * Kante über seinem nächsten Vorfahren. *«wenn man am Vater irgendwas anhaengt, ist es genauso in den
 * Kindern verfuegbar.»*
 *
 * ⚠️ **Die neunzehn Renderer verlieren die Marke zu Recht** ([D-621](../../docs/NewConcept/90-decision-log.md)):
 * *«sie sind Werte, die man in einer Einstellung waehlt, keine Einstellungen. Dass sie herausfallen,
 * ist die Berichtigung und nicht der Verlust.»* **Und genau davon lebt der Wähler**: er entsteht aus
 * den **unmarkierten** Kindern des Kantenziels ([D-540](../../docs/NewConcept/90-decision-log.md)) und
 * bot bis heute null Möglichkeiten an (`INF-042`).
 *
 * ⚠️ **Dieses Skript schreibt nichts, und das ist eine Lehre und kein Versäumnis.** *Der erste
 * Entwurf sicherte hier — Schattenzeile, Änderungsgruppe, Version — und **kam nie zum Zug: das Laden
 * von WordPress hebt die Schemafassung, bevor die erste Zeile des Skripts läuft.** Die 39 Marken
 * waren weg, als das Skript startete. **Das Sichern gehört darum in den Fassungsschritt selbst**
 * ({@see \Taxmod\WordPress\Persistence\Schema}), wo es auf jeder Installation läuft und nichts es
 * überholen kann. Hier bleibt das Zählen: **was die Kante sagen wird, gegen das, was in der Spalte
 * steht.***
 *
 * ⚠️ **Der Massstab ist, was jeder Knoten zeichnet.** *Vor- und nachher mit
 * [`renderer-per-node.php`](renderer-per-node.php) abnehmen und vergleichen — weicht eine Zeile ab,
 * bricht die Wanderung ab.*
 *
 *     php scripts/dev/field-type-drop-migrate.php
 *
 * @see docs/pakete/modelltabellen/package.md
 */

$root = null;

foreach (array_slice($argv, 1) as $arg) {
    $root ??= $arg;
}

$root ??= getenv('WP_ROOT') ?: null;

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

$nodes = Schema::table('nodes');

if ($wpdb->get_var("SHOW COLUMNS FROM {$nodes} LIKE 'field_type'") === null) {
    echo "Die Spalte ist schon weg — nichts zu tun.\n";

    exit(0);
}

$markiert = $wpdb->get_results(
    "SELECT id, version, name, field_type FROM {$nodes} WHERE field_type IS NOT NULL AND field_type <> ''",
    ARRAY_A
) ?: [];

printf("%d Knoten tragen eine Marke.\n", count($markiert));

// ⚠️ **Der Vergleich, der die Wanderung trägt: was die Kante sagen wird, gegen das, was in der
// Spalte steht.** *Er wird gezählt und ausgewiesen — die Abweichungen sind die Berichtigung, die
// D-621 ausdrücklich will, und dürfen darum nicht stillschweigend durchlaufen.*
$eltern = [];
$pfade  = [];

foreach ($wpdb->get_results("SELECT id, path, field_type FROM {$nodes}", ARRAY_A) ?: [] as $zeile) {
    $pfade[(int) $zeile['id']] = $zeile;
}

$eigene = [];

foreach ($wpdb->get_results('SELECT to_node_id, kind FROM ' . Schema::table('relations'), ARRAY_A) ?: [] as $zeile) {
    $ziel = (int) $zeile['to_node_id'];

    $eigene[$ziel] = ($eigene[$ziel] ?? 'setting') === 'setting' && $zeile['kind'] === 'setting'
        ? 'setting'
        : 'model';
}

$abweichungen = [];

foreach ($pfade as $id => $zeile) {
    $antwort = 'model';

    foreach (array_reverse(explode('.', (string) $zeile['path'])) as $stufe) {
        if (isset($eigene[(int) $stufe])) {
            $antwort = $eigene[(int) $stufe];

            break;
        }
    }

    $spalte = ($zeile['field_type'] ?? '') === 'setting' ? 'setting' : 'model';

    if ($antwort !== $spalte) {
        $abweichungen[$id] = $spalte . ' -> ' . $antwort;
    }
}

printf(
    "%d Knoten bekommen aus der Kante eine andere Antwort als aus der Spalte%s\n",
    count($abweichungen),
    $abweichungen === [] ? '.' : ': ' . implode(', ', array_map(
        static fn (int $id, string $wie): string => ($pfade[$id]['id'] ?? $id) . ' ' . $wie,
        array_keys($abweichungen),
        $abweichungen
    ))
);

echo "Gesichert und geloescht wird in Schema (Fassung 33), nicht hier.\n";
