<?php declare(strict_types=1);

/**
 * A journal entry carries its address — against the real table, and against every row already in it.
 *
 * ⚠️ **The fault this ends was measured, not suspected** ([D-427](../../docs/NewConcept/90-decision-log.md)):
 * a setting row read `what = "setting min set"`, `before = NULL`, `after = "10"`. *Something set `min`
 * to 10, and not **for which place** ([D-413](../../docs/NewConcept/90-decision-log.md)). Of 5773
 * setting rows in this database, **0** named a path.*
 *
 * ⚠️ **Two halves, and the second is the one that could not be a core test.** *Part 1 writes a
 * setting at a path and reads the address back out of the column. Part 2 walks **every row that
 * already exists** — 10000-odd of them, in four dialects — and asserts that the new reader gets the
 * same address out of them as the `strrpos(' path=')` it replaced. A fake table with three rows in it
 * cannot make that promise; only the grown one can.*
 *
 * ⚠️ *Nothing is rewritten and nothing is deleted. The old setting rows lack an address that **cannot
 * be recovered** — stamping `path=` on them would falsify the ones that were written at a real path —
 * so [D-476](../../docs/NewConcept/90-decision-log.md)'s rule applies: a step that destroys has to be
 * able to say what it destroys, and here it cannot.*
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

use Taxmod\WordPress\Admin\SettingsScreen;
use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\FrozenState;
use Taxmod\Core\Model\IdentitySpace;
use Taxmod\Core\Model\Label;
use Taxmod\Core\Model\SeededRole;
use Taxmod\Core\Model\SettingKey;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Service\Labels;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\Shadow;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbLabelRepository;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRelationRepository;
use Taxmod\WordPress\SystemClock;

// ⚠️ *Seit TASK-019 traegt jeder Knoten eine Beschriftungszeile ([D-580](../../docs/NewConcept/90-decision-log.md)) —
// und dieser Lauf raeumt Knoten mit rohem SQL weg, also am Ende hinter sich her. **Es faellt nur,
// worauf weder ein Knoten noch eine Kante zeigt.***
register_shutdown_function(static fn (): int => \Taxmod\WordPress\Persistence\Schema::forgetOrphanLabels());

global $wpdb;

$ok  = 0;
$bad = 0;

function check(string $what, bool $passed, string $detail = ''): void
{
    global $ok, $bad;

    if ($passed) {
        ++$ok;
        echo "  OK   $what\n";
    } else {
        ++$bad;
        echo "  FAIL $what" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}

/** ⚠️ *An empty result and a broken query look identical through `$wpdb`, so every read says so.* */
function sane(string $where): void
{
    global $wpdb;

    if ($wpdb->last_error !== '') {
        fwrite(STDERR, "Query broken ({$where}): {$wpdb->last_error}\n");
        exit(2);
    }
}

Schema::install();
update_option(Schema::VERSION_OPTION, Schema::VERSION, true);

$changelog = new WpdbChangelog(new SystemClock());
$nodes     = new WpdbNodeRepository();
$relations     = new WpdbRelationRepository();
$framework = new SeededFrameworkNodes($nodes, $relations, $changelog);
$framework->seed();

$editor   = new ModelEditor($nodes, $relations, $framework, $changelog);
// ⚠️ *Ohne Knotenspeicher: `Labels` hat ihn benutzt, um `node` von `relation` zu **raten**, und die
// Zeile nennt ihren Raum seit Fassung 31 selbst (`INF-035`, D-597).*
$labels   = new Labels(new WpdbLabelRepository(), SettingsScreen::neutralLocale(), $changelog);

$journal = Schema::table('changelog');

// ── 1./2. Hier stand der Schreiber der `settings`-Tabelle ──────────────────
//
// ⚠️ **Zwei Abschnitte sind mit der Tabelle gegangen** ([D-579](../../docs/NewConcept/90-decision-log.md)):
// *sie schrieben eine Einstellung an einer Adresse und lasen die Adresse aus der Journalspalte
// zurueck. **Der Schreiber ist fort**, und eine Zusage ueber eine Zeile, die niemand mehr schreibt,
// misst nichts. Was die Spalte kann, misst Abschnitt 3 an den Labels und Abschnitt 4 an **jeder
// Zeile, die schon dasteht** — einschliesslich der alten Einstellungszeilen.*

$thing = $editor->createNode('__ja Thing', $framework->rootOf(Branch::Model)->id);
$text  = $editor->createNode('__ja Text', $framework->rootOf(Branch::DataTypes)->id);
$first = $editor->addField($thing->id, $text->id, '__ja first');

// ── 3. A label writes its address in the state and a verb in `what` ────────
echo "\n== 3. The label side speaks the same format ==\n";

$mark3 = (int) $wpdb->get_var("SELECT COALESCE(MAX(id), 0) FROM {$journal}");
sane('third mark');

$role = $framework->roleId(SeededRole::Form);

if ($role === 0) {
    echo "  --   no seeded label roles here; the label half is skipped\n";
} else {
    $labels->put(new Label($thing->id, IdentitySpace::Node, SeededRole::Form, Label::BASE_NUMBER, SettingsScreen::neutralLocale(), '__ja Ein Text mit Leerzeichen'));

    $labelRow = $wpdb->get_row($wpdb->prepare(
        "SELECT what, after_state FROM {$journal} WHERE id > %d AND what LIKE %s ORDER BY id DESC LIMIT 1",
        $mark3,
        'label%'
    ), ARRAY_A);
    sane('the label row');

    check('`what` is a verb again, not a data structure', ($labelRow['what'] ?? '') === 'label set', (string) ($labelRow['what'] ?? 'nothing'));

    $labelState = FrozenState::parse($labelRow['after_state'] ?? null);

    // ⚠️ *Die Rolle steht seit TASK-019 als **Wort** in der Zeile und nicht mehr als Knotennummer
    // ([D-598](../../docs/NewConcept/90-decision-log.md): die Rollen sind Spalten). Und einen `path`
    // gibt es an einer Beschriftung nicht mehr ([D-580](../../docs/NewConcept/90-decision-log.md)) —
    // **die Adresse ist die `label_id`**, und die haengt an genau einem Eigentuemer.*
    check('the role is in the state', $labelState?->field('role') === SeededRole::Form->value, var_export($labelState?->field('role'), true));
    check('and no path is claimed any more', $labelState?->field('path') === null, var_export($labelState?->field('path'), true));
    check('and the text came back whole', $labelState?->field('text') === '__ja Ein Text mit Leerzeichen', var_export($labelState?->field('text'), true));
}

// ── 4. Every row that already exists still gives up its address ────────────
echo "\n== 4. Every row already in the table ==\n";

$all = $wpdb->get_results("SELECT id, before_state, after_state FROM {$journal}", ARRAY_A);
sane('the whole journal');

// ⚠️ *The rule this replaced, kept here as the thing to be measured against. A check that only
// asserted the new reader works would be green on a reader that quietly answers differently.*
$oldRule = static function (?string $stored): ?string {
    if ($stored === null) {
        return null;
    }

    $at = strrpos($stored, ' path=');

    return $at === false ? null : substr($stored, $at + 6);
};

$disagreed = [];
$parsed    = 0;
$withPath  = 0;
$plain     = 0;
$oldOrder  = 0;
$oldWrong  = 0;

foreach ($all as $row) {
    foreach (['before_state', 'after_state'] as $column) {
        $stored = $row[$column];

        if ($stored === null || $stored === '') {
            continue;
        }

        ++$parsed;

        $state = FrozenState::parse((string) $stored);

        if ($state === null) {
            $disagreed[] = "#{$row['id']} {$column}: nothing came back";

            continue;
        }

        if ($state->plainValue() !== null) {
            ++$plain;

            // A row that is not a field list must come back byte for byte.
            if ($state->write() !== $stored) {
                $disagreed[] = "#{$row['id']} {$column}: a plain row changed";
            }

            continue;
        }

        $path = $state->field('path');

        if ($path === null) {
            continue;
        }

        ++$withPath;

        // ⚠️ **The comparison only means something where the old rule was applicable at all** — a row
        // in the **old** order, where `path` is the last field. *Measured on those, the two rules must
        // agree on every single row: 0 of 10745 rows hold ` path=` twice, which is the only case where
        // they could differ, and this is what keeps that measurement true tomorrow.*
        if (str_ends_with((string) $stored, ' path=' . $path)) {
            ++$oldOrder;

            if ($path !== $oldRule((string) $stored)) {
                $disagreed[] = sprintf('#%d %s: «%s» vs «%s»', $row['id'], $column, $path, (string) $oldRule((string) $stored));
            }

            continue;
        }

        // ⚠️ *And on a row in the **new** order the old rule is simply wrong — it would hand back the
        // path with the name glued to it. **That is the evidence the reader had to be replaced** and
        // not merely tidied: it is counted rather than asserted in prose.*
        if ($path !== $oldRule((string) $stored)) {
            ++$oldWrong;
        }
    }
}

printf(
    "       %d rows, %d state columns read, %d field lists with a path (%d in the old order), %d plain\n",
    count($all),
    $parsed,
    $withPath,
    $oldOrder,
    $plain
);

check('every stored state was readable', $disagreed === [], implode(' | ', array_slice($disagreed, 0, 5)));
// ⚠️ **Die zwei Deckel standen auf tausend, und das Aenderungsbuch ist am 2026-09-05 auf sein Wort
// geleert worden** («einmal komplett leeren, nicht selektiv», [D-634](../../docs/NewConcept/90-decision-log.md)).
// *Eine Zusage auf **tausende alte Zeilen** bewacht damit einen vergangenen Zustand und keinen
// gewollten — `PR-9` sagt, dann wird sie auf den heutigen umgeschrieben, sichtbar und mit Grund,
// **und niemals entschaerft.** Der Gegenfall bleibt deshalb erhalten: es muss **ueberhaupt** etwas
// gelesen worden sein, sonst waere «alle lesbar» auch wahr, wenn nichts da ist. Nur die Zahl ist
// die von heute statt die von gestern.*
check('and something was actually read', $parsed > 0, (string) $parsed);

// ⚠️ *Die alte Ordnung kommt in einem frisch geleerten Buch gar nicht mehr vor — sie ist die Form
// von **vor** der Berichtigung. Ihre Zahl wird darum **gemeldet und nicht verlangt**; verlangt wird
// weiter, dass die alte Regel dort, wo sie noch vorkommt, nachweislich falsch antwortet (die
// naechste Zusage), denn das ist der Satz, um dessentwillen es diese Pruefung gibt.*
printf("       (alte Ordnung: %d Zeilen — gemeldet, nicht verlangt)\n", $oldOrder);
// ⚠️ *Not a nicety: it says the old reader **could not have been kept**. Any row whose `path` is not
// the last field — every setting entry now, and every node state written since — makes
// `strrpos(' path=')` answer the path with the rest of the row glued to it.*
check(
    'and the rule that was replaced is measurably wrong on rows in the new format',
    $oldWrong > 0,
    sprintf('%d rows where `strrpos(\' path=\')` answers something else', $oldWrong)
);

// ── 5. The reader the restore depends on ───────────────────────────────────
echo "\n== 5. Parking and coming back, through the new order ==\n";

$was = $nodes->byId($thing->id)->path;

$editor->moveToTrash($thing->id);

check(
    'the old place is still found in the journal',
    $changelog->pathBeforeLastParking($thing->id) === $was,
    var_export($changelog->pathBeforeLastParking($thing->id), true) . ' vs ' . $was
);

$restored = $editor->restore($thing->id);

check('and the node came back to it', $restored->node->path === $was, $restored->node->path);

// ── 6. Tidying up ─────────────────────────────────────────────────────────
echo "\n== 6. Tidying up ==\n";

$editor->removeField($thing->id, $first->id);
$editor->moveToTrash($thing->id);
$editor->moveToTrash($text->id);

foreach ([$thing->id, $text->id, $first->id] as $id) {
    $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('labels') . ' WHERE owner_id = %d', $id));
    $wpdb->query($wpdb->prepare("DELETE FROM {$journal} WHERE owner_id = %d", $id));
}

// ⚠️ **Und aus dem Papierkorb heraus, sonst liegen sie in seinem Baum.** *Bis zum 2026-09-01 hat
// dieser Lauf seine zwei Knoten **geparkt** und stehen gelassen — je Lauf ein frisches Paar, das
// vorige verschwand, aber zwei lagen immer dort. Der Eigentümer hat dieselbe Sorte Müll an
// `__uv Resistor` gefunden: «ist übrigens ein Überbleibsel von dir, ich brauche den nicht.»*
//
// ⚠️ **Aufheben, dann entfernen** ([D-535](../../docs/NewConcept/90-decision-log.md)) — *sein Wort:
// «löschen tun wir ja eh nicht, wir schieben es in die Schattentabelle». Auch beim eigenen Müll.*
//
// ⚠️ *Nur die eigenen Ids und **nie** `clearTrash()`: das räumt auch weg, was ein Mensch dort
// geparkt hat und zurückholen wollte.*
foreach ([$thing->id, $text->id] as $meiner) {
    foreach ($wpdb->get_col($wpdb->prepare('SELECT id FROM ' . Schema::table('node_records') . ' WHERE node_id = %d', $meiner)) ?: [] as $satzId) {
        Shadow::keep('relation_records', 'node_record_id = %d', [(int) $satzId], true);
        Shadow::keep('node_records', 'id = %d', [(int) $satzId], true);

        $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('relation_records') . ' WHERE node_record_id = %d', (int) $satzId));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('node_records') . ' WHERE id = %d', (int) $satzId));
    }

    Shadow::keep('relations', 'from_node_id = %d OR to_node_id = %d', [$meiner, $meiner], true);
    Shadow::keep('nodes', 'id = %d', [$meiner], true);

    $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('relations') . ' WHERE from_node_id = %d OR to_node_id = %d', $meiner, $meiner));
    $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('nodes') . ' WHERE id = %d', $meiner));
}

sane('tidying');

check(
    'the scratch journal rows are gone',
    (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$journal} WHERE owner_id = %d", $thing->id)) === 0
);

// ⚠️ *Der Gegenfall: kein eigener Knoten bleibt liegen. Ohne ihn war der Lauf grün und liess zwei
// im Papierkorb — dreizehn Läufe lang.*
check(
    'and no scratch node is left behind',
    (int) $wpdb->get_var("SELECT COUNT(*) FROM " . Schema::table('nodes_named') . " WHERE name LIKE '\_\_ja %'") === 0,
    (string) $wpdb->get_var("SELECT COUNT(*) FROM " . Schema::table('nodes_named') . " WHERE name LIKE '\_\_ja %'")
);

echo "\n---- {$ok} passed, {$bad} failed ----\n";

exit($bad === 0 ? 0 : 1);
