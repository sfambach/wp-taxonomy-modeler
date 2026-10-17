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

echo "Werte mit Verweis\n";

$verweis = $apply->execute(['changes' => [
    ['op' => 'create_record', 'ref' => 'z', 'node' => $ergebnis['refs']['i']],
    ['op' => 'set_values', 'record' => '@z', 'values' => [(string) $ergebnis['refs']['f'] => '@z']],
]]);
$zweiter = is_array($verweis) ? $node->execute(['node' => $ergebnis['refs']['i']]) : null;
$zWerte  = is_array($zweiter) ? array_column(array_column($zweiter['records'], null, 'id')[$verweis['refs']['z']]['values'] ?? [], 'value', 'relation_id') : [];

check('ein Wert «@ref» wird die Id des Angelegten', ($zWerte[$ergebnis['refs']['f']] ?? null) === (string) ($verweis['refs']['z'] ?? -1), wp_json_encode($zWerte));

echo "Listeneinstellung (D-923)\n";

$liste0 = $apply->execute(['changes' => [
    ['op' => 'set_list', 'node' => $ergebnis['refs']['i'], 'attribute' => 'summary_fields', 'members' => [$ergebnis['refs']['f']]],
]]);
$gewaehlt = (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM ' . Schema::table('settings_value') . " WHERE node_id = %d AND attribut = 'summary_fields' AND aktiv = 1 AND wert_kante_id = %d",
    $ergebnis['refs']['i'],
    $ergebnis['refs']['f']
));

check('set_list setzt die Feldwahl des Knotens', is_array($liste0) && $gewaehlt === 1, is_wp_error($liste0) ? $liste0->get_error_message() : (string) $gewaehlt);
check('eine unbekannte Einstellung wird abgewiesen', is_wp_error($apply->execute(['changes' => [['op' => 'set_list', 'node' => $ergebnis['refs']['i'], 'attribute' => 'gibt_es_nicht', 'members' => []]]])));

echo "Feld mit Multiplizität (D-914)\n";

$liste = $apply->execute(['changes' => [
    ['op' => 'add_field', 'ref' => 'l', 'node' => $ergebnis['refs']['i'], 'target' => $text?->id ?? 0, 'name' => '__ab Liste', 'multiplicity' => '0..*'],
]]);
$dritter = is_array($liste) ? $node->execute(['node' => $ergebnis['refs']['i']]) : null;
$lFeld   = is_array($dritter) ? array_column($dritter['fields'], null, 'relation_id')[$liste['refs']['l']] ?? null : null;

check('add_field setzt die Multiplizität gleich mit', ($lFeld['multiplicity'] ?? '') === '0..*', is_wp_error($liste) ? $liste->get_error_message() : wp_json_encode($lFeld));

echo "Kombifeld, Umbenennen, Entfernen (D-927)\n";

// *Ein Teil lebt nur unter den Kompositionen — dort, wo auch der Einheitenwert steht.*
$kompositionen = (int) $wpdb->get_var($wpdb->prepare(
    'SELECT parent_node_id FROM ' . Schema::table('nodes') . ' WHERE id = %d',
    \Taxmod\WordPress\Persistence\UnitScaffold::unitValueId() ?? 0
));

$kombi = $apply->execute(['changes' => [
    ['op' => 'create_node', 'ref' => 't', 'name' => '__ab Teil', 'parent' => $kompositionen],
    ['op' => 'add_field', 'ref' => 'innen', 'node' => '@t', 'target' => $text?->id ?? 0, 'name' => '__ab Innen'],
    ['op' => 'add_field', 'ref' => 'kombi', 'node' => $ergebnis['refs']['i'], 'target' => '@t', 'name' => '__ab Kombi'],
    ['op' => 'set_values', 'record' => $ergebnis['refs']['r'], 'values' => ['@kombi' => ['neu' => ['@innen' => 'im Teil']]]],
    ['op' => 'rename_field', 'node' => $ergebnis['refs']['i'], 'field' => '@kombi', 'name' => '__ab Kombi neu'],
]]);
$teil = is_array($kombi) ? (int) $wpdb->get_var($wpdb->prepare(
    'SELECT v.value_ref FROM ' . Schema::table('relation_records') . ' v WHERE v.node_record_id = %d AND v.relation_id = %d',
    $ergebnis['refs']['r'],
    $kombi['refs']['kombi']
)) : 0;
$innen = $teil === 0 ? null : $wpdb->get_var($wpdb->prepare(
    'SELECT value_text FROM ' . Schema::table('relation_records') . ' WHERE node_record_id = %d AND relation_id = %d',
    $teil,
    $kombi['refs']['innen']
));

check('ein inneres Feld «@ref» füllt den neuen Teil', $innen === 'im Teil', is_wp_error($kombi) ? $kombi->get_error_message() : (string) $innen);

$vierter = is_array($kombi) ? $node->execute(['node' => $ergebnis['refs']['i']]) : null;
$kFeld   = is_array($vierter) ? array_column($vierter['fields'], null, 'relation_id')[$kombi['refs']['kombi']] ?? null : null;
check('rename_field gibt dem Feld den neuen Namen', ($kFeld['name'] ?? '') === '__ab Kombi neu', wp_json_encode($kFeld));

$weg      = is_array($kombi) ? $apply->execute(['changes' => [['op' => 'remove_field', 'node' => $ergebnis['refs']['i'], 'field' => $kombi['refs']['kombi']]]]) : null;
$fuenfter = is_array($weg) ? $node->execute(['node' => $ergebnis['refs']['i']]) : null;
check('remove_field nimmt das Feld vom Knoten', is_array($fuenfter) && ! in_array($kombi['refs']['kombi'] ?? -1, array_column($fuenfter['fields'], 'relation_id'), true), is_wp_error($weg) ? $weg->get_error_message() : '');

echo "Knoten verschieben, Preis als Einheitenwert (D-928, D-929, D-930, D-931)\n";

// *Eine Währung ist ein Zweig der Einheiten (D-039): unter «Base units» wählt der Einheitenwert sie wie Gramm oder Hertz.*
$basis     = \Taxmod\WordPress\Persistence\UnitScaffold::nodeId(\Taxmod\WordPress\Persistence\UnitScaffold::BASE_UNITS_NAME) ?? 0;
$konstante = (int) $wpdb->get_var($wpdb->prepare('SELECT parent_node_id FROM ' . Schema::table('nodes') . ' WHERE id = %d', $basis));
$einheiten = $node->execute(['node' => \Taxmod\WordPress\Persistence\UnitScaffold::unitValueId() ?? 0]);
$teile     = is_array($einheiten) ? array_column($einheiten['fields'], null, 'name') : [];

$geld = $apply->execute(['changes' => [
    ['op' => 'create_node', 'ref' => 'geld', 'name' => '__ab Geld', 'parent' => $konstante, 'class' => 'Choice'],
    ['op' => 'create_node', 'ref' => 'taler', 'name' => '__ab Taler', 'parent' => '@geld', 'class' => 'Unit'],
    ['op' => 'move_node', 'node' => '@geld', 'parent' => $basis],
    ['op' => 'add_field', 'ref' => 'preis', 'node' => $ergebnis['refs']['i'], 'target' => \Taxmod\WordPress\Persistence\UnitScaffold::unitValueId() ?? 0, 'name' => '__ab Preis', 'multiplicity' => '0..1'],
    ['op' => 'set_values', 'record' => $ergebnis['refs']['r'], 'values' => ['@preis' => ['p' => [
        (string) ($teile['wert']['relation_id'] ?? 0)    => '29.28',
        (string) ($teile['einheit']['relation_id'] ?? 0) => '@taler',
    ]]]],
]]);
$gezogen = is_array($geld) ? $node->execute(['node' => $geld['refs']['geld']]) : null;
check('move_node hängt den Knoten unter den neuen Vater', ($gezogen['node']['parent'] ?? null) === $basis, is_wp_error($geld) ? $geld->get_error_message() : wp_json_encode($gezogen['node'] ?? null));

$zurueck = is_array($geld) ? $apply->execute(['changes' => [['op' => 'move_node', 'node' => $geld['refs']['geld'], 'parent' => $geld['refs']['taler']]]]) : null;
check('move_node unter das eigene Kind wird abgewiesen', is_wp_error($zurueck));

wp_set_current_user(0);
$preis = is_array($geld) ? wp_strip_all_tags(do_blocks('<!-- wp:taxmod/record {"record":' . $ergebnis['refs']['r'] . ',"field":' . $geld['refs']['preis'] . '} /-->')) : '';
wp_set_current_user((int) ($admin[0] ?? 1));
check('ein Preis als Einheitenwert zeigt Betrag und Währung', str_contains($preis, '29.28') && str_contains($preis, '__ab Taler'), trim($preis));

$nur = is_array($geld) ? $apply->execute(['changes' => [[
    'op'        => 'set_list',
    'node'      => \Taxmod\WordPress\Persistence\UnitScaffold::unitValueId() ?? 0,
    'field'     => $geld['refs']['preis'],
    'attribute' => 'erlaubte_einheiten',
    'members'   => [$geld['refs']['taler']],
]]]) : null;
$erlaubt = is_array($nur) ? $wpdb->get_col($wpdb->prepare(
    'SELECT wert_knoten_id FROM ' . Schema::table('settings_value') . ' WHERE relation_id = %d AND attribut = %s AND aktiv = 1',
    $geld['refs']['preis'],
    'erlaubte_einheiten'
)) : [];
check('set_list mit Feld setzt die erlaubten Einheiten nur an dieser Verwendungsstelle (D-930)', array_map('intval', $erlaubt) === [$geld['refs']['taler']], is_wp_error($nur) ? $nur->get_error_message() : wp_json_encode($erlaubt));

$leer = is_array($geld) ? $apply->execute(['changes' => [
    ['op' => 'create_record', 'ref' => 'ohne', 'node' => $ergebnis['refs']['i']],
    ['op' => 'set_values', 'record' => '@ohne', 'values' => [(string) $geld['refs']['preis'] => ['n' => [
        (string) ($teile['wert']['relation_id'] ?? 0)    => '',
        (string) ($teile['einheit']['relation_id'] ?? 0) => (string) $geld['refs']['taler'],
    ]]]],
]]) : null;
$ohneTeil = is_array($leer) ? (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM ' . Schema::table('relation_records') . ' WHERE node_record_id = %d AND relation_id = %d',
    $leer['refs']['ohne'],
    $geld['refs']['preis']
)) : -1;
check('ein Preis ohne Betrag legt keinen Teil an, auch wenn die Währung mitkommt (D-931)', $ohneTeil === 0, is_wp_error($leer) ? $leer->get_error_message() : (string) $ohneTeil);

$zwei = is_array($geld) ? $apply->execute(['changes' => [
    ['op' => 'create_node', 'ref' => 'kreuzer', 'name' => '__ab Kreuzer', 'parent' => $geld['refs']['geld'], 'class' => 'Unit'],
    ['op' => 'set_list', 'node' => \Taxmod\WordPress\Persistence\UnitScaffold::unitValueId() ?? 0, 'field' => $geld['refs']['preis'], 'attribute' => 'erlaubte_einheiten', 'members' => [$geld['refs']['taler'], '@kreuzer']],
]]) : null;
$_GET['page']               = 'taxmod';
$_GET['taxmod_open_record'] = (string) $ergebnis['refs']['r'];
$plugin                     = (new ReflectionClass(\Taxmod\WordPress\Plugin::class))->newInstanceWithoutConstructor();
(new ReflectionProperty($plugin, 'file'))->setValue($plugin, dirname(__DIR__, 2) . '/wp-taxonomy-modeler.php');
$maske = is_array($zwei) ? $plugin->screen()->render() : '';
unset($_GET['page'], $_GET['taxmod_open_record']);
preg_match('/<select name="taxmod_value\[' . $ergebnis['refs']['r'] . '\]\[' . ($geld['refs']['preis'] ?? 0) . '\][^"]*\[' . ($teile['einheit']['relation_id'] ?? 0) . '\]"[^>]*>(.*?)<\/select>/s', $maske, $waehler);
check('die Pflicht-Einheit eines Preises bietet kein leeres «—» an (D-931)', isset($waehler[1]) && str_contains($waehler[1], '__ab Kreuzer') && ! str_contains($waehler[1], '<option value=""'), is_wp_error($zwei) ? $zwei->get_error_message() : substr($waehler[1] ?? 'kein Wähler', 0, 200));

// *Ein Feld mit mehreren Einheitenwerten nennt im Vergleich jeden mit Einheit — gemessen am 2026-10-17: der Takt eines Mainboards
// stand als «#33615, #33616, #33617» (D-925).*
$takte = is_array($zwei) ? $apply->execute(['changes' => [
    ['op' => 'add_field', 'ref' => 'takte', 'node' => $ergebnis['refs']['i'], 'target' => \Taxmod\WordPress\Persistence\UnitScaffold::unitValueId() ?? 0, 'name' => '__ab Takte', 'multiplicity' => '0..*'],
    ['op' => 'set_values', 'record' => $ergebnis['refs']['r'], 'values' => ['@takte' => [
        'a' => [(string) ($teile['wert']['relation_id'] ?? 0) => '50', (string) ($teile['einheit']['relation_id'] ?? 0) => (string) $geld['refs']['taler']],
        'b' => [(string) ($teile['wert']['relation_id'] ?? 0) => '60', (string) ($teile['einheit']['relation_id'] ?? 0) => (string) $geld['refs']['taler']],
    ]]],
]]) : null;
wp_set_current_user(0);
$vergleich = is_array($takte) ? wp_strip_all_tags(do_blocks('<!-- wp:taxmod/record ' . wp_json_encode([
    'display'   => 'comparison',
    'record'    => $ergebnis['refs']['r'],
    'records'   => (string) $ergebnis['refs']['r'],
    'rowFields' => (string) $takte['refs']['takte'],
]) . ' /-->')) : '';
wp_set_current_user((int) ($admin[0] ?? 1));
check('mehrere Einheitenwerte im Vergleich heissen «50 Taler, 60 Taler», nicht ihre Nummern', str_contains($vergleich, '50 __ab Taler, 60 __ab Taler'), is_wp_error($takte) ? $takte->get_error_message() : trim($vergleich));

echo "Block im Beitrag (D-912)\n";

check('der Block taxmod/record ist registriert', WP_Block_Type_Registry::get_instance()->is_registered('taxmod/record'));

wp_set_current_user(0);
$block = do_blocks('<!-- wp:taxmod/record {"record":' . $ergebnis['refs']['r'] . ',"field":' . $ergebnis['refs']['f'] . '} /-->');
check('der Block zeigt den Wert des Satzes, maskiert', str_contains($block, esc_html('C:\\Pfad «Hallo»')) || str_contains($block, 'C:\\Pfad &laquo;Hallo&raquo;'), substr(wp_strip_all_tags($block), 0, 200));
check('der Block trägt keine Hilfetexte der Maske', ! str_contains($block, 'taxmod-hint'));
check('der Block schickt nichts ab', ! str_contains($block, '<form') && ! str_contains($block, '<input'));
check('ein fehlender Satz zeigt Lesern nichts', trim(do_blocks('<!-- wp:taxmod/record {"record":999999999} /-->')) === '');
wp_set_current_user((int) ($admin[0] ?? 1));

echo "\n$ok ok, $bad failed\n";
exit($bad === 0 ? 0 : 1);
