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

// ⚠️ **Kein Wächter schreibt in das Modell des Eigentümers** — die Klammer dreht am Ende
// alles zurück, auch nach einem Abbruch. Siehe `lib/no-write.php` und `tests/README.md`.
require __DIR__ . '/lib/no-write.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\Core\Model\IdentitySpace;
use Taxmod\Core\Model\Label;
use Taxmod\Core\Model\SeededRole;
use Taxmod\Core\Model\SettingKey;
use Taxmod\Core\Renderer\Purpose;
use Taxmod\Core\Renderer\ShippedRenderers;
use Taxmod\Core\Service\ModelValues;
use Taxmod\WordPress\Admin\SettingsScreen;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\WpdbLabelRepository;
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
$relations     = new WpdbRelationRepository();
$framework = new SeededFrameworkNodes($nodes, $relations, new WpdbChangelog(new SystemClock()));
$records   = new WpdbRecordRepository();
$registry  = ShippedRenderers::registry();

$r = Schema::table('relations_named');
$n = Schema::table('nodes_named');

// ⚠️ **Umbenennen heisst seit TASK-019: eine Beschriftung schreiben** ([D-580](../../docs/NewConcept/90-decision-log.md),
// [D-646](../../docs/NewConcept/90-decision-log.md)). *`nodes.name` und `relations.name` gibt es
// nicht mehr; gelesen wird ueber die Sicht, geschrieben ueber die Ablage.*
$beschriftungen = new WpdbLabelRepository();

$benenne = static function (IdentitySpace $raum, int $id, string $name) use ($beschriftungen): void {
    $beschriftungen->put(new Label($id, $raum, SeededRole::Name, Label::BASE_NUMBER, SettingsScreen::neutralLocale(), $name));
};

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
    global $nodes, $relations, $framework, $records, $registry;

    $model   = new ModelValues($records, $relations, $nodes, $framework);
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
// ⚠️ *«Unter der Wurzel, aber nicht im Muell» fragt seit Fassung 35 der Speicher — der Weg ist keine
// Spalte mehr, und ein `LIKE` darauf laege still leer, was diesen Lauf gruen und blind machte
// (TASK-001).*
$beobachtet = array_values(array_diff(
    $nodes->subtreeIds($framework->root()->id),
    $nodes->subtreeIds($framework->trash()->id),
    [$framework->root()->id]
));

sort($beobachtet);

echo "\n== Die Ids stehen aufgeschrieben ==\n";

$vorher = gezeichnet($beobachtet);

$aussenId = $framework->settingRelationId(SettingKey::Renderer);
$innenId  = $framework->settingValueRelationId(SettingKey::Renderer);

// ⚠️ **Diese Zusage ist zum zweiten Mal umgezogen, und beide Umzuege stehen hier, weil der zweite
// den ersten zurücknimmt.** *Sie fragte einmal zwei aufgeschriebene Kanten-Ids ab; als der Renderer
// mit TASK-020 in `nodes.settings_record_id` zog, fragte sie die **Spalte**; und mit TASK-057 fragt
// sie wieder die **Kante** — weil der Eigentuemer berichtigt hat, was ich aus seinem Satz gemacht
// hatte: «ich meinte einfach eine Multiplizitaet von 1», am Knoten
// ([D-642](../../docs/NewConcept/90-decision-log.md)).*
//
// ⚠️ **Nicht entschaerft, umgezogen** (`PR-9`): *dieselbe Zahl, dieselbe Aussage — es gibt Traeger,
// und keiner zeigt ins Leere —, nur an der Form, die heute gilt.*
$kante = $framework->settingRelationId(SettingKey::Renderer);

$traeger = array_map(intval(...), $wpdb->get_col($wpdb->prepare(
    'SELECT DISTINCT r.node_id FROM ' . Schema::table('relation_records') . ' v'
        . ' JOIN ' . Schema::table('node_records') . ' r ON r.id = v.node_record_id'
        . " WHERE v.relation_id = %d AND v.value_ref_kind = 'record' ORDER BY r.node_id",
    $kante
)));

check('es gibt Traeger an der Einstellungskante', count($traeger) >= 4, count($traeger) . ' Knoten');

$haltlos = (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM ' . Schema::table('relation_records') . ' v'
        . ' LEFT JOIN ' . Schema::table('node_records') . ' s ON s.id = v.value_ref'
        . " WHERE v.relation_id = %d AND v.value_ref_kind = 'record' AND s.id IS NULL",
    $kante
));

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

register_shutdown_function(static function () use ($knotenNamenVorher, $benenne): void {
    foreach ($knotenNamenVorher as $id => $name) {
        $benenne(IdentitySpace::Node, (int) $id, $name);
    }
});

foreach ($knotenNamenVorher as $id => $name) {
    $benenne(IdentitySpace::Node, (int) $id, 'Etwas ganz anderes');
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
register_shutdown_function(static function () use ($namenVorher, $benenne): void {
    foreach ($namenVorher as $id => $name) {
        $benenne(IdentitySpace::Relation, (int) $id, $name);
    }
});

foreach ($namenVorher as $id => $name) {
    $benenne(IdentitySpace::Relation, (int) $id, 'Etwas ganz anderes');
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
    $benenne(IdentitySpace::Relation, (int) $id, $name);
}

foreach ($knotenNamenVorher as $id => $name) {
    $benenne(IdentitySpace::Node, (int) $id, $name);
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
