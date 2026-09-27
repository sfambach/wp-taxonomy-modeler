<?php declare(strict_types=1);

/**
 * Die Cleanup-Seite zeigt den Rückstand und lässt ihn einzeln entfernen.
 *
 * ⚠️ **[D-247](../../docs/NewConcept/90-decision-log.md), entschieden am 2026-08-23 und nie gebaut.**
 * *«Cleanup was meant for tidying — nodes that have no connections any more, or settings that broke
 * because something was deleted.» Drei Quellen nennt die Entscheidung namentlich, und alle drei werden
 * hier hergestellt, gemessen, gezeigt und entfernt.*
 *
 * ⚠️ **Gegen die echte Datenbank, weil es genau die Fälle sind, die es im Kern nicht gibt.** *Ein
 * verwaister Override ist eine Zeile, deren Besitzer **nicht existiert** — ein Repository von Objekten
 * hat dafür keinen Rückgabewert, und ein Doppelgänger im Kerntest könnte den Zustand gar nicht
 * herstellen.*
 *
 * ⚠️ **Auf Ids geprüft und nicht auf Namen** — ein abgestürzter Lauf hinterlässt Knoten mit denselben
 * Namen, und ein Namensvergleich fände dann den Rest statt die eigene Wiese.
 *
 * ⚠️ *Und die Gegenprobe ist mitgeprüft: was **kein** Rückstand ist, darf die Seite nicht anbieten, und
 * ein Entfernen-Aufruf auf eine Id, die keiner ist, muss nichts tun. Ohne das wäre eine Abfrage, die
 * versehentlich alles findet, genauso grün.*
 *
 * Usage: php scripts/dev/cleanup-screen-check.php
 *
 * @see docs/NewConcept/20-interaction.md
 */

define('WP_ADMIN', true);
define('WP_USE_THEMES', false);

require (getenv('WP_ROOT') ?: 'C:/Devel/Wordpress') . '/wp-load.php';

// ⚠️ **Kein Wächter schreibt in das Modell des Eigentümers** — die Klammer dreht am Ende
// alles zurück, auch nach einem Abbruch. Siehe `lib/no-write.php` und `tests/README.md`.
require __DIR__ . '/lib/no-write.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

wp_set_current_user(1);

use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Service\DataEntry;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\WordPress\Admin\CleanupScreen;
use Taxmod\WordPress\Persistence\{Residue, Schema, SeededFrameworkNodes, WpdbChangelog, WpdbLabelRepository, WpdbNodeRepository, WpdbRecordRepository, WpdbRelationRepository};
use Taxmod\WordPress\SystemClock;

// ⚠️ *Seit TASK-019 traegt jeder Knoten eine Beschriftungszeile ([D-580](../../docs/NewConcept/90-decision-log.md)) —
// und dieser Lauf raeumt Knoten mit rohem SQL weg, also am Ende hinter sich her. **Es faellt nur,
// worauf weder ein Knoten noch eine Kante zeigt.***
register_shutdown_function(static fn (): int => \Taxmod\WordPress\Persistence\Schema::forgetOrphanLabels());

$nodes = new WpdbNodeRepository();
$relations = new WpdbRelationRepository();
$log   = new WpdbChangelog(new SystemClock());
$fw    = new SeededFrameworkNodes($nodes, $relations, $log);

$editor   = new ModelEditor($nodes, $relations, $fw, $log);
$data     = new DataEntry(new WpdbRecordRepository(), $relations, $nodes, $fw, new SystemClock(), $log);

$residue = new Residue($fw, new WpdbLabelRepository(), $log, new WpdbRecordRepository());

$failed = 0;

$say = static function (bool $ok, string $what) use (&$failed): void {
    printf("  %-4s %s\n", $ok ? 'ok' : 'FAIL', $what);

    if (! $ok) {
        ++$failed;
    }
};

global $wpdb;

$p = $wpdb->prefix . 'taxmod_';

$roh = static function (string $sql) use ($wpdb): void {
    $wpdb->query($sql);

    if ($wpdb->last_error !== '') {
        fwrite(STDERR, "Abfrage kaputt: {$wpdb->last_error}\n  ({$sql})\n");

        exit(2);
    }
};

// ⚠️ *Was schon liegt, wird gezählt und **nicht** angetastet: die sieben echten Werte ohne Kante sind
// der Rückstand des Eigentümers, nicht der dieser Prüfung. Jede Zusage unten ist eine **Differenz**.*
$vorherWerte  = array_sum($residue->valuesWithoutRelation());
$vorherAllein = count($residue->nodesWithoutConnections());
$vorherDaten  = count($residue->recordsWithoutNode());

printf(
    "\nvorhanden vor dem Lauf: %d Werte ohne Kante, %d Knoten ohne Verbindung, %d Knoten mit zurueckgelassenen Daten\n",
    $vorherWerte,
    $vorherAllein,
    $vorherDaten
);

// ── Die Wiese: dreimal Rückstand, jede Quelle auf ihrem eigenen Weg ────────
$modell = $editor->createNode('__cl Modell', $fw->rootOf(Branch::Model)->id);
$typ    = $editor->createNode('__cl Typ', $fw->rootOf(Branch::DataTypes)->id);
$feld   = $editor->addField($modell->id, $typ->id, '__cl menge');

// Quelle 2: ein Wert, dessen Kante danach verschwindet (D-159).
$satz = $data->create($modell->id);
$data->put($satz->id, $feld->id, TypedValue::ofText('__cl bleibt liegen'));
$roh("DELETE FROM {$p}relations WHERE id = {$feld->id}");

// ⚠️ *Quelle 1 — der verwaiste Override — ist mit der `settings`-Tabelle gegangen (D-579). **Was es
// nicht mehr gibt, laesst nichts liegen**; die Seite bietet seither drei Akte statt vier.*

// Quelle 3: ein Knoten, dessen Kante verschwindet — er selbst bleibt stehen.
$allein = $editor->createNode('__cl Alleinstehend', $fw->rootOf(Branch::Model)->id);
// ⚠️ *Seit TASK-018 hängt ein Knoten an einer **Spalte** und nicht an einer Kante
// ([D-581](../../NewConcept/90-decision-log.md)) — die Wiese muss beides kappen, sonst ist der
// Knoten weiterhin im Baum und ist zu Recht kein Rückstand.*
$roh("DELETE FROM {$p}relations WHERE to_node_id = {$allein->id} OR from_node_id = {$allein->id}");
$roh("UPDATE {$p}nodes SET parent_node_id = NULL WHERE id = {$allein->id}");

// ⚠️ *Quelle 4: ein Datensatz, dessen **Knoten** verschwindet. Seine Settings gehen mit, damit
// diese Wiese **nur** die vierte Quelle füttert und nicht nebenbei die erste — sonst misst der
// Vergleich «der fremde Rückstand ist unverändert» am Ende die eigene Unordnung mit.*
$leiche     = $editor->createNode('__cl Datenleiche', $fw->rootOf(Branch::Model)->id);
$leichfeld  = $editor->addField($leiche->id, $typ->id, '__cl zahl');
$leichsatz  = $data->create($leiche->id);
$data->put($leichsatz->id, $leichfeld->id, TypedValue::ofText('__cl ohne Knoten'));
$leichkanten = array_map('intval', $wpdb->get_col(
    "SELECT id FROM {$p}relations WHERE from_node_id = {$leiche->id} OR to_node_id = {$leiche->id}"
));
$leichbesitz = implode(',', array_merge([$leiche->id], $leichkanten));
$roh("DELETE FROM {$p}relations WHERE from_node_id = {$leiche->id} OR to_node_id = {$leiche->id}");
$roh("DELETE FROM {$p}nodes WHERE id = {$leiche->id}");

echo "\n== 1. gemessen: alle drei Quellen sehen ihren eigenen Rückstand ==\n";

$werte  = $residue->valuesWithoutRelation();
$einzel = array_map(static fn (object $n): int => $n->id, $residue->nodesWithoutConnections());

$say(array_key_exists($feld->id, $werte), sprintf('der Wert an der verschwundenen Kante %d liegt da (%d Zeile(n))', $feld->id, $werte[$feld->id] ?? 0));
$say(in_array($allein->id, $einzel, true), sprintf('der Knoten %d hängt an nichts mehr', $allein->id));

// ⚠️ **Die vierte Quelle misst einen ausdrücklich verbotenen Zustand** — der Eigentümer, 2026-08-28:
// *«ein Record ohne Knoten wäre undenkbar … was soll ich denn damit machen?»* Ein Verbot beseitigt
// keinen Rückstand, und ohne diese Messung wäre der Satz eine Behauptung ohne Prüfung.
$daten = $residue->recordsWithoutNode();
$say(
    array_key_exists($leiche->id, $daten),
    sprintf('der Datensatz zum verschwundenen Knoten %d liegt da', $leiche->id)
);
$say(
    ($daten[$leiche->id]['records'] ?? 0) === 1 && ($daten[$leiche->id]['values'] ?? 0) === 1,
    sprintf('mit einem Datensatz und einem Wert gezaehlt (%s)', json_encode($daten[$leiche->id] ?? []))
);

// ⚠️ *Die Gegenprobe. `__cl Modell` steht im Baum und hat ein Setting — er darf in keiner der drei
// Listen auftauchen, sonst misst die Abfrage nicht, was sie behauptet.*
$say(! in_array($modell->id, $einzel, true), 'der Knoten, der im Baum hängt, ist kein Rückstand');
$say(! array_key_exists($modell->id, $daten), 'die Daten eines lebenden Knotens sind kein Rückstand');

echo "\n== 2. die Seite zeigt genau das, mit einem Knopf je Zeile ==\n";

$r      = new ReflectionClass(\Taxmod\WordPress\Plugin::class);
$plugin = $r->newInstanceWithoutConstructor();
$r->getProperty('file')->setValue($plugin, dirname(__DIR__, 2) . '/wp-taxonomy-modeler.php');

$markup = $plugin->cleanupScreen()->render();

$say(str_starts_with($markup, '<div class="wrap"'), 'render() gibt Markup zurück statt zu sterben');
$say(str_contains($markup, 'value="' . $feld->id . '"'), 'die verschwundene Kante steht mit ihrer Id auf der Seite');
$say(str_contains($markup, 'value="' . $allein->id . '"'), 'der alleinstehende Knoten steht mit seiner Id auf der Seite');
$say(str_contains($markup, 'value="forget_values"'), 'ein Knopf für die Werte');
$say(str_contains($markup, 'value="purge_node"'), 'ein Knopf für den Knoten');
$say(str_contains($markup, 'value="forget_records"'), 'ein Knopf für die Daten ohne Knoten');
$say(
    str_contains($markup, 'value="' . $leiche->id . '"'),
    'der verschwundene Knoten steht mit seiner Id auf der Seite'
);
$say(substr_count($markup, 'name="_taxmod_nonce"') >= 3, sprintf('jede Zeile trägt ihre eigene Nonce (%d gefunden)', substr_count($markup, 'name="_taxmod_nonce"')));
// ⚠️ **Und nichts sonst.** *Die Log-Aufräumung ist inzwischen entschieden
// ([D-473](../../docs/NewConcept/90-decision-log.md)) — **und sie hat ein Tor, das es noch nicht gibt**:
// «keine unaufgelösten Konflikte aus diesem Zeitraum», gemessen am Konfliktlöser, der eine eigene Seite
// ist und nicht gebaut. Bis dahin darf auf dieser Seite kein vierter Akt stehen.* *Die erste Fassung
// dieser Zusage suchte das Wort «changelog» und fiel durch — es steht in der **Erklärung** der dritten
// Quelle («seine Id und sein Changelog bleiben»). Ein Wort im Text ist kein Knopf; geprüft wird, welche
// Akte die Seite anbietet.*
preg_match_all('/name="do" value="([a-z_]+)"/', $markup, $akte);

$angeboten = array_values(array_unique($akte[1]));
sort($angeboten);

$say(
    $angeboten === ['forget_empty_records', 'forget_records', 'forget_values', 'purge_node'],
    'genau vier Akte, keiner für das Log (' . implode(', ', $angeboten) . ')'
);

// ⚠️ **Die Nonce der Seite muss die sein, die `handlePost()` verlangt.** *`handlePost()` selbst laesst
// sich hier nicht aufrufen — es endet in `wp_safe_redirect()` und `exit`. **Was daran schiefgehen kann,
// ist der Name**: die Nonce wird aus Akt **und** Ziel gebaut, und ein Knopf, dessen Nonce anders
// heisst, fuehrt zu «Are you sure you want to do this?» und zu keiner Fehlermeldung, die den Grund
// nennt. Also wird derselbe Name hier nachgerechnet und im Markup gesucht.*
$erwartet = wp_create_nonce(CleanupScreen::ACTION . '_purge_node_' . $allein->id);

$say(str_contains($markup, 'value="' . $erwartet . '"'), 'die Nonce im Knopf ist die, die der Akt prüft');

// ⚠️ *Und der leere Fall muss als gute Nachricht lesbar sein — nicht als leere Tabelle. Er wird unten
// nach dem Aufräumen geprüft, wenn die eigene Wiese weg ist.*

echo "\n== 3. entfernt wird einzeln, und jeder Akt bekommt eine Änderungsnummer ==\n";

$marke = (int) $wpdb->get_var("SELECT COALESCE(MAX(id), 0) FROM {$p}changelog");

if ($wpdb->last_error !== '') {
    fwrite(STDERR, "Abfrage kaputt: {$wpdb->last_error}\n");

    exit(2);
}

$goneWerte  = $residue->forgetValuesOfRelation($feld->id);
$goneKnoten = $residue->purgeNodeWithoutConnections($allein->id);
$goneDaten  = $residue->forgetRecordsOfGoneNode($leiche->id);

$say($goneWerte === 1, sprintf('ein Wert entfernt (%d)', $goneWerte));
$say($goneKnoten !== null, sprintf('der alleinstehende Knoten ging (%s)', json_encode($goneKnoten)));

$say(
    $goneDaten !== null && $goneDaten['records'] === 1 && $goneDaten['values'] === 1,
    sprintf('der Datensatz ohne Knoten ging mit seinem Wert (%s)', json_encode($goneDaten))
);

$neu = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}changelog WHERE id > {$marke}");

$say($neu >= 3, sprintf('drei Akte, drei Zeilen im Log — die Geschichte bleibt (%d)', $neu));

echo "\n== 4. danach ist es weg, und was nie Rückstand war, ist unberührt ==\n";

$say(! array_key_exists($feld->id, $residue->valuesWithoutRelation()), 'der Wert ist weg');
$say(! in_array($allein->id, array_map(static fn (object $n): int => $n->id, $residue->nodesWithoutConnections()), true), 'der Knoten ist weg');
$say((int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}nodes WHERE id = {$allein->id}") === 0, 'seine Zeile in nodes auch');
$say(! array_key_exists($leiche->id, $residue->recordsWithoutNode()), 'die Daten ohne Knoten sind weg');
$say(
    (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}relation_records WHERE node_record_id = {$leichsatz->id}") === 0,
    'und ihre Werte mit ihnen — kein Wert ohne Datensatz zurückgelassen'
);

// ⚠️ **Der Rückstand des Eigentümers muss noch genauso daliegen.** *Eine Reparaturfläche, die beim
// Aufräumen der eigenen Wiese fremde Zeilen mitnimmt, wäre genau das automatische Aufräumen, das
// [D-247](../../docs/NewConcept/90-decision-log.md) verbietet.*
$say(array_sum($residue->valuesWithoutRelation()) === $vorherWerte, sprintf('die %d vorher vorhandenen Werte ohne Kante liegen unberührt da', $vorherWerte));
$say(
    count($residue->recordsWithoutNode()) === $vorherDaten,
    sprintf('und die %d fremden Datenleichen liegen noch', $vorherDaten)
);

echo "\n== 5. der Wächter: eine Id, die kein Rückstand ist, wird nicht entfernt ==\n";

$say($residue->forgetValuesOfRelation($modell->id) === 0, 'eine Id, die keine verschwundene Kante ist, entfernt nichts');
$say($residue->purgeNodeWithoutConnections($modell->id) === null, 'ein Knoten im Baum wird nicht entfernt — und es wird nicht als Akt gemeldet');
$say($residue->forgetRecordsOfGoneNode($modell->id) === null, 'die Daten eines lebenden Knotens werden nicht entfernt');
$say((int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}nodes WHERE id = {$modell->id}") === 1, 'er steht noch');

// aufraeumen
foreach ([$satz->id] as $id) {
    $roh("DELETE FROM {$p}relation_records WHERE node_record_id = {$id}");
    $roh("DELETE FROM {$p}node_records WHERE id = {$id}");
}

foreach ([$modell->id, $typ->id] as $id) {
    $e   = array_map('intval', $wpdb->get_col("SELECT id FROM {$p}relations WHERE from_node_id = {$id} OR to_node_id = {$id}"));
    $own = $e === [] ? (string) $id : $id . ',' . implode(',', $e);

    // ⚠️ *Seit TASK-019 zeigt der Knoten auf seine Beschriftung ([D-580](../../docs/NewConcept/90-decision-log.md)).*
    $roh("DELETE FROM {$p}label_texts WHERE label_id IN (SELECT label_id FROM {$p}nodes WHERE id IN ({$own}))");
    $roh("DELETE FROM {$p}changelog WHERE owner_id IN ({$own})");

    if ($e !== []) {
        $roh('DELETE FROM ' . $p . 'relations WHERE id IN (' . implode(',', $e) . ')');
    }

    $roh("DELETE FROM {$p}nodes WHERE id = {$id}");
}

// die Log-Zeilen der drei entfernten Sachen — sie gehören zur Wiese und nicht zur Geschichte
foreach ([$feld->id, $allein->id, $leiche->id, $leichfeld->id] as $id) {
    $roh("DELETE FROM {$p}changelog WHERE owner_id = {$id}");
}

echo "\n";

$eigene = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$p}nodes WHERE id IN (" . implode(',', [$modell->id, $typ->id, $allein->id]) . ')'
);

$say($eigene === 0, 'die Wiese ist wieder weg');
$say(array_sum($residue->valuesWithoutRelation()) === $vorherWerte, 'und der fremde Rückstand ist unverändert');

// ⚠️ *Der leere Fall — [D-247](../../docs/NewConcept/90-decision-log.md) will «nichts aufzuräumen»
// als Nachricht und nicht als leere Tabelle.*
//
// ⚠️ **Der Zeuge war bis zum 2026-09-04 die Gruppe der verwaisten Settings, die auf dieser
// Installation immer leer war. Sie ist mit der Tabelle gegangen (D-579)**, also wird jetzt gemessen,
// **ob** eine der drei verbliebenen Quellen leer ist — und nur dann verlangt. *Eine Zusage, die eine
// leere Quelle voraussetzt, waere sonst rot, sobald der Eigentuemer Rueckstand hat, und das misst
// nicht den Schirm.*
$leer = $plugin->cleanupScreen()->render();

$eineLeer = $residue->valuesWithoutRelation() === []
    || $residue->nodesWithoutConnections() === []
    || $residue->recordsWithoutNode() === [];

if ($eineLeer) {
    $say(str_contains($leer, 'Nothing to tidy up here'), 'wo nichts liegt, steht ein Satz und keine leere Liste');
} else {
    echo "  --   keine Quelle ist leer, der leere Fall ist hier nicht zu sehen
";
}

echo "\n== 6. die Quelle aus TASK-077: leere Benutzersätze, die kein Akt angelegt hat ==\n";

// ⚠️ *Gemessen am 2026-09-09: 104 leere `user`-Sätze an einem Knoten, alle mit `created_at`
// Mitternacht und ohne eine Journalzeile — kein Akt hat sie angelegt. **Ohne Journal** ist die
// Bedingung: ein Satz, den ein Mensch anlegt und leer lässt, hat seine Anlegezeile und bleibt.*
$wiese  = $editor->createNode('__cl Wiese', $fw->rootOf(Branch::Model)->id);
$roh(sprintf(
    "INSERT INTO {$p}node_records (version, node_id, node_version, created_at, record_type, relation_id)
     VALUES (1, %d, %d, '2026-08-30 00:00:00', 'user', 0)",
    $wiese->id,
    $wiese->version
));
$fremd = (int) $wpdb->insert_id;
$eigen = $data->create($wiese->id);

$leere = $residue->emptyUserRecordsWithoutHistory();
$say(($leere[$wiese->id] ?? 0) === 1, sprintf('der journallose leere Satz liegt da, genau einer (%d)', $leere[$wiese->id] ?? 0));
$say(
    (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}changelog WHERE owner_kind = 'record' AND owner_id = {$eigen->id}") >= 1,
    'der Satz eines Menschen hat seine Anlegezeile im Journal'
);
$say(! array_key_exists($modell->id, $leere), 'ein Satz mit Werten ist kein Rückstand');

$markup = $plugin->cleanupScreen()->render();
$say(str_contains($markup, 'value="forget_empty_records"'), 'ein Knopf für die leeren Sätze');
$say(str_contains($markup, 'value="' . $wiese->id . '"'), 'und die Zeile nennt den Knoten');

$gone = $residue->forgetEmptyUserRecordsOf($wiese->id);
$say($gone === 1, sprintf('genau einer ging (%s)', var_export($gone, true)));
$say((int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}node_records WHERE id = {$fremd}") === 0, 'der journallose ist weg');
$say((int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}node_records_history WHERE id = {$fremd}") === 1, 'und liegt im Schatten, also ist es umkehrbar');
$say((int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}node_records WHERE id = {$eigen->id}") === 1, 'der Satz des Menschen steht noch');
$say($residue->forgetEmptyUserRecordsOf($wiese->id) === null, 'und ein zweiter Aufruf findet nichts mehr und meldet keinen Akt');

printf("\n%s\n", $failed === 0 ? 'all green' : sprintf('%d FEHLER', $failed));

exit($failed === 0 ? 0 : 1);
