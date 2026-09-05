<?php declare(strict_types=1);

/**
 * Die Wanderung aus [TASK-020]: der Halter eines Einstellungsdatensatzes zieht von der Kante in
 * die Spalte `nodes.settings_record_id`.
 *
 * ⚠️ **Heute** `Knoten → Wertzeile an der Kante «Display Option» → Satz`, **danach**
 * `Knoten.settings_record_id → Satz`. *Die Kante «Display Option» gibt es nicht mehr — der
 * Eigentümer hat den Hüllknoten gelöscht ([D-604](../../docs/NewConcept/90-decision-log.md)) —,
 * und damit hängen die 29 Wertzeilen an einer Kante, die es nicht gibt. Der Zeiger gehört an den
 * Ort, den [D-584](../../docs/NewConcept/90-decision-log.md) nennt: **eine Spalte, keine Kante.***
 *
 * ⚠️ **Gemessen, nicht angenommen:** *der Besitzer ist der Knoten, dem der Datensatz gehört, in dem
 * die Wertzeile steht (`records.node_id`). Ist er mehrdeutig — zwei Wertzeilen für denselben
 * Knoten — bricht dieses Skript ab, statt eine zu wählen.*
 *
 * ⚠️ **Was dieses Skript ausdrücklich NICHT anfasst:** *Wertzeilen an anderen toten Kanten, die
 * Datensätze selbst, und die Sätze ohne Knoten. Siehe `docs/pakete/modelltabellen/inbox.md`.*
 *
 * Aufruf: php scripts/dev/settings-record-column-migrate.php          (nur zaehlen)
 *         php scripts/dev/settings-record-column-migrate.php --go     (schreiben, Transaktion)
 *
 * @see docs/NewConcept/90-decision-log.md
 */

define('WP_USE_THEMES', false);

require 'C:/Devel/Wordpress/wp-load.php';

$go = in_array('--go', $argv, true);

global $wpdb;

$p = $wpdb->prefix . 'taxmod_';

// ⚠️ *Die gefallene Traegerkante «Display Option». Sie steht nicht mehr in `relations`; ihre Id
// steht nur noch in den Wertzeilen, die sie halten.*
$deadCarrierEdgeId = 44093;

// ---------------------------------------------------------------- messen

$rows = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT v.id, v.node_record_id, v.value_ref, v.value_ref_kind, r.node_id AS owner_node
           FROM {$p}relation_records v
           JOIN {$p}node_records r ON r.id = v.node_record_id
          WHERE v.relation_id = %d",
        $deadCarrierEdgeId
    ),
    ARRAY_A
);

$moves        = [];   // owner_node => [value_row_id, settings_record_id]
$leftStanding = [];

foreach ($rows as $row) {
    if ($row['value_ref_kind'] !== 'record' || (int) $row['value_ref'] === 0) {
        $leftStanding[] = $row;

        continue;
    }

    $owner = (int) $row['owner_node'];

    // ⚠️ **Die Wurzel bleibt ausdrücklich stehen — gemessen, nicht vermutet.** *Ihr Halter zeigt auf
    // einen Satz von `checkbox`. Solange er an einer toten Kante hing, sah ihn niemand; **in der
    // Spalte wirkt er, und die Vorfahrenkette aus [D-602](../../docs/NewConcept/90-decision-log.md)
    // trägt ihn an jeden Knoten des Modells.** Gemessen: `composition-check` und `package7-check`
    // wurden davon rot — «a bool gets the sliding switch — checkbox», «the date is a date control».
    // Das ist `INF-014`, eine eigene Entscheidung, und sie gehört nicht in diese Wanderung.*
    // *Eine Wurzel erkennt man an ihrem Pfad: er ist ihre eigene Id, ohne Punkt.*
    $istWurzel = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$p}nodes WHERE id = %d AND path = CAST(id AS CHAR)",
        $owner
    ));

    if ($istWurzel === 1) {
        $leftStanding[] = $row + ['grund' => 'INF-014, Display-Option am Wurzelknoten'];

        continue;
    }

    // Zwei Halter fuer einen Knoten waeren zwei Antworten auf eine Frage. Abbruch ist besser als
    // eine stille Wahl.
    if (isset($moves[$owner])) {
        fwrite(STDERR, "ABBRUCH: Knoten {$owner} hat mehr als einen Einstellungshalter.\n");

        exit(1);
    }

    $moves[$owner] = [(int) $row['id'], (int) $row['value_ref']];
}

$owners = array_keys($moves);
$ownerIn = $owners === [] ? '0' : implode(',', array_map('intval', $owners));

$knownOwners = array_map('intval', $wpdb->get_col("SELECT id FROM {$p}nodes WHERE id IN ({$ownerIn})"));
$missingOwner = array_diff($owners, $knownOwners);

if ($missingOwner !== []) {
    fwrite(STDERR, 'ABBRUCH: Besitzerknoten fehlen: ' . implode(', ', $missingOwner) . "\n");

    exit(1);
}

$targets = array_values(array_unique(array_map(static fn (array $m): int => $m[1], $moves)));
$targetIn = $targets === [] ? '0' : implode(',', array_map('intval', $targets));

$knownTargets = array_map('intval', $wpdb->get_col("SELECT id FROM {$p}node_records WHERE id IN ({$targetIn})"));
$missingTarget = array_diff($targets, $knownTargets);

if ($missingTarget !== []) {
    fwrite(STDERR, 'ABBRUCH: Einstellungsdatensaetze fehlen: ' . implode(', ', $missingTarget) . "\n");

    exit(1);
}

// Eine schon gefuellte Spalte waere ein zweiter Schreiber. Gemessen gibt es keinen; gepruefft wird
// es trotzdem, weil ein stiller Ueberschreiber genau der Fehler ist, den heute schon einer gemacht
// hat.
$alreadySet = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$p}nodes WHERE id IN ({$ownerIn}) AND settings_record_id IS NOT NULL"
);

if ($alreadySet > 0) {
    fwrite(STDERR, "ABBRUCH: {$alreadySet} Besitzer tragen schon eine settings_record_id.\n");

    exit(1);
}

$filledBefore = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}nodes WHERE settings_record_id IS NOT NULL");
$deadBefore   = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$p}relation_records v
       LEFT JOIN {$p}relations r ON r.id = v.relation_id
      WHERE r.id IS NULL"
);

// ---------------------------------------------------------------- zeigen

printf("Gefuellte nodes.settings_record_id, vorher: %d\n", $filledBefore);
printf("Wertzeilen an Kanten, die es nicht gibt, vorher: %d\n\n", $deadBefore);

printf("Halter, die in die Spalte ziehen: %d\n", count($moves));

$names = $wpdb->get_results("SELECT id, name FROM {$p}nodes WHERE id IN ({$ownerIn})", OBJECT_K);

foreach ($moves as $owner => [$valueRowId, $recordId]) {
    printf("   %-26s Knoten %-6d → Satz %d\n", $names[$owner]->name ?? '?', $owner, $recordId);
}

printf("\nZeilen, die ausdruecklich stehenbleiben (nicht in TASK-020): %d\n", count($leftStanding));

foreach ($leftStanding as $row) {
    printf(
        "   Wertzeile %d an Satz %d — kind=%s ref=%s%s\n",
        $row['id'],
        $row['node_record_id'],
        $row['value_ref_kind'],
        $row['value_ref'],
        isset($row['grund']) ? ' — ' . $row['grund'] : ''
    );
}

$expectedDead = $deadBefore - count($moves);

printf("\nGefuellte Spalten danach erwartet: %d\n", $filledBefore + count($moves));
printf("Wertzeilen an toten Kanten danach erwartet: %d\n", $expectedDead);

if (! $go) {
    echo "\n— Probelauf. Mit --go wird geschrieben. —\n";

    exit(0);
}

// ---------------------------------------------------------------- schreiben

$wpdb->query('START TRANSACTION');

$failed = null;

foreach ($moves as $owner => [$valueRowId, $recordId]) {
    $ok = $wpdb->query($wpdb->prepare(
        "UPDATE {$p}nodes SET settings_record_id = %d WHERE id = %d",
        $recordId,
        $owner
    ));

    if ($ok === false) {
        $failed = "Spalte an Knoten {$owner}";

        break;
    }

    if ($wpdb->query($wpdb->prepare("DELETE FROM {$p}relation_records WHERE id = %d", $valueRowId)) === false) {
        $failed = "Wegfall der Halterzeile {$valueRowId}";

        break;
    }
}

if ($failed !== null) {
    $wpdb->query('ROLLBACK');

    fwrite(STDERR, "\nFEHLGESCHLAGEN bei: {$failed} — ROLLBACK, nichts geschrieben.\n");

    exit(1);
}

$wpdb->query('COMMIT');

$filledAfter = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}nodes WHERE settings_record_id IS NOT NULL");
$deadAfter   = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$p}relation_records v
       LEFT JOIN {$p}relations r ON r.id = v.relation_id
      WHERE r.id IS NULL"
);

printf("\nGeschrieben.\n");
printf("Gefuellte nodes.settings_record_id: %d (erwartet %d)\n", $filledAfter, $filledBefore + count($moves));
printf("Wertzeilen an toten Kanten: %d (erwartet %d)\n", $deadAfter, $expectedDead);

exit($deadAfter === $expectedDead ? 0 : 1);
