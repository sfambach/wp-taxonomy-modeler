<?php declare(strict_types=1);
/**
 * Das Modell über die Abilities lesen und ändern — dieselben Wege wie die Maske, eine Handlung, alles oder nichts (D-911).
 *
 *     php scripts/dev/abilities-check.php [path/to/wordpress]
 *
 * ```mermaid
 * flowchart LR
 *   R["registriert, öffentlich"] --> G["ohne Fähigkeit abgewiesen"]
 *   G --> A["apply: Knoten, Feld, Satz, Wert"] --> L["read-node liest es zurück"]
 *   A --> N["eine Änderungsnummer"]
 *   F["ein Schritt scheitert"] --> X["nichts bleibt"]
 * ```
 *
 * @see docs/NewConcept/90-decision-log.md
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

// ⚠️ **Kein Wächter schreibt in das Modell des Eigentümers** — siehe `lib/no-write.php`.
require __DIR__ . '/lib/no-write.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\Core\Model\Type\TextType;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;

global $wpdb;
$ok  = 0;
$bad = 0;

function check(string $what, bool $passed, string $detail = ''): void
{
    global $ok, $bad;

    if ($passed) {
        $ok++;
        echo "  OK   $what\n";

        return;
    }

    $bad++;
    echo "  FAIL $what" . ($detail !== '' ? " — $detail" : '') . "\n";
}

if (! function_exists('wp_get_ability')) {
    echo "  SKIP this WordPress has no Abilities API\n";
    exit(0);
}

echo "Registriert\n";

$tree  = wp_get_ability('taxmod/read-tree');
$node  = wp_get_ability('taxmod/read-node');
$apply = wp_get_ability('taxmod/apply');

check('read-tree, read-node und apply sind registriert', $tree !== null && $node !== null && $apply !== null);

if ($tree === null || $node === null || $apply === null) {
    exit(1);
}

check('alle drei sind öffentlich (REST/MCP)', $tree->get_meta_item('show_in_rest') && $node->get_meta_item('show_in_rest') && $apply->get_meta_item('show_in_rest'));
check('nur apply schreibt', ($tree->get_meta_item('annotations')['readonly'] ?? null) === true && ($apply->get_meta_item('annotations')['readonly'] ?? null) === false);

echo "Ohne Fähigkeit\n";

wp_set_current_user(0);
check('ein Gast wird abgewiesen', is_wp_error($tree->execute(['depth' => 1])));

$admin = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
wp_set_current_user((int) ($admin[0] ?? 1));

echo "Lesen\n";

$baum = $tree->execute(['depth' => 1]);
check('read-tree liefert die Wurzel als erste Zeile', is_array($baum) && ($baum['rows'][0]['id'] ?? 0) === $baum['root'], is_wp_error($baum) ? $baum->get_error_message() : '');

$trash = (int) get_option('taxmod_trash_id', 0);
check('der Papierkorb steht nicht im Baum', is_array($baum) && ! in_array($trash, array_column($baum['rows'], 'id'), true));
check('read-tree hält die Tiefe ein', is_array($baum) && max(array_column($baum['rows'], 'depth')) <= 1);

echo "Ändern\n";

$text = (new WpdbNodeRepository())->byImplementations([TextType::class])[TextType::class] ?? null;
check('der Typknoten Text ist da', $text !== null);

$vorher = (int) $wpdb->get_var('SELECT MAX(id) FROM ' . Schema::table('changelog'));

$ergebnis = $apply->execute(['changes' => [
    ['op' => 'create_node', 'ref' => 'i', 'name' => '__ab Initiative', 'parent' => $baum['root']],
    ['op' => 'create_node', 'ref' => 'k', 'name' => '__ab Kind', 'parent' => '@i'],
    ['op' => 'add_field', 'ref' => 'f', 'node' => '@i', 'target' => $text?->id ?? 0, 'name' => '__ab Titel'],
    ['op' => 'create_record', 'ref' => 'r', 'node' => '@i'],
    ['op' => 'set_values', 'record' => '@r', 'values' => ['@f' => 'C:\\Pfad «Hallo»']],
]]);

check('apply nimmt die Liste an', is_array($ergebnis), is_wp_error($ergebnis) ? $ergebnis->get_error_message() : '');

if (! is_array($ergebnis)) {
    exit(1);
}

check('jede Änderung meldet ihre Id', count($ergebnis['applied']) === 5 && ! in_array(null, array_column($ergebnis['applied'], 'id'), true));

$gelesen = $node->execute(['node' => $ergebnis['refs']['i']]);
$felder  = is_array($gelesen) ? array_column($gelesen['fields'], null, 'relation_id') : [];
$feld    = $felder[$ergebnis['refs']['f']] ?? null;

check('read-node findet das Kind', is_array($gelesen) && in_array($ergebnis['refs']['k'], array_column($gelesen['children'], 'id'), true));
check('read-node findet das Feld mit seinem Typ', ($feld['name'] ?? '') === '__ab Titel' && ($feld['type'] ?? '') === 'text', wp_json_encode($feld));

$satz  = is_array($gelesen) ? array_column($gelesen['records'], null, 'id')[$ergebnis['refs']['r']] ?? null : null;
$werte = array_column($satz['values'] ?? [], 'value', 'relation_id');

check('der Wert steht unverändert im Satz, Schrägstrich eingeschlossen', ($werte[$ergebnis['refs']['f']] ?? null) === 'C:\\Pfad «Hallo»', wp_json_encode($werte));

$gruppen = $wpdb->get_col($wpdb->prepare('SELECT DISTINCT change_group_id FROM ' . Schema::table('changelog') . ' WHERE id > %d', $vorher));
check('die ganze Liste hat eine Änderungsnummer', count($gruppen) === 1 && $gruppen[0] !== null, wp_json_encode($gruppen));

echo "Alles oder nichts\n";

// ⚠️ *Die Namen stehen in den Beschriftungen, nicht in `nodes` — also über read-tree gezählt, wie ein Aufrufer sie sieht.*
$knoten = static function (string $name) use ($tree): int {
    $zeilen = $tree->execute(['depth' => 1]);

    return is_array($zeilen) ? count(array_keys(array_column($zeilen['rows'], 'name'), $name, true)) : -1;
};

$fehler = $apply->execute(['changes' => [
    ['op' => 'create_node', 'ref' => 'x', 'name' => '__ab Bleibt nicht', 'parent' => $baum['root']],
    ['op' => 'create_node', 'name' => '__ab Ins Leere', 'parent' => 999999999],
]]);

check('ein scheiternder Schritt wird gemeldet', is_wp_error($fehler) && str_contains($fehler->get_error_message(), 'Change 1'), is_wp_error($fehler) ? $fehler->get_error_message() : 'kein Fehler');
check('der Schritt davor ist zurückgerollt', $knoten('__ab Bleibt nicht') === 0);
check('ein unbekannter Verweis wird abgewiesen', is_wp_error($apply->execute(['changes' => [['op' => 'rename_node', 'node' => '@nichts', 'name' => 'x']]])));
check('die erste Liste steht noch', $knoten('__ab Initiative') === 1);

echo "\n$ok ok, $bad failed\n";
exit($bad === 0 ? 0 : 1);
