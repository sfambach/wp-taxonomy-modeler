<?php declare(strict_types=1);

/**
 * Wandert ein Knoten samt allem darunter den Weg **ueber die Maske** an eine andere Stelle?
 *
 * TASK-055. **Sein Befund:** *«schau warum ich knoten nicht verschieben kann — wollte `stree/…` und
 * `zip/…` nach `combined` verschieben».*
 *
 * ⚠️ **Der Akt war da, und kein Waechter ging je seinen Weg.** *`package2-check` prueft den Kern
 * (`ModelEditor::move()`), niemand prueft die Maske: ob die Seite ueberhaupt ein Steuerelement
 * `target` zeichnet, ob es im selben Formular wie der Knopf steht, ob das Ziel darunter ist und ob
 * nach dem Abschicken wirklich etwas dasteht. **Genau dazwischen lag es** — der Dialog bot nur die
 * aufgeklappten Zeilen an, also war das gewuenschte Ziel gar nicht waehlbar.*
 *
 * ⚠️ **Und der Nachwuchs wird mitgemessen.** *«Verschoben» heisst: der Knoten **und alles darunter**.
 * Ein Kind, dessen Pfad stehenbleibt, haengt am neuen Ort in der Kante und am alten im Pfad — und
 * das faellt erst Wochen spaeter auf.*
 *
 * ⚠️ **Umkehrbar oder gar nicht:** eine Schattenzeile fuer die neue Fassung des Knotens und eine
 * Zeile im Aenderungsbuch mit Vorher und Nachher. *Ohne die beiden waere das Verschieben ein Akt,
 * den niemand zuruecknehmen kann.*
 *
 * ⚠️ **Eigene Knoten, kein Name aus seinem Modell** ([D-613](../../docs/NewConcept/90-decision-log.md),
 * [D-614](../../docs/NewConcept/90-decision-log.md)): angelegt, geprueft, weggeraeumt — nach dem
 * eigenen Namensmuster `__mv `, nie ueber `clearTrash()`.
 *
 * Usage: php scripts/dev/move-mask-check.php C:/Devel/Wordpress
 *
 * @see docs/pakete/modelltabellen/tasks.md
 */

$wordpress = $argv[1] ?? 'C:/Devel/Wordpress';

define('WP_USE_THEMES', false);

require rtrim($wordpress, '/') . '/wp-load.php';

// ⚠️ **Kein Wächter schreibt in das Modell des Eigentümers** — die Klammer dreht am Ende
// alles zurück, auch nach einem Abbruch. Siehe `lib/no-write.php` und `tests/README.md`.
require __DIR__ . '/lib/no-write.php';
require __DIR__ . '/../../vendor/autoload.php';

use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbLabelRepository;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRecordRepository;
use Taxmod\WordPress\Persistence\WpdbRelationRepository;
use Taxmod\Core\Model\Branch;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\WordPress\Plugin;
use Taxmod\WordPress\SystemClock;

// ⚠️ *Seit TASK-019 traegt jeder Knoten eine Beschriftungszeile ([D-580](../../docs/NewConcept/90-decision-log.md)) —
// und dieser Lauf raeumt Knoten mit rohem SQL weg, also am Ende hinter sich her. **Es faellt nur,
// worauf weder ein Knoten noch eine Kante zeigt.***
register_shutdown_function(static fn (): int => \Taxmod\WordPress\Persistence\Schema::forgetOrphanLabels());

$passed = 0;
$failed = 0;

function check(string $what, bool $ok, string $detail = ''): void
{
    global $passed, $failed;

    if ($ok) {
        $passed++;

        echo "  ok   {$what}\n";

        return;
    }

    $failed++;

    echo "  FAIL {$what}" . ($detail === '' ? '' : " — {$detail}") . "\n";
}

global $wpdb;

$p = $wpdb->prefix . 'taxmod_';

$log       = new WpdbChangelog(new SystemClock());
$nodes     = new WpdbNodeRepository();
$relations     = new WpdbRelationRepository();
$framework = new SeededFrameworkNodes($nodes, $relations, $log);
$editor    = new ModelEditor($nodes, $relations, $framework, $log, new WpdbLabelRepository(), new WpdbRecordRepository());

/** Die Seite als Markup, so wie ein Browser sie bekommt. */
function seite(int $nodeId): string
{
    $_GET['page']        = 'taxmod-nodes';
    $_GET['taxmod_node'] = (string) $nodeId;

    $rc     = new ReflectionClass(Plugin::class);
    $plugin = $rc->newInstanceWithoutConstructor();
    $rc->getProperty('file')->setValue($plugin, __FILE__);

    return $plugin->screen()->render();
}

/**
 * Den Akt abschicken, wie das Seitenformular ihn abschickt — und die Weiterleitung abfangen.
 *
 * ⚠️ *`$_REQUEST` mit, weil `check_admin_referer()` dort nachsieht und nicht in `$_POST`.*
 *
 * @param array<string, mixed> $post
 */
function abschicken(array $post): bool
{
    global $letzteMeldung;

    $_POST    = $post;
    $_REQUEST = $post;

    $gewandert     = false;
    $letzteMeldung = '';

    $fang = static function ($ort) use (&$gewandert): string {
        global $letzteMeldung;

        $gewandert = true;

        $abfrage = [];
        parse_str((string) parse_url((string) $ort, PHP_URL_QUERY), $abfrage);

        // ⚠️ *Der Rand reicht den Grund in der Adresse weiter und {@see NodesScreen::notice()} zeichnet
        // ihn. Ohne diese Zeile misst der Waechter nur, **dass** weitergeleitet wurde — und genau das
        // war der blinde Fleck: «der Dialog geht zu und nichts passiert» ist auch dann falsch, wenn
        // die Verweigerung richtig ist.*
        $letzteMeldung = rawurldecode((string) ($abfrage['taxmod_message'] ?? ''));

        throw new RuntimeException('redirect');
    };

    add_filter('wp_redirect', $fang, 1);

    $rc     = new ReflectionClass(Plugin::class);
    $plugin = $rc->newInstanceWithoutConstructor();
    $rc->getProperty('file')->setValue($plugin, __FILE__);

    try {
        $plugin->screen()->handlePost();
    } catch (RuntimeException) {
        // ⚠️ *Erwartet: der Akt ist durch und wollte weiterleiten.*
    } finally {
        remove_filter('wp_redirect', $fang, 1);

        $_POST    = [];
        $_REQUEST = [];
    }

    return $gewandert;
}

wp_set_current_user(1);

echo "\n== eigene Knoten: zwei Elternknoten, einer wandert mit seinem Kind ==\n";

$wurzel = $framework->rootOf(Branch::Model);

$von     = $editor->createNode('__mv von', $wurzel->id);
$nach    = $editor->createNode('__mv nach', $wurzel->id);
$wandrer = $editor->createNode('__mv wandrer', $von->id);
$kind    = $editor->createNode('__mv kind', $wandrer->id);

check('der Ausgangsort ist der erwartete', $wandrer->parentId() === $von->id, (string) $wandrer->parentId());

echo "\n== was die Maske anbietet ==\n";

$markup = seite($wandrer->id);

check(
    'die Seite zeichnet einen Knopf, der verschiebt',
    (bool) preg_match('/<button[^>]*name="do"[^>]*value="move"/', $markup),
    'kein Knopf mit do=move im Markup'
);

check(
    'sie zeichnet Steuerelemente fuer das Ziel',
    (bool) preg_match('/name="target" value="\d+"/', $markup),
    'kein Steuerelement namens target'
);

// ⚠️ **Das Ziel muss **waehlbar** sein, nicht nur irgendein Ziel.** *Genau hier hing sein Fall: der
// Dialog zeichnete nur die aufgeklappten Zeilen, und ein geschlossener Ast war deshalb kein
// moegliches Ziel — der Akt funktionierte, das Ziel war nur nicht anzukommen.*
check(
    'der Zielknoten steht als waehlbare Zeile darunter',
    str_contains($markup, 'name="target" value="' . $nach->id . '"'),
    'kein target-Steuerelement fuer ' . $nach->id
);

// ⚠️ *Ein Steuerelement ausserhalb des Formulars schickt lautlos nichts mit — dann kaeme `target=0`
// an und der Akt liefe ins Leere, ohne dass irgendetwas danach aussaehe.*
$vorDemKnopf   = substr($markup, 0, (int) strpos($markup, 'value="move"'));
$formularAuf   = substr_count($vorDemKnopf, '<form');
$formularZu    = substr_count($vorDemKnopf, '</form>');
$vorDemZiel    = substr($markup, 0, (int) strpos($markup, 'name="target" value="' . $nach->id . '"'));

check(
    'Ziel und Knopf stecken im selben Formular',
    $formularAuf - $formularZu === 1
        && substr_count($vorDemZiel, '<form') - substr_count($vorDemZiel, '</form>') === 1,
    'Formulartiefe am Knopf ' . ($formularAuf - $formularZu)
);

echo "\n== abschicken, und nachsehen wo alles steht ==\n";

$vorher   = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}nodes_history WHERE id = {$wandrer->id}");
$buchZuvor = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}changelog WHERE owner_id = {$wandrer->id}");

$gewandert = abschicken([
    'do'            => 'move',
    'id'            => (string) $wandrer->id,
    'target'        => (string) $nach->id,
    '_taxmod_nonce' => wp_create_nonce('taxmod_node_' . $wandrer->id),
]);

check('der Akt ist durchgelaufen', $gewandert);

$frisch = new WpdbNodeRepository();
$neuer  = $frisch->find($wandrer->id);
$neuesKind = $frisch->find($kind->id);
$zielFrisch = $frisch->find($nach->id);

check(
    'der Knoten haengt jetzt unter dem Ziel',
    $neuer !== null && $neuer->parentId() === $nach->id,
    'Elternknoten ist ' . (string) ($neuer?->parentId() ?? 0)
);

check(
    'sein Pfad sagt dasselbe wie seine Kante',
    $neuer !== null && $zielFrisch !== null && $neuer->path === $zielFrisch->path . '.' . $neuer->id,
    (string) ($neuer?->path ?? '')
);

// ⚠️ **Alles darunter geht mit** — die Zusage, ohne die «verschoben» nur die halbe Wahrheit ist.
check(
    'das Kind ist mitgewandert',
    $neuesKind !== null && $neuer !== null && str_starts_with($neuesKind->path, $neuer->path . '.'),
    (string) ($neuesKind?->path ?? '')
);

echo "\n== umkehrbar: Schatten und Aenderungsbuch ==\n";

check(
    'die alte Fassung steht im Schatten',
    (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}nodes_history WHERE id = {$wandrer->id}") > $vorher,
    'keine neue Zeile in nodes_history'
);

$buch = $wpdb->get_row(
    "SELECT what, before_state, after_state FROM {$p}changelog"
    . " WHERE owner_id = {$wandrer->id} AND owner_kind = 'node' ORDER BY id DESC LIMIT 1",
    ARRAY_A
);

check('das Aenderungsbuch hat eine Zeile dazubekommen',
    (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}changelog WHERE owner_id = {$wandrer->id}") > $buchZuvor);

check(
    'sie heisst «moved» und nennt Vorher und Nachher',
    ($buch['what'] ?? '') === 'moved'
        && str_contains((string) ($buch['before_state'] ?? ''), $von->path)
        && str_contains((string) ($buch['after_state'] ?? ''), $nach->path),
    (string) ($buch['what'] ?? 'keine Zeile')
);

echo "\n== und die Maske zeigt danach den neuen Ort ==\n";

$markup = seite($wandrer->id);

check(
    'der neue Elternknoten ist im Dialog vorgewaehlt',
    str_contains($markup, 'name="target" value="' . $nach->id . '" checked'),
    'nicht als gewaehlt markiert'
);

try {
    // ⚠️ **Der Weg, den er wirklich gegangen ist** — TASK-065 angehaengt, sein Befund vom 2026-09-06:
    // *«man klick den combined knoten an und der dialog geht zu aber nichts passiert auch speichern
    // hilft nicht».*
    //
    // ⚠️ **Gemessen, und es lag weder am Akt noch am Angebot.** *Der Akt lief (oben), und `Combined`
    // stand als waehlbare Zeile im Markup. Was fehlte, war der **Knopf**: das Skript schloss den
    // Dialog, sobald ein Knoten gewaehlt wurde (TASK-028), und der Bestaetigungsknopf des
    // Verschiebedialogs steht **innen** — also nahm das Zuklappen dem Benutzer genau die Stelle weg,
    // an der er haette abschicken muessen. **Ein Dialog mit eigenem Knopf darf sich beim Waehlen
    // nicht schliessen; einer ohne muss es.**
    echo "\n== waehlen darf den Knopf nicht wegnehmen ==\n";

    $markup = seite($wandrer->id);

    // ⚠️ *Nicht der erste Fuss der Seite — seit D-730 (2026-09-12) hat auch das «+» einen, und es steht vor dem Verschieben.
    // Gesucht ist der Fuss, der den Knopf `move` traegt.*
    $fussAuf = false;

    foreach (array_keys(iterator_to_array(new \ArrayIterator(explode('taxmod-dialog-foot', $markup)))) as $i) {
        $stueck = explode('taxmod-dialog-foot', $markup)[$i];

        if ($i > 0 && str_contains(substr($stueck, 0, 400), 'value="move"')) {
            $fussAuf = true;

            break;
        }
    }

    check(
        'der Verschiebedialog traegt seinen Knopf innen',
        $fussAuf === true,
        'kein taxmod-dialog-foot mit do=move'
    );

    $skript = (string) file_get_contents(__DIR__ . '/../../assets/admin.js');

    check(
        'das Skript schliesst einen Dialog mit Fuss nicht',
        (bool) preg_match(
            '/taxmod-dialog-foot.*?if \( fuss \).*?return;/s',
            $skript
        ),
        'admin.js schliesst weiterhin jeden Waehldialog'
    );

    // ⚠️ **Geändert am 2026-09-15 mit [D-804](../../docs/NewConcept/90-decision-log.md), sichtbar (`PR-9`):** *hier stand die
    // Gegenprobe «der Anlege-Dialog hat **keinen** Knopf innen und schliesst beim Waehlen». D-804 gibt **jedem** Dialog
    // «OK» und «Abbrechen» — «this is a general rule for all dialogs». Die Zusage lautet jetzt: jeder Dialog hat seinen Fuss.*
    check(
        'jeder Dialog hat seinen Fuss, auch der Anlege-Dialog (D-804)',
        str_contains($markup, 'name="field_target"')
            && substr_count($markup, 'taxmod-dialog-foot') === substr_count($markup, 'taxmod-dialog-panel'),
        substr_count($markup, 'taxmod-dialog-foot') . ' Fuesse an '
            . substr_count($markup, 'taxmod-dialog-panel') . ' Dialogen'
    );

    echo "\n== und wo nichts geschieht, steht ein Grund auf dem Schirm ==\n";

    global $letzteMeldung;

    abschicken([
        'do'            => 'move',
        'id'            => (string) $wandrer->id,
        'target'        => '0',
        '_taxmod_nonce' => wp_create_nonce('taxmod_node_' . $wandrer->id),
    ]);

    $ohneZiel = (string) $letzteMeldung;

    check(
        'ohne gewaehltes Ziel kommt ein Satz zurueck und kein «ok»',
        $ohneZiel !== '' && $ohneZiel !== 'ok',
        'Meldung war «' . $ohneZiel . '»'
    );

    // ⚠️ *Und er steht **auf dem Schirm**, nicht nur in der Adresse — die Adresse ist der Weg, der
    // Toast ist die Zusage.*
    // ⚠️ *Roh und nicht kodiert: PHP legt `$_GET` schon entschluesselt ab, und der Rand liest dort.*
    $_GET['taxmod_message'] = $ohneZiel;
    $mitMeldung             = seite($wandrer->id);
    unset($_GET['taxmod_message']);

    check(
        'die Seite zeichnet ihn als Hinweis',
        str_contains($mitMeldung, 'taxmod-toast-bad')
            && str_contains($mitMeldung, esc_html(sanitize_text_field($ohneZiel))),
        'kein taxmod-toast-bad mit dem Satz'
    );

    // ⚠️ *Dasselbe fuer die Verweigerung des Kerns: ein Zug in den eigenen Nachwuchs ist zu Recht
    // verboten, und «nichts passiert» waere auch hier die falsche Antwort.*
    abschicken([
        'do'            => 'move',
        'id'            => (string) $wandrer->id,
        'target'        => (string) $kind->id,
        '_taxmod_nonce' => wp_create_nonce('taxmod_node_' . $wandrer->id),
    ]);

    check(
        'ein Zug in den eigenen Nachwuchs nennt seinen Grund',
        $letzteMeldung !== '' && $letzteMeldung !== 'ok',
        'Meldung war «' . (string) $letzteMeldung . '»'
    );
} finally {

echo "\n== aufraeumen ==\n";

// ⚠️ *Nach dem eigenen Namensmuster und nie ueber `clearTrash()` — dort liegt seine geparkte Arbeit.
// Nach Namen und nicht nur nach den Ids dieses Laufs: ein abgestuerzter Lauf laesst sonst Reste
// stehen, die der naechste als eigenen Fehlschlag meldet.*
$meine = array_map('intval', $wpdb->get_col("SELECT id FROM {$p}nodes_named WHERE name LIKE '\\_\\_mv %'"));
$in    = $meine === [] ? '0' : implode(',', $meine);

$wpdb->query("DELETE FROM {$p}relation_records WHERE node_record_id IN (SELECT id FROM {$p}node_records WHERE node_id IN ({$in}))");
$wpdb->query("DELETE FROM {$p}node_records WHERE node_id IN ({$in})");
$wpdb->query("DELETE FROM {$p}labels WHERE owner_id IN ({$in})");
$wpdb->query("DELETE FROM {$p}relations WHERE from_node_id IN ({$in}) OR to_node_id IN ({$in})");
$wpdb->query("DELETE FROM {$p}nodes WHERE id IN ({$in})");
$wpdb->query("DELETE FROM {$p}nodes_history WHERE id IN ({$in})");
$wpdb->query("DELETE FROM {$p}changelog WHERE owner_id IN ({$in}) AND owner_kind = 'node'");

check(
    'der Waechter laesst nichts zurueck',
    (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}nodes_named WHERE name LIKE '\\_\\_mv %'") === 0
);

}

printf("\n%d ok, %d failed\n", $passed, $failed);

exit($failed === 0 ? 0 : 1);
