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

/**
 * Was jeder Knoten zeichnet — mit einem **frischen** Leser, damit nichts aus dem Gedächtnis kommt.
 *
 * ⚠️ *Frisch je Durchgang: {@see ModelValues} merkt sich seine Funde je Instanz (`CD-7`), und
 * ein wiederverwendeter Leser würde die alte Antwort zurückgeben und die Prüfung grün lügen.*
 *
 * @param list<int> $ids
 * @return array<int, string>
 */
function gezeichnet(array $ids): array
{
    global $nodes, $edges, $framework, $records, $registry;

    $model   = new ModelValues($records, $edges, $nodes, $framework);
    $antwort = [];

    foreach ($ids as $id) {
        $knoten       = $nodes->find($id);
        $antwort[$id] = $knoten === null
            ? '(kein Knoten)'
            : ($registry->chosenFor($knoten, $model->forNode($knoten), Purpose::Edit)?->name() ?? 'nichts');
    }

    return $antwort;
}

// ⚠️ **Beobachtet wird über Ids, und die Auswahl trifft der Baum, nicht eine Liste seiner Namen**
// ([D-613](../../docs/NewConcept/90-decision-log.md), vollzieht [D-022](../../docs/NewConcept/90-decision-log.md)).
// *Hier standen sechs Namen aus seinem Modell — `Passiv`, `Dimension`, `Parts List` unter ihnen.
// **Zwei davon kommen in seinem Modell doppelt vor**, und ein `WHERE name = … LIMIT 1` greift dann
// eine von beiden, ohne zu wissen welche.*
//
// ⚠️ *Der Gegenfall wird davon **stärker**, nicht schwächer: beobachtet wird jeder Knoten unter der
// Wurzel, und verlangt wird, dass mindestens vier davon überhaupt etwas anderes als `plain`
// zeichnen. Fällt die Auflösung aus, ist diese Zahl null — genau der Ausfall, den es zu fangen gilt.*
$beobachtet = array_map(intval(...), $wpdb->get_col($wpdb->prepare(
    "SELECT id FROM {$n} WHERE path LIKE %s AND path NOT LIKE %s ORDER BY id",
    $wpdb->esc_like($framework->root()->path . '.') . '%',
    $wpdb->esc_like($framework->trash()->path . '.') . '%'
)));

echo "\n== Die Ids stehen aufgeschrieben ==\n";

$vorher = gezeichnet($beobachtet);

$aussenId = $framework->settingEdgeId(SettingKey::Renderer);
$innenId  = $framework->settingValueEdgeId(SettingKey::Renderer);

// ⚠️ **Hier standen zwei Zusagen auf aufgeschriebene Kanten-Ids, und beide waren rot.** *Der
// Renderer haengt seit TASK-020 an `nodes.settings_record_id` ([D-584](../../docs/NewConcept/90-decision-log.md));
// die beiden Optionen trugen Ids geloeschter Kanten und sind mit dem Huellknoten `DisplayOption`
// gegangen ([D-604](../../docs/NewConcept/90-decision-log.md)). **Eine Zusage auf eine Form, die es
// nicht mehr gibt, prueft nichts** — also fragt sie jetzt die Spalte (`PR-9`: eine Pruefung
// bewacht den heutigen Zielzustand, und die Aenderung ist sichtbar).*
$traeger = array_map(intval(...), $wpdb->get_col(
    "SELECT id FROM {$n} WHERE settings_record_id IS NOT NULL ORDER BY id"
));

check('es gibt Traeger in der Spaltenform', count($traeger) >= 4, count($traeger) . ' Knoten');

$haltlos = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$n} k LEFT JOIN " . Schema::table('node_records')
        . ' s ON s.id = k.settings_record_id'
        . ' WHERE k.settings_record_id IS NOT NULL AND s.id IS NULL'
);

check('und kein Traeger zeigt ins Leere', $haltlos === 0, "{$haltlos} haltlos");

// ⚠️ **Der Gegenfall.** *Ohne ihn wäre «vorher wie nachher» auch dann wahr, wenn beide Male nichts
// herauskäme — und genau das war der Ausfall, den es zu fangen gilt.*
$etwas = count(array_filter($vorher, static fn (string $w): bool => $w !== 'nichts' && $w !== 'plain'));

check(
    'und die Aufloesung liefert ueberhaupt etwas',
    $etwas >= 4,
    "{$etwas} von " . count($vorher) . ': ' . implode(', ', $vorher)
);

echo "\n== Jetzt beide Kanten umbenennen ==\n";

// ⚠️ **Und die Traegerknoten dazu.** *Der Weg zum Renderer laeuft heute ueber die Spalte, also
// gehoert der Name des Traegers in denselben Gegenfall: er darf an dem, was gezeichnet wird,
// nichts aendern.*
$knotenNamenVorher = [];

foreach ($traeger as $id) {
    $knotenNamenVorher[$id] = (string) $wpdb->get_var($wpdb->prepare("SELECT name FROM {$n} WHERE id = %d", $id));
}

register_shutdown_function(static function () use ($knotenNamenVorher, $n): void {
    global $wpdb;

    foreach ($knotenNamenVorher as $id => $name) {
        $wpdb->query($wpdb->prepare("UPDATE {$n} SET name = %s WHERE id = %d", $name, $id));
    }
});

foreach ($knotenNamenVorher as $id => $name) {
    $wpdb->query($wpdb->prepare("UPDATE {$n} SET name = %s WHERE id = %d", 'Etwas ganz anderes', $id));
}

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

// ⚠️ **Seit TASK-020 kann diese Zahl kleiner als 2 sein, und das ist kein Ausfall, sondern die
// Entscheidung.** *Der Renderer hängt an `nodes.settings_record_id` und nicht mehr an einer
// Trägerkante ([D-584](../../docs/NewConcept/90-decision-log.md)); die beiden aufgeschriebenen
// Kanten gingen mit dem Hüllknoten `DisplayOption`, den der Eigentümer gelöscht hat
// ([D-604](../../docs/NewConcept/90-decision-log.md)). **Umbenannt werden kann nur, was es gibt** —
// also ist die Zusage: jede noch vorhandene der beiden trägt danach den anderen Namen. **Die
// eigentliche Zusage steht unverändert darunter:** die Zeichnung ändert sich davon nicht.*
$vorhanden = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$r} WHERE id IN (%d, %d)",
    $aussenId,
    $innenId
));

check(
    "die noch vorhandenen der beiden Kanten heissen jetzt anders ({$vorhanden} von 2 gibt es)",
    $umbenannt === $vorhanden,
    "{$umbenannt} von {$vorhanden}"
);

$nachher = gezeichnet($beobachtet);

// ⚠️ *Eine Zusage über den ganzen Baum statt eine je Name — und die Abweichungen werden **genannt**,
// mit Id, damit ein rotes Ergebnis auch sagt, wo man nachsehen muss.*
$abweichend = [];

foreach ($beobachtet as $id) {
    if (($nachher[$id] ?? null) !== $vorher[$id]) {
        $abweichend[] = "#{$id}: " . ($nachher[$id] ?? 'nichts') . ' statt ' . $vorher[$id];
    }
}

check(
    'kein Knoten zeichnet nach der Umbenennung anders (' . count($beobachtet) . ' beobachtet)',
    $abweichend === [],
    implode(', ', array_slice($abweichend, 0, 10))
);

echo "\n== Und die Namen sind zurueck ==\n";

foreach ($namenVorher as $id => $name) {
    $wpdb->query($wpdb->prepare("UPDATE {$r} SET name = %s WHERE id = %d", $name, $id));
}

foreach ($knotenNamenVorher as $id => $name) {
    $wpdb->query($wpdb->prepare("UPDATE {$n} SET name = %s WHERE id = %d", $name, $id));
}

$knotenZurueck = 0;

foreach ($knotenNamenVorher as $id => $name) {
    $knotenZurueck += (string) $wpdb->get_var($wpdb->prepare("SELECT name FROM {$n} WHERE id = %d", $id)) === $name ? 1 : 0;
}

check(
    'die Namen der Traegerknoten stehen wieder da',
    $knotenZurueck === count($knotenNamenVorher),
    "{$knotenZurueck} von " . count($knotenNamenVorher)
);

$zurueck = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$r} WHERE (id = %d AND name = %s) OR (id = %d AND name = %s)",
    $aussenId,
    $namenVorher[$aussenId] ?? '',
    $innenId,
    $namenVorher[$innenId] ?? ''
));

check(
    'die Namen der noch vorhandenen Kanten stehen wieder da',
    $zurueck === $vorhanden,
    "{$zurueck} von {$vorhanden}"
);

echo "\n" . ($bad === 0 ? "Alles gruen: $ok\n" : "$bad fehlgeschlagen, $ok in Ordnung\n");

exit($bad === 0 ? 0 : 1);
