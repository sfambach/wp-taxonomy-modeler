<?php declare(strict_types=1);

/**
 * Das Speichern im Hintergrund bekommt einen Satz zurück und keine neue Seite ([D-1089](../../docs/NewConcept/90-decision-log.md)).
 *
 * ⚠️ **Sein Befund:** *«bei jeder eingabe speichert er erst lange wenn man dann neue information eingibt werden diese gelöscht weil die
 * seite neu geladen wird».* Das Skript schickt das Formular jetzt per `fetch` mit `taxmod_quiet`; dieser Lauf prüft den Rand dazu:
 *
 * 1. «Seite speichern» mit `taxmod_quiet` schreibt und antwortet mit JSON (`ok`, Meldung, kein `goto`) — keine Umleitung.
 * 2. Ein Fehler kommt als `ok: false` mit dem Satz des Kerns zurück.
 * 3. Ein Akt, der den Aufbau ändert («New record»), nennt mit `taxmod_quiet` die Adresse, zu der die Seite gehen muss.
 * 4. Ohne `taxmod_quiet` leitet der Rand um wie bisher.
 *
 * ⚠️ *Ein Prozess, mit Klammer: `DOING_AJAX` lässt `wp_send_json()` in `wp_die()` enden statt in `die`, und der Ajax-Handler wirft —
 * so kommt der Lauf nach jedem Akt zurück. Die Umleitung fängt der Filter `wp_redirect` ebenso.*
 *
 * Usage: php scripts/dev/quiet-save-check.php
 *
 * @see assets/admin.js
 */

define('WP_ADMIN', true);
define('WP_USE_THEMES', false);
define('DOING_AJAX', true);

require (getenv('WP_ROOT') ?: 'C:/Devel/Wordpress') . '/wp-load.php';
require __DIR__ . '/lib/no-write.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\Core\Model\Branch;
use Taxmod\WordPress\Admin\NodesScreen;

wp_set_current_user(1);

final class QuietSaveStop extends RuntimeException
{
}

$failed = 0;

$say = static function (bool $ok, string $what) use (&$failed): void {
    printf("  %-4s %s\n", $ok ? 'ok' : 'FAIL', $what);

    if (! $ok) {
        ++$failed;
    }
};

$r      = new ReflectionClass(\Taxmod\WordPress\Plugin::class);
$plugin = $r->newInstanceWithoutConstructor();
$r->getProperty('file')->setValue($plugin, dirname(__DIR__, 2) . '/wp-taxonomy-modeler.php');

$editor = $plugin->editor();
$fw     = (new ReflectionMethod($plugin, 'frameworkNodes'))->invoke($plugin);
$aktion = (string) (new ReflectionClass(NodesScreen::class))->getConstant('ACTION');
$knoten = $editor->createNode('__qs Knoten', $fw->rootOf(Branch::Model)->id);

add_filter('wp_die_ajax_handler', static fn (): callable => static function (): never {
    throw new QuietSaveStop('json');
});

$umgeleitet = '';

add_filter('wp_redirect', static function (string $location) use (&$umgeleitet): string {
    $umgeleitet = $location;

    throw new QuietSaveStop('redirect');
});

/** @return array{json: ?array<string, mixed>, redirect: string} */
$abschicken = static function (array $post) use ($plugin, $aktion, &$umgeleitet): array {
    $post['action']        = $aktion;
    $post['_taxmod_nonce'] = wp_create_nonce($aktion . '_' . $post['id']);
    $_POST                 = $post;
    $_REQUEST              = $post;
    $umgeleitet            = '';

    ob_start();

    try {
        $plugin->screen()->handlePost();
    } catch (QuietSaveStop) {
        // Der Akt ist durch; was er sagte, steht im Puffer oder in `$umgeleitet`.
    }

    $ausgabe = (string) ob_get_clean();

    return ['json' => json_decode($ausgabe, true), 'redirect' => $umgeleitet];
};

$seite = static fn (string $name, bool $quiet): array => array_filter([
    'do'                => 'put_setting',
    'id'                => (string) $knoten->id,
    'relation'          => '0',
    'name'              => $name,
    'label_locale'      => '',
    NodesScreen::QUIET  => $quiet ? '1' : null,
], static fn ($wert): bool => $wert !== null);

echo "\n== 1. Seite speichern im Hintergrund ==\n";

$antwort = $abschicken($seite('__qs Knoten still', true));

$say(is_array($antwort['json']), 'die Antwort ist JSON');
$say(($antwort['json']['ok'] ?? null) === true, 'ok: true');
$say(array_key_exists('goto', $antwort['json'] ?? []) && $antwort['json']['goto'] === null, 'kein goto — die Seite bleibt stehen');
$say(($antwort['json']['message'] ?? '') !== '', 'eine Meldung fürs Toast');
$say($antwort['redirect'] === '', 'keine Umleitung');
$say($editor->find($knoten->id)?->name === '__qs Knoten still', 'der Name ist geschrieben');

echo "\n== 2. ein Fehler kommt als Satz zurück ==\n";

$antwort = $abschicken(['do' => '__qs_unbekannt', 'id' => (string) $knoten->id, NodesScreen::QUIET => '1']);

$say(($antwort['json']['ok'] ?? null) === false, 'ok: false');
$say(($antwort['json']['message'] ?? '') !== '', 'mit dem Satz des Rands');

echo "\n== 3. ein Akt, der den Aufbau ändert, nennt seine Adresse ==\n";

$antwort = $abschicken(['do' => 'add_record', 'id' => (string) $knoten->id, NodesScreen::QUIET => '1']);

$say(is_string($antwort['json']['goto'] ?? null) && str_contains((string) $antwort['json']['goto'], 'taxmod_node=' . $knoten->id), 'goto zeigt auf den Knoten');

echo "\n== 4. ohne taxmod_quiet wie bisher ==\n";

$antwort = $abschicken($seite('__qs Knoten laut', false));

$say($antwort['json'] === null, 'keine JSON-Antwort');
$say(str_contains($antwort['redirect'], 'taxmod_node=' . $knoten->id), 'Umleitung zum Knoten');
$say($editor->find($knoten->id)?->name === '__qs Knoten laut', 'der Name ist geschrieben');

echo $failed === 0 ? "\nAlles grün.\n" : "\n{$failed} rot.\n";

exit($failed === 0 ? 0 : 1);
