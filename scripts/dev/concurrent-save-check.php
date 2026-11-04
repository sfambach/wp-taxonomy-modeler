<?php declare(strict_types=1);

/**
 * Two people, one record — and two pickers, one stock ([D-1148](../../docs/NewConcept/90-decision-log.md)).
 *
 * ⚠️ **Sein Wort:** *«sodass später ein Mehrbenutzerverarbeitung … sich nicht gegenseitig kaputt macht. Auch … mehrere Kommissionierer
 * gleichzeitig … Und das sollte schon geregelt sein.»* This run walks the rule the way the browser does:
 *
 * 1. The open record form carries what it read (`taxmod_record_versions`), the record and its parts.
 * 2. A save with the versions it read writes and raises the record's version once; the same value again raises nothing.
 * 3. A save with an older version is refused, writes nothing, keeps the input, and the page shows the conflict dialog.
 * 4. «Save my input anyway» writes the kept input.
 * 5. A quiet save answers with the new versions, so the next autosave does not collide with itself.
 * 6. `taxmod/write-data` with `expect`: an older version is `taxmod_conflict` and writes nothing; the current one writes and
 *    answers the new versions.
 * 7. The booking lock: while one request holds it, a second connection does not get it; after release it does.
 *
 * ⚠️ *One process with the bracket from `lib/no-write.php`; the redirect and `wp_send_json()` are caught as in `quiet-save-check`.
 * The second connection in step 7 is a real one — a lock held by the same connection would be granted again.*
 *
 * Usage: php scripts/dev/concurrent-save-check.php
 */

define('WP_ADMIN', true);
define('WP_USE_THEMES', false);
define('DOING_AJAX', true);

require (getenv('WP_ROOT') ?: 'C:/Devel/Wordpress') . '/wp-load.php';
require __DIR__ . '/lib/no-write.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\Type\TextType;
use Taxmod\WordPress\Admin\NodesScreen;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WriteLock;

wp_set_current_user(1);

final class ConcurrentSaveStop extends RuntimeException
{
}

$failed = 0;

$say = static function (bool $ok, string $what, string $detail = '') use (&$failed): void {
    printf("  %-4s %s%s\n", $ok ? 'ok' : 'FAIL', $what, $ok || $detail === '' ? '' : ' — ' . $detail);

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
$text   = (new WpdbNodeRepository())->byImplementations([TextType::class])[TextType::class] ?? null;
$knoten = $editor->createNode('__cs Knoten', $fw->rootOf(Branch::Model)->id);
$feld   = $editor->addField($knoten->id, (int) $text?->id, '__cs Titel');
$satz   = $plugin->screen()->entries()->create($knoten->id);

add_filter('wp_die_ajax_handler', static fn (): callable => static function (): never {
    throw new ConcurrentSaveStop('json');
});

$umgeleitet = '';

add_filter('wp_redirect', static function (string $location) use (&$umgeleitet): string {
    $umgeleitet = $location;

    throw new ConcurrentSaveStop('redirect');
});

/** @return array{json: ?array<string, mixed>, redirect: string} */
$abschicken = static function (array $post) use ($plugin, $aktion, $knoten, &$umgeleitet): array {
    $post['action']        = $aktion;
    $post['id']            = (string) $knoten->id;
    $post['_taxmod_nonce'] = wp_create_nonce($aktion . '_' . $knoten->id);
    $_POST                 = wp_slash($post);
    $_REQUEST              = $_POST;
    $umgeleitet            = '';

    ob_start();

    try {
        $plugin->screen()->handlePost();
    } catch (ConcurrentSaveStop) {
        // The act is through; what it said is in the buffer or in `$umgeleitet`.
    }

    return ['json' => json_decode((string) ob_get_clean(), true), 'redirect' => $umgeleitet];
};

$version = static fn (int $id): int => (int) $GLOBALS['wpdb']->get_var('SELECT version FROM ' . Schema::table('node_records') . ' WHERE id = ' . $id);
$wert    = static fn (): ?string => $GLOBALS['wpdb']->get_var('SELECT value_text FROM ' . Schema::table('relation_records') . ' WHERE node_record_id = ' . $satz->id . ' AND relation_id = ' . $feld->id);
$speichern = static fn (string $text, string $versions, bool $quiet = false): array => $abschicken(array_filter([
    'do'                         => 'save_record',
    'node_record_id'             => (string) $satz->id,
    NodesScreen::RECORD_VERSIONS => $versions,
    'taxmod_value'               => [(string) $satz->id => [(string) $feld->id => $text]],
    NodesScreen::QUIET           => $quiet ? '1' : null,
], static fn ($w): bool => $w !== null));

echo "\n== 1. the form carries what it read ==\n";

$_GET = ['page' => 'taxmod-nodes', 'taxmod_node' => (string) $knoten->id, 'taxmod_preview_record' => (string) $satz->id];
$seite = $plugin->screen()->render();
$gelesen = $satz->id . ':' . $version($satz->id);
$say(str_contains($seite, 'name="' . NodesScreen::RECORD_VERSIONS . '" value="' . $gelesen . '"'), 'the open record form carries «' . $gelesen . '»');
$_GET = [];

echo "\n== 2. a save with what it read ==\n";

$v0 = $version($satz->id);
$speichern('__cs eins', $satz->id . ':' . $v0);
$v1 = $version($satz->id);
$say($wert() === '__cs eins', 'written', (string) $wert());
$say($v1 === $v0 + 1, 'the record moved on by one', "$v0 → $v1");
$speichern('__cs eins', $satz->id . ':' . $v1);
$say($version($satz->id) === $v1, 'the same value again moves nothing', (string) $version($satz->id));

echo "\n== 3. a save with an older version ==\n";

$antwort = $speichern('__cs veraltet', $satz->id . ':' . $v0);
$say($wert() === '__cs eins', 'refused: nothing of it is written', (string) $wert());
$say($version($satz->id) === $v1, 'and the version stands');
$say(str_contains($antwort['redirect'], 'taxmod_conflict='), 'the page is told there is a conflict', $antwort['redirect']);
parse_str((string) parse_url($antwort['redirect'], PHP_URL_QUERY), $adresse);
$schluessel = (string) ($adresse['taxmod_conflict'] ?? '');
$say($schluessel !== '' && get_transient('taxmod_conflict_1_' . $schluessel) !== false, 'the input is kept');

$_GET = ['page' => 'taxmod-nodes', 'taxmod_node' => (string) $knoten->id, 'taxmod_message' => 'x', 'taxmod_conflict' => $schluessel];
$seite = $plugin->screen()->render();
$say(str_contains($seite, 'id="taxmod-conflict-dialog" checked'), 'the conflict dialog is open when the page arrives');
$say(str_contains($seite, 'value="save_record_anyway"'), 'and offers «Save my input anyway»');
$_GET = [];

echo "\n== 4. save mine anyway ==\n";

$abschicken(['do' => 'save_record_anyway', 'taxmod_conflict' => $schluessel]);
$say($wert() === '__cs veraltet', 'the kept input is written', (string) $wert());
$say(get_transient('taxmod_conflict_1_' . $schluessel) === false, 'and no longer kept');

echo "\n== 5. a quiet save hands back its versions ==\n";

$v2      = $version($satz->id);
$antwort = $speichern('__cs still', $satz->id . ':' . $v2, true);
$say(($antwort['json']['ok'] ?? null) === true, 'ok');
$say(($antwort['json']['versions'] ?? '') === $satz->id . ':' . ($v2 + 1), 'with the new versions', (string) ($antwort['json']['versions'] ?? ''));
$antwort = $speichern('__cs still zwei', (string) ($antwort['json']['versions'] ?? ''), true);
$say(($antwort['json']['ok'] ?? null) === true && $wert() === '__cs still zwei', 'the next autosave with them passes');
$antwort = $speichern('__cs still alt', $satz->id . ':' . $v2, true);
$say(($antwort['json']['ok'] ?? null) === false && is_string($antwort['json']['goto'] ?? null), 'a stale quiet save is refused and sent to the dialog');

echo "\n== 6. write-data with expect ==\n";

$schreiben = function_exists('wp_get_ability') ? wp_get_ability('taxmod/write-data') : null;

if ($schreiben === null) {
    $say(true, 'SKIP this WordPress has no Abilities API');
} else {
    $jetzt   = $version($satz->id);
    $alt     = $schreiben->execute(['changes' => [['op' => 'set_values', 'record' => $satz->id, 'values' => [(string) $feld->id => '__cs modul alt']]], 'expect' => [['record' => $satz->id, 'version' => $jetzt - 1]]]);
    $say(is_wp_error($alt) && $alt->get_error_code() === 'taxmod_conflict', 'an older version is taxmod_conflict', is_wp_error($alt) ? $alt->get_error_code() : 'written');
    $say($wert() === '__cs still zwei' && $version($satz->id) === $jetzt, 'and writes nothing');
    $neu = $schreiben->execute(['changes' => [['op' => 'set_values', 'record' => $satz->id, 'values' => [(string) $feld->id => '__cs modul']]], 'expect' => [['record' => $satz->id, 'version' => $jetzt]]]);
    $say(is_array($neu) && $wert() === '__cs modul', 'the current version writes', is_wp_error($neu) ? $neu->get_error_message() : '');
    $say(is_array($neu) && ($neu['versions'][$satz->id] ?? 0) === $jetzt + 1, 'and answers the new version');
}

echo "\n== 7. one booking at a time ==\n";

if (defined('DB_ENGINE') && DB_ENGINE === 'sqlite') {
    $say(true, 'SKIP SQLite serialises every write itself');
} else {
    $zweite = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
    $name   = WriteLock::name('__cs');
    $frei   = static fn (): string => (string) $zweite->query("SELECT GET_LOCK('" . $zweite->real_escape_string($name) . "', 0)")->fetch_row()[0];

    $waehrend = WriteLock::around('__cs', $frei);
    $say($waehrend === '0', 'while one request holds it, a second connection does not get it', $waehrend);
    $danach = $frei();
    $say($danach === '1', 'after release it does', $danach);
    $zweite->query("SELECT RELEASE_LOCK('" . $zweite->real_escape_string($name) . "')");

    $belegt = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
    $belegt->query("SELECT GET_LOCK('" . $belegt->real_escape_string($name) . "', 0)");

    try {
        WriteLock::around('__cs', static fn (): bool => true, 1);
        $say(false, 'a booking that waits too long is refused');
    } catch (\Taxmod\Core\Exception\ConcurrentChange $e) {
        $say(true, 'a booking that waits too long is refused, with its reason');
    }

    $belegt->close();
    $zweite->close();
}

echo $failed === 0 ? "\nAll green.\n" : "\n{$failed} red.\n";

exit($failed === 0 ? 0 : 1);
