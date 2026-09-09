<?php declare(strict_types=1);

/**
 * Die Kantenart ist in der eigenen Feldzeile änderbar — und beim Wechsel wandern die Werte mit.
 *
 *     php scripts/dev/field-kind-check.php [path/to/wordpress]
 *
 * ⚠️ **Sein Auftrag (TASK-066):** *«müsste änderbar sein mit den schon benannten regeln»* — und die
 * Regeln: [D-618](../../docs/NewConcept/90-decision-log.md) *«der benutzer legt fest»*,
 * [D-665](../../docs/NewConcept/90-decision-log.md) *ein Auswahlfeld wird überall gleich gezeichnet*,
 * [D-690](../../docs/NewConcept/90-decision-log.md) *«Werte wandern mit»*.
 *
 * ```mermaid
 * flowchart LR
 *   Z["eigene Zeile · select kind"] -->|"composition → aggregation"| A["Art steht"]
 *   Z -->|"composition → setting, ein Wert"| S["Wert im default-Satz, Benutzersatz leer"]
 *   Z -->|"setting → composition"| B["Wert bleibt im default-Satz"]
 *   Z -->|"composition → setting, zwei Werte"| K["Konflikt · nichts wandert"]
 *   G["geerbte Zeile"] --> W["die Art als Wort, kein Auswahlfeld"]
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
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbLabelRepository;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRecordRepository;
use Taxmod\WordPress\Persistence\WpdbRelationRepository;
use Taxmod\WordPress\Plugin;
use Taxmod\WordPress\SystemClock;

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
$relations = new WpdbRelationRepository();
$rows      = new WpdbRecordRepository();
$framework = new SeededFrameworkNodes($nodes, $relations, $log);
$editor    = new ModelEditor($nodes, $relations, $framework, $log, new WpdbLabelRepository(), $rows);
$data      = new DataEntry($rows, $relations, $nodes, $framework, new SystemClock(), $log);

wp_set_current_user(1);

function seite(int $nodeId): string
{
    $_GET['page']        = 'taxmod-nodes';
    $_GET['taxmod_node'] = (string) $nodeId;

    $rc     = new ReflectionClass(Plugin::class);
    $plugin = $rc->newInstanceWithoutConstructor();
    $rc->getProperty('file')->setValue($plugin, __FILE__);

    return $plugin->screen()->render();
}

/** Den Akt abschicken, wie das Seitenformular ihn abschickt — und die Antwort aus der Weiterleitung lesen. */
function abschicken(array $post): string
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

    return rawurldecode((string) ($teile['taxmod_message'] ?? ''));
}

function artSetzen(int $nodeId, int $feldId, string $art): string
{
    return abschicken([
        'do'                   => 'put_setting',
        'id'                   => (string) $nodeId,
        '_taxmod_nonce'        => wp_create_nonce('taxmod_node_' . $nodeId),
        'taxmod_field_setting' => [(string) $feldId => ['kind' => $art]],
    ]);
}

$text = $editor->nodeImplementing(TextType::class);

if ($text === null) {
    echo "  FAIL `Text` fehlt im Gerüst\n";

    exit(1);
}

echo "== 1. die eigene Zeile zeigt die Art als Auswahlfeld, die geerbte als Wort ==\n";

$modell = $editor->createNode('__fk Modell', $framework->rootOf(Branch::Model)->id);
$feld   = $editor->addField($modell->id, $text->id, '__fk Feld', RelationKind::Composition);
$kind   = $editor->createNode('__fk Kind', $modell->id);

$markup = seite($modell->id);
$name   = 'taxmod_field_setting[' . $feld->id . '][kind]';
check('die eigene Zeile trägt das Auswahlfeld der Art', str_contains($markup, 'name="' . $name . '"'));
check(
    'und `composition` steht als gewählt',
    (bool) preg_match('/name="' . preg_quote($name, '/') . '"[^>]*>.*?<option value="composition"[^>]*selected/s', $markup)
);
check('mit genau den drei Werten', substr_count(explode('</select>', explode('name="' . $name . '"', $markup)[1] ?? '')[0], '<option') === 3);

$geerbt = seite($kind->id);
check('die geerbte Zeile am Kind zeigt kein Auswahlfeld', ! str_contains($geerbt, 'name="' . $name . '"'));
check('sondern die Art als Wort', str_contains($geerbt, '<td class="taxmod-field-kind"><code>composition</code></td>'));

echo "\n== 2. ohne Werte wechselt die Art einfach ==\n";

artSetzen($modell->id, $feld->id, 'aggregation');
check('composition → aggregation', $editor->relationById($feld->id)?->kind === RelationKind::Aggregation, (string) $editor->relationById($feld->id)?->kind->value);
artSetzen($modell->id, $feld->id, 'composition');
check('und zurück', $editor->relationById($feld->id)?->kind === RelationKind::Composition);
artSetzen($modell->id, $feld->id, 'unfug');
check('ein unbekanntes Wort ist keine Angabe', $editor->relationById($feld->id)?->kind === RelationKind::Composition);

echo "\n== 3. Feld → Einstellung mit Benutzersätzen: nichts wandert, die Seite warnt und wartet auf den Haken (D-699) ==\n";

$satz = $data->create($modell->id);
$data->put($satz->id, $feld->id, TypedValue::ofText('sieben'));
$zweiter = $data->create($modell->id);
$data->put($zweiter->id, $feld->id, TypedValue::ofText('acht'));
check('zwei Benutzersätze tragen je einen Wert', ($data->valuesOf($satz->id)[0] ?? null)?->value->text === 'sieben' && ($data->valuesOf($zweiter->id)[0] ?? null)?->value->text === 'acht');

$antwort = artSetzen($modell->id, $feld->id, 'setting');
check('die Antwort nennt die Sätze und verlangt den Haken', str_contains($antwort, '2 entries') && str_contains($antwort, 'tick the confirmation'), $antwort);
check('die Kante bleibt ein Feld', $editor->relationById($feld->id)?->kind === RelationKind::Composition);
check('und beide Werte stehen noch', ($data->valuesOf($satz->id)[0] ?? null)?->value->text === 'sieben' && ($data->valuesOf($zweiter->id)[0] ?? null)?->value->text === 'acht');

// ⚠️ *Die Weiterleitung trägt den wartenden Wechsel als Umstand; die Seite zeigt ihn in der Zeile.*
$_GET['taxmod_kind_pending'] = $feld->id . ':setting:2:2';
$markup = seite($modell->id);
unset($_GET['taxmod_kind_pending']);
$name   = 'taxmod_field_setting[' . $feld->id . '][kind]';
check('die Zeile zeigt `setting` vorgewählt', (bool) preg_match('/name="' . preg_quote($name, '/') . '"[^>]*>.*?<option value="setting"[^>]*selected/s', $markup));
check('mit dem Haken «ich bestätige»', str_contains($markup, 'name="taxmod_field_setting[' . $feld->id . '][kind_confirm]"'));
check('und dem Satz, was der Haken kostet', str_contains($markup, '2 entries with 2 values go to the shadow'));

echo "\n== 4. mit Haken: die Sätze gehen in den Schatten, die Art wechselt, die Einstellung beginnt leer ==\n";

abschicken([
    'do'                   => 'put_setting',
    'id'                   => (string) $modell->id,
    '_taxmod_nonce'        => wp_create_nonce('taxmod_node_' . $modell->id),
    'taxmod_field_setting' => [(string) $feld->id => ['kind' => 'setting', 'kind_confirm' => '1']],
]);
check('die Kante ist eine Einstellung', $editor->relationById($feld->id)?->kind === RelationKind::Setting);
check('die zwei Benutzersätze sind aus der lebenden Tabelle', $data->find($satz->id) === null && $data->find($zweiter->id) === null);
check('und liegen im Schatten, also ist es umkehrbar', (int) $wpdb->get_var("SELECT COUNT(*) FROM " . \Taxmod\WordPress\Persistence\Schema::table('node_records_history') . " WHERE id IN ({$satz->id}, {$zweiter->id})") === 2);
check('die Einstellung beginnt leer', ($data->settingValuesOf($modell->id, [$feld->id])[$feld->id] ?? null) === null);

echo "\n== 5. Einstellung → Feld: was im Einstellungssatz steht, bleibt dort (D-699, Satz 3) ==\n";

// ⚠️ *Gesät über den Speicher, nicht über den Kern: ein Textwert an einer Einstellungskante auf `Text`
// weist der Kern heute ab (ein Teil bräuchte einen eigenen Satz) — hier geht es nur darum, dass eine
// Zeile im Einstellungssatz den Artwechsel überlebt.*
$einstellungssatz = $rows->add(new \Taxmod\Core\Model\NodeRecord(0, $modell->id, $editor->find($modell->id)?->version ?? 1, '2026-09-10 00:00:00', \Taxmod\Core\Model\RecordType::Settings));
$rows->putValue(new \Taxmod\Core\Model\RelationRecord($einstellungssatz, $feld->id, '', TypedValue::ofText('neun')));
check('ein Wert liegt im Einstellungssatz', ($data->settingValuesOf($modell->id, [$feld->id])[$feld->id] ?? null)?->text === 'neun');
artSetzen($modell->id, $feld->id, 'composition');
check('die Kante ist wieder ein Feld', $editor->relationById($feld->id)?->kind === RelationKind::Composition);
check('der Wert steht weiter im Einstellungssatz', ($data->settingValuesOf($modell->id, [$feld->id])[$feld->id] ?? null)?->text === 'neun');

echo "\n{$passed} ok, {$failed} failed\n";

exit($failed === 0 ? 0 : 1);
