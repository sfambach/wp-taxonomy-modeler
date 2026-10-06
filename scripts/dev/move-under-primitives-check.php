<?php declare(strict_types=1);

/**
 * Verschieben unter `Primitives` nimmt die Sätze mit — Teile bleiben, Einträge ohne Verwender nach Bestätigung in den Schatten.
 *
 *     php scripts/dev/move-under-primitives-check.php [path/to/wordpress]
 *
 * ⚠️ **[D-701](../../docs/NewConcept/90-decision-log.md), sein Beschluss:** *«Fall 1 bleibt ganz, Fall 2 wird
 * gezeigt der benutzer muss bestätigen».* Und sein Einwand davor: *«allerdings dürfen sie nicht einfach
 * still gelöscht»*.
 *
 * ```mermaid
 * flowchart LR
 *   D["__mp Ding unter Model · zwei Einträge"] -->|"einer gehalten von __mp Kunde"| T["Teil · bleibt"]
 *   D -->|"einer ohne Verwender"| F["gezeigt · nach Bestätigung in den Schatten"]
 *   M["move nach Data Types ohne Bestätigung"] --> N["nichts passiert · die Seite sagt, was es kostet"]
 *   M2["move mit Bestätigung"] --> U["Knoten unter Data Types · Teil da · Eintrag im Schatten"]
 * ```
 *
 * @see docs/NewConcept/20-interaction.md
 */

$wordpress = $argv[1] ?? (getenv('WP_ROOT') ?: 'C:/Devel/Wordpress');

define('WP_USE_THEMES', false);

require rtrim($wordpress, '/') . '/wp-load.php';

// ⚠️ **Kein Wächter schreibt in das Modell des Eigentümers** — die Klammer dreht am Ende alles zurück.
require __DIR__ . '/lib/no-write.php';
require __DIR__ . '/../../vendor/autoload.php';

use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\RelationKind;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Model\Type\TextType;
use Taxmod\Core\Service\DataEntry;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbLabelRepository;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRecordRepository;
use Taxmod\WordPress\Persistence\WpdbRelationRepository;
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

global $wpdb;

$log       = new WpdbChangelog(new SystemClock());
$nodes     = new WpdbNodeRepository();
$relations = new WpdbRelationRepository();
$rows      = new WpdbRecordRepository();
$framework = new SeededFrameworkNodes($nodes, $relations, $log);
$editor    = new ModelEditor($nodes, $relations, $framework, $log, new WpdbLabelRepository(), $rows);
$data      = new DataEntry($rows, $relations, $nodes, $framework, new SystemClock(), $log);

wp_set_current_user(1);

function seite(int $nodeId, array $umstaende = []): string
{
    $_GET = ['page' => 'taxmod-nodes', 'taxmod_node' => (string) $nodeId, ...$umstaende];

    $rc     = new ReflectionClass(Plugin::class);
    $plugin = $rc->newInstanceWithoutConstructor();
    $rc->getProperty('file')->setValue($plugin, __FILE__);

    $markup = $plugin->screen()->render();
    $_GET   = [];

    return $markup;
}

/** Den Akt abschicken, wie das Seitenformular ihn abschickt — und die Antwort aus der Weiterleitung lesen. */
function abschicken(array $post): array
{
    $_POST    = $post;
    $_REQUEST = $post;
    $GLOBALS['taxmod_letzte_adresse'] = '';

    $fang = static function (string $ort): string {
        $GLOBALS['taxmod_letzte_adresse'] = $ort;

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

    parse_str((string) parse_url((string) $GLOBALS['taxmod_letzte_adresse'], PHP_URL_QUERY), $teile);

    return ['nachricht' => rawurldecode((string) ($teile['taxmod_message'] ?? '')), 'umstaende' => $teile];
}

function verschieben(int $nodeId, int $target, bool $bestaetigt = false): array
{
    return abschicken([
        'do'            => 'move',
        'id'            => (string) $nodeId,
        '_taxmod_nonce' => wp_create_nonce('taxmod_node_' . $nodeId),
        'target'        => (string) $target,
        ...($bestaetigt ? ['taxmod_move_confirm' => '1'] : []),
    ]);
}

$text = $editor->nodeImplementing(TextType::class);

if ($text === null) {
    echo "  FAIL `Text` fehlt im Gerüst\n";

    exit(1);
}

echo "== die Wiese: ein Ding mit zwei Einträgen, einer davon ein Teil eines Kunden ==\n";

$modellWurzel = $framework->rootOf(Branch::Model);
$ding  = $editor->createNode('__mp Ding', $modellWurzel->id);
$name  = $editor->addField($ding->id, $text->id, '__mp Name', RelationKind::Composition);
$kunde = $editor->createNode('__mp Kunde', $modellWurzel->id);
$hat   = $editor->addField($kunde->id, $ding->id, '__mp hat', RelationKind::Aggregation);

$teil = $data->create($ding->id);
$data->put($teil->id, $name->id, TypedValue::ofText('__mp gehalten'));
$frei = $data->create($ding->id);
$data->put($frei->id, $name->id, TypedValue::ofText('__mp frei'));

$kundeSatz = $data->create($kunde->id);
$data->put($kundeSatz->id, $hat->id, TypedValue::ofRecordReference($teil->id));

$ohne = $data->unheldUserRecordsUnder($nodes->find($ding->id));
check('genau ein Eintrag ohne Verwender, mit einem Wert', $ohne['records'] === 1 && $ohne['values'] === 1 && $ohne['ids'] === [$frei->id], json_encode($ohne));

echo "\n== 1. ohne Bestätigung: nichts passiert, die Seite sagt, was es kostet ==\n";

$dataTypes = $framework->rootOf(Branch::DataTypes);
$antwort   = verschieben($ding->id, $dataTypes->id);
check('die Antwort nennt den Eintrag und verlangt die Bestätigung', str_contains($antwort['nachricht'], '1 entry') && str_contains($antwort['nachricht'], 'confirm below'), $antwort['nachricht']);
check('der Knoten steht noch unter Model', $editor->find($ding->id)?->parentNodeId === $modellWurzel->id);
check('beide Einträge stehen noch', $data->find($teil->id) !== null && $data->find($frei->id) !== null);

$umstand = (string) ($antwort['umstaende']['taxmod_move_pending'] ?? '');
check('die Weiterleitung trägt den wartenden Umzug', $umstand === "{$ding->id}:{$dataTypes->id}:1:1", $umstand);

$markup = seite($ding->id, ['taxmod_move_pending' => $umstand]);
check('die Seite zeigt den Knopf «ich bestätige»', str_contains($markup, 'taxmod-move-pending') && str_contains($markup, 'name="taxmod_move_confirm"'));
check('mit dem Ziel im Formular', str_contains($markup, 'name="target" value="' . $dataTypes->id . '"'));

echo "\n== 2. mit Bestätigung: der Knoten zieht um, der Teil bleibt, der freie Eintrag liegt im Schatten ==\n";

$antwort = verschieben($ding->id, $dataTypes->id, true);
check('der Umzug ist durch', $editor->find($ding->id)?->parentNodeId === $dataTypes->id, $antwort['nachricht']);
check('der Teil mit Verwender steht noch, mit seinem Wert', ($data->valuesOf($teil->id)[0] ?? null)?->value->text === '__mp gehalten');
check('der Eintrag ohne Verwender ist aus der lebenden Tabelle', $data->find($frei->id) === null);
check('und liegt im Schatten, also ist es umkehrbar', (int) $wpdb->get_var("SELECT COUNT(*) FROM " . Schema::table('node_records_history') . " WHERE id = {$frei->id}") === 1);
check('der Kunde zeigt weiter auf seinen Teil', ($data->valuesOf($kundeSatz->id)[0] ?? null)?->value->reference === $teil->id);

echo "\n== 3. ein Umzug innerhalb von Model fragt nicht ==\n";

$anderes = $editor->createNode('__mp Anderes', $modellWurzel->id);
$eintrag = $data->create($anderes->id);
$ziel2   = $editor->createNode('__mp Ziel', $modellWurzel->id);
$antwort = verschieben($anderes->id, $ziel2->id);
check('der Umzug unter Model geht sofort', $editor->find($anderes->id)?->parentNodeId === $ziel2->id, $antwort['nachricht']);
check('und der Eintrag bleibt', $data->find($eintrag->id) !== null);

echo "\n{$passed} ok, {$failed} failed\n";

exit($failed === 0 ? 0 : 1);
