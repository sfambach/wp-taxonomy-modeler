<?php declare(strict_types=1);

/**
 * Die Version wird immer mitgeschrieben — und eine Wertaenderung erzeugt ueberhaupt eine Chronik.
 *
 * ⚠️ **Der Eigentuemer, 2026-09-05:** *«warum wird die Version nicht mitgeschrieben? Hatte ich nicht
 * grade gesagt, sie soll immer geschrieben werden.»* — [D-634](../../docs/NewConcept/90-decision-log.md),
 * TASK-056.
 *
 * ⚠️ **Gemessen, bevor es diese Pruefung gab, und beides war unsichtbar:** *`ModelEditor` meldete
 * **17 mal** und nannte die Version **kein einziges Mal** — nicht aus Streit, sondern weil die
 * Meldestelle sie als letzten Wert mit Vorgabe `null` entgegennahm. Und `DataEntry` meldete
 * **ueberhaupt nicht**: 4 354 Schattenzeilen bei Datensatzwerten gegen **null** Chronikzeilen.*
 *
 * ⚠️ **Gegen die echte Datenbank und ueber den ganzen Bestand, nicht nur ueber die eigenen Zeilen.**
 * *Eine Zusage, die nur die Zeilen prueft, die sie selbst geschrieben hat, ist gruen, sobald ein
 * anderer Weg still danebenher schreibt — genau der Zustand, den sie ersetzt.*
 *
 * ⚠️ **Die Ausnahmen stehen namentlich hier und sind Befunde, keine Bequemlichkeit** (`PR-4`):
 * *`labels` traegt gar keine Versionsspalte, und ein Sammelakt wie das Leeren des Papierkorbs
 * erzeugt keine einzelne Zeile. Kommt ein anderes Verb ohne Version dazu, wird diese Pruefung rot —
 * das ist der Sinn der Liste.*
 */

define('WP_ADMIN', true);
define('WP_USE_THEMES', false);

require 'C:/Devel/Wordpress/wp-load.php';
require 'C:/Devel/Wordpress/source/wp-taxonomy-tree/vendor/autoload.php';

wp_set_current_user(1);

use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\TypedValue;

/**
 * Verben, die ohne Version im Buch stehen duerfen — mit dem Grund, aus dem es keine gibt.
 *
 * @var array<string,string>
 */
const OHNE_VERSION = [
    'label set'      => 'labels hat keine Versionsspalte',
    'label cleared'  => 'labels hat keine Versionsspalte',
    'labels removed' => 'labels hat keine Versionsspalte',
    'trash cleared'  => 'ein Sammelakt ueber hunderte Zeilen, keine einzelne',
];

$failed = 0;

$say = static function (bool $ok, string $what) use (&$failed): void {
    printf("  %-4s %s\n", $ok ? 'ok' : 'FAIL', $what);

    if (! $ok) {
        ++$failed;
    }
};

$r      = new ReflectionClass(\Taxmod\WordPress\Plugin::class);
$plugin = $r->newInstanceWithoutConstructor();
$r->getProperty('file')->setValue($plugin, 'C:/Devel/Wordpress/source/wp-taxonomy-tree/wp-taxonomy-modeler.php');

// ⚠️ *Ueber `$plugin` und nicht ueber eigene Exemplare — geprueft werden soll die Verdrahtung. Ein
// selbst gebauter Dienst haette sein eigenes Aenderungsbuch und waere gruen.*
$editor = $plugin->editor();
$fw     = (new ReflectionMethod($plugin, 'frameworkNodes'))->invoke($plugin);
$screen = $plugin->screen();
$data   = (new ReflectionProperty($screen, 'data'))->getValue($screen);

global $wpdb;

$p = $wpdb->prefix . 'taxmod_';

$fragen = static function (string $sql) use ($wpdb): array {
    $rows = $wpdb->get_results($sql);

    if ($wpdb->last_error !== '') {
        fwrite(STDERR, "Abfrage kaputt: {$wpdb->last_error}\n");

        exit(2);
    }

    return $rows ?: [];
};

$marke = (int) $wpdb->get_var("SELECT COALESCE(MAX(id), 0) FROM {$p}changelog");

// ── 1 · Modelaenderungen ───────────────────────────────────────────────────
$modell = $fw->rootOf(Branch::Model)->id;
$typen  = $fw->rootOf(Branch::DataTypes)->id;

$knoten = $editor->createNode('__ver Knoten', $modell);
$editor->rename($knoten->id, '__ver Knoten neu');
$editor->hidePlacement($knoten->id, true);

$typ  = $editor->createNode('__ver Zahl', $typen);
$feld = $editor->addField($knoten->id, $typ->id, 'menge');
$editor->renameField($knoten->id, $feld->id, 'anzahl');

$neu = $fragen($wpdb->prepare(
    "SELECT id, owner_id, owner_kind, what, version FROM {$p}changelog WHERE id > %d ORDER BY id",
    $marke
));

echo "== Modelaenderungen schreiben ihre Version mit ==\n";

$ohne = array_values(array_filter($neu, static fn (object $z): bool => $z->version === null));

printf("       %d Zeilen geschrieben: %s\n", count($neu), implode(', ', array_map(static fn (object $z): string => $z->what, $neu)));

$say(count($neu) >= 6, sprintf('der Akt hat gemeldet (%d Zeilen)', count($neu)));
$say(
    $ohne === [],
    $ohne === []
        ? 'jede Zeile traegt eine Version'
        : sprintf('ohne Version: %s', implode(', ', array_map(static fn (object $z): string => $z->what, $ohne)))
);

// ⚠️ *Nicht nur «irgendeine Zahl»: die gemeldete Version muss die der Zeile sein, die es jetzt gibt.
// Eine Pruefung auf «nicht null» waere mit einer festverdrahteten 1 gruen.*
$jetzt = (int) $wpdb->get_var($wpdb->prepare("SELECT version FROM {$p}nodes WHERE id = %d", $knoten->id));
$letzte = $fragen($wpdb->prepare(
    "SELECT version FROM {$p}changelog WHERE owner_id = %d AND owner_kind = 'node' ORDER BY id DESC LIMIT 1",
    $knoten->id
));

$say(
    $letzte !== [] && (int) $letzte[0]->version === $jetzt,
    sprintf('die zuletzt gemeldete Version ist die des Knotens (%s gegen %d)', $letzte[0]->version ?? 'keine', $jetzt)
);

// ── 2 · Eine Wertaenderung erzeugt ueberhaupt eine Chronik ─────────────────
$marke2 = (int) $wpdb->get_var("SELECT COALESCE(MAX(id), 0) FROM {$p}changelog");

$satz = $data->create($knoten->id);

$data->put($satz->id, $feld->id, TypedValue::ofInt(7));
$data->put($satz->id, $feld->id, TypedValue::ofInt(8));
$data->clear($satz->id, $feld->id);

$wert = $fragen($wpdb->prepare(
    "SELECT owner_id, owner_kind, what, version, change_group_id FROM {$p}changelog WHERE id > %d ORDER BY id",
    $marke2
));

echo "\n== eine Wertaenderung erzeugt eine Chronikzeile ==\n";

printf("       %d Zeilen: %s\n", count($wert), implode(', ', array_map(static fn (object $z): string => $z->what, $wert)));

$verben = array_map(static fn (object $z): string => (string) $z->what, $wert);

$say(in_array('record created', $verben, true), 'das Anlegen eines Datensatzes meldet');
$say(count(array_filter($verben, static fn (string $v): bool => $v === 'value set')) === 2, 'beide Schreibvorgaenge melden');
$say(in_array('value cleared', $verben, true), 'das Leeren meldet');
$say(
    array_filter($wert, static fn (object $z): bool => $z->version === null) === [],
    'und keine dieser Zeilen steht ohne Version da'
);

// ⚠️ *Die zweite Schreibung trifft dieselbe Zeile, also muss ihre Version hoeher sein als die der
// ersten. Genau das kann eine Meldung, die die Version erfindet, nicht.*
$gesetzt = array_values(array_filter($wert, static fn (object $z): bool => $z->what === 'value set'));

$say(
    count($gesetzt) === 2 && (int) $gesetzt[1]->version > (int) $gesetzt[0]->version,
    sprintf(
        'die zweite Schreibung traegt die hoehere Version (%s dann %s)',
        $gesetzt[0]->version ?? '-',
        $gesetzt[1]->version ?? '-'
    )
);

// ── 3 · Ueber den ganzen Bestand ───────────────────────────────────────────
$luecken = $fragen(
    "SELECT what, COUNT(*) AS n FROM {$p}changelog WHERE version IS NULL GROUP BY what ORDER BY n DESC"
);

echo "\n== keine Zeile im ganzen Buch ohne Version, ausser den benannten ==\n";

$fremd = array_values(array_filter(
    $luecken,
    static fn (object $z): bool => ! array_key_exists((string) $z->what, OHNE_VERSION)
));

foreach ($luecken as $zeile) {
    printf(
        "       %-24s %5d  %s\n",
        $zeile->what,
        $zeile->n,
        OHNE_VERSION[(string) $zeile->what] ?? '⚠️ unerklaert'
    );
}

$say(
    $fremd === [],
    $fremd === []
        ? 'kein unerklaertes Verb ohne Version im Bestand'
        : sprintf('ohne Version und ohne Grund: %s', implode(', ', array_map(static fn (object $z): string => $z->what, $fremd)))
);

// ── aufraeumen ─────────────────────────────────────────────────────────────
$wpdb->query($wpdb->prepare("DELETE FROM {$p}record_values WHERE record_id = %d", $satz->id));
$wpdb->query($wpdb->prepare("DELETE FROM {$p}record_values_history WHERE record_id = %d", $satz->id));
$wpdb->query($wpdb->prepare("DELETE FROM {$p}records WHERE id = %d", $satz->id));
$wpdb->query($wpdb->prepare("DELETE FROM {$p}records_history WHERE id = %d", $satz->id));

foreach ([$feld->id, $knoten->id, $typ->id, $satz->id] as $id) {
    $wpdb->query($wpdb->prepare("DELETE FROM {$p}changelog WHERE owner_id = %d", $id));
    $wpdb->query($wpdb->prepare("DELETE FROM {$p}labels WHERE owner_id = %d", $id));
}

$wpdb->query($wpdb->prepare("DELETE FROM {$p}relations WHERE id = %d", $feld->id));
$wpdb->query($wpdb->prepare("DELETE FROM {$p}relations_history WHERE id = %d", $feld->id));

foreach ([$knoten->id, $typ->id] as $id) {
    $wpdb->query($wpdb->prepare("DELETE FROM {$p}nodes WHERE id = %d", $id));
    $wpdb->query($wpdb->prepare("DELETE FROM {$p}nodes_history WHERE id = %d", $id));
}

echo "\n";
$say((int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}nodes WHERE name LIKE '__ver %'") === 0, 'die Wiese ist wieder weg');

printf("\n%s\n", $failed === 0 ? 'all green' : sprintf('%d FEHLER', $failed));

exit($failed === 0 ? 0 : 1);
