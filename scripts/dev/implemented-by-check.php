<?php declare(strict_types=1);
/**
 * Der Knoten nennt die PHP-Klasse, die ihn umsetzt — und die Klasse gibt es (TASK-008).
 *
 *     php scripts/dev/implemented-by-check.php [path/to/wordpress]
 *
 * ⚠️ **Dieser Wächter ist Teil der Entscheidung, nicht ihr Zubehör.** *`package.md` §3.2 sagt es in
 * einem Satz: «Dazu gehört ein Wächter: eine Zeile, die eine Klasse nennt, die es nicht gibt, wird
 * rot.» **Das ist der Ausgleich dafür, dass ein Klassenname die Daten an den Code bindet** — und es
 * ist der eine Vorteil, den eine Marke nicht hätte: eine Marke könnte auf nichts zeigen, ohne dass
 * es jemals auffiele.*
 *
 * Geprüft wird viererlei:
 *
 * 1. **Die Spalte gibt es**, an der lebenden Tabelle und an ihrem Schatten.
 * 2. **Jede Angabe nennt eine Klasse, die es gibt** — lebende Zeilen.
 * 3. **Keine Klasse steht an zwei Knoten.** *Zwei Knoten, die beide der Schieber-Renderer sind, sind
 *    kein Angebot, sondern ein Fehler: der Leser nimmt einen von beiden und weiss nicht welchen.*
 * 4. **Jeder registrierte Renderer, Konverter und Validator hat seinen Knoten** — sonst wäre die
 *    Ablösung der Optionen (TASK-009) eine Ablösung ins Leere.
 *
 * ⚠️ *Der Schatten wird **gezählt und nicht verlangt**: er trägt Klassennamen von Fassungen, deren
 * Klassen es heute nicht mehr gibt, und das ist richtig so — Geschichte ist eingefroren
 * ([D-065](../../docs/NewConcept/90-decision-log.md)). Dieselbe Form wie in
 * [`value-ref-space-check.php`](value-ref-space-check.php).*
 *
 * ⚠️ *Dieser Lauf schreibt nichts und legt nichts an — er liest nur, also gibt es nichts wegzuräumen
 * (TASK-025, TASK-039, TASK-047).*
 *
 * @see docs/pakete/modelltabellen/package.md
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

use Taxmod\Core\Converter\ShippedConverters;
use Taxmod\Core\Renderer\ShippedRenderers;
use Taxmod\Core\Validator\ShippedValidators;
use Taxmod\WordPress\Persistence\Schema;

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

function hatSpalte(string $tabelle, string $spalte): bool
{
    global $wpdb;

    return $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$tabelle} LIKE %s", $spalte)) !== null;
}

echo "1 · Die Spalte steht, lebend und im Schatten\n";

$nodes  = Schema::table('nodes');
$shadow = Schema::table('nodes_history');

check('nodes.implemented_by', hatSpalte($nodes, 'implemented_by'));
check('nodes_history.implemented_by', hatSpalte($shadow, 'implemented_by'), 'der Schatten zieht mit');

if ($bad > 0) {
    echo "\nOhne die Spalte hat der Rest nichts zu prüfen.\n";

    exit(1);
}

echo "\n2 · Jede Angabe nennt eine Klasse, die es gibt\n";

/** @var list<array{id: string, name: string, implemented_by: string}> $zeilen */
$zeilen = $wpdb->get_results(
    "SELECT id, name, implemented_by FROM {$nodes}
     WHERE implemented_by IS NOT NULL AND implemented_by <> '' ORDER BY id",
    ARRAY_A
) ?: [];

$fehlend = [];

foreach ($zeilen as $zeile) {
    if (! class_exists((string) $zeile['implemented_by'])) {
        $fehlend[] = $zeile['id'] . ' «' . $zeile['name'] . '» → ' . $zeile['implemented_by'];
    }
}

check(
    'jede der ' . count($zeilen) . ' Angaben zeigt auf eine vorhandene Klasse',
    $fehlend === [],
    implode(' · ', array_slice($fehlend, 0, 5))
);

// ⚠️ *Der Schatten wird gezählt, nicht verlangt — siehe den Kopf dieses Laufs.*
$imSchatten = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$shadow} WHERE implemented_by IS NOT NULL AND implemented_by <> ''"
);

printf("       gemessen: %d Angaben in lebenden Zeilen, %d in alten Fassungen\n", count($zeilen), $imSchatten);

echo "\n3 · Keine Klasse steht an zwei Knoten\n";

/** @var list<array{implemented_by: string, wieviele: string}> $mehrfach */
$mehrfach = $wpdb->get_results(
    "SELECT implemented_by, COUNT(*) AS wieviele FROM {$nodes}
     WHERE implemented_by IS NOT NULL AND implemented_by <> ''
     GROUP BY implemented_by HAVING COUNT(*) > 1",
    ARRAY_A
) ?: [];

check(
    'jede Klasse steht an höchstens einem Knoten',
    $mehrfach === [],
    implode(' · ', array_map(
        static fn (array $z): string => $z['implemented_by'] . ' ×' . $z['wieviele'],
        array_slice($mehrfach, 0, 5)
    ))
);

echo "\n4 · Jeder registrierte Renderer, Konverter und Validator hat seinen Knoten\n";

// ⚠️ *Die Registraturen werden hier frisch gefüllt und nicht aus dem Plugin geholt: dieser Lauf soll
// prüfen, was der Code **enthält**, nicht was eine Anfrage gerade zusammengebaut hat.*
$renderer  = ShippedRenderers::registry();
$konverter = ShippedConverters::registry();
$validator = ShippedValidators::registry();

$erwartet = [];

foreach ($renderer->namesForNodes() as $name) {
    $klasse = $renderer->classFor($name);

    if ($klasse !== null) {
        $erwartet[$klasse] = 'Renderer ' . $name;
    }
}

foreach ($konverter->namesForNodes() as $name) {
    $klasse = $konverter->classFor($name);

    if ($klasse !== null) {
        $erwartet[$klasse] = 'Converter ' . $name;
    }
}

foreach ($validator->namesForNodes() as $name) {
    $klasse = $validator->classFor($name);

    if ($klasse !== null) {
        $erwartet[$klasse] = 'Validator ' . $name;
    }
}

$dastehend = [];

foreach ($zeilen as $zeile) {
    $dastehend[(string) $zeile['implemented_by']] = true;
}

$ohneKnoten = [];

foreach ($erwartet as $klasse => $wer) {
    if (! isset($dastehend[$klasse])) {
        $ohneKnoten[] = $wer;
    }
}

check(
    'alle ' . count($erwartet) . ' registrierten Klassen stehen an einem Knoten',
    $ohneKnoten === [],
    implode(' · ', array_slice($ohneKnoten, 0, 8))
);

echo "\n" . ($bad === 0 ? "Alles gruen: $ok\n" : "$bad fehlgeschlagen, $ok in Ordnung\n");

exit($bad === 0 ? 0 : 1);
