<?php declare(strict_types=1);
/**
 * Der Verstecken-Schalter an der Feldzeile.
 *
 *     php scripts/dev/field-hide-check.php [path/to/wordpress]
 *
 * ⚠️ **[D-467](../../docs/NewConcept/90-decision-log.md) nannte diesen Fall als *den* Grund, `hide` an
 * die Kante zu legen** — der Eigentümer: *«wo ich es sagen würde, ist an den **Feldern** eines
 * Modellknotens, wenn ich etwas nur im Hintergrund haben will, um damit zu rechnen.»*
 *
 * ⚠️ **Und es gab ihn eine Woche lang nur als Spalte.** *Gemessen am 2026-08-30, bevor der Knopf
 * gebaut wurde: **sieben versteckte Kanten, alle sieben Vererbungskanten, kein einziges Feld.** Nicht
 * weil niemand wollte, sondern weil es nichts zu drücken gab — genau die Sorte «geschrieben und nicht
 * gebaut», die dieses Projekt schon mehrfach gefunden hat.*
 *
 * ⚠️ **Diese Prüfung hat zwei Tage lang das Falsche gemessen, und sie hat den Schaden selbst
 * angerichtet.** *Sie schaltete eine **echte** Kante seines Modells um — `Prefixes.exponent` — und
 * behauptete dabei `hide = 0` als Ausgangslage. Am 2026-09-05 um 21:46:39 hat der Eigentümer genau
 * dieses Feld im Schirm versteckt, drei Sekunden nach einem Prüflauf; das Änderungsprotokoll zeigt
 * den einzelnen «field hidden» ohne Gegenstück. **Von da an war die Prüfung rot** — und schlimmer:
 * ihr rohes Zurückschreiben (`register_shutdown_function` auf den *gelesenen* Wert) hat seinen
 * Zustand seither in jedem Lauf festgenagelt, an 25 Läufen gezählt. *Der Knopf war nie kaputt.*
 *
 * **Zwei Regeln folgen daraus, und die Prüfung hält sie jetzt beide:**
 *
 * 1. **Ein Wächter fasst das Modell des Eigentümers nicht an.** Er baut seine eigene Wiese
 *    (Präfix `__fh `) und räumt sie im `finally` weg, auch wenn eine Zusage fehlschlägt.
 * 2. **Keine Momentaufnahme seines Bestands als Zusage.** Nicht «das Feld ist sichtbar», sondern
 *    «umgeschaltet ändert sich Spalte und Zeichen, und zurück ist es wieder wie vorher».
 *
 * @see docs/NewConcept/20-interaction.md
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
// *Gemessen am 2026-09-06 liess dieser Lauf je Durchgang fünf Beschriftungen liegen.*
require __DIR__ . '/lib/no-write.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\Core\Model\Branch;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
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
$relations = new WpdbRelationRepository();
$log       = new WpdbChangelog(new SystemClock());
$framework = new SeededFrameworkNodes($nodes, $relations, $log);
$editor    = new ModelEditor($nodes, $relations, $framework, $log);

$prefix = $wpdb->prefix . 'taxmod_';

/**
 * Die Feldzeilen einer Maske, so wie der Schirm sie zeichnet.
 *
 * @return list<string> das Markup je Verstecken-Knopf
 */
$knoepfeAuf = static function (int $nodeId): array {
    $_GET['page']        = 'taxmod';
    $_GET['taxmod_node'] = (string) $nodeId;

    wp_set_current_user(1);

    $rc   = new ReflectionClass(\Taxmod\WordPress\Plugin::class);
    $ctor = $rc->getConstructor();
    $ctor->setAccessible(true);
    $plugin = $rc->newInstanceWithoutConstructor();
    $ctor->invoke($plugin, __FILE__);

    preg_match_all(
        '#<button[^>]*value="toggle_field_hide"[^>]*>.*?</button>#s',
        $plugin->screen()->render(),
        $treffer
    );

    return $treffer[0];
};

$hide = static fn (int $relationId): int => (int) $GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare(
    'SELECT hide FROM ' . Schema::table('relations') . ' WHERE id = %d',
    $relationId
));

// ── Eine eigene Wiese: ein Vater mit einem Feld, ein Kind mit einem eigenen ─
// ⚠️ *Beides wird gebraucht — der Vater liefert das **geerbte** Feld, an dem der Schalter stumm sein
// muss, das Kind das eigene, an dem er wirkt. Aus seinem Bestand genommen wäre beides eine Annahme
// über Zeilen, die er jederzeit ändern darf.*
$gebaut = [];

try {
    $typ   = $editor->createNode('__fh Typ', $framework->rootOf(Branch::DataTypes)->id);
    $gebaut[] = $typ->id;
    $vater = $editor->createNode('__fh Vater', $framework->rootOf(Branch::Model)->id);
    $gebaut[] = $vater->id;
    $kind  = $editor->createNode('__fh Kind', $vater->id);
    $gebaut[] = $kind->id;

    $editor->addField($vater->id, $typ->id, '__fh geerbt');
    $eigenes = $editor->addField($kind->id, $typ->id, '__fh eigen');

    echo "\n== 1. Der Schalter steht in jeder Feldzeile ==\n";

    $sichtbar = $knoepfeAuf($kind->id);

    check('der Schalter wird gezeichnet', $sichtbar !== [], count($sichtbar) . ' gefunden');

    $bedienbar = array_values(array_filter($sichtbar, static fn (string $b): bool => ! str_contains($b, 'disabled')));

    // ⚠️ **Nur an der eigenen Deklaration.** *Ein geerbtes Feld ist **dieselbe Kante** — es hier zu
    // verstecken hiesse, es überall zu verstecken; wer das will, sagt es dort, wo das Feld erklärt
    // ist. Die Wiese garantiert mindestens ein geerbtes, also ist die Zusage nie leer wahr.*
    check(
        'nur das eigene Feld ist bedienbar, die geerbten nicht',
        count($bedienbar) === 1 && count($sichtbar) >= 2,
        count($bedienbar) . ' bedienbar von ' . count($sichtbar)
    );

    echo "\n== 2. Er schaltet wirklich, und das Symbol dreht sich mit ==\n";

    check('ein frisch erklärtes Feld ist sichtbar', $hide($eigenes->id) === 0, (string) $hide($eigenes->id));

    // ⚠️ *Das Auge sagt, was der **Klick** tut. Ein sichtbares Feld bietet ein durchgestrichenes Auge
    // — «versteck es» — ein verstecktes ein offenes. **Ohne diesen Unterschied sähe ein verstecktes
    // Feld in der Liste aus wie ein sichtbares**, und die Liste ist der einzige Ort, an dem man es
    // zurückholen kann. Geprüft wird der **bedienbare** Knopf, nicht eine Summe über die Seite:
    // eine Zählung über das ganze Markup hängt an jedem anderen Auge, das der Schirm sonst noch zeigt.*
    check(
        'sichtbar zeigt er das durchgestrichene Auge',
        str_contains($bedienbar[0] ?? '', 'dashicons-hidden')
    );

    $editor->hideField($kind->id, $eigenes->id, true);

    check('nach dem Verstecken steht es so in der Spalte', $hide($eigenes->id) === 1);

    $versteckteKnoepfe = array_values(array_filter(
        $knoepfeAuf($kind->id),
        static fn (string $b): bool => ! str_contains($b, 'disabled')
    ));

    check(
        'das Symbol wechselt von «hidden» auf «visibility»',
        str_contains($versteckteKnoepfe[0] ?? '', 'dashicons-visibility')
            && ! str_contains($versteckteKnoepfe[0] ?? '', 'dashicons-hidden')
    );

    echo "\n== 3. Und es wird beim Zeichnen wirklich gelesen ==\n";

    // ⚠️ **Die Zusage, um die es geht.** *`Rendering` filtert versteckte Kanten aus dem Formular
    // (`array_filter(… ! $relation->hide)`). **Ohne diese Zeile wäre der Schalter ein Knopf ohne
    // Wirkung** — und genau so war es, solange er fehlte: die Spalte konnte es, niemand las sie für
    // Felder.*
    $roh = file_get_contents(dirname(__DIR__, 2) . '/src/Core/Service/Rendering.php');

    check(
        'das Zeichnen filtert versteckte Kanten heraus',
        str_contains($roh, 'static fn (Relation $relation): bool => ! $relation->hide')
    );

    $editor->hideField($kind->id, $eigenes->id, false);

    check('und zurückgeschaltet ist es wieder sichtbar', $hide($eigenes->id) === 0);
} finally {
    // ⚠️ *Im `finally`, nicht am Ende — eine Aufräumung, die an der letzten Zusage hängt, räumt genau
    // dann nicht auf, wenn es nötig wäre ([D-519](../../docs/NewConcept/90-decision-log.md)).*
    foreach (array_reverse($gebaut) as $id) {
        $kanten = array_map('intval', $wpdb->get_col(
            "SELECT id FROM {$prefix}relations WHERE from_node_id = {$id} OR to_node_id = {$id}"
        ));
        $eigner = $kanten === [] ? (string) $id : $id . ',' . implode(',', $kanten);

        $wpdb->query("DELETE FROM {$prefix}labels WHERE owner_id IN ({$eigner})");
        $wpdb->query("DELETE FROM {$prefix}changelog WHERE owner_id IN ({$eigner})");

        if ($kanten !== []) {
            $wpdb->query("DELETE FROM {$prefix}relations WHERE id IN (" . implode(',', $kanten) . ')');
        }

        $wpdb->query("DELETE FROM {$prefix}nodes WHERE id = {$id}");
    }
}

echo "\n";
check(
    'die Wiese ist wieder weg',
    (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}nodes_named WHERE name LIKE '__fh %'") === 0
);

echo "\n" . ($bad === 0 ? "Alles grün: $ok\n" : "$bad fehlgeschlagen, $ok in Ordnung\n");

exit($bad === 0 ? 0 : 1);
