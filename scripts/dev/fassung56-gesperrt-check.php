<?php declare(strict_types=1);

/**
 * Fassung 56 läuft einmal und nie wieder — eine gewollte Komposition unter `Model` bleibt Komposition.
 *
 *     php scripts/dev/fassung56-gesperrt-check.php [path/to/wordpress]
 *
 * ⚠️ **[D-1097](../../docs/NewConcept/90-decision-log.md), sein Wort:** *«wird der kopf gelöscht stirbt auch dei postion wenn das
 * nicht der fall ist darf des auch keine compositon sein».* *Die «Positionen» unter `Model` werden Kompositionen mit Satzverweisen
 * — genau die Form, die Fassung 56 ([D-938](../../docs/NewConcept/90-decision-log.md)) in Aggregationen zurückdrehte. Ohne Sperre
 * täte sie das beim nächsten Fassungssprung still wieder.*
 *
 * ```mermaid
 * flowchart LR
 *   W["Wiese __f56: Komposition auf Model, ein Satzverweis"] --> M{"Marke gesetzt?"}
 *   M -->|ja| B["bleibt Komposition"]
 *   M -->|"nein, Fassung ≥ 56"| B2["bleibt, und die Marke entsteht"]
 *   M -->|"nein, Fassung 55"| G["Gegenprobe: wird Aggregation"]
 * ```
 *
 * *Der Schritt wird über `ReflectionMethod` gerufen und nicht über `Schema::install()`: dessen `dbDelta` darf `ALTER TABLE` schicken,
 * und das bestätigte die Klammer still.*
 */

$wordpress = $argv[1] ?? (getenv('WP_ROOT') ?: 'C:/Devel/Wordpress');

define('WP_USE_THEMES', false);

require rtrim($wordpress, '/') . '/wp-load.php';

// ⚠️ **Kein Wächter schreibt in das Modell des Eigentümers** — die Klammer dreht am Ende alles zurück.
require __DIR__ . '/lib/no-write.php';
require __DIR__ . '/../../vendor/autoload.php';

use Taxmod\Core\Model\Type\TextType;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;

global $wpdb;

wp_set_current_user(1);

if (! function_exists('wp_get_ability')) {
    echo "  SKIP this WordPress has no Abilities API\n";
    exit(0);
}

$apply = wp_get_ability('taxmod/apply');
$text  = (new WpdbNodeRepository())->byImplementations([TextType::class])[TextType::class] ?? null;
$model = (int) get_option('taxmod_branch_model_id', 0);

if ($apply === null || $text === null || $model === 0) {
    echo "  FAIL Gerüst fehlt (apply, Text, Model)\n";
    exit(1);
}

$fehler = 0;
$pruefe = static function (string $was, bool $gut, string $mehr = '') use (&$fehler): void {
    printf("  %s %s%s\n", $gut ? 'ok  ' : 'FAIL', $was, $gut || $mehr === '' ? '' : ' — ' . $mehr);
    $fehler += $gut ? 0 : 1;
};

$geruest = $apply->execute(['changes' => [
    ['op' => 'create_node', 'ref' => 'k', 'name' => '__f56 Kopf', 'parent' => $model],
    ['op' => 'create_node', 'ref' => 'p', 'name' => '__f56 Position', 'parent' => $model],
    ['op' => 'add_field', 'ref' => 'pt', 'node' => '@p', 'target' => $text->id, 'name' => '__f56 Text'],
    ['op' => 'add_field', 'ref' => 'pos', 'node' => '@k', 'target' => '@p', 'name' => '__f56 Positionen', 'kind' => 'composition', 'multiplicity' => '0..*'],
    ['op' => 'create_record', 'ref' => 'r', 'node' => '@k'],
    ['op' => 'set_values', 'record' => '@r', 'values' => ['@pos' => ['neu' => ['@pt' => 'eins']]]],
]]);

if (! is_array($geruest)) {
    printf("  FAIL die Wiese entsteht nicht — %s\n", is_wp_error($geruest) ? $geruest->get_error_message() : '?');
    exit(1);
}

$kante = (int) $geruest['refs']['pos'];
$art   = static fn (): string => (string) $wpdb->get_var($wpdb->prepare('SELECT kind FROM ' . Schema::table('relations') . ' WHERE id = %d', $kante));
$werte = (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM ' . Schema::table('relation_records') . " WHERE relation_id = %d AND value_ref_kind = 'record'",
    $kante
));

$pruefe('die Wiese: eine Komposition auf einen Knoten unter Model mit Satzverweis', $art() === 'composition' && $werte === 1, "{$art()}, {$werte} Verweise");

$schritt = new ReflectionMethod(Schema::class, 'keepReferencingCompositionsAsReferences');
$fassung = (int) get_option(Schema::VERSION_OPTION, 0);

$pruefe('die Installation hat Fassung 56 hinter sich', $fassung >= 56, (string) $fassung);
$pruefe('die Fassung des Kodes ist mindestens 56', Schema::VERSION >= 56, (string) Schema::VERSION);

// (1) wie nach jedem künftigen Aufstieg: die Marke steht.
$schritt->invoke(null);
$pruefe('mit Marke bleibt die Komposition', $art() === 'composition', $art());
$pruefe('die Marke steht nach dem Lauf', get_option(Schema::FASSUNG56_DONE, null) !== null);

// (2) eine Installation, die vor der Sperre auf 56 kam: keine Marke, aber Fassung 56.
delete_option(Schema::FASSUNG56_DONE);
$schritt->invoke(null);
$pruefe('ohne Marke auf Fassung 56 bleibt sie auch', $art() === 'composition', $art());
$pruefe('und die Marke entsteht dabei', get_option(Schema::FASSUNG56_DONE, null) !== null);

// (3) Gegenprobe: ohne die Sperre wäre sie umgestellt — der Schritt selbst lebt, und die Sperre ist es, die ihn hält.
delete_option(Schema::FASSUNG56_DONE);
update_option(Schema::VERSION_OPTION, 55, true);
$schritt->invoke(null);
$pruefe('Gegenprobe: auf Fassung 55 ohne Marke stellt der Schritt sie um', $art() === 'aggregation', $art());
update_option(Schema::VERSION_OPTION, $fassung, true);

exit($fehler === 0 ? 0 : 1);
