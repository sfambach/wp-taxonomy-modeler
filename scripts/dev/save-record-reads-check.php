<?php declare(strict_types=1);

/**
 * Ein Satz wird gespeichert, ohne dieselbe Frage zweimal zu stellen ([D-1485](../../docs/NewConcept/90-decision-log.md)).
 *
 *     php scripts/dev/save-record-reads-check.php
 *
 * Gemessen live am 2026-11-19 über das Seitenzeit-Protokoll: eine Knoten-Aktion stellte 645–691 Anweisungen, einmal 4005. Lokal
 * las ein `save_record` jeden Knoten und jede Relation je Feld neu — 128 Anweisungen, 59 davon wörtlich wiederholt —, weil die
 * Aktion ohne Lesegedächtnis lief. Dieser Lauf schickt das Speichern so ab, wie WordPress es ruft, und zählt.
 *
 * ⚠️ *Die Prüfung am Schreibpunkt ([D-1148](../../docs/NewConcept/90-decision-log.md)) liest dabei weiter frisch: eine sperrende
 * Lesung (`FOR UPDATE`) geht nie über das Gedächtnis. Auch das wird hier gezählt, nicht behauptet.*
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
use Taxmod\WordPress\Persistence\Query;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRecordRepository;

wp_set_current_user(1);

/**
 * Die Decken — **gemessen, nicht gewünscht; sie dürfen fallen und nie steigen.**
 *
 * `repeats`: wörtlich gleiche Lesungen zwischen zwei Schreibanweisungen. `statements`: alle Anweisungen eines Speicherns von
 * fünf Textfeldern. Gemessen am 2026-11-19: ohne Lesegedächtnis 85 Wiederholungen und 155 Anweisungen, mit ihm 0 und 63.
 */
const SAVE_RECORD_CEILINGS = ['repeats' => 0, 'statements' => 63];

final class SaveRecordReadsStop extends RuntimeException
{
}

$failed = 0;

$say = static function (bool $ok, string $what, string $detail = '') use (&$failed): void {
    printf("  %-4s %s%s\n", $ok ? 'ok' : 'FAIL', $what, $detail === '' ? '' : ' — ' . $detail);

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
$knoten = $editor->createNode('__sr Knoten', $fw->rootOf(Branch::Model)->id);
$felder = [];

for ($i = 0; $i < 5; $i++) {
    $felder[] = $editor->addField($knoten->id, (int) $text?->id, '__sr Feld ' . $i);
}

$satz = $plugin->screen()->entries()->create($knoten->id);

add_filter('wp_die_ajax_handler', static fn (): callable => static function (): never {
    throw new SaveRecordReadsStop('json');
});
add_filter('wp_redirect', static function (): never {
    throw new SaveRecordReadsStop('redirect');
});

/** @var list<string> $asked every statement of the act, in order */
$asked      = [];
$remembered = false;

add_filter('query', static function (string $sql) use (&$asked, &$remembered): string {
    $asked[]    = $sql;
    $remembered = $remembered || Query::isRemembering();

    return $sql;
});

$version = static fn (): int => (int) $wpdb->get_var('SELECT version FROM ' . Schema::table('node_records') . ' WHERE id = ' . $satz->id);
$wert    = static fn (): ?string => $wpdb->get_var('SELECT value_text FROM ' . Schema::table('relation_records') . ' WHERE node_record_id = ' . $satz->id . ' AND relation_id = ' . $felder[0]->id);

$speichern = static function (string $wort, int $gelesen) use ($plugin, $aktion, $knoten, $satz, $felder, &$asked, &$remembered): void {
    $werte = [];

    foreach ($felder as $i => $feld) {
        $werte[(string) $feld->id] = $wort . ' ' . $i;
    }

    $_POST = wp_slash([
        'action'                     => $aktion,
        'id'                         => (string) $knoten->id,
        '_taxmod_nonce'              => wp_create_nonce($aktion . '_' . $knoten->id),
        'do'                         => 'save_record',
        'node_record_id'             => (string) $satz->id,
        NodesScreen::RECORD_VERSIONS => $satz->id . ':' . $gelesen,
        'taxmod_value'               => [(string) $satz->id => $werte],
    ]);
    $_REQUEST   = $_POST;
    $asked      = [];
    $remembered = false;

    ob_start();

    try {
        $plugin->handleNodeAction();
    } catch (SaveRecordReadsStop) {
        // The act is through.
    }

    ob_end_clean();
};

echo "Ein Speichern, wie WordPress es ruft\n";

$v0 = $version();
$speichern('__sr eins', $v0);
$act = $asked;

$say($wert() === '__sr eins 0' && $version() === $v0 + 1, 'geschrieben, und der Satz ist eine Fassung weiter');
$say($remembered, 'die Aktion läuft mit Lesegedächtnis');

$repeats = 0;
$seen    = [];

foreach ($act as $sql) {
    if (preg_match('/^\s*(SELECT|WITH|SHOW|EXPLAIN|DESCRIBE)\b/i', $sql) !== 1) {
        $seen = [];

        continue;
    }

    if (preg_match('/\bFOR\s+UPDATE\s*$/i', $sql) === 1) {
        continue;
    }

    $repeats += isset($seen[$sql]) ? 1 : 0;
    $seen[$sql] = true;
}

$say($repeats <= SAVE_RECORD_CEILINGS['repeats'], sprintf('zwischen zwei Schreibanweisungen wird keine Lesung wiederholt, kein Knoten zweimal gelesen (%d, Decke %d)', $repeats, SAVE_RECORD_CEILINGS['repeats']));
$say(count($act) <= SAVE_RECORD_CEILINGS['statements'], sprintf('ein Speichern von fünf Feldern stellt höchstens %d Anweisungen (%d)', SAVE_RECORD_CEILINGS['statements'], count($act)));

echo "\nDie Prüfung am Schreibpunkt liest frisch\n";

$locking = count(array_filter($act, static fn (string $sql): bool => preg_match('/\bFOR\s+UPDATE\s*$/i', $sql) === 1));

$say($locking >= 1, 'das Speichern stellt die sperrende Lesung der Fassungen (' . $locking . ')');

$records = new WpdbRecordRepository();
$asked   = [];

Query::remembering(static function () use ($records, $satz): void {
    $records->versionsOf([$satz->id], true);
    $records->versionsOf([$satz->id], true);
    $records->versionsOf([$satz->id]);
    $records->versionsOf([$satz->id]);
});

$say(count(array_filter($asked, static fn (string $sql): bool => str_contains($sql, 'FOR UPDATE'))) === 2, 'zweimal sperrend gefragt heisst zweimal die Datenbank gefragt — nie das Gedächtnis');
$say(count($asked) === 3, 'die gewöhnliche Lesung daneben wird behalten (3 Anweisungen für 4 Fragen)', (string) count($asked));

$v1 = $version();
$speichern('__sr zwei', $v1 - 1);
$say($wert() === '__sr eins 0' && $version() === $v1, 'mit einer älteren Fassung wird nichts geschrieben — auch im Gedächtnis-Lauf');
$speichern('__sr zwei', $v1);
$say($wert() === '__sr zwei 0', 'mit der gelesenen Fassung wird geschrieben');

printf("\n%s\n", $failed === 0 ? 'all green' : sprintf('%d FEHLER', $failed));

exit($failed === 0 ? 0 : 1);
