<?php declare(strict_types=1);
/**
 * Eine Umbenennung ändert nichts an dem, was gezeichnet wird.
 *
 *     php scripts/dev/rename-survives-check.php [path/to/wordpress]
 *
 * ⚠️ **[D-543](../../docs/NewConcept/90-decision-log.md), auf sein Wort: «ja, Id — Name war nie
 * erlaubt.»** *Und er hat recht: das steht seit langem unter `CD · Prohibited` — nach Anzeigenamen
 * unterscheiden. Trotzdem stand genau das in {@see \Taxmod\Core\Service\ModelValues}, von mir
 * geschrieben, mit einem Docblock daneben, der es verteidigte.*
 *
 * ⚠️ **Was passiert ist.** *Der Eigentümer hat die Kante `renderer` der Wurzel in «Display Options»
 * umbenannt — sein Recht, ein Name ist eine Beschriftung. **Damit fiel die Renderer-Auflösung im
 * ganzen Schirm aus:** `Base units`, `Passiv`, `Integer`, `Dimension`, `Prefixes` und `Parts List`
 * zeichneten alle mit `plain` statt mit `chooser-inline`, `form`, `spinner`, `node`,
 * `chooser-dialog`, `form`. **Ohne eine Zeile Fehler.***
 *
 * ⚠️ **Diese Prüfung benennt selbst um** — beide Kanten, die äussere und die innere — und verlangt,
 * dass danach dasselbe herauskommt. *Sie stellt die Namen in einer `register_shutdown_function`
 * zurück, auch wenn sie mittendrin abstürzt: **eine Prüfung, die ihren Schaden liegen lässt, ist
 * schlimmer als keine.***
 *
 * ⚠️ *Und der Gegenfall gehört dazu: die Auflösung muss vorher überhaupt etwas liefern. Sonst wäre
 * «vorher wie nachher» auch dann wahr, wenn beide Male nichts herauskäme.*
 *
 * @see docs/NewConcept/91-open-questions.md — OQ-140, geschlossen durch D-543
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

use Taxmod\Core\Model\SettingKey;
use Taxmod\Core\Renderer\Purpose;
use Taxmod\Core\Renderer\ShippedRenderers;
use Taxmod\Core\Service\ModelValues;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRecordRepository;
use Taxmod\WordPress\Persistence\WpdbRelationRepository;
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
$framework = new SeededFrameworkNodes($nodes, $edges, new WpdbChangelog(new SystemClock()));
$records   = new WpdbRecordRepository();
$registry  = ShippedRenderers::registry();

$r = Schema::table('relations');
$n = Schema::table('nodes');

/** Was jeder Knoten zeichnet — mit einem **frischen** Leser, damit nichts aus dem Gedächtnis kommt. */
function gezeichnet(array $namen): array
{
    global $wpdb, $nodes, $edges, $framework, $records, $registry, $n;

    // ⚠️ *Frisch je Durchgang: {@see ModelValues} merkt sich seine Funde je Instanz (`CD-7`), und
    // ein wiederverwendeter Leser würde die alte Antwort zurückgeben und die Prüfung grün lügen.*
    $model  = new ModelValues($records, $edges, $nodes, $framework);
    $antwort = [];

    foreach ($namen as $name) {
        $id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$n} WHERE name = %s LIMIT 1", $name));

        if ($id === 0) {
            $antwort[$name] = '(kein Knoten)';

            continue;
        }

        $knoten          = $nodes->byId($id);
        $gewaehlt        = $registry->chosenFor($knoten, $model->forNode($knoten), Purpose::Edit);
        $antwort[$name]  = $gewaehlt?->name() ?? 'nichts';
    }

    return $antwort;
}

$beobachtet = ['Base units', 'Passiv', 'Integer', 'Dimension', 'Prefixes', 'Parts List'];

echo "\n== Die Ids stehen aufgeschrieben ==\n";

$vorher = gezeichnet($beobachtet);

$aussenId = $framework->settingEdgeId(SettingKey::Renderer);
$innenId  = $framework->settingValueEdgeId(SettingKey::Renderer);

check('die Id der Traegerkante steht da', $aussenId !== 0, (string) $aussenId);
check('die Id der Wertkante steht da', $innenId !== 0, (string) $innenId);

// ⚠️ **Der Gegenfall.** *Ohne ihn wäre «vorher wie nachher» auch dann wahr, wenn beide Male nichts
// herauskäme — und genau das war der Ausfall, den es zu fangen gilt.*
$etwas = count(array_filter($vorher, static fn (string $w): bool => $w !== 'nichts' && $w !== 'plain'));

check(
    'und die Aufloesung liefert ueberhaupt etwas',
    $etwas >= 4,
    "{$etwas} von " . count($vorher) . ': ' . implode(', ', $vorher)
);

echo "\n== Jetzt beide Kanten umbenennen ==\n";

$namenVorher = [];

foreach ([$aussenId, $innenId] as $id) {
    if ($id === 0) {
        continue;
    }

    $namenVorher[$id] = (string) $wpdb->get_var($wpdb->prepare("SELECT name FROM {$r} WHERE id = %d", $id));
}

// ⚠️ **Vor der ersten Änderung angemeldet, nicht danach.** *Ein Absturz zwischen Umbenennen und
// Zurueckbenennen liesse den Schirm kaputt zurueck — und der naechste Lauf wuerde den Schaden fuer
// den Zustand halten.*
register_shutdown_function(static function () use ($namenVorher, $r): void {
    global $wpdb;

    foreach ($namenVorher as $id => $name) {
        $wpdb->query($wpdb->prepare("UPDATE {$r} SET name = %s WHERE id = %d", $name, $id));
    }
});

foreach ($namenVorher as $id => $name) {
    $wpdb->query($wpdb->prepare("UPDATE {$r} SET name = %s WHERE id = %d", 'Etwas ganz anderes', $id));
}

$umbenannt = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$r} WHERE id IN (%d, %d) AND name = 'Etwas ganz anderes'",
    $aussenId,
    $innenId
));

check('beide Kanten heissen jetzt anders', $umbenannt === 2, "{$umbenannt} von 2");

$nachher = gezeichnet($beobachtet);

foreach ($beobachtet as $name) {
    check(
        "«{$name}» zeichnet unveraendert mit «{$vorher[$name]}»",
        ($nachher[$name] ?? null) === $vorher[$name],
        ($nachher[$name] ?? 'nichts') . ' statt ' . $vorher[$name]
    );
}

echo "\n== Und die Namen sind zurueck ==\n";

foreach ($namenVorher as $id => $name) {
    $wpdb->query($wpdb->prepare("UPDATE {$r} SET name = %s WHERE id = %d", $name, $id));
}

$zurueck = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$r} WHERE (id = %d AND name = %s) OR (id = %d AND name = %s)",
    $aussenId,
    $namenVorher[$aussenId] ?? '',
    $innenId,
    $namenVorher[$innenId] ?? ''
));

check('beide Namen stehen wieder da', $zurueck === 2, "{$zurueck} von 2");

echo "\n" . ($bad === 0 ? "Alles gruen: $ok\n" : "$bad fehlgeschlagen, $ok in Ordnung\n");

exit($bad === 0 ? 0 : 1);
