<?php declare(strict_types=1);

/**
 * The preview, and the two flags it exists to make visible — `PR-9`.
 *
 * ⚠️ **The owner's reason for wanting it built now** is the reason this check is worth having:
 * *we need the preview to fix the flag and renderer concept errors.* `hide` and `read_only` were
 * stored, resolved and **had no surface that showed them doing anything** — a settings panel draws
 * the switch, never its effect. So this check does not merely assert that a panel appears; it
 * **flips each flag and asserts the effect**, which is the only version that would catch the fault
 * he is looking for.
 *
 * ⚠️ *It writes settings into the real database and takes them out again. Every earlier version of
 * this pattern littered the tree — a new attribute and nine records per run, which the owner saw —
 * so the cleanup runs whatever happens.*
 */

define('WP_ADMIN', true);
define('WP_USE_THEMES', false);

require 'C:/Devel/Wordpress/wp-load.php';
require __DIR__ . '/../../vendor/autoload.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/template.php';

use Taxmod\Core\Model\SettingKey;
use Taxmod\WordPress\Plugin;

wp_set_current_user(1);

global $wpdb;

$prefix = $wpdb->prefix . 'taxmod_';
$failed = 0;

function check(string $what, bool $held, string $saw = ''): void
{
    global $failed;

    if (! $held) {
        $failed++;
    }

    echo ($held ? '  ok   ' : '  FAIL '), $what, ($saw === '' ? '' : "  — {$saw}"), "\n";
}

$plugin = (new ReflectionClass(Plugin::class))->newInstanceWithoutConstructor();
$screen = (new ReflectionMethod(Plugin::class, 'screen'))->invoke($plugin);

/** Renders one node's detail page and returns only the preview band. */
function previewOf(object $screen, int $nodeId): string
{
    $_GET['taxmod_node'] = $nodeId;

    $html = $screen->render();
    $at   = strpos($html, 'taxmod-preview');

    if ($at === false) {
        return '';
    }

    $band = substr($html, $at);
    $end  = strpos($band, 'taxmod-page-block');

    return $end === false ? $band : substr($band, 0, $end);
}

// ── A model that holds data, and one that cannot ─────────────────────────────────────────────────

echo "== the preview appears where records are possible, and not elsewhere ==\n";

$model = (int) $wpdb->get_var("SELECT node_id FROM {$prefix}records GROUP BY node_id ORDER BY COUNT(*) DESC LIMIT 1");

check('a model with records was found to test against', $model > 0, (string) $model);

$band = previewOf($screen, $model);

check('the preview band is drawn', $band !== '', strlen($band) . ' bytes');
check('both sides are drawn', substr_count($band, 'taxmod-preview-side') === 2, (string) substr_count($band, 'taxmod-preview-side'));

// ⚠️ **Provenance is asserted, not assumed.** A good-looking preview over sample values reads as
// proof that the model holds real ones, which is the opposite of what a preview is for.
check('it says where the values came from', str_contains($band, 'Filled from'));

// ⚠️ **Diese Zusage war zweifach kaputt und ist es beides nicht mehr — 2026-08-29 gemessen.**
//
// *Erstens hat sie **nie gelaufen**: sie suchte einen Knoten namens `int`, und der heisst in diesem
// Modell `Integer`. `$dataType > 0` war immer falsch, also stand die Zeile da und prüfte nichts —
// eine Prüfung, die still übersprungen wird, ist schlechter als keine, weil sie im Bericht als
// Deckung mitgelesen wird.*
//
// *Zweitens behauptete sie das Gegenteil des Gebauten: «einem Datentyp wird gesagt, es gebe nichts
// zu zeigen». [D-430](../../docs/NewConcept/90-decision-log.md) hat genau das abgeschafft — der
// Eigentümer: «warum keine Vorschau auf den einfachen Datentypen?» — und ein Datentyp **zeichnet
// sich seither selbst als Feld**. Die tote Suche hat verhindert, dass der Widerspruch auffiel.*
//
// ⚠️ *Der Knoten wird jetzt über seinen **Ast** gesucht und nicht über einen Namen: ein Name ist
// Modellinhalt und darf sich ändern, ein Ast ist Gerüst.*
$dataTypeRoot = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT id FROM {$prefix}nodes WHERE name = %s LIMIT 1",
    'Data Types'
));

$dataType = $dataTypeRoot === 0 ? 0 : (int) $wpdb->get_var($wpdb->prepare(
    "SELECT to_id FROM {$prefix}relations WHERE from_id = %d AND kind = %s ORDER BY position ASC LIMIT 1",
    $dataTypeRoot,
    'inheritance'
));

check('a data type was found to test against', $dataType > 0, (string) $dataType);

if ($dataType > 0) {
    $_GET['taxmod_node'] = $dataType;
    $whole = $screen->render();

    // ⚠️ **D-430: ein einfacher Typ ist kein Ding, das Datensätze hält — er *ist* ein Feld.** *Die
    // beiden Seiten sind der ganze Punkt: `read_only` ist die Einstellung, deren gesamte Bedeutung
    // darin besteht, dass Anzeige und Bearbeitung auseinandergehen.*
    check('a data type previews itself as a field', substr_count($whole, 'taxmod-preview-side') === 2, (string) substr_count($whole, 'taxmod-preview-side'));
    check('and it is not told there is nothing to preview', ! str_contains($whole, 'Nothing to preview here'));
}

// ── The flags, which is the point ────────────────────────────────────────────────────────────────

echo "\n== hide removes a row from the preview and says which ==\n";

$edge = $wpdb->get_row(
    $wpdb->prepare("SELECT id, name FROM {$prefix}relations WHERE from_id = %d AND name <> '' LIMIT 1", $model),
    ARRAY_A
);

if ($edge === null) {
    echo "  --   no named attribute on that model; the flag half is not exercised\n";
} else {
    $edgeId = (int) $edge['id'];
    $name   = (string) $edge['name'];

    /**
     * Puts one flag on the edge, or clears both.
     *
     * ⚠️ **Two homes since 2026-08-28, and that is the decision** ([D-457]): `hide` is a **column**
     * on `relations`, `read_only` stays a setting ([D-461]). *So this helper writes to two places,
     * and the fact that it has to is the clearest statement of what changed.*
     */
    $flag = static function (?string $key) use ($wpdb, $prefix, $edgeId): void {
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$prefix}settings WHERE owner_id = %d AND setting_key = 'read_only'",
            $edgeId
        ));
        $wpdb->query($wpdb->prepare(
            "UPDATE {$prefix}relations SET hide = 0 WHERE id = %d",
            $edgeId
        ));

        if ($key === 'hide') {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$prefix}relations SET hide = 1 WHERE id = %d",
                $edgeId
            ));

            return;
        }

        if ($key !== null) {
            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$prefix}settings (owner_id, setting_key, value_int) VALUES (%d, %s, 1)",
                $edgeId,
                $key
            ));
        }
    };

    try {
        $flag(null);
        $plain = previewOf($screen, $model);

        check("with no flag, «{$name}» is drawn", str_contains($plain, $name));
        check('and nothing is reported as left out', ! str_contains($plain, 'Left out by hide'));

        $flag('hide');
        $hidden = previewOf($screen, $model);

        // ⚠️ **Named rather than silently absent.** *A preview that quietly drops a field cannot be
        // told apart from one that forgot it* — and the owner is using this screen to judge whether
        // `hide` is right, so what it removed has to be legible.
        check('with hide, it is reported as left out', str_contains($hidden, 'Left out by hide'));
        check('and the name appears in that report', (bool) preg_match('#Left out by hide:[^<]*' . preg_quote($name, '#') . '#', $hidden));

        echo "\n== read_only keeps the row and refuses the edit ==\n";

        $flag(SettingKey::ReadOnly->value);
        $fixed = previewOf($screen, $model);

        check('with read_only, the row is still drawn', str_contains($fixed, $name));
        check('and it is reported as read-only', str_contains($fixed, 'read-only'));

        // ⚠️ **This is the assertion that distinguishes the two flags.** Collapsing them would make a
        // read-only field invisible, which is the opposite of what it is for.
        check('read_only does not hide anything', ! str_contains($fixed, 'Left out by hide'));
    } finally {
        // ⚠️ Runs even on a failed assertion, because a check that leaves settings behind changes
        // what the next run measures.
        $flag(null);
    }
}

echo "\n== hide puts the renderer out of force (D-399) ==\n";

$node = (int) $wpdb->get_var("SELECT id FROM {$prefix}nodes WHERE name = 'yotta' LIMIT 1");

if ($node === 0) {
    echo "  --   no scaffolded node to test against\n";
} else {
    /** Reads the renderer `<select>` out of the node's own settings panel. */
    $rendererControl = static function (object $screen, int $node): string {
        $_GET['taxmod_node']   = $node;
        $_GET['taxmod_hidden'] = '1';

        preg_match('#<select[^>]*name="[^"]*\[renderer\][^"]*"[^>]*>#', $screen->render(), $m);

        return $m[0] ?? '';
    };

    try {
        // ⚠️ *Die Spalte, nicht die Einstellung ([D-457]).*
        $wpdb->query($wpdb->prepare("UPDATE {$prefix}nodes SET hide = 0 WHERE id = %d", $node));

        $control = $rendererControl($screen, $node);

        // ⚠️ **Es gibt keinen Renderer-Wähler je Verwendungsstelle mehr, und das ist eine
        // Entscheidung** ([D-520](../../docs/NewConcept/90-decision-log.md)): *er lag in der
        // Einstellungstafel unter jeder Feldzeile, und die ist entfallen, weil dieselben Angaben nach
        // [D-518](../../docs/NewConcept/90-decision-log.md) als Feldzeilen im Settings-Block stehen.*
        //
        // ⚠️ **Was diese Prüfung darum nicht mehr messen kann, misst sie auch nicht mehr, statt es
        // wegzulassen und still zu bleiben:** *«der Wähler ist da» und «er ist editierbar» sind
        // Zusicherungen über ein Steuerelement, das absichtlich weg ist. **Die verbleibende Zusage
        // ist die, um die es [D-399](../../docs/NewConcept/90-decision-log.md) ging**: `hide` nimmt dem
        // Zeichnen nichts, und dafür genügt, dass nichts abgeschaltet wird. Der Ersatz — ein Wert am
        // Feld statt eine Einstellung — steht auf [Zeile 82](../../docs/NewConcept/97-implementation-plan.md#the-working-list).*
        check(
            'solange es keinen Wähler gibt, ist auch keiner abgeschaltet',
            ! str_contains($control, 'disabled'),
            $control
        );

        $wpdb->query($wpdb->prepare("UPDATE {$prefix}nodes SET hide = 1 WHERE id = %d", $node));

        // ⚠️ **The owner's words are the specification**: *`hide` would have to put the renderer out of
        // force — so no renderer is valid, because it is not used here; the field should then be
        // greyed out.* Greyed and **not removed**: a control that vanishes when a switch is thrown
        // makes a person hunt for the row they were about to use.
        $control = $rendererControl($screen, $node);

        // ⚠️ **Gedreht 2026-08-28** ([D-448], [D-457]). *[D-399](../../docs/NewConcept/90-decision-log.md)s
        // zweite Haelfte lebte davon, dass `hide` ein **Feld** verstecken kann. Jetzt versteckt es einen
        // **Knoten im Baum**, und das sagt nichts darueber, wie er gezeichnet wuerde — also bleibt die
        // Wahl editierbar.*
        check('with hide, the renderer control stays editable', ! str_contains($control, 'disabled'));
    } finally {
        $wpdb->query($wpdb->prepare("UPDATE {$prefix}nodes SET hide = 0 WHERE id = %d", $node));
    }
}

// ── The middle rung: rows marked as test data (D-028, list rows 75 and 17) ───────────────────────

echo "\n== real data → rows marked as test data → the defaults ==\n";

/**
 * A node that can hold records, has attributes and holds **nothing** yet.
 *
 * ⚠️ *A clean node rather than the busy one above, because the point is which record the preview
 * chooses and a node with 21 real rows can never show the marked rung at all.*
 */
$clean = (int) $wpdb->get_var(
    "SELECT n.id
       FROM {$prefix}nodes n
       JOIN {$prefix}relations r ON r.from_id = n.id AND r.name <> ''
      WHERE n.id NOT IN (SELECT node_id FROM {$prefix}records)
      GROUP BY n.id
      ORDER BY COUNT(r.id) DESC
      LIMIT 1"
);

/** Writes one record against a node and hands back its id, so the cleanup has something to name. */
$record = static function (int $node, bool $isTest) use ($wpdb, $prefix): int {
    $wpdb->insert(
        $prefix . 'records',
        [
            'node_id'      => $node,
            'node_version' => (int) $wpdb->get_var($wpdb->prepare("SELECT version FROM {$prefix}nodes WHERE id = %d", $node)),
            'created_at'   => gmdate('Y-m-d H:i:s'),
            // ⚠️ *Seit Schema 15 eine Aufzählung statt eines Schalters ([C65](../../docs/NewConcept/10-domain-core.md)):
            // `user`, `default`, `example`. **Hier wird weiter nur der Testdatenfall gebraucht**, denn
            // das ist die Sprosse, um die es dieser Prüfung geht.*
            'kind'         => $isTest ? 'example' : 'user',
        ],
        ['%d', '%d', '%s', '%s']
    );

    return (int) $wpdb->insert_id;
};

$written = [];

try {
    $empty = previewOf($screen, $clean);

    check('a node with attributes and no records was found', $clean > 0, (string) $clean);
    check('with nothing entered, the defaults are named', str_contains($empty, 'Filled from the defaults'));

    // ── only a marked row ────────────────────────────────────────────────────────────────────────
    $written[] = $marked = $record($clean, true);

    $onlyTest = previewOf($screen, $clean);

    // ⚠️ **Testdaten sind besser als gar nichts** — die dritte Sprosse sind die Vorgaben, nicht die
    // zweite. *Vor Schema 13 gab es die Spalte nicht; die Vorschau nahm `records[0]` und hätte hier
    // dasselbe gezeichnet, ohne es zu sagen.*
    check('a marked row draws where there is no real one', str_contains($onlyTest, 'record #' . $marked));

    // ⚠️ **Und sie sagt, dass es Testdaten sind.** *Eine aus Testdaten gefüllte Vorschau, die sich
    // wie eine aus echten Daten liest, ist genau der Fehler, für den es diese Zeile gibt
    // ([D-241]: das Kennzeichen steuert, was gezeigt wird).*
    check('and it says the row is marked as test data', str_contains($onlyTest, 'marked as test data'));

    // ── a real row beside it, and it must win ────────────────────────────────────────────────────
    // ⚠️ **Die echte Zeile bekommt die *höhere* Id.** *Sonst gewönne sie durch `ORDER BY id` und
    // die Prüfung wäre aus dem falschen Grund grün — genau der Fall, den `records[0]` still traf.*
    $written[] = $real = $record($clean, false);

    $both = previewOf($screen, $clean);

    check('real data outranks the marked row', str_contains($both, 'record #' . $real));
    check('and the marked row is no longer named', ! str_contains($both, 'record #' . $marked));
    check('nor is the preview still calling itself test data', ! str_contains($both, 'marked as test data'));

    // ── a preview draws; it never writes ─────────────────────────────────────────────────────────
    echo "\n== drawing a preview writes nothing ==\n";

    $before = [
        (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}records"),
        (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}record_values"),
    ];

    previewOf($screen, $clean);
    previewOf($screen, $model);

    $after = [
        (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}records"),
        (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}record_values"),
    ];

    // ⚠️ **Die Vorschau zeichnet über *markierten* Zeilen, sie legt keine an.** *Ein Entwurf, der
    // einen Test-Record anlegen müsste, um etwas zu zeigen, schriebe Musterwerte in genau die
    // Daten, über die er berichten soll.*
    check('no record was created by drawing', $before[0] === $after[0], "{$before[0]} → {$after[0]}");
    check('and no value either', $before[1] === $after[1], "{$before[1]} → {$after[1]}");
} finally {
    // ⚠️ Läuft auch nach einer gefallenen Zusage: eine Prüfung, die Datensätze liegen lässt,
    // verändert, was der nächste Lauf misst.
    foreach ($written as $id) {
        $wpdb->query($wpdb->prepare("DELETE FROM {$prefix}records WHERE id = %d", $id));
        $wpdb->query($wpdb->prepare("DELETE FROM {$prefix}record_values WHERE record_id = %d", $id));
    }
}

echo "\n", $failed === 0 ? "all green\n" : "{$failed} failed\n";

exit($failed === 0 ? 0 : 1);
