<?php declare(strict_types=1);

/**
 * Die Datensätze eines Knotens erscheinen in Seiten zu fünf, und die Abfragen hängen an der Seite, nicht am Bestand.
 *
 *     php scripts/dev/record-pages-check.php [path/to/wordpress]
 *
 * ⚠️ **Sein Wort vom 2026-09-13** ([D-763](../../docs/NewConcept/90-decision-log.md)): *«dies nur auf
 * seiten aufteilen und immer nur 5 laden».* Gemessen davor: `CPUs` mit 59 Sätzen, 5,8 s und 4 939 Abfragen.
 *
 * ```mermaid
 * flowchart LR
 *   W["Wiese __rp: 12 Sätze"] --> S1["Seite 1: Sätze 1–5, Leiste"]
 *   W --> S3["Seite 3: Sätze 11–12"]
 *   W --> L["last / 99: letzte Seite"]
 *   W --> A["Abfragen bei 7 und 12 Sätzen gleich"]
 *   K["Knoten mit 5 Sätzen"] --> O["keine Leiste"]
 * ```
 *
 * @see docs/NewConcept/97-implementation-plan.md
 */

$wordpress = $argv[1] ?? (getenv('WP_ROOT') ?: 'C:/Devel/Wordpress');

define('WP_USE_THEMES', false);
define('SAVEQUERIES', true);

require rtrim($wordpress, '/') . '/wp-load.php';

// ⚠️ **Kein Wächter schreibt in das Modell des Eigentümers** — die Klammer dreht am Ende alles zurück.
require __DIR__ . '/lib/no-write.php';
require __DIR__ . '/../../vendor/autoload.php';

use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Service\DataEntry;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\SeededTypeNodes;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbLabelRepository;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRecordRepository;
use Taxmod\WordPress\Persistence\WpdbRelationRepository;
use Taxmod\WordPress\Persistence\WpdbSettingsRepository;
use Taxmod\WordPress\Plugin;
use Taxmod\WordPress\SystemClock;

register_shutdown_function(static fn (): int => Schema::forgetOrphanLabels());

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

/** @return array{0: string, 1: int} das Markup der Knotenseite und die Zahl ihrer Abfragen */
function seite(int $nodeId, ?string $blatt = null): array
{
    global $wpdb;

    $_GET['page']        = 'taxmod-nodes';
    $_GET['taxmod_node'] = (string) $nodeId;

    if ($blatt === null) {
        unset($_GET['taxmod_record_page']);
    } else {
        $_GET['taxmod_record_page'] = $blatt;
    }

    $rc     = new ReflectionClass(Plugin::class);
    $plugin = $rc->newInstanceWithoutConstructor();
    $rc->getProperty('file')->setValue($plugin, __FILE__);

    $wpdb->queries = [];
    $markup        = $plugin->screen()->render();
    $abfragen      = count($wpdb->queries);
    unset($_GET['taxmod_record_page']);

    return [$markup, $abfragen];
}

/** @param list<int> $ids @return list<int> welche der Sätze die Seite zeigt */
function gezeigt(string $markup, array $ids): array
{
    return array_values(array_filter($ids, static fn (int $id): bool => str_contains($markup, '<code>#' . $id . '</code>')));
}

$log       = new WpdbChangelog(new SystemClock());
$nodes     = new WpdbNodeRepository();
$relations = new WpdbRelationRepository();
$rows      = new WpdbRecordRepository();
$framework = new SeededFrameworkNodes($nodes, $relations, $log);
$editor    = new ModelEditor($nodes, $relations, $framework, $log, new WpdbLabelRepository(), $rows, new WpdbSettingsRepository());
$data      = new DataEntry($rows, $relations, $nodes, $framework, new SystemClock(), $log);
$types     = new SeededTypeNodes($nodes, $framework);

wp_set_current_user(1);

// ⚠️ *Die Seitengrösse ist seine Einstellung (5, 10, 20, 50); dieser Lauf misst mit fünf und setzt sie selbst — die Klammer dreht es zurück.*
update_option(\Taxmod\WordPress\Admin\SettingsScreen::RECORDS_PER_PAGE, 5);

echo "record-pages — Datensätze in Seiten zu fünf (D-763)\n";

$wiese = $editor->createNode('__rp Seiten', $framework->rootOf(Branch::Model)->id);
$feld  = $editor->addField($wiese->id, $types->nodeId(SimpleType::Text), 'name');

$anlegen = static function (int $knoten, int $wieviele) use ($data, $feld): array {
    $ids = [];

    for ($i = 1; $i <= $wieviele; $i++) {
        $satz = $data->create($knoten);
        $data->put($satz->id, $feld->id, TypedValue::ofText('__rp ' . $i));
        $ids[] = $satz->id;
    }

    return $ids;
};

// 1 · Fünf Sätze: eine Seite, keine Leiste
$fuenf = $anlegen($wiese->id, 5);
[$m] = seite($wiese->id);
check('fünf Sätze stehen alle da', gezeigt($m, $fuenf) === $fuenf);
check('bei fünf Sätzen keine Blätterleiste', ! str_contains($m, 'taxmod-record-pager'));

// 2 · Sieben Sätze, Abfragen gemessen
$sieben = [...$fuenf, ...$anlegen($wiese->id, 2)];
[$m, $abfragenBeiSieben] = seite($wiese->id);
check('bei sieben Sätzen zeigt Seite 1 die ersten fünf', gezeigt($m, $sieben) === array_slice($sieben, 0, 5), implode(',', gezeigt($m, $sieben)));
check('und eine Blätterleiste', str_contains($m, 'taxmod-record-pager'));

// 3 · Zwölf Sätze
$zwoelf = [...$sieben, ...$anlegen($wiese->id, 5)];
[$m, $abfragenBeiZwoelf] = seite($wiese->id);
check('bei zwölf Sätzen zeigt Seite 1 die ersten fünf', gezeigt($m, $zwoelf) === array_slice($zwoelf, 0, 5));
check(
    'die Abfragen hängen an der Seite, nicht am Bestand',
    abs($abfragenBeiZwoelf - $abfragenBeiSieben) <= 2,
    "sieben Sätze {$abfragenBeiSieben}, zwölf Sätze {$abfragenBeiZwoelf}"
);
check('Seite 1 nennt «Records 1–5 of 12»', str_contains($m, 'Records 1–5 of 12'));

[$m] = seite($wiese->id, '2');
check('Seite 2 zeigt die Sätze 6–10', gezeigt($m, $zwoelf) === array_slice($zwoelf, 5, 5));

[$m] = seite($wiese->id, '3');
check('Seite 3 zeigt die letzten zwei', gezeigt($m, $zwoelf) === array_slice($zwoelf, 10, 2));
check('der Link zur nächsten Seite ist auf der letzten gesperrt', str_contains($m, 'aria-disabled="true" title="Next page"'));

[$m] = seite($wiese->id, 'last');
check('«last» ist die letzte Seite', gezeigt($m, $zwoelf) === array_slice($zwoelf, 10, 2));

[$m] = seite($wiese->id, '99');
check('eine Seite hinter dem Ende zeigt die letzte', gezeigt($m, $zwoelf) === array_slice($zwoelf, 10, 2));

[$m] = seite($wiese->id, 'x');
check('Unsinn in der Adresse ist Seite 1', gezeigt($m, $zwoelf) === array_slice($zwoelf, 0, 5));

// 3a · Ein Satz mit Teilen fragt keine Knoten nach, die schon ein anderer Satz gelesen hat (D-764)
$einheitswert = array_values($nodes->ofClass(\Taxmod\Core\Model\NodeClass\UnitValue::class))[0] ?? null;
check('ein Einheitswert-Knoten ist da', $einheitswert !== null);

if ($einheitswert !== null) {
    $dezimal  = $types->nodeId(SimpleType::Decimal);
    $wertFeld = array_values(array_filter($editor->fieldsOf($einheitswert->id), static fn ($f): bool => $f->toNodeId === $dezimal))[0] ?? null;
    $teile    = $editor->createNode('__rp Teile', $framework->rootOf(Branch::Model)->id);
    $messung  = $editor->addField($teile->id, $einheitswert->id, 'messung');
    $teilSaetze = [];

    for ($i = 1; $i <= 10; $i++) {
        $satz = $data->create($teile->id);
        $teil = $data->partsOf($satz->id)[$messung->id] ?? $data->createPart($satz->id, $messung->id)->id;

        if ($wertFeld !== null) {
            $data->put($teil, $wertFeld->id, TypedValue::ofDecimal((string) $i));
        }

        $teilSaetze[] = $satz->id;
    }

    $einzeln = static function () use (&$wpdb): int {
        return count(array_filter($wpdb->queries, static fn (array $q): bool => str_contains($q[0], 'WHERE n.id = ')));
    };

    update_option(\Taxmod\WordPress\Admin\SettingsScreen::RECORDS_PER_PAGE, 5);
    seite($teile->id);
    $beiFuenf = $einzeln();
    update_option(\Taxmod\WordPress\Admin\SettingsScreen::RECORDS_PER_PAGE, 10);
    [$m] = seite($teile->id);
    $beiZehn = $einzeln();
    update_option(\Taxmod\WordPress\Admin\SettingsScreen::RECORDS_PER_PAGE, 5);

    check('zehn Sätze mit Teilen stehen da', gezeigt($m, $teilSaetze) === $teilSaetze);
    check(
        'Einzelabfragen nach Knoten wachsen nicht mit den gezeigten Sätzen',
        $beiZehn <= $beiFuenf,
        "fünf Sätze {$beiFuenf}, zehn Sätze {$beiZehn}"
    );
}

// 3b · Die Einstellung zählt
update_option(\Taxmod\WordPress\Admin\SettingsScreen::RECORDS_PER_PAGE, 10);
[$m] = seite($wiese->id);
check('mit der Einstellung 10 zeigt Seite 1 zehn Sätze', gezeigt($m, $zwoelf) === array_slice($zwoelf, 0, 10));
update_option(\Taxmod\WordPress\Admin\SettingsScreen::RECORDS_PER_PAGE, 7);
[$m] = seite($wiese->id);
check('eine Zahl, die keine der vier ist, zählt als fünf', gezeigt($m, $zwoelf) === array_slice($zwoelf, 0, 5));
update_option(\Taxmod\WordPress\Admin\SettingsScreen::RECORDS_PER_PAGE, 5);

// 4 · Die Seite reist nur auf demselben Knoten
[$m] = seite($wiese->id, '2');
check(
    'die Links der Leiste tragen die Seitenzahl',
    str_contains($m, 'taxmod_record_page=3')
);
check(
    'ein Link auf einen anderen Knoten trägt sie nicht',
    ! preg_match('/taxmod_node=' . $framework->rootOf(Branch::Model)->id . '[^"]*taxmod_record_page/', $m)
);

// 5 · «New record» führt auf die letzte Seite, und Speichern bleibt, wo es war
$abschicken = static function (array $post): string {
    $_POST    = $post;
    $_REQUEST = $post;
    $ort      = '';
    $fang     = static function (string $wohin) use (&$ort): string {
        $ort = $wohin;
        throw new RuntimeException('redirect');
    };

    add_filter('wp_redirect', $fang, 1);

    $rc     = new ReflectionClass(Plugin::class);
    $plugin = $rc->newInstanceWithoutConstructor();
    $rc->getProperty('file')->setValue($plugin, __FILE__);

    try {
        $plugin->screen()->handlePost();
    } catch (RuntimeException) {
    } finally {
        remove_filter('wp_redirect', $fang, 1);
        $_POST    = [];
        $_REQUEST = [];
    }

    return $ort;
};

$aktion = (new ReflectionClassConstant(\Taxmod\WordPress\Admin\NodesScreen::class, 'ACTION'))->getValue();
$form   = [
    'action'        => $aktion,
    'id'            => (string) $wiese->id,
    '_taxmod_nonce' => wp_create_nonce($aktion . '_' . $wiese->id),
];

$ort = $abschicken([...$form, 'do' => 'add_record', 'taxmod_record_page' => '1']);
check('«New record» führt auf die letzte Seite', str_contains($ort, 'taxmod_record_page=last'), $ort);

$ort = $abschicken([...$form, 'do' => 'save_record', 'node_record_id' => (string) $zwoelf[5], 'taxmod_record_page' => '2']);
check('Speichern auf Seite 2 bleibt auf Seite 2', str_contains($ort, 'taxmod_record_page=2'), $ort);

echo "\n" . ($failed === 0 ? 'all green' : "{$failed} FAILED") . " ({$passed} ok)\n";
exit($failed === 0 ? 0 : 1);
