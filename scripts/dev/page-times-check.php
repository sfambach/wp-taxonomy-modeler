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
$say(PageTimesLog::subject(true, ['page' => 'taxmod-lager-kontakte', 'tab' => '149000102677'], []) === 'taxmod-lager-kontakte' && PageTimesLog::subject(true, ['page' => 'taxmod-x', 'tab' => 'satz-13'], []) === 'taxmod-x', 'ein Reiter mit Ziffern ist eine Satz-Id und bleibt draussen');
$say(PageTimesLog::subject(true, [], ['action' => 'taxmod_fragment', 'record' => '9']) === 'action:taxmod_fragment', 'ein Nachladen oder Formular heisst action:…');
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
$say(array_keys($last) === ['t', 'p', 'ms', 'q', 'mb', 'o', 'b', 'r', 'h'], 'ein Eintrag trägt genau t, p, ms, q, mb, o, b, r, h');
$say(array_filter($last, 'is_int') === array_diff_key($last, ['p' => 1]) && $last['p'] === 'taxmod-probe/a', 'alles ausser der Seite ist eine Zahl');
$say(! str_contains((string) wp_json_encode($entries), (string) wp_get_current_user()->user_login), 'kein Benutzername im Ring');

$autoload = (string) $wpdb->get_var($wpdb->prepare("SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", PageTimesLog::OPTION));

$say(in_array($autoload, ['no', 'off', 'auto-off'], true), 'die Option wird nicht bei jeder Anfrage mitgeladen (autoload ' . $autoload . ')');

update_option(PageTimesLog::OPTION, array_fill(0, PageTimes::KEEP, $last), false);
PageTimesLog::write('taxmod-probe/b');

$entries = PageTimesLog::entries();

$say(count($entries) === PageTimes::KEEP && $entries[PageTimes::KEEP - 1]['p'] === 'taxmod-probe/b', 'der Ring bleibt bei ' . PageTimes::KEEP . ' und behält den neuesten');
$say(strlen((string) maybe_serialize($entries)) < 33000, 'ein voller Ring bleibt unter 33 KB — drei Zahlen mehr je Eintrag seit D-1482 (' . strlen((string) maybe_serialize($entries)) . ' Bytes)');

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
    $say(is_array($answer) && ($answer['environment']['db_round_trip_us'] ?? 0) > 0 && isset($answer['calls'][0]['loaded_ms'], $answer['calls'][0]['drawn_ms'], $answer['calls'][0]['http_ms']), 'wohin die Zeit ging: geladen, gezeichnet, fremde Server, und was eine leere Abfrage kostet');

    wp_set_current_user(0);
    $say($ability->check_permissions(['limit' => 1]) !== true && $node->check_permissions(['id' => 1]) !== true, 'ohne Anmeldung so verschlossen wie read-node');
    wp_set_current_user(1);
}

printf("\n%s\n", $failed === 0 ? 'all green' : sprintf('%d FEHLER', $failed));

exit($failed === 0 ? 0 : 1);
