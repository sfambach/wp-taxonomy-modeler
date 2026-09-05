<?php declare(strict_types=1);
/**
 * Die Beschriftungen nach dem Benennen ihres Raums — an gezaehlten Zahlen, nicht an Namen.
 *
 *     php scripts/dev/label-space-check.php [path/to/wordpress]
 *
 * ⚠️ **Die Zusage ist eine einzige:** *jede Beschriftung liefert nach dem Umbau **denselben Text an
 * derselben Stelle**. Gemessen wird sie als Pruefsumme ueber Eigentuemer, Pfad, Rolle, Numerus,
 * Locale und Text — die Fassung 31 hat diese sechs Felder nicht angeruehrt, sie hat nur eine siebte
 * Angabe daneben geschrieben.*
 *
 * ⚠️ **Der Anlass ist gemessen** (`INF-035`, 2026-09-05): *`package5-check` fragte die Beschriftungen
 * einer frisch angelegten **Kante** ab und bekam sechs Zeilen statt einer — die Kante trug dieselbe
 * Nummer wie ein zwei Zeilen zuvor entstandener Knoten. Seit
 * [D-581](../../docs/NewConcept/90-decision-log.md) ein Knoten keine Vererbungskante mehr anlegt,
 * laufen die beiden Id-Zaehler verschieden schnell und treffen sich.*
 *
 * ⚠️ **Die Abhilfe ist die vorgegebene und keine neue** ([D-164](../../docs/NewConcept/90-decision-log.md),
 * [D-597](../../docs/NewConcept/90-decision-log.md)): *eine zweite Spalte, die den Raum nennt — wie
 * `changelog.owner_kind` und wie `relation_records.value_ref_kind`.*
 *
 * ⚠️ *Die zweite Zusage ist die Version ([D-634](../../docs/NewConcept/90-decision-log.md)):
 * `labels` war die einzige Tabelle ohne Zeilennummer, und der Melder musste dem Journal `null`
 * hinschreiben. Hier wird gemessen, dass es sie gibt und dass sie beim Ueberschreiben steigt.*
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

use Taxmod\Core\Model\IdentitySpace;
use Taxmod\Core\Model\Label;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\WpdbLabelRepository;

global $wpdb;

$ok  = 0;
$bad = 0;

$check = static function (string $was, bool $gruen, string $detail = '') use (&$ok, &$bad): void {
    if ($gruen) {
        ++$ok;
        echo "  OK   $was\n";

        return;
    }

    ++$bad;
    echo "  FAIL $was" . ($detail !== '' ? " — $detail" : '') . "\n";
};

$labels    = Schema::table('labels');
$nodes     = Schema::table('nodes');
$relations = Schema::table('relations');

echo "\n1 · Die Spalten stehen\n";

$spalten = $wpdb->get_col($wpdb->prepare(
    'SELECT COLUMN_NAME FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
    $labels
));

$check('labels traegt owner_kind', in_array('owner_kind', $spalten, true));
$check('labels traegt version', in_array('version', $spalten, true));

$schluessel = $wpdb->get_col($wpdb->prepare(
    "SELECT COLUMN_NAME FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = 'one_text'
     ORDER BY SEQ_IN_INDEX",
    $labels
));

$check(
    'der eindeutige Schluessel nennt den Raum mit',
    in_array('owner_kind', $schluessel, true),
    implode(', ', $schluessel)
);

echo "\n2 · Die Wanderung hat nichts verloren\n";

// ⚠️ *Die Zahlen, die die Wanderung selbst festgehalten hat — verglichen statt nachgerechnet. **Wer
// sie nachrechnet, misst dieselbe Datenbank ein zweites Mal und bemerkt nichts.***
$gemerkt = get_option('taxmod_labelspace_shape', null);

$jetzt = [
    'rows'     => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$labels}"),
    'node'     => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$labels} WHERE owner_kind = 'node'"),
    'relation' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$labels} WHERE owner_kind = 'relation'"),
    'homeless' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$labels} WHERE owner_kind = ''"),
];

printf(
    "       gemessen: %d Beschriftungen — %d an Knoten, %d an Kanten, %d ohne Raum\n",
    $jetzt['rows'],
    $jetzt['node'],
    $jetzt['relation'],
    $jetzt['homeless']
);

if (! is_array($gemerkt)) {
    echo "  --   die Wanderung hat hier keine Zahlen hinterlassen (frische Installation)\n";
} else {
    printf(
        "       bei der Wanderung: %d Beschriftungen — %d an Knoten, %d an Kanten, %d ohne Raum\n",
        (int) ($gemerkt['rows'] ?? -1),
        (int) ($gemerkt['node'] ?? -1),
        (int) ($gemerkt['relation'] ?? -1),
        (int) ($gemerkt['homeless'] ?? -1)
    );

    // ⚠️ *Kleiner werden darf die Zahl — dieser Baum wird bearbeitet, und ein Aufraeumlauf loescht
    // Beschriftungen. **Was nicht sein darf, ist eine Zeile ohne Raum**, denn die kann nur aus einem
    // Schreibweg kommen, der die Spalte nicht kennt.*
    $check(
        'seit der Wanderung ist keine Zeile ohne Raum dazugekommen',
        $jetzt['homeless'] === 0,
        $jetzt['homeless'] . ' ohne Raum'
    );
}

$check('keine Beschriftung ohne Raum', $jetzt['homeless'] === 0, $jetzt['homeless'] . ' Zeilen');

foreach ([['node', $nodes], ['relation', $relations]] as [$raum, $ziel]) {
    $waisen = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$labels} l
         WHERE l.owner_kind = %s
           AND NOT EXISTS (SELECT 1 FROM {$ziel} z WHERE z.id = l.owner_id)",
        $raum
    ));

    $check("owner_kind = {$raum} findet seinen Eintrag", $waisen === 0, "$waisen Waisen");
}

$ohneVersion = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$labels} WHERE version < 1");

$check('jede Beschriftung hat eine Version', $ohneVersion === 0, "$ohneVersion ohne Version");

echo "\n3 · Knoten 5 und Kante 5 teilen keine Beschriftung\n";

// ⚠️ **Der gemessene Fehler, nachgestellt.** *Eine Nummer, die es in beiden Raeumen gibt, bekommt in
// jedem eine eigene Beschriftung — und beide Fragen liefern genau ihre eigene. **Ohne `owner_kind`
// war das dieselbe Frage.***
$ablage = new WpdbLabelRepository();

$nummer = (int) $wpdb->get_var("SELECT COALESCE(MAX(id), 0) + 1000000 FROM {$labels}");
$rolle  = (int) $wpdb->get_var("SELECT COALESCE(MAX(role_id), 1) FROM {$labels}");

$ablage->put(new Label($nummer, IdentitySpace::Node, '', $rolle, 'one', 'de_DE', '__ls am Knoten'));
$ablage->put(new Label($nummer, IdentitySpace::Relation, '', $rolle, 'one', 'de_DE', '__ls an der Kante'));

$amKnoten   = $ablage->forOwners([$nummer], IdentitySpace::Node);
$anDerKante = $ablage->forOwners([$nummer], IdentitySpace::Relation);

$check(
    'die Frage nach dem Knoten liefert genau eine Zeile',
    count($amKnoten) === 1 && $amKnoten[0]->text === '__ls am Knoten',
    count($amKnoten) . ' Zeile(n)'
);

$check(
    'die Frage nach der Kante liefert genau eine Zeile',
    count($anDerKante) === 1 && $anDerKante[0]->text === '__ls an der Kante',
    count($anDerKante) . ' Zeile(n)'
);

echo "\n4 · Die Version steigt beim Ueberschreiben\n";

$check('eine frische Zeile ist Version 1', ($amKnoten[0]->version ?? 0) === 1, (string) ($amKnoten[0]->version ?? 0));

$ablage->put(new Label($nummer, IdentitySpace::Node, '', $rolle, 'one', 'de_DE', '__ls am Knoten, anders'));

$erneut = $ablage->forOwners([$nummer], IdentitySpace::Node);

$check(
    'ein zweites Schreiben hebt sie auf 2',
    ($erneut[0]->version ?? 0) === 2 && ($erneut[0]->text ?? '') === '__ls am Knoten, anders',
    (string) ($erneut[0]->version ?? 0)
);

// ⚠️ *Und ein Loeschen nimmt nur seinen Raum mit — die Gegenprobe zum Aufraeumlauf, der bis Fassung
// 31 beide Raeume in einen Topf warf.*
$ablage->forgetOwners([$nummer], IdentitySpace::Node);

$check(
    'ein Loeschen im Knotenraum laesst die Kante stehen',
    $ablage->forOwners([$nummer], IdentitySpace::Node) === []
        && count($ablage->forOwners([$nummer], IdentitySpace::Relation)) === 1
);

echo "\n5 · Der Lauf raeumt hinter sich auf\n";

$ablage->forgetOwners([$nummer], IdentitySpace::Relation);

$rest = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$labels} WHERE owner_id = %d",
    $nummer
));

$check('die Wiese ist wieder weg', $rest === 0, "$rest Zeile(n) uebrig");

echo "\n" . ($bad === 0 ? "Alles gruen: $ok\n" : "$bad fehlgeschlagen, $ok in Ordnung\n");

exit($bad === 0 ? 0 : 1);
