<?php declare(strict_types=1);
/**
 * Welchen Renderer bekommt ein Knoten — an echten Daten festgenagelt.
 *
 *     php scripts/dev/renderer-choice-check.php [path/to/wordpress]
 *
 * ⚠️ **Diese Datei entstand aus einem Fehler, den nichts gemeldet hätte.** *Am 2026-08-30 wurden die
 * 32 Renderer-Einstellungen an ihre neue Adresse umgezogen — **bevor der Leser dort suchte**. Jeder
 * Knoten hätte seinen Renderer verloren, und **alle 305 Randprüfungen blieben grün**, weil keine
 * einzige die Renderer-Wahl bewacht. `renderer-snapshot.php` sieht danach aus, hat aber null Zusagen:
 * es ist ein Abdruck, keine Prüfung.*
 *
 * ⚠️ **Sie ist die Sicherung für den Umzug, nicht für den Renderer.** *Sie hält fest, was **heute**
 * herauskommt. Wandert die Angabe an eine neue Stelle und der Leser wandert mit, bleibt sie grün;
 * wandert nur eines von beidem, wird sie rot. **Genau das ist ihr Zweck.***
 *
 * ⚠️ *Die Knoten werden über ihren **Namen** gesucht — und **fehlt einer, ist das ein Fehlschlag und
 * kein Überspringen**. Eine Prüfung, die ihren Gegenstand nicht findet, ist nicht grün.*
 *
 * @see docs/NewConcept/30-renderer.md
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
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\Core\Renderer\Purpose;
use Taxmod\Core\Renderer\ShippedRenderers;
use Taxmod\Core\Service\Settings;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\TableIdentityAllocator;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRelationRepository;
use Taxmod\WordPress\Persistence\WpdbSettingRepository;
use Taxmod\WordPress\SystemClock;

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

$nodes     = new WpdbNodeRepository();
$edges     = new WpdbRelationRepository();
$log       = new WpdbChangelog(new SystemClock());
$framework = new SeededFrameworkNodes($nodes, $edges, new TableIdentityAllocator(), $log);
$settings  = new Settings(new WpdbSettingRepository(), $nodes, $framework);
$registry  = ShippedRenderers::registry();

/** Der Knoten mit diesem Namen, oder null. */
function knoten(string $name): ?\Taxmod\Core\Model\Node
{
    global $wpdb, $nodes;

    $id = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT id FROM ' . Schema::table('nodes') . ' WHERE name = %s LIMIT 1',
        $name
    ));

    return $id === 0 ? null : $nodes->find($id);
}

echo "\n== Welchen Renderer ein Knoten bekommt ==\n";

// ⚠️ *Der erwartete Wert ist das, was am 2026-08-30 herauskam — festgenagelt, damit ein Umzug ihn
// nicht unbemerkt verliert.*
$erwartet = [
    'Base units' => 'chooser-inline',
    'Passiv'     => 'form',
    'Integer'    => 'spinner',
    'Dimension'  => 'node',
    'Prefixes'   => 'chooser-dialog',
    'Parts List' => 'form',
];

foreach ($erwartet as $name => $soll) {
    $node = knoten($name);

    if ($node === null) {
        check("«{$name}» steht im Modell", false, 'kein Knoten dieses Namens');

        continue;
    }

    $gewaehlt = $registry->chosenFor(
        $node,
        $settings->resolve($settings->chainFor($node)),
        Purpose::Edit
    );

    check("«{$name}» zeichnet mit «{$soll}»", ($gewaehlt?->name() ?? null) === $soll, $gewaehlt?->name() ?? 'nichts');
}

echo "\n== Und an einer Verwendungsstelle ==\n";

// ⚠️ **Die vier Angaben an Kanten sind der eigentliche Grund für den zweistufigen Pfad.** *Ohne sie
// hätte man «der Renderer **dieses Feldes**» nicht ausdrücken können, ohne einen Behälter zu erfinden.*
$anKanten = $wpdb->get_results(
    "SELECT s.owner_id, s.value_text, rel.name AS feld, von.name AS von
     FROM " . Schema::table('settings') . " s
     JOIN " . Schema::table('relations') . " rel ON rel.id = s.owner_id
     JOIN " . Schema::table('nodes') . " von ON von.id = rel.from_id
     WHERE s.setting_key = 'renderer'",
    ARRAY_A
) ?: [];

check('es gibt Renderer an Verwendungsstellen', $anKanten !== [], 'keine gefunden');

foreach ($anKanten as $z) {
    $kante = null;

    foreach ($edges->fieldEdgesOf([(int) $wpdb->get_var($wpdb->prepare(
        'SELECT from_id FROM ' . Schema::table('relations') . ' WHERE id = %d',
        (int) $z['owner_id']
    ))]) as $eine) {
        if ($eine->id === (int) $z['owner_id']) {
            $kante = $eine;
        }
    }

    if ($kante === null) {
        check("die Kante «{$z['von']}.{$z['feld']}» ist auffindbar", false);

        continue;
    }

    $gewaehlt = $registry->chosenFor(
        $kante,
        $settings->resolve($settings->chainForUseSite($kante)),
        Purpose::Edit
    );

    check(
        "«{$z['von']}.{$z['feld']}» zeichnet mit «{$z['value_text']}»",
        ($gewaehlt?->name() ?? null) === $z['value_text'],
        $gewaehlt?->name() ?? 'nichts'
    );
}

echo "\n" . ($bad === 0 ? "Alles gruen: $ok\n" : "$bad fehlgeschlagen, $ok in Ordnung\n");

exit($bad === 0 ? 0 : 1);
