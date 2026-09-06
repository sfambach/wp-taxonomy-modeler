<?php declare(strict_types=1);

/**
 * Does clearing the trash remove the thing and keep the record that it existed?
 *
 * The owner asked for the act as a button — *build a button behind the Trash label, «clear», so we can
 * tidy up* — and [row 10](../../docs/NewConcept/97-implementation-plan.md#the-working-list) had the
 * shape written down since the hand-run that emptied 42 parked nodes.
 *
 * ⚠️ **A boundary check, because every assertion here is about rows.** Whether a node *resolves* proves
 * nothing: the question is what is left in five tables afterwards, and only a real database answers it.
 *
 * ⚠️ **It builds its own rubbish and parks it**, on the rule [row 23](../../docs/NewConcept/97-implementation-plan.md#the-working-list)
 * states from the reading side and today's damage taught from the writing side: *a checker must not
 * touch data a person is editing.* **Nothing here goes near the owner's own trash** — it makes two
 * nodes, an attribute, a setting and a label, parks them, clears, and then asserts.
 *
 * ⚠️ **Und er raeumt nur seinen eigenen Teil** (TASK-039). *`clearTrash()` leerte immer den ganzen
 * Papierkorb; einmal hat dieser Waechter damit `DisplayOption` des Eigentuemers endgueltig geloescht
 * und 190 Datensaetze ohne Knoten zurueckgelassen. **Jetzt nimmt der Akt eine Auswahl entgegen**, und
 * dieser Lauf nennt genau die Knoten, die er selbst angelegt hat. Fremdes Geparktes wird danach Knoten
 * fuer Knoten nachgezaehlt, nicht ueber eine Gesamtzahl.*
 *
 * Usage: php scripts/dev/cleartrash-check.php C:/Devel/Wordpress
 *
 * @see docs/NewConcept/90-decision-log.md
 */

$wordpress = $argv[1] ?? 'C:/Devel/Wordpress';

define('WP_USE_THEMES', false);

require rtrim($wordpress, '/') . '/wp-load.php';

// ⚠️ **Kein Wächter schreibt in das Modell des Eigentümers** — die Klammer dreht am Ende
// alles zurück, auch nach einem Abbruch. Siehe `lib/no-write.php` und `tests/README.md`.
require __DIR__ . '/lib/no-write.php';
require __DIR__ . '/../../vendor/autoload.php';

use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\IdentitySpace;
use Taxmod\Core\Model\Label;
use Taxmod\Core\Model\SeededRole;
use Taxmod\Core\Model\SettingKey;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Service\DataEntry;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbLabelRepository;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRecordRepository;
use Taxmod\WordPress\Persistence\WpdbRelationRepository;
use Taxmod\WordPress\SystemClock;

// ⚠️ *Seit TASK-019 traegt jeder Knoten eine Beschriftungszeile ([D-580](../../docs/NewConcept/90-decision-log.md)) —
// und dieser Lauf raeumt Knoten mit rohem SQL weg, also am Ende hinter sich her. **Es faellt nur,
// worauf weder ein Knoten noch eine Kante zeigt.***
register_shutdown_function(static fn (): int => \Taxmod\WordPress\Persistence\Schema::forgetOrphanLabels());

$passed = 0;
$failed = 0;

function check(string $what, bool $ok, string $detail = ''): void
{
    global $passed, $failed;

    if ($ok) {
        $passed++;

        echo "  ok   {$what}\n";

        return;
    }

    $failed++;

    echo "  FAIL {$what}" . ($detail === '' ? '' : " — {$detail}") . "\n";
}

$log       = new WpdbChangelog(new SystemClock());
$nodes     = new WpdbNodeRepository();
$relations     = new WpdbRelationRepository();
$labelRows = new WpdbLabelRepository();
$framework = new SeededFrameworkNodes($nodes, $relations, $log);

// ⚠️ *Das Record-Repository geht mit hinein — hier stand ein `$rows`, das es nie gab, und damit nahm
// der Akt in diesem Lauf die Datensaetze **nicht** mit.*
$rows = new WpdbRecordRepository();

$editor = new ModelEditor($nodes, $relations, $framework, $log, $labelRows, $rows);

global $wpdb;

$p = $wpdb->prefix . 'taxmod_';

/** How many rows a table holds for these owners — the only question that matters here. */
function rowsFor(string $table, array $ownerIds, string $column = 'owner_id'): int
{
    global $wpdb, $p;

    if ($ownerIds === []) {
        return 0;
    }

    $in = implode(',', array_map('intval', $ownerIds));

    // ⚠️ *Seit TASK-019 haengt eine Beschriftung nicht am Eigentuemer, sondern der Eigentuemer an ihr
    // ([D-580](../../docs/NewConcept/90-decision-log.md)) — gezaehlt wird ueber `label_id`.*
    if ($table === 'labels') {
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$p}label_texts t
             WHERE t.label_id IN (SELECT label_id FROM {$p}nodes WHERE id IN ({$in}))
                OR t.label_id IN (SELECT label_id FROM {$p}relations WHERE id IN ({$in}))"
        );
    }

    return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}{$table} WHERE {$column} IN ({$in})");
}

echo "\n== something to throw away ==\n";

$root  = $framework->rootOf(Branch::Compositions);
$doomed = $editor->createNode('__ct doomed', $root->id);
$target = $editor->createNode('__ct target', $root->id);
$relation   = $editor->addField($doomed->id, $target->id, 'feld');

// ⚠️ *Hier bekam der Knoten eine Zeile in der `settings`-Tabelle, damit sich zeigen laesst, dass sie
// mit ihm verschwindet. **Die Tabelle ist mit D-579 gestrichen**; was mitgeht, sind Labels, Kanten
// und Datensaetze — und genau das misst der Rest dieser Datei.*
// ⚠️ *Die Rolle ist seit TASK-019 eine **Spalte** ([D-598](../../docs/NewConcept/90-decision-log.md))
// und keine Knotennummer mehr — es gibt hier nichts nachzuschlagen.*

$labelRows->put(new Label($doomed->id, IdentitySpace::Node, SeededRole::Form, Label::BASE_NUMBER, 'de_DE', 'Weg damit'));

// ⚠️ *Ein eigener Datensatz, damit die Zusage «its records went with it» etwas zu pruefen hat —
// [C102](../../docs/NewConcept/10-domain-core.md): einen Datensatz ohne seinen Knoten darf es nicht
// geben.*
$data = new DataEntry($rows, $relations, $nodes, $framework, new SystemClock());

$data->create($doomed->id);

$owners = [$doomed->id, $relation->id];

// ⚠️ **Was schon im Papierkorb liegt, gehoert nicht dem Waechter — und wird von ihm nicht angefasst.**
// *Gemessener Schaden (TASK-039): der Eigentuemer hatte `DisplayOption` (44089) geparkt; der naechste
// Waechterlauf rief `clearTrash()`, das **den ganzen** Papierkorb leert, und der Knoten war endgueltig
// fort — 190 Datensaetze standen ohne Knoten da. Die Zusage des Papierkorbs lautet «geparkt, nicht
// geloescht»; ein Pruefprogramm darf sie nicht brechen ([Zeile 23](../../docs/NewConcept/97-implementation-plan.md#the-working-list):
// ein Pruefer fasst keine Daten an, an denen jemand arbeitet).*
//
// ⚠️ **Seit TASK-039 nimmt `clearTrash()` eine Auswahl entgegen** — der Waechter nennt seinen eigenen
// Knoten und raeumt nur ihn. *Das Fremde wird vorher gezaehlt, damit hinterher Knoten fuer Knoten
// nachgewiesen werden kann, dass es noch dasteht.*
$fremdImPapierkorb = array_values(array_filter(
    $nodes->subtreeOf($framework->trash()),
    static fn ($one): bool => !str_starts_with($one->name, '__ct ')
));
$fremdeIds = array_map(static fn ($one): int => $one->id, $fremdImPapierkorb);

check('and a label', rowsFor('labels', $owners) > 0, (string) rowsFor('labels', $owners));

// ⚠️ *Only the doomed one is parked. `__ct target` stays in the model — which is what makes the last
// assertion mean something: a purge that took a living node's attribute target would be a disaster,
// and this is the shape that would catch it.*
$editor->moveToTrash($doomed->id);

$trash  = $framework->trash();
$parked = count($nodes->subtreeOf($trash));

check('it sits in the trash', $parked > 0, (string) $parked);

echo "\n== clearing it ==\n";

// ⚠️ **Nur der eigene Knoten wird genannt** (TASK-039). *Der Akt raeumt eine Auswahl; was der
// Eigentuemer geparkt hat, steht nicht darin und bleibt liegen.*
$gone = $editor->clearTrash([$doomed->id]);

printf("  %d Knoten, %d Kanten, %d Labels\n", $gone['nodes'], $gone['relations'], $gone['labels']);

// ⚠️ **Geaenderte Zusage (`PR-9`).** *Hier stand «the trash is empty». Das war die Zusage, die den
// Schaden festschrieb: sie ist nur wahr, wenn der Waechter auch fremdes Geparktes mitnimmt. Was
// gemeint war, ist enger — **vom Waechter selbst bleibt nichts im Papierkorb** —, und genau das
// wird geprueft.*
$reste = array_values(array_filter(
    $nodes->subtreeOf($trash),
    static fn ($one): bool => str_starts_with($one->name, '__ct ')
));

check('vom Waechter bleibt nichts im Papierkorb', $reste === [], (string) count($reste));
check('nur der eigene Knoten wurde geraeumt', $gone['nodes'] === 1, (string) $gone['nodes']);
check('the node is gone', $nodes->find($doomed->id) === null);
check('its labels went with it', rowsFor('labels', $owners) === 0, (string) rowsFor('labels', $owners));
check('its relations went with it', (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}relations WHERE id = {$relation->id}") === 0);
check(
    'its records went with it',
    (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}node_records WHERE node_id = {$doomed->id}") === 0
);

echo "\n== and what must survive ==\n";

// ⚠️ **[D-340](../../docs/NewConcept/90-decision-log.md): eine einmal vergebene Id wird nie wieder
// vergeben.** *Bis Fassung 20 hielt das eine Zeile in `identities`, und hier stand die Prüfung, dass
// sie das Aufräumen überlebt. **Mit TASK-004 ist die Tabelle gestrichen** — was die Zusage jetzt hält,
// ist der Zähler der Tabelle selbst: InnoDB senkt `AUTO_INCREMENT` beim Löschen nicht. Die Zusage ist
// dieselbe, die Prüfung fragt nur eine andere Stelle (`PR-9`).*
// ⚠️ *Aus `SHOW CREATE TABLE` gelesen — `information_schema` gibt die Zahl zwischengespeichert und
// im Versuch als `NULL` zurück. Dieselbe Lesart wie in [`id-space-check.php`](id-space-check.php).*
$erzeugt = ($wpdb->get_row("SHOW CREATE TABLE {$p}nodes", ARRAY_N) ?: [1 => ''])[1];
$treffer = [];
$zaehler = preg_match('/AUTO_INCREMENT=(\d+)/', (string) $erzeugt, $treffer) === 1
    ? (int) $treffer[1]
    : (int) $wpdb->get_var("SELECT COALESCE(MAX(id), 0) + 1 FROM {$p}nodes");
check(
    'die Nummer bleibt verbraucht, der Zaehler steht darueber',
    $zaehler > $doomed->id,
    "$zaehler > {$doomed->id}"
);

// ⚠️ **[D-065](../../docs/NewConcept/90-decision-log.md): the changelog outlives what it refers to.**
check(
    'its history is kept',
    (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}changelog WHERE owner_id = {$doomed->id}") > 0
);

check(
    'and the act itself is journalled',
    (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}changelog WHERE owner_id = {$trash->id} AND what = 'trash cleared'") > 0
);

// ⚠️ **The counter-check that gives the whole file its meaning**: the attribute's **target** was never
// parked, so it must still be there. *Without this, a purge that followed relations outward would pass
// every assertion above and quietly delete half the model.*
check('a living node the rubbish pointed at is untouched', $nodes->find($target->id) !== null);

// ⚠️ **Die Zusage, um die es in TASK-039 geht: geparkt heisst geparkt, auch ueber einen Waechterlauf
// hinweg.** *Was vor dem Lauf im Papierkorb lag, liegt danach noch dort — Knoten fuer Knoten geprueft,
// nicht ueber eine Gesamtzahl, weil eine Zahl gleich bleiben kann, waehrend die Knoten getauscht sind.*
$nochDa = array_map(static fn ($one): int => $one->id, $nodes->subtreeOf($trash));
$verloren = array_values(array_diff($fremdeIds, $nochDa));

check(
    'fremdes Geparktes ueberlebt den Waechterlauf',
    $verloren === [],
    $verloren === [] ? count($fremdeIds) . ' geprueft' : 'verloren: ' . implode(', ', $verloren)
);

echo "\n== tidying up ==\n";

// ⚠️ **By name, not only this run's ids — the same self-healing `package7-check` has.** *Two runs of
// mine died before this line while the act was being got right, and the third then reported **their**
// leftovers as its own failure. A cleanup that only knows the ids of the run it is in blames the wrong
// run.*
$mine  = array_map('intval', $wpdb->get_col("SELECT id FROM {$p}nodes_named WHERE name LIKE '\\_\\_ct %'"));
$in    = $mine === [] ? (string) $target->id : implode(',', $mine);
$stray = array_map('intval', $wpdb->get_col("SELECT id FROM {$p}relations WHERE from_node_id IN ({$in}) OR to_node_id IN ({$in})"));
$own   = $stray === [] ? $in : $in . ',' . implode(',', $stray);

$wpdb->query("DELETE FROM {$p}label_texts WHERE label_id IN (SELECT label_id FROM {$p}nodes WHERE id IN ({$own}))");

if ($stray !== []) {
    $wpdb->query("DELETE FROM {$p}relations WHERE id IN (" . implode(',', $stray) . ')');
}

// ⚠️ **Und die Datensaetze dazu — sie fehlten hier, und der Waechter liess bei jedem Lauf einen
// Waisen zurueck.** *Gemessen am 2026-09-05: zwei Laeufe, zwei Waisen, und davon waren
// `id-space-check` und `package6-check` rot — «records.node_id findet seinen Eintrag in nodes».
// **Nicht die Daten des Eigentuemers, sondern dieser Aufraeumweg.** Zuerst die Wertzeilen, dann die
// Saetze: umgekehrt haette die zweite Anweisung ihre Zeilen nicht mehr finden koennen.*
$meineSaetze = array_map('intval', $wpdb->get_col("SELECT id FROM {$p}node_records WHERE node_id IN ({$in})"));

if ($meineSaetze !== []) {
    $wpdb->query('DELETE FROM ' . $p . 'relation_records WHERE node_record_id IN (' . implode(',', $meineSaetze) . ')');
}

$wpdb->query("DELETE FROM {$p}node_records WHERE node_id IN ({$in})");

$wpdb->query("DELETE FROM {$p}nodes WHERE id IN ({$in})");

check(
    'the check leaves nothing behind',
    (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}nodes_named WHERE name LIKE '\\_\\_ct %'") === 0
);

// ⚠️ *Die Zusage dazu, damit derselbe Rest nicht ein zweites Mal unbemerkt bleibt: **kein Datensatz
// ohne Knoten**, gefragt ueber den ganzen Bestand und nicht nur ueber die eigenen Ids — ein Waisen
// aus einem abgestuerzten Lauf ist derselbe Schaden wie einer aus diesem.*
check(
    'und kein Datensatz steht ohne seinen Knoten da',
    (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$p}node_records s LEFT JOIN {$p}nodes k ON k.id = s.node_id WHERE k.id IS NULL"
    ) === 0
);

printf("\n%d ok, %d failed\n", $passed, $failed);

exit($failed === 0 ? 0 : 1);
