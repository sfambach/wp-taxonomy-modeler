<?php declare(strict_types=1);

/**
 * Geht die Renderer-Wahl den Weg **ueber die Maske** — waehlen, speichern, neu lesen, nachsehen?
 *
 * TASK-052, [D-617](../../docs/NewConcept/90-decision-log.md). **Sein Befund:** *«der wird irgendwie
 * aktuell nicht beruecksichtigt und auch nicht gespeichert».*
 *
 * ⚠️ **Diese Datei entstand, weil vier gruene Waechter ihm widersprachen und er recht hatte.**
 * *`renderer-choice-check`, `setting-write-check`, `page-blocks-check` und `multiplicity-check`
 * schreiben alle ueber den **Kern**. Gemessen am 2026-09-05 stand auf der Seite von `Integer` **kein
 * einziges Steuerelement mit `renderer` im Namen** — es gab nichts zu speichern, weil es nichts zu
 * bedienen gab, und keine der vier konnte das sehen. **Diese hier geht deshalb den ganzen Weg:
 * Markup lesen, `handlePost()` rufen, frisch aufloesen.***
 *
 * ⚠️ **Eigene Knoten, kein Name aus seinem Modell** ([D-613](../../docs/NewConcept/90-decision-log.md),
 * [D-614](../../docs/NewConcept/90-decision-log.md)): angelegt, geprueft, weggeraeumt — und
 * weggeraeumt nach dem eigenen Namensmuster `__rcm `, nie ueber `clearTrash()`.
 *
 * ⚠️ *`handlePost()` endet mit `exit`. Der Lauf haengt sich deshalb in `wp_redirect` und wirft dort —
 * **die Weiterleitung ist das Zeichen, dass der Akt durch ist**, und der Wurf holt den Lauf zurueck,
 * ohne dass die Methode dafuer umgebaut werden muesste.*
 *
 * Usage: php scripts/dev/renderer-choice-mask-check.php C:/Devel/Wordpress
 *
 * @see docs/pakete/modelltabellen/tasks.md
 */

$wordpress = $argv[1] ?? 'C:/Devel/Wordpress';

define('WP_USE_THEMES', false);

require rtrim($wordpress, '/') . '/wp-load.php';
require __DIR__ . '/../../vendor/autoload.php';

use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Model\SettingKey;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\Core\Service\ModelValues;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\SeededTypeNodes;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbLabelRepository;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRecordRepository;
use Taxmod\WordPress\Persistence\WpdbRelationRepository;
use Taxmod\WordPress\Plugin;
use Taxmod\WordPress\SystemClock;

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

global $wpdb;

$p = $wpdb->prefix . 'taxmod_';

$log       = new WpdbChangelog(new SystemClock());
$nodes     = new WpdbNodeRepository();
$relations     = new WpdbRelationRepository();
$rows      = new WpdbRecordRepository();
$framework = new SeededFrameworkNodes($nodes, $relations, $log);
$editor    = new ModelEditor($nodes, $relations, $framework, $log, new WpdbLabelRepository(), $rows);
$types     = new SeededTypeNodes($nodes, $framework);

/** Die Seite als Markup, so wie ein Browser sie bekommt. */
function seite(int $nodeId): string
{
    $_GET['page']        = 'taxmod-nodes';
    $_GET['taxmod_node'] = (string) $nodeId;

    $rc     = new ReflectionClass(Plugin::class);
    $plugin = $rc->newInstanceWithoutConstructor();
    $rc->getProperty('file')->setValue($plugin, __FILE__);

    return $plugin->screen()->render();
}

/**
 * Den Akt abschicken, wie das Seitenformular ihn abschickt — und die Weiterleitung abfangen.
 *
 * @param array<string, mixed> $post
 */
function abschicken(array $post): bool
{
    // ⚠️ *`$_REQUEST` mit, weil `check_admin_referer()` dort nachsieht und nicht in `$_POST` — sonst
    // stirbt der Akt mit «the link you followed has expired», und der Waechter meldete einen Fehler,
    // den nur er selbst gemacht hat.*
    $_POST    = $post;
    $_REQUEST = $post;

    $gewandert = false;

    $fang = static function () use (&$gewandert): string {
        $gewandert = true;

        throw new RuntimeException('redirect');
    };

    add_filter('wp_redirect', $fang, 1);

    $rc     = new ReflectionClass(Plugin::class);
    $plugin = $rc->newInstanceWithoutConstructor();
    $rc->getProperty('file')->setValue($plugin, __FILE__);

    try {
        $plugin->screen()->handlePost();
    } catch (RuntimeException) {
        // ⚠️ *Erwartet: der Akt ist durch und wollte weiterleiten.*
    } finally {
        remove_filter('wp_redirect', $fang, 1);
        $_POST = [];
        $_REQUEST = [];
    }

    return $gewandert;
}

wp_set_current_user(1);

echo "\n== ein eigener Knoten, der einen Renderer haben kann ==\n";

$intId = $types->nodeId(SimpleType::Int);

if ($intId === null) {
    echo "  FAIL der Int-Typ ist nicht aufgeschrieben — ohne ihn gibt es keine Renderer zur Wahl\n";

    exit(1);
}

$probe = $editor->createNode('__rcm probe', $intId);

$markup = seite($probe->id);

check(
    'die Seite zeichnet einen Renderer-Waehler',
    str_contains($markup, 'name="taxmod_setting[renderer]"'),
    'kein Steuerelement mit diesem Namen im Markup'
);

// ⚠️ **Ohne `form="…"` schickt das Steuerelement lautlos nichts** — genau der Regress, den
// `labels-page-save-check` einmal gefangen hat. *Ein Waehler, der dasteht und nichts abschickt, sieht
// aus wie einer, der gespeichert hat.*
check(
    'er haengt am Seitenformular',
    (bool) preg_match(
        '/<select name="taxmod_setting\[renderer\]" form="taxmod-page-' . $probe->id . '"/',
        $markup
    ),
    'kein form="taxmod-page-' . $probe->id . '" am Waehler'
);

preg_match_all(
    '/<option value="([^"]*)"/',
    (string) (preg_split('/name="taxmod_setting\[renderer\]"/', $markup)[1] ?? ''),
    $treffer
);

$angebot = array_values(array_filter(array_slice($treffer[1] ?? [], 0, 8), static fn (string $n): bool => $n !== ''));

check('er bietet mindestens zwei Renderer an', count($angebot) >= 2, implode(',', $angebot));

echo "\n== waehlen, speichern, frisch lesen ==\n";

/** Der Renderer, den die Aufloesung nach einem frischen Lesen nennt. */
function gespeicherterRenderer(int $nodeId): string
{
    $nodes = new WpdbNodeRepository();
    $relations = new WpdbRelationRepository();
    $fw    = new SeededFrameworkNodes($nodes, $relations, new WpdbChangelog(new SystemClock()));
    $model = new ModelValues(new WpdbRecordRepository(), $relations, $nodes, $fw);

    $node = $nodes->find($nodeId);

    if ($node === null) {
        return '';
    }

    return (string) (($model->forNode($node)[SettingKey::Renderer->value] ?? null)?->value->text ?? '');
}

$vorher = gespeicherterRenderer($probe->id);
$wahl   = $angebot[0] === $vorher ? $angebot[1] : $angebot[0];

$gewandert = abschicken([
    'do'             => 'put_setting',
    'id'             => (string) $probe->id,
    '_taxmod_nonce'  => wp_create_nonce('taxmod_node_' . $probe->id),
    'taxmod_setting' => ['renderer' => $wahl],
]);

check('der Akt ist durchgelaufen', $gewandert);

$nachher = gespeicherterRenderer($probe->id);

check(
    'die Wahl steht nach dem Speichern da',
    $nachher === $wahl,
    "gewaehlt {$wahl}, gelesen «{$nachher}», vorher «{$vorher}»"
);

// ⚠️ **Die Spalte ist der Ort** ([D-584](../../docs/NewConcept/90-decision-log.md)) — *und ohne diese
// Zusage waere «gelesen» auch dann gruen, wenn der Wert an einer Kante laege, die niemand mehr hat.*
$spalte = (int) $wpdb->get_var("SELECT settings_record_id FROM {$p}nodes WHERE id = {$probe->id}");

check('sie steht in nodes.settings_record_id', $spalte !== 0, (string) $spalte);

echo "\n== und die Maske zeigt danach, was dasteht ==\n";

$markup = seite($probe->id);

check(
    'der Waehler steht auf der Wahl',
    (bool) preg_match('/<option value="' . preg_quote($wahl, '/') . '" selected/', $markup),
    "«{$wahl}» ist im Markup nicht als gewaehlt markiert"
);

echo "\n== eine zweite Wahl gewinnt gegen die erste ==\n";

$zweite = $angebot[0] === $wahl ? ($angebot[1] ?? $wahl) : $angebot[0];

abschicken([
    'do'             => 'put_setting',
    'id'             => (string) $probe->id,
    '_taxmod_nonce'  => wp_create_nonce('taxmod_node_' . $probe->id),
    'taxmod_setting' => ['renderer' => $zweite],
]);

check(
    'die zweite Wahl steht da',
    gespeicherterRenderer($probe->id) === $zweite,
    'gelesen «' . gespeicherterRenderer($probe->id) . "», gewaehlt {$zweite}"
);

echo "\n== aufraeumen ==\n";

// ⚠️ *Nach dem eigenen Namensmuster und nie ueber `clearTrash()` — dort liegt seine geparkte Arbeit
// (TASK-039). Nach Namen und nicht nur nach den Ids dieses Laufs: ein abgestuerzter Lauf laesst sonst
// Reste stehen, die der naechste als eigenen Fehlschlag meldet.*
$meine = array_map('intval', $wpdb->get_col("SELECT id FROM {$p}nodes WHERE name LIKE '\\_\\_rcm %'"));
$in    = $meine === [] ? (string) $probe->id : implode(',', $meine);

$wpdb->query("DELETE FROM {$p}relation_records WHERE node_record_id IN (SELECT id FROM {$p}node_records WHERE node_id IN ({$in}))");
$wpdb->query("DELETE FROM {$p}node_records WHERE node_id IN ({$in})");
$wpdb->query("DELETE FROM {$p}labels WHERE owner_id IN ({$in})");
$wpdb->query("DELETE FROM {$p}relations WHERE from_node_id IN ({$in}) OR to_node_id IN ({$in})");
$wpdb->query("DELETE FROM {$p}nodes WHERE id IN ({$in})");

check(
    'der Waechter laesst nichts zurueck',
    (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}nodes WHERE name LIKE '\\_\\_rcm %'") === 0
);

printf("\n%d ok, %d failed\n", $passed, $failed);

exit($failed === 0 ? 0 : 1);
