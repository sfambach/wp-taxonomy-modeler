<?php declare(strict_types=1);

/**
 * Das Seitenzeit-Protokoll ([D-1476](../../docs/NewConcept/90-decision-log.md)): was es festhält, was nicht, und dass ein Wächterlauf es nicht füllt.
 *
 *     php scripts/dev/page-times-check.php
 *
 * Sein Wort 2026-11-19: «dass du das mal mitloggst, vielleicht für jede Seite. Dann kann ich dir das schicken, dann musst du
 * nicht messen.»
 */

define('WP_ADMIN', true);
define('WP_USE_THEMES', false);

require (getenv('WP_ROOT') ?: 'C:/Devel/Wordpress') . '/wp-load.php';
require __DIR__ . '/lib/no-write.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

wp_set_current_user(1);

use Taxmod\Core\Service\PageTimes;
use Taxmod\WordPress\Admin\PageTimesLog;

$failed = 0;

$say = static function (bool $ok, string $what) use (&$failed): void {
    printf("  %-4s %s\n", $ok ? 'ok' : 'FAIL', $what);

    if (! $ok) {
        ++$failed;
    }
};

echo "Was festgehalten wird\n";

$get = ['page' => 'taxmod-logistik', 'tab' => 'arrived', 's' => 'geheimes Suchwort', 'taxmod_open_record' => '4711', 'order' => '815', 'user' => '1'];

$say(PageTimesLog::subject(true, $get, $get) === 'taxmod-logistik/arrived', 'eine Seite heisst page/tab — Suchwort, Satz-Id und Bestellung bleiben draussen');
$say(PageTimesLog::subject(true, ['page' => 'taxmod'], []) === 'taxmod', 'ohne Reiter nur die Seite');
$carried = ['page' => 'taxmod', 'tab' => 'x', 'taxmod_node' => '149000102677', 's' => 'geheimes Suchwort', 'group' => '77', 'order' => '815', '_wpnonce' => 'abc', '4711' => '1', 'Feld-9' => '1', 'pick' => ['76688', '76697']];
$names   = PageTimesLog::switches($carried);
$say($names === 'group,order,pick,s,taxmod_node', 'die Schalter eines Aufrufs sind die Namen seiner Parameter, sortiert — ohne page, tab und Nonce, ohne Namen mit Ziffern (' . $names . ')');
$say(! str_contains($names, 'geheim') && ! str_contains($names, '149000102677') && ! str_contains($names, '815') && ! str_contains($names, '76688') && ! str_contains($names, '77'), 'kein Wert, keine Id, kein Suchwort steht in den Schaltern');
$say(count(explode(',', PageTimesLog::switches(array_fill_keys(range('a', 'z'), '1')))) === 8, 'höchstens acht Namen');
$say(PageTimesLog::switches(['page' => 'taxmod']) === '', 'ohne Schalter nichts');
$say(PageTimesLog::subject(true, ['page' => 'taxmod-lager-kontakte', 'tab' => '149000102677'], []) === 'taxmod-lager-kontakte' && PageTimesLog::subject(true, ['page' => 'taxmod-x', 'tab' => 'satz-13'], []) === 'taxmod-x', 'ein Reiter mit Ziffern ist eine Satz-Id und bleibt draussen');
$say(PageTimesLog::subject(true, [], ['action' => 'taxmod_fragment', 'record' => '9']) === 'action:taxmod_fragment', 'ein Nachladen oder Formular heisst action:…');
$say(PageTimesLog::subject(true, ['page' => 'taxmod-lager-cockpit', 'area' => 'lager', 'group' => '77'], []) === 'taxmod-lager-cockpit:lager', 'der Bereich des Cockpits unterscheidet als Wort');
$say(PageTimesLog::subject(true, ['page' => 'taxmod-lager-cockpit', 'area' => '4711'], []) === 'taxmod-lager-cockpit', 'ein Bereich mit Ziffern bleibt draussen');
$say(PageTimesLog::subject(true, [], ['action' => 'taxmod_node', 'do' => 'save_page', 'id' => '5']) === 'action:taxmod_node:save_page' && PageTimesLog::subject(true, [], ['action' => 'taxmod_node', 'do' => ['add_field' => '1']]) === 'action:taxmod_node:add_field', 'die Knoten-Aktion nennt ihr Wort, auch als Schlüssel des Knopfs');
$say(PageTimesLog::subject(true, [], ['action' => 'taxmod_node', 'do' => ['42' => '1']]) === 'action:taxmod_node' && PageTimesLog::subject(true, [], ['action' => 'taxmod_node', 'do' => 'x 9']) === 'action:taxmod_node', 'was kein Wort ist, bleibt draussen');
$say(PageTimesLog::subject(true, ['page' => 'plugins'], ['action' => 'heartbeat']) === null, 'eine fremde Seite wird nicht mitgeschrieben');
$say(PageTimesLog::subject(false, $get, $get) === null, 'ausserhalb der Verwaltung wird nichts mitgeschrieben');
$say(PageTimesLog::subject(true, ['page' => ['taxmod']], []) === null, 'ein Feld statt eines Wortes zählt nicht');

echo "\nEin Wächterlauf schreibt nicht mit\n";

$_GET     = ['page' => 'taxmod'];
$_REQUEST = $_GET;
$before   = PageTimesLog::entries();

PageTimesLog::record();
$say(PageTimesLog::entries() === $before, 'record() lässt den Ring in einem Wächterlauf, wie er war');
$say(has_action('shutdown', [PageTimesLog::class, 'record']) !== false, 'das Mitschreiben hängt am Ende der Anfrage');

echo "\nDer Ring\n";

delete_option(PageTimesLog::OPTION);
PageTimesLog::write('taxmod-probe/a');
PageTimesLog::write('taxmod-probe/a');

$entries = PageTimesLog::entries();
$last    = $entries[count($entries) - 1] ?? [];

$say(count($entries) === 2, 'ein Aufruf ist ein Eintrag');
$say(array_keys($last) === ['t', 'p', 'ms', 'q', 'mb', 'o', 'b', 'r', 'h', 'd', 's'], 'ein Eintrag trägt genau t, p, ms, q, mb, o, b, r, h, d, s');
$say(is_int($last['d']) && is_int($last['s']) && $last['d'] >= 0 && $last['d'] <= $last['ms'] && $last['s'] <= $last['ms'], 'd und s sind Millisekunden in der Datenbank und nie mehr als der ganze Aufruf (Nachtrag D-1482)');
$say(array_filter($last, 'is_int') === array_diff_key($last, ['p' => 1]) && $last['p'] === 'taxmod-probe/a', 'alles ausser der Seite ist eine Zahl');
$say(! str_contains((string) wp_json_encode($entries), (string) wp_get_current_user()->user_login), 'kein Benutzername im Ring');

$autoload = (string) $wpdb->get_var($wpdb->prepare("SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", PageTimesLog::OPTION));

$say(in_array($autoload, ['no', 'off', 'auto-off'], true), 'die Option wird nicht bei jeder Anfrage mitgeladen (autoload ' . $autoload . ')');

update_option(PageTimesLog::OPTION, array_fill(0, PageTimes::KEEP, $last), false);
PageTimesLog::write('taxmod-probe/b');

$entries = PageTimesLog::entries();

$say(count($entries) === PageTimes::KEEP && $entries[PageTimes::KEEP - 1]['p'] === 'taxmod-probe/b', 'der Ring bleibt bei ' . PageTimes::KEEP . ' und behält den neuesten');
$say(strlen((string) maybe_serialize($entries)) < 38000, 'ein voller Ring bleibt unter 38 KB — drei Zahlen mehr je Eintrag seit D-1482, zwei weitere (d, s) mit dem Nachtrag (' . strlen((string) maybe_serialize($entries)) . ' Bytes)');

$kept = PageTimesLog::entries();
PageTimesLog::write('taxmod-probe/c', 'group,order,pick,s,taxmod_node');
$entries = PageTimesLog::entries();
$with    = $entries[count($entries) - 1] ?? [];
$say(($with['a'] ?? null) === 'group,order,pick,s,taxmod_node' && array_keys($with) === ['t', 'p', 'ms', 'q', 'mb', 'o', 'b', 'r', 'h', 'd', 's', 'a'], 'ein Aufruf mit Schaltern trägt ihre Namen unter a (Nachtrag zu D-1482), einer ohne trägt kein a');
update_option(PageTimesLog::OPTION, array_fill(0, PageTimes::KEEP, ['a' => 'taxmod_collapsed,taxmod_hidden,taxmod_message,taxmod_node,taxmod_open_record,taxmod_root,taxmod_search,term'] + $with), false);
$say(strlen((string) maybe_serialize(PageTimesLog::entries())) < 65000, 'ein voller Ring, jeder Eintrag mit acht langen Namen, bleibt unter 65 KB (' . strlen((string) maybe_serialize(PageTimesLog::entries())) . ' Bytes)');
update_option(PageTimesLog::OPTION, $kept, false);

echo "\nDer Schalter\n";

update_option(PageTimesLog::SWITCH, '0');
$say(get_option(PageTimesLog::SWITCH, '1') === '0', 'abgestellt heisst 0 und nicht gelöscht');
delete_option(PageTimesLog::SWITCH);
$say(get_option(PageTimesLog::SWITCH, '1') !== '0', 'nie gesetzt heisst an');

echo "\nDas Lesen\n";

$ability = wp_get_ability('taxmod/page-times');
$node    = wp_get_ability('taxmod/read-node');

$say($ability !== null, 'taxmod/page-times ist angemeldet');

if ($ability !== null && $node !== null) {
    $meta = $ability->get_meta();

    $say(($meta['annotations']['readonly'] ?? false) === true && ($meta['annotations']['destructive'] ?? true) === false, 'sie liest nur');

    $answer = $ability->execute(['limit' => 1, 'page' => 'taxmod-probe/b']);

    $say(is_array($answer) && array_keys($answer) === ['environment', 'pages', 'calls'], 'sie antwortet mit Umgebung, Seiten und Aufrufen');
    $say(is_array($answer) && count($answer['calls']) === 1 && $answer['calls'][0]['page'] === 'taxmod-probe/b', 'limit und page greifen');
    $say(is_array($answer) && isset($answer['pages']['taxmod-probe/a']['median_ms'], $answer['environment']['opcache']), 'je Seite die Mitte, dazu OPcache an oder aus');
    $say(is_array($answer) && ($answer['environment']['db_round_trip_us'] ?? 0) > 0 && isset($answer['calls'][0]['loaded_ms'], $answer['calls'][0]['drawn_ms'], $answer['calls'][0]['http_ms'], $answer['calls'][0]['db_ms'], $answer['calls'][0]['stream_ms']), 'wohin die Zeit ging: geladen, gezeichnet, fremde Server, und was eine leere Abfrage kostet');

    wp_set_current_user(0);
    $say($ability->check_permissions(['limit' => 1]) !== true && $node->check_permissions(['id' => 1]) !== true, 'ohne Anmeldung so verschlossen wie read-node');
    wp_set_current_user(1);
}

echo "\nDie Vorschau gemessen (D-1482, Nachtrag 2)\n";

$preview = wp_get_ability('taxmod/preview-times');

$say($preview !== null, 'taxmod/preview-times ist angemeldet');

if ($preview !== null) {
    global $wpdb;

    $meta = $preview->get_meta();
    $say(($meta['annotations']['readonly'] ?? false) === true && ($meta['annotations']['destructive'] ?? true) === false, 'sie liest nur');

    // Any record with a text value: the check needs a record and its node, not a particular one.
    $row = $wpdb->get_row("SELECT r.id AS record, r.node_id AS node, v.value_text AS word FROM {$wpdb->prefix}taxmod_node_records r JOIN {$wpdb->prefix}taxmod_relation_records v ON v.node_record_id = r.id WHERE r.record_type = 'user' AND CHAR_LENGTH(v.value_text) > 5 ORDER BY r.id DESC LIMIT 1", ARRAY_A);

    if (! is_array($row)) {
        $say(true, 'kein Satz mit Textwert in dieser Datenbank — die Messung selbst bleibt ungeprüft');
    } else {
        $answer = $preview->execute(['node' => (int) $row['node'], 'record' => (int) $row['record']]);

        $say(is_array($answer) && array_keys($answer) === ['node', 'record', 'total_ms', 'total_queries', 'mb', 'steps', 'fields'], 'sie antwortet mit Summe, Schritten und Feldern');
        $say(is_array($answer) && $answer['fields'] !== [] && array_slice(array_keys($answer['fields'][0]), 0, 5) === ['relation_id', 'name', 'ms', 'queries', 'kb'], 'je Feld Nummer, Name, ms, Abfragen, KB');
        $counted = is_array($answer) ? array_filter($answer['fields'], static fn (array $one): bool => isset($one['markup'])) : [];
        $say(array_filter($counted, static fn (array $one): bool => $one['kb'] < 100 || array_keys($one['markup']) !== ['tags', 'classes'] || array_filter(array_merge($one['markup']['tags'], $one['markup']['classes']), static fn (mixed $n): bool => ! is_int($n)) !== []) === [], 'ein großes Feld (ab 100 KB) zählt sein Markup aus: Elemente und Klassen, nur Zahlen (Nachtrag 3)');
        $say(is_array($answer) && in_array('admin side, whole', array_column($answer['steps'], 'step'), true), 'und beide Seiten als Ganzes');
        $say(is_array($answer) && ! str_contains((string) wp_json_encode($answer), (string) $row['word']), 'kein Wert des Satzes steht in der Antwort');

        $other = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}taxmod_node_records WHERE node_id <> %d LIMIT 1", (int) $row['node']));
        $say($other === 0 || is_wp_error($preview->execute(['node' => (int) $row['node'], 'record' => $other])), 'ein Satz eines anderen Knotens wird abgewiesen');
    }

    wp_set_current_user(0);
    $say($preview->check_permissions(['node' => 1, 'record' => 1]) !== true, 'ohne Anmeldung verschlossen');
    wp_set_current_user(1);
}

printf("\n%s\n", $failed === 0 ? 'all green' : sprintf('%d FEHLER', $failed));

exit($failed === 0 ? 0 : 1);
