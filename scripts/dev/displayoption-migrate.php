<?php declare(strict_types=1);

/**
 * Die Wanderung aus [D-594](../../docs/NewConcept/90-decision-log.md): der Huellknoten
 * `DisplayOption` faellt, seine Renderer-Wahlen ziehen eine Ebene hoch.
 *
 * ⚠️ **Heute** `Halter → Satz (node_id = DisplayOption) → Feld render → Knoten «compact»`,
 * **danach** `Halter → Satz (node_id = «compact»)`. *Der Verweis, der schon dasteht, wird zur
 * `node_id` des Satzes; die `render`-Wertzeile faellt damit weg. Das ist mechanisch und braucht
 * keine Entscheidung je Zeile* ([D-594](../../docs/NewConcept/90-decision-log.md)).
 *
 * ⚠️ **Der Konverter zieht mit, aber an eine andere Kante:** *er ist seit
 * [D-585](../../docs/NewConcept/90-decision-log.md) eine Einstellung am Basisknoten `Renderer` und
 * wird an jeden Renderer vererbt. Die Wertzeile wechselt darum von der toten `DisplayOption`-Kante
 * auf die geerbte `converter`-Kante.*
 *
 * ⚠️ **Was dieses Skript ausdruecklich NICHT anfasst** — *weil es nicht in
 * [D-594](../../docs/NewConcept/90-decision-log.md) steht und Erfinden schlimmer ist als
 * Liegenlassen:* die `render`-Wertzeilen der neuen Form (`value_ref_kind = 'record'`,
 * [D-583](../../docs/NewConcept/90-decision-log.md)), Wertzeilen ohne Verweis (`value_ref = 0`),
 * die Halterzeilen der umgezogenen Saetze, und Datensaetze verschwundener Knoten ausserhalb von
 * `DisplayOption`. Siehe `docs/pakete/modelltabellen/inbox.md`.
 *
 * ⚠️ *Probelauf ist die Voreinstellung. Ohne `--go` wird nichts geschrieben, nur gezaehlt und
 * gezeigt — dasselbe Muster wie {@see orphans-clean.php}, aus demselben Grund.*
 *
 * Aufruf: php scripts/dev/displayoption-migrate.php          (nur zaehlen)
 *         php scripts/dev/displayoption-migrate.php --go     (schreiben, in einer Transaktion)
 *
 * @see docs/NewConcept/90-decision-log.md
 */

define('WP_USE_THEMES', false);

require (getenv('WP_ROOT') ?: 'C:/Devel/Wordpress') . '/wp-load.php';

$go = in_array('--go', $argv, true);

global $wpdb;

$p = $wpdb->prefix . 'taxmod_';

// ⚠️ **Der Huellknoten ist schon geloescht** — der Eigentuemer hat ihn selbst entfernt, und genau
// dadurch stehen seine Saetze ohne Knoten da ([D-604](../../docs/NewConcept/90-decision-log.md)).
// Er ist deshalb nicht ueber `nodes` auffindbar, sondern nur ueber die `node_id`, die seine Saetze
// noch tragen.
$hullNodeId = 44089;
// Die Felder des gefallenen Huellknotens. Ihre Kanten sind mit ihm gegangen, ihre Ids stehen aber
// noch in den Wertzeilen.
$renderRelationId    = 44091;
$converterRelationId = 44092;
// Das geerbte `converter`-Feld am Basisknoten `Renderer` (D-585).
$inheritedConverterRelationId = 65595;

// ---------------------------------------------------------------- messen

$renderRows = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT v.id, v.node_record_id, v.value_ref, v.value_ref_kind
           FROM {$p}relation_records v
           JOIN {$p}node_records r ON r.id = v.node_record_id
          WHERE r.node_id = %d AND v.relation_id = %d",
        $hullNodeId,
        $renderRelationId
    ),
    ARRAY_A
);

$moves      = [];   // node_record_id => [value_row_id, ziel_node_id]
$leftStanding = []; // Zeilen, die nicht in D-594 stehen

foreach ($renderRows as $row) {
    if ($row['value_ref_kind'] !== 'node' || (int) $row['value_ref'] === 0) {
        $leftStanding[] = $row;

        continue;
    }

    $recordId = (int) $row['node_record_id'];

    // Zwei Knotenverweise an einem Satz waeren nicht mechanisch aufloesbar. Gemessen gibt es das
    // nicht — aber ein Abbruch ist besser als eine stille Wahl.
    if (isset($moves[$recordId])) {
        fwrite(STDERR, "ABBRUCH: Satz {$recordId} hat mehr als einen render-Knotenverweis.\n");

        exit(1);
    }

    $moves[$recordId] = [(int) $row['id'], (int) $row['value_ref']];
}

$targets = array_values(array_unique(array_map(static fn (array $m): int => $m[1], $moves)));
$known   = $targets === []
    ? []
    : $wpdb->get_results(
        "SELECT id, name, version FROM {$p}nodes_named WHERE id IN (" . implode(',', $targets) . ')',
        OBJECT_K
    );

$missing = array_diff($targets, array_map('intval', array_keys($known)));

if ($missing !== []) {
    fwrite(STDERR, 'ABBRUCH: Ziel-Knoten fehlen: ' . implode(', ', $missing) . "\n");

    exit(1);
}

// Die Konverter-Werte. Nur die mit einem echten Verweis ziehen um — eine Zeile ohne Verweis traegt
// nichts, und was nichts traegt, wird nicht an eine neue Kante gehaengt.
$converterRows = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT v.id, v.node_record_id, v.value_ref, v.value_ref_kind
           FROM {$p}relation_records v
           JOIN {$p}node_records r ON r.id = v.node_record_id
          WHERE r.node_id = %d AND v.relation_id = %d",
        $hullNodeId,
        $converterRelationId
    ),
    ARRAY_A
);

$converterMoves = [];

foreach ($converterRows as $row) {
    if ((int) $row['value_ref'] === 0) {
        $leftStanding[] = $row;

        continue;
    }

    $converterMoves[] = (int) $row['id'];
}

// Die leeren Behaelter: Saetze des Huellknotens, die keine Renderer-Wahl tragen. D-594: «fallen
// ersatzlos». Mit ihnen faellt die Halterzeile, die auf sie zeigt — sie zeigte danach auf nichts,
// und eine Leiche mehr zu erzeugen waere das Gegenteil des Auftrags.
$kept  = array_keys($moves);
$keptIn = $kept === [] ? '0' : implode(',', array_map('intval', $kept));

$emptyRecords = array_map('intval', $wpdb->get_col(
    $wpdb->prepare(
        "SELECT id FROM {$p}node_records WHERE node_id = %d AND id NOT IN ({$keptIn})",
        $hullNodeId
    )
));

$emptyIn = $emptyRecords === [] ? '0' : implode(',', $emptyRecords);

$emptyWithValues = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$p}relation_records WHERE node_record_id IN ({$emptyIn})"
);

if ($emptyWithValues > 0) {
    fwrite(STDERR, "ABBRUCH: {$emptyWithValues} Wertzeilen an angeblich leeren Behaeltern.\n");

    exit(1);
}

$danglingHolders = array_map('intval', $wpdb->get_col(
    "SELECT id FROM {$p}relation_records
      WHERE value_ref_kind = 'record' AND value_ref IN ({$emptyIn})"
));

$orphansBefore = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$p}node_records r
       LEFT JOIN {$p}nodes n ON n.id = r.node_id
      WHERE n.id IS NULL"
);

// ---------------------------------------------------------------- zeigen

printf("Datensaetze ohne Knoten, vorher: %d\n", $orphansBefore);
printf("Davon vom Huellknoten %d: %d\n\n", $hullNodeId, count($moves) + count($emptyRecords));

printf("Renderer-Wahlen, die umziehen: %d\n", count($moves));

$byTarget = [];

foreach ($moves as [, $target]) {
    $byTarget[$target] = ($byTarget[$target] ?? 0) + 1;
}

arsort($byTarget);

foreach ($byTarget as $target => $count) {
    printf("   %-18s %2d  (Knoten %d)\n", $known[$target]->name, $count, $target);
}

printf("\nKonverter-Werte an die geerbte Kante %d: %d\n", $inheritedConverterRelationId, count($converterMoves));
printf("Leere Behaelter, die fallen: %d\n", count($emptyRecords));
printf("   mit ihnen ihre Halterzeilen ins Leere: %d\n", count($danglingHolders));

printf("\nZeilen, die ausdruecklich stehenbleiben (nicht in D-594): %d\n", count($leftStanding));

foreach ($leftStanding as $row) {
    printf(
        "   Wertzeile %d an Satz %d — kind=%s ref=%s\n",
        $row['id'],
        $row['node_record_id'],
        $row['value_ref_kind'] === '' ? '(leer)' : $row['value_ref_kind'],
        $row['value_ref']
    );
}

$expectedAfter = $orphansBefore - count($moves) - count($emptyRecords);

printf("\nDatensaetze ohne Knoten, danach erwartet: %d\n", $expectedAfter);

if (! $go) {
    echo "\n— Probelauf. Mit --go wird geschrieben. —\n";

    exit(0);
}

// ---------------------------------------------------------------- schreiben

$wpdb->query('START TRANSACTION');

$failed = null;

foreach ($moves as $recordId => [$valueRowId, $target]) {
    // `node_version` sagt, von welchem Stand des Knotens der Satz ist. Wechselt der Knoten,
    // wechselt sie mit — sonst zeigte sie in die Geschichte eines anderen Knotens.
    $ok = $wpdb->query($wpdb->prepare(
        "UPDATE {$p}node_records SET node_id = %d, node_version = %d WHERE id = %d",
        $target,
        (int) $known[$target]->version,
        $recordId
    ));

    if ($ok === false) {
        $failed = "Umzug von Satz {$recordId}";

        break;
    }

    if ($wpdb->query($wpdb->prepare("DELETE FROM {$p}relation_records WHERE id = %d", $valueRowId)) === false) {
        $failed = "Wegfall der render-Zeile {$valueRowId}";

        break;
    }
}

if ($failed === null) {
    foreach ($converterMoves as $valueRowId) {
        $ok = $wpdb->query($wpdb->prepare(
            "UPDATE {$p}relation_records SET relation_id = %d WHERE id = %d",
            $inheritedConverterRelationId,
            $valueRowId
        ));

        if ($ok === false) {
            $failed = "Konverter-Zeile {$valueRowId}";

            break;
        }
    }
}

if ($failed === null && $danglingHolders !== []) {
    if ($wpdb->query('DELETE FROM ' . $p . 'relation_records WHERE id IN (' . implode(',', $danglingHolders) . ')') === false) {
        $failed = 'Halterzeilen der leeren Behaelter';
    }
}

if ($failed === null && $emptyRecords !== []) {
    if ($wpdb->query('DELETE FROM ' . $p . 'records WHERE id IN (' . implode(',', $emptyRecords) . ')') === false) {
        $failed = 'leere Behaelter';
    }
}

if ($failed !== null) {
    $wpdb->query('ROLLBACK');

    fwrite(STDERR, "\nFEHLGESCHLAGEN bei: {$failed} — ROLLBACK, nichts geschrieben.\n");

    exit(1);
}

$wpdb->query('COMMIT');

$orphansAfter = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$p}node_records r
       LEFT JOIN {$p}nodes n ON n.id = r.node_id
      WHERE n.id IS NULL"
);

printf("\nGeschrieben.\n");
printf("Datensaetze ohne Knoten, danach: %d (erwartet %d)\n", $orphansAfter, $expectedAfter);

exit($orphansAfter === $expectedAfter ? 0 : 1);
