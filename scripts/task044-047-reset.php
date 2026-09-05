<?php declare(strict_types=1);
/**
 * Einmalige Wanderung: TASK-044 und TASK-047 — der Rueckstand aus abgestuerzten Waechterlaeufen.
 *
 *     php scripts/task044-047-reset.php [--apply] [path/to/wordpress]
 *
 * ⚠️ **Es faellt nur, was eine geschriebene Aufgabe deckt.** *Drei Posten, jeder einzeln begruendet:*
 *
 * 1. **TASK-044** ([D-610](../docs/NewConcept/90-decision-log.md)): *«die 2 Zeilen mit `value_ref = 0`»*
 *    — gemessen ist heute **eine** uebrig. Ein Verweis auf die Nummer `0` zeigt auf keinen Knoten;
 *    `value-ref-space-check` meldet ihn als Verweis ins Leere.
 * 2. **TASK-044**, zweiter Halbsatz: *«die wirklich unbenutzten leeren Datensaetze»* — und die Grenze
 *    steht in der Aufgabe: **unbenutzt heisst nirgends referenziert, nicht ohne Wertzeilen.** Dieser
 *    Lauf nimmt deshalb nur die Teilmenge, bei der beides gemessen zutrifft und zusaetzlich **der
 *    Knoten selbst fort ist**: 28 Waisen aus abgestuerzten Geruestlaeufen vom 2026-09-04.
 *    *Die uebrigen leeren Datensaetze bleiben stehen — sie haengen an lebenden Knoten und ihre
 *    Entscheidung ist nicht geschrieben (`PR-4`).*
 * 3. **TASK-047**: *«Zwei Kanten auf den Zweigkopf ‹Constants› entfernen — Rueckstand aus
 *    Waechterlaeufen, ohne Eintrag im Aenderungsbuch.»* Der Lauf sucht sie nicht aus einer Liste in
 *    dieser Datei, sondern fragt die Kanten, die auf den Zweigkopf `Constants` **zeigen**.
 *
 * Dazu ein vierter Posten, der **nichts loescht**: zwei Wertzeilen tragen im `path` die Kante `44092`,
 * waehrend ihr eigenes `edge_id` die lebende Kante `65595` nennt. `path` ist der **Spiegel** von
 * `edge_id` (TASK-002), also traegt die Spalte keine eigene Aussage — der Spiegel wird auf seinen
 * Herrn gestellt. *Herkunft: `44092` war die `converter`-Kante des Huellknotens `DisplayOption`, den
 * [D-585](../docs/NewConcept/90-decision-log.md) gestrichen hat; die Wanderung hat `edge_id`
 * nachgezogen und den Spiegel stehen lassen.*
 *
 * ⚠️ **Alles ist umkehrbar.** *Jede geloeschte Zeile steht vorher als Schattenzeile mit `deleted = 1`
 * in ihrer `_history`; die zwei Kanten bekommen zusaetzlich ihren Eintrag im Aenderungsbuch, den sie
 * bei ihrer Entstehung nie hatten.*
 *
 * @see docs/pakete/modelltabellen/tasks.md
 */

$argumente = array_slice($argv, 1);
$apply     = in_array('--apply', $argumente, true);
$root      = null;

foreach ($argumente as $eines) {
    if ($eines !== '--apply') {
        $root = $eines;
    }
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
require dirname(__DIR__) . '/vendor/autoload.php';

use Taxmod\WordPress\Persistence\Schema;

global $wpdb;

$jetzt = current_time('mysql');

/* ---------------------------------------------------------------- 1 · value_ref = 0 */

$nullVerweise = $wpdb->get_results(
    'SELECT * FROM ' . Schema::table('record_values') . ' WHERE value_ref = 0 ORDER BY id',
    ARRAY_A
) ?: [];

echo count($nullVerweise) . " Wertzeile(n) mit value_ref = 0 (TASK-044):\n";

foreach ($nullVerweise as $z) {
    echo "  Zeile {$z['id']} · Satz {$z['record_id']} · Kante {$z['edge_id']}\n";
}

/* ------------------------------------------------- 2 · Datensaetze ohne Knoten, ohne Verweis */

$waisen = $wpdb->get_results(
    'SELECT r.* FROM ' . Schema::table('records') . ' r
      LEFT JOIN ' . Schema::table('nodes') . ' n ON n.id = r.node_id
     WHERE n.id IS NULL
     ORDER BY r.id',
    ARRAY_A
) ?: [];

$loeschbar = [];
$behalten  = [];

foreach ($waisen as $r) {
    $id = (int) $r['id'];

    // ⚠️ **Die Grenze aus TASK-044, jede Richtung einzeln gefragt.** *Wer eine dieser Fragen
    // ueberspringt, loescht eine Renderer-Wahl, die ihre Aussage in der `node_id` traegt.*
    $benutzt = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM ' . Schema::table('record_values') . ' WHERE record_id = %d',
        $id
    ))
        + (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . Schema::table('record_values')
            . " WHERE value_ref_kind = 'record' AND value_ref = %d",
            $id
        ))
        + (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . Schema::table('nodes') . ' WHERE settings_record_id = %d',
            $id
        ))
        + (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . Schema::table('relations')
            . ' WHERE settings_record_id = %d OR target_settings_record_id = %d',
            $id,
            $id
        ));

    if ($benutzt === 0) {
        $loeschbar[] = $r;
    } else {
        $behalten[] = $r;
    }
}

echo "\n" . count($loeschbar) . ' von ' . count($waisen)
    . " Datensaetzen ohne Knoten sind nirgends referenziert (TASK-044).\n";

if ($behalten !== []) {
    echo '  ' . count($behalten) . " bleiben stehen, weil etwas auf sie zeigt:\n";

    foreach ($behalten as $r) {
        echo "    Satz {$r['id']} (Knoten {$r['node_id']})\n";
    }
}

/* ------------------------------------------------------- 3 · Zwei Kanten auf «Constants» */

$constants = (int) $wpdb->get_var(
    'SELECT n.id FROM ' . Schema::table('nodes') . " n WHERE n.name = 'Constants' LIMIT 1"
);

$aufConstants = [];

if ($constants !== 0) {
    $aufConstants = $wpdb->get_results($wpdb->prepare(
        'SELECT * FROM ' . Schema::table('relations') . " WHERE to_id = %d AND kind <> 'inheritance'",
        $constants
    ), ARRAY_A) ?: [];
}

echo "\n" . count($aufConstants) . " Kante(n) zeigen auf den Zweigkopf «Constants» (TASK-047):\n";

foreach ($aufConstants as $k) {
    $von = $wpdb->get_var($wpdb->prepare(
        'SELECT name FROM ' . Schema::table('nodes') . ' WHERE id = %d',
        (int) $k['from_id']
    ));

    echo "  Kante {$k['id']} · " . ($von ?? '?') . " --{$k['name']}--> Constants ({$k['kind']})\n";
}

/* --------------------------------------------------------- 4 · Der Spiegel path = edge_id */

$spiegel = $wpdb->get_results(
    'SELECT * FROM ' . Schema::table('record_values') . ' WHERE path <> CAST(edge_id AS CHAR) ORDER BY id',
    ARRAY_A
) ?: [];

echo "\n" . count($spiegel) . " Wertzeile(n), deren path nicht ihr eigenes edge_id nennt:\n";

foreach ($spiegel as $z) {
    echo "  Zeile {$z['id']} · path {$z['path']} · edge_id {$z['edge_id']}\n";
}

if (! $apply) {
    echo "\nProbelauf. Mit --apply wird geschrieben.\n";

    exit(0);
}

/* ================================================================= schreiben */

/**
 * Die Zeile als Schattenzeile mit `deleted = 1` ablegen — das ist der Rueckweg.
 *
 * @param array<string,mixed> $zeile
 * @param list<string>        $spalten
 */
$schatten = static function (string $tabelle, array $zeile, array $spalten, bool $geloescht = true) use ($wpdb, $jetzt): void {
    $satz = ['deleted' => $geloescht ? 1 : 0, 'archived_at' => $jetzt];

    foreach ($spalten as $spalte) {
        $satz[$spalte] = $zeile[$spalte] ?? null;
    }

    $wpdb->insert(Schema::table($tabelle . '_history'), $satz);
};

$wertSpalten = [
    'id', 'record_id', 'edge_id', 'path', 'locale', 'position', 'version',
    'value_int', 'value_decimal', 'value_text', 'value_date', 'value_ref', 'value_ref_kind',
];

foreach ($nullVerweise as $z) {
    $schatten('record_values', $z, $wertSpalten);
    $wpdb->query($wpdb->prepare(
        'DELETE FROM ' . Schema::table('record_values') . ' WHERE id = %d',
        (int) $z['id']
    ));
}

foreach ($loeschbar as $r) {
    $schatten('records', $r, ['id', 'node_id', 'node_version', 'version', 'created_at', 'kind']);
    $wpdb->query($wpdb->prepare(
        'DELETE FROM ' . Schema::table('records') . ' WHERE id = %d',
        (int) $r['id']
    ));
}

foreach ($aufConstants as $k) {
    $schatten('relations', $k, [
        'id', 'version', 'from_id', 'to_id', 'kind', 'name', 'position', 'multiplicity',
        'parked_by_group_id', 'hide', 'settings_record_id', 'target_settings_record_id',
    ]);

    // ⚠️ *Der Eintrag, den die Aufgabe vermisst — «ohne Eintrag im Aenderungsbuch» war der Befund.*
    $wpdb->insert(Schema::table('changelog'), [
        'owner_id'    => (int) $k['id'],
        'owner_kind'  => 'relation',
        'at'          => $jetzt,
        'by_user_id'  => 0,
        'what'        => 'attribute removed',
        'before_state' => wp_json_encode($k),
        'after_state' => null,
        'version'     => (int) $k['version'],
    ]);

    $wpdb->query($wpdb->prepare(
        'DELETE FROM ' . Schema::table('relations') . ' WHERE id = %d',
        (int) $k['id']
    ));
}

foreach ($spiegel as $z) {
    // ⚠️ *Hier faellt nichts, also `deleted = 0` — die Schattenzeile ist die alte Fassung, nicht ein Grab.*
    $schatten('record_values', $z, $wertSpalten, false);
    $wpdb->query($wpdb->prepare(
        'UPDATE ' . Schema::table('record_values') . ' SET path = %s WHERE id = %d',
        (string) $z['edge_id'],
        (int) $z['id']
    ));
}

echo "\nGeschrieben: " . count($nullVerweise) . ' Wertzeile(n) geloescht, '
    . count($loeschbar) . ' Datensatz/Datensaetze geloescht, '
    . count($aufConstants) . ' Kante(n) geloescht, '
    . count($spiegel) . " Spiegel gestellt.\n";
