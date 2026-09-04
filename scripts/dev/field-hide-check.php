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
 * ⚠️ *Sie schaltet ein echtes Feld um und wieder zurück — über die **Id** der Kante, samt
 * `register_shutdown_function`, damit auch ein Absturz zurückschaltet.*
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
$edges     = new WpdbRelationRepository();
$log       = new WpdbChangelog(new SystemClock());
$framework = new SeededFrameworkNodes($nodes, $edges, $log);
$editor    = new ModelEditor($nodes, $edges, $framework, $log);

// ── Ein Knoten, der ein eigenes Feld erklärt und zwei erbt ──────────────────
$prefixes = null;

foreach ($nodes->childrenOf($framework->rootOf(Branch::Constants)) as $child) {
    if ($child->name === 'Prefixes') {
        $prefixes = $child;
    }
}

if ($prefixes === null) {
    check('ein Knoten Prefixes unter Constants', false);

    echo "\n1 fehlgeschlagen\n";

    exit(1);
}

$eigenes = null;

foreach ($editor->fieldsOf($prefixes->id) as $edge) {
    if ($edge->fromId === $prefixes->id) {
        $eigenes = $edge;
    }
}

if ($eigenes === null) {
    check('Prefixes erklärt ein eigenes Feld', false);

    echo "\n1 fehlgeschlagen\n";

    exit(1);
}

$vorher = (int) $wpdb->get_var($wpdb->prepare(
    'SELECT hide FROM ' . Schema::table('relations') . ' WHERE id = %d',
    $eigenes->id
));

// ⚠️ *Roh zurückgeschrieben und nicht über den Akt — eine Aufräumung, die am geprüften Code hängt,
// räumt genau dann nicht auf, wenn es nötig wäre ([D-519](../../docs/NewConcept/90-decision-log.md)).*
register_shutdown_function(static function () use ($eigenes, $vorher): void {
    global $wpdb;

    $wpdb->update(Schema::table('relations'), ['hide' => $vorher], ['id' => $eigenes->id], ['%d'], ['%d']);
});

$seite = static function (int $nodeId): string {
    $_GET['page']        = 'taxmod';
    $_GET['taxmod_node'] = (string) $nodeId;

    wp_set_current_user(1);

    $rc   = new ReflectionClass(\Taxmod\WordPress\Plugin::class);
    $ctor = $rc->getConstructor();
    $ctor->setAccessible(true);
    $plugin = $rc->newInstanceWithoutConstructor();
    $ctor->invoke($plugin, __FILE__);

    return $plugin->screen()->render();
};

echo "\n== 1. Der Schalter steht in jeder Feldzeile ==\n";

$markup = $seite($prefixes->id);

preg_match_all('#<button[^>]*value="toggle_field_hide"[^>]*>#', $markup, $knoepfe);

check('der Schalter wird gezeichnet', $knoepfe[0] !== [], count($knoepfe[0]) . ' gefunden');

$bedienbar = array_values(array_filter($knoepfe[0], static fn (string $b): bool => ! str_contains($b, 'disabled')));

// ⚠️ **Nur an der eigenen Deklaration.** *Ein geerbtes Feld ist **dieselbe Kante** — gemessen sehen
// fünf Knoten die Kante `44093` als ihr `renderer`-Feld. Es hier zu verstecken hiesse, es überall zu
// verstecken; wer das will, sagt es dort, wo das Feld erklärt ist.*
check(
    'nur das eigene Feld ist bedienbar, die geerbten nicht',
    count($bedienbar) === 1 && count($knoepfe[0]) >= 2,
    count($bedienbar) . ' bedienbar von ' . count($knoepfe[0])
);

echo "\n== 2. Er schaltet wirklich, und das Symbol dreht sich mit ==\n";

check('das Feld ist zunächst sichtbar', $vorher === 0, (string) $vorher);

$editor->hideField($prefixes->id, $eigenes->id, true);

check(
    'nach dem Verstecken steht es so in der Spalte',
    (int) $wpdb->get_var($wpdb->prepare('SELECT hide FROM ' . Schema::table('relations') . ' WHERE id = %d', $eigenes->id)) === 1
);

$versteckt = $seite($prefixes->id);

// ⚠️ *Das Auge sagt, was der **Klick** tut. Ein verstecktes Feld bietet ein offenes Auge — «zeig es
// wieder» — und ein sichtbares ein durchgestrichenes. **Ohne diesen Unterschied sähe ein verstecktes
// Feld in der Liste aus wie ein sichtbares**, und die Liste ist der einzige Ort, an dem man es
// zurückholen kann.*
check(
    'das Symbol wechselt von «hidden» auf «visibility»',
    substr_count($versteckt, 'dashicons-visibility') > substr_count($markup, 'dashicons-visibility'),
    substr_count($markup, 'dashicons-visibility') . ' → ' . substr_count($versteckt, 'dashicons-visibility')
);

echo "\n== 3. Und es wird beim Zeichnen wirklich gelesen ==\n";

// ⚠️ **Die Zusage, um die es geht.** *`Rendering` filtert versteckte Kanten aus dem Formular
// (`array_filter(… ! $edge->hide)`). **Ohne diese Zeile wäre der Schalter ein Knopf ohne Wirkung** —
// und genau so war es, solange er fehlte: die Spalte konnte es, niemand las sie für Felder.*
$roh = file_get_contents(dirname(__DIR__, 2) . '/src/Core/Service/Rendering.php');

check(
    'das Zeichnen filtert versteckte Kanten heraus',
    str_contains($roh, 'static fn (Relation $edge): bool => ! $edge->hide')
);

$editor->hideField($prefixes->id, $eigenes->id, false);

check(
    'und zurückgeschaltet ist es wieder sichtbar',
    (int) $wpdb->get_var($wpdb->prepare('SELECT hide FROM ' . Schema::table('relations') . ' WHERE id = %d', $eigenes->id)) === 0
);

echo "\n" . ($bad === 0 ? "Alles grün: $ok\n" : "$bad fehlgeschlagen, $ok in Ordnung\n");

exit($bad === 0 ? 0 : 1);
