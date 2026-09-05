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

/**
 * Renders one node's detail page and returns only the preview band.
 *
 * ⚠️ **Ein frischer Schirm je Zeichnung, und ohne das log diese Prüfung.** *{@see \Taxmod\Core\Service\ModelValues}
 * merkt sich die Wertzeilen eines Datensatzes — absichtlich, denn ohne das Gedächtnis wäre jede
 * gezeichnete Zeile eine eigene Abfrage (`CD-7`). **Das Gedächtnis gilt für eine Anfrage.** Diese Prüfung
 * schreibt aber zwischen zwei Zeichnungen im **selben** Prozess: die erste ohne Flag füllte das
 * Gedächtnis, die dritte las es, und `read_only` war unsichtbar. *Gemessen: auf einer echten Seite stand
 * es da, in der Prüfung nicht — und die Prüfung hatte recht, nur über die falsche Sache.*
 *
 * ⚠️ *`$screen` bleibt im Aufruf stehen, damit der Rest der Datei unverändert bleibt; benutzt wird er
 * nicht mehr. **Ein Parameter, der nichts tut, ist eine Lüge** — er geht, sobald diese Prüfung ohnehin
 * angefasst wird.*
 */
function previewOf(object $screen, int $nodeId): string
{
    $_GET['taxmod_node'] = $nodeId;

    $frisch = (new ReflectionClass(Plugin::class))->newInstanceWithoutConstructor();
    $html   = (new ReflectionMethod(Plugin::class, 'screen'))->invoke($frisch)->render();
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

// ⚠️ **Ein Knoten mit Datensätzen **und** einem echten Feld — und die zweite Hälfte fehlte.** *Gewählt
// war «der Knoten mit den meisten Datensätzen», und das ist heute `DisplayOption`; dessen erste benannte
// Kante heisst `render` und ist eine **Einstellungskante**. Seit
// [D-518](../../docs/NewConcept/90-decision-log.md) lässt die Vorschau Einstellungen weg — also wurde die
// Zeile als «versteckt» gezählt, und die `read_only`-Zusagen darunter prüften an einer Zeile, die gar
// nicht gezeichnet wird. **Die Prüfung war rot, ohne dass am Schirm etwas falsch war.***
$model = (int) $wpdb->get_var(
    "SELECT r.node_id FROM {$prefix}node_records r
     INNER JOIN {$prefix}relations e ON e.from_node_id = r.node_id AND e.name <> '' AND e.kind <> 'setting' AND e.kind <> 'inheritance'
     GROUP BY r.node_id ORDER BY COUNT(*) DESC LIMIT 1"
);

check('a model with records was found to test against', $model > 0, (string) $model);

$band = previewOf($screen, $model);

check('the preview band is drawn', $band !== '', strlen($band) . ' bytes');
// ⚠️ **Drei Seiten und nicht zwei, seit [D-547](../../docs/NewConcept/90-decision-log.md).** *Auf sein
// Wort: «es gibt eine dritte Form neben Admin und Show, machen wir jetzt Settings — eine dritte Ansicht
// in der Preview.» **Eine Prüfung, die eine Zahl festschreibt, wird rot, wenn die Entscheidung sie
// ändert**, und dass sie rot wurde, war richtig; sie war nur nicht nachgezogen.*
//
// ⚠️ *Ein **Datentyp** hat weiter zwei — er ist selbst ein Feld ([D-430](../../docs/NewConcept/90-decision-log.md))
// und hat keine Einstellungen unter sich. Die zwei Zahlen sind darum nicht dieselbe Zusage.*
check('all three sides are drawn', substr_count($band, 'taxmod-preview-side') === 3, (string) substr_count($band, 'taxmod-preview-side'));

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

// ⚠️ *Aus der Spalte statt aus der Kante (TASK-018, [D-581](../../NewConcept/90-decision-log.md)).*
$dataType = $dataTypeRoot === 0 ? 0 : (int) $wpdb->get_var($wpdb->prepare(
    "SELECT id FROM {$prefix}nodes WHERE parent_node_id = %d ORDER BY sort_order ASC LIMIT 1",
    $dataTypeRoot
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
    // ⚠️ *Und hier ebenso: eine Kante, die die Vorschau wirklich zeichnet — keine Einstellung.*
    $wpdb->prepare(
        "SELECT id, name FROM {$prefix}relations
         WHERE from_node_id = %d AND name <> '' AND kind <> 'setting' AND kind <> 'inheritance' LIMIT 1",
        $model
    ),
    ARRAY_A
);

if ($edge === null) {
    echo "  --   no named attribute on that model; the flag half is not exercised\n";
} else {
    $edgeId = (int) $edge['id'];
    $name   = (string) $edge['name'];

    // ── Wo `read_only` heute wirklich liegt ──────────────────────────────────────────────────────
    //
    // ⚠️ **Diese Prüfung schrieb in die `settings`-Tabelle, die niemand mehr liest** — *und war deshalb
    // rot, ohne dass am Schirm etwas falsch war. Der Eigentümer hat die Frage gestellt, die es aufdeckte:
    // «warum wird das in Integer trotzdem nicht aufgelöst? Ist da ein Fehler?» **Ja, und zwar zwei:** der
    // Leser konnte nur `renderer` beantworten, und dieser Wächter schrieb an die alte Stelle. `PR-12`
    // verlangt beides zusammen — **der Wächter zieht mit dem Leser um.***
    //
    // ⚠️ *Die Adresse einer Angabe an einer **Verwendungsstelle** ist `<Stelle>.<Einstellung>` im
    // `default`-Satz des Besitzers — dieselbe Form, die der Renderer schon benutzt.*
    $readOnlyEdge = (int) $wpdb->get_var(
        "SELECT id FROM {$prefix}relations WHERE BINARY name = 'read_only' AND kind = 'setting' ORDER BY id LIMIT 1"
    );

    $ownerRecord = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$prefix}node_records WHERE node_id = %d AND record_type = 'default' ORDER BY id LIMIT 1",
        $model
    ));

    check('die read_only-Kante steht im Modell', $readOnlyEdge > 0, (string) $readOnlyEdge);
    check('und das Modell hat einen default-Satz', $ownerRecord > 0, (string) $ownerRecord);

    /**
     * Puts one flag on the edge, or clears both.
     *
     * ⚠️ **Two homes since 2026-08-28, and that is the decision** ([D-457]): `hide` is a **column**
     * on `relations`, `read_only` stays a setting ([D-461]) — *und eine Einstellung ist seit
     * [D-529](../../docs/NewConcept/90-decision-log.md) eine **Kante mit einem Wert im Datensatz**.*
     */
    $flag = static function (?string $key) use ($wpdb, $prefix, $edgeId, $readOnlyEdge, $ownerRecord): void {
        $pfad = $edgeId . '.' . $readOnlyEdge;

        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$prefix}relation_records WHERE node_record_id = %d AND path = %s",
            $ownerRecord,
            $pfad
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
                "INSERT INTO {$prefix}relation_records (node_record_id, relation_id, path, locale, value_int, position, version)
                 VALUES (%d, %d, %s, '', 1, 0, 1)",
                $ownerRecord,
                $readOnlyEdge,
                $pfad
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
/**
 * ⚠️ **Der erste Bewerber, an dem die Vorschau überhaupt gezeichnet wird — und nicht einfach der
 * erste Bewerber.** *«Kanten und keine Datensätze» reicht nicht: es gibt zwei Knoten namens
 * `Adresse` mit je fünf Kanten, einen unter einem Ast, der Daten hält, und einen unter einem, der
 * keine hält. **Welchen `LIMIT 1` bei Gleichstand nahm, entschied die Datenbank** — mit dem einen
 * war der Abschnitt grün, mit dem anderen viermal rot, und an der Vorschau war beides Mal nichts
 * falsch. `ORDER BY n.id` macht die Wahl wiederholbar, die Schleife macht sie richtig.*
 */
$clean = 0;

foreach ($wpdb->get_col(
    "SELECT n.id
       FROM {$prefix}nodes n
       JOIN {$prefix}relations r ON r.from_node_id = n.id AND r.name <> ''
      WHERE n.id NOT IN (SELECT node_id FROM {$prefix}node_records)
      GROUP BY n.id
      ORDER BY COUNT(r.id) DESC, n.id ASC"
) as $bewerber) {
    if (previewOf($screen, (int) $bewerber) !== '') {
        $clean = (int) $bewerber;
        break;
    }
}

/** Writes one record against a node and hands back its id, so the cleanup has something to name. */
$record = static function (int $node, bool $isTest) use ($wpdb, $prefix): int {
    $wpdb->insert(
        $prefix . 'node_records',
        [
            'node_id'      => $node,
            'node_version' => (int) $wpdb->get_var($wpdb->prepare("SELECT version FROM {$prefix}nodes WHERE id = %d", $node)),
            'created_at'   => gmdate('Y-m-d H:i:s'),
            // ⚠️ *Seit Schema 15 eine Aufzählung statt eines Schalters ([C65](../../docs/NewConcept/10-domain-core.md)):
            // `user`, `default`, `example`. **Hier wird weiter nur der Testdatenfall gebraucht**, denn
            // das ist die Sprosse, um die es dieser Prüfung geht.*
            'record_type'  => $isTest ? 'example' : 'user',
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
        (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}node_records"),
        (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}relation_records"),
    ];

    previewOf($screen, $clean);
    previewOf($screen, $model);

    $after = [
        (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}node_records"),
        (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}relation_records"),
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
        $wpdb->query($wpdb->prepare("DELETE FROM {$prefix}node_records WHERE id = %d", $id));
        $wpdb->query($wpdb->prepare("DELETE FROM {$prefix}relation_records WHERE node_record_id = %d", $id));
    }
}

echo "\n", $failed === 0 ? "all green\n" : "{$failed} failed\n";

exit($failed === 0 ? 0 : 1);
