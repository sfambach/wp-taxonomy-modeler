<?php declare(strict_types=1);
/**
 * Eine Angabe des Modells wird dort geschrieben, wo sie gelesen wird.
 *
 *     php scripts/dev/setting-write-check.php [path/to/wordpress]
 *
 * ⚠️ **Der Eigentümer hat es an der Oberfläche gesehen:** *«den Render kann ich noch nicht setzen.»*
 * *Und gemessen war es kein fehlender Renderer, sondern ein **Schreiber an der alten Stelle**: der
 * Wähler am Knoten schrieb in die `settings`-Tabelle, und {@see \Taxmod\Core\Service\ModelValues} liest
 * aus Datensätzen und **gewinnt** ({@see \Taxmod\Core\Service\Rendering::withModelValues()} verteilt das
 * Modell zuletzt). **Also blieb jede Wahl ohne Wirkung** — an 26 Knoten, die ihren Renderer im
 * Datensatz tragen, spurlos.*
 *
 * ⚠️ **Vierter Fall derselben Sache an einem Tag**: *Daten umgezogen, Schreiber stehengeblieben. Deshalb
 * heisst die Reihenfolge in diesem Projekt **Wächter, Leser, Daten** — und deshalb steht diese Prüfung
 * hier, bevor der Schirm umgestellt wird.*
 *
 * ⚠️ **Sein Zuschnitt, und er ist der richtige:** *«auch Settings sind etwas, das gerendert werden kann,
 * und die Renderer dafür existieren schon — der Unterschied: Eingabe geschieht im Modell und nicht im
 * Frontend.» Darum prüft dieser Lauf **keine neue Speicherform**, sondern einen Rundlauf durch die
 * vorhandene: schreiben, lesen, dasselbe herausbekommen.*
 *
 * ⚠️ *Der Lauf legt sich einen eigenen Knoten an und räumt ihn samt Datensätzen wieder weg, auch bei
 * einem Absturz. **Keine Prüfung darf ihren Müll liegen lassen** — sechs ältere tun es und werden
 * nachgezogen.*
 *
 * @see docs/NewConcept/02-field-and-setting.md
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

use Taxmod\Core\Exception\NotYetStorable;
use Taxmod\Core\Model\SettingKey;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Service\DataEntry;
use Taxmod\Core\Service\ModelValues;
use Taxmod\Core\Service\Rendering;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRecordRepository;
use Taxmod\WordPress\Persistence\WpdbRelationRepository;
use Taxmod\WordPress\SystemClock;

// ⚠️ *Seit TASK-019 traegt jeder Knoten eine Beschriftungszeile ([D-580](../../docs/NewConcept/90-decision-log.md)) —
// und dieser Lauf raeumt Knoten mit rohem SQL weg, also am Ende hinter sich her. **Es faellt nur,
// worauf weder ein Knoten noch eine Kante zeigt.***
register_shutdown_function(static fn (): int => \Taxmod\WordPress\Persistence\Schema::forgetOrphanLabels());

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
$log       = new WpdbChangelog(new SystemClock());
$framework = new SeededFrameworkNodes($nodes, $relations, $log);
$records   = new WpdbRecordRepository();
$data      = new DataEntry($records, $relations, $nodes, $framework, new SystemClock());

/** @var list<int> Was dieser Lauf angelegt hat — Knoten und Datensätze. */
$meineKnoten  = [];
$meineSaetze  = [];

// ⚠️ *Vor der ersten Änderung angemeldet, nicht danach.*
register_shutdown_function(static function () use (&$meineKnoten, &$meineSaetze): void {
    global $wpdb;

    foreach ($meineSaetze as $id) {
        $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('relation_records') . ' WHERE node_record_id = %d', $id));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('node_records') . ' WHERE id = %d', $id));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('relation_records_history') . ' WHERE node_record_id = %d', $id));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('node_records_history') . ' WHERE id = %d', $id));
    }

    foreach ($meineKnoten as $id) {
        // ⚠️ *Auch die Datensätze, die der Lauf nicht selbst notiert hat — ein Teil entsteht innen
        // drin, und ein Teil ohne Besitzer wäre genau der Müll, den diese Prüfung nicht machen darf.*
        foreach ($wpdb->get_col($wpdb->prepare('SELECT id FROM ' . Schema::table('node_records') . ' WHERE node_id = %d', $id)) ?: [] as $satzId) {
            // ⚠️ **Und die Teile mit, denn sie gehören einem **anderen** Knoten.** *Gemessen: nach den
            // Läufen dieses Abends standen **36** Teil-Sätze von `DisplayOption` ohne Besitzer da. Der
            // Aufräumer löschte nur, was `node_id = <mein Knoten>` trug — ein Teil trägt aber die Id
            // des Zielknotens. **Der Verweis verschwand, der Satz blieb.***
            foreach ($wpdb->get_col($wpdb->prepare('SELECT value_ref FROM ' . Schema::table('relation_records') . ' WHERE node_record_id = %d AND value_ref IS NOT NULL', (int) $satzId)) ?: [] as $teilId) {
                $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('relation_records') . ' WHERE node_record_id = %d', (int) $teilId));
                $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('node_records') . ' WHERE id = %d', (int) $teilId));
            }

            $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('relation_records') . ' WHERE node_record_id = %d', (int) $satzId));
            $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('node_records') . ' WHERE id = %d', (int) $satzId));
        }

        $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('relations') . ' WHERE from_node_id = %d OR to_node_id = %d', $id, $id));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('nodes') . ' WHERE id = %d', $id));
    }
});


/**
 * Der Einstellungsdatensatz, der an der Kante `renderer` dieses Knotens haengt — oder `0`.
 *
 * ⚠️ **Eine Stelle statt sechs** (TASK-057). *Vorher stand hier sechsmal
 * `SELECT settings_record_id FROM nodes`; die Spalte ist gefallen
 * ([D-642](../../docs/NewConcept/90-decision-log.md)), und die Kantenform ist zu lang, um sie
 * sechsmal hinzuschreiben. **Sechs Abschriften einer Adresse sind sechs Gelegenheiten, sie
 * verschieden zu schreiben.***
 */
function traegersatz(int $knotenId): int
{
    global $wpdb, $framework;

    return (int) $wpdb->get_var($wpdb->prepare(
        'SELECT v.value_ref FROM ' . Schema::table('node_records') . ' r
           INNER JOIN ' . Schema::table('relation_records') . " v ON v.node_record_id = r.id
          WHERE r.node_id = %d AND r.record_type = 'default'
            AND v.relation_id = %d AND v.value_ref_kind = 'record'
          LIMIT 1",
        $knotenId,
        $framework->settingRelationId(SettingKey::Renderer)
    ));
}
echo "\n== Der Renderer haengt an der Spalte, nicht an einem Kantenpaar ==\n";
// ⚠️ **Diese Zusage ist zweimal umgezogen, und der zweite Umzug nimmt den ersten zurueck.**
//
// ⚠️ *Sie hiess einmal «die Saat hat die zwei Kanten aufgeschrieben». Dann fielen beide mit dem
// Huellknoten `DisplayOption` ([D-604](../../docs/NewConcept/90-decision-log.md)), der Renderer zog
// in `nodes.settings_record_id`, und sie hiess «die alte Traegerkante ist **nicht** mehr
// aufgeschrieben».*
//
// ⚠️ **Mit TASK-057 fragt sie wieder nach der Kante** ([D-642](../../docs/NewConcept/90-decision-log.md)):
// *«das hast du leider falsch verstanden, ich meinte einfach eine Multiplizitaet von 1» — **am
// Knoten**. Aus «genau einer» hatte ich «also keine Kante» gemacht; **der Schluss war meiner.***
//
// ⚠️ *Die **innere** Wertkante bleibt bei `0`, und das ist keine Nachlaessigkeit: sie gehoerte dem
// Huellknoten und ist mit ihm gefallen. Der Teil hinter der Einstellungskante **ist** jetzt der
// gewaehlte Renderer — eine Stufe, nicht zwei.*
check(
    'die Einstellungskante `renderer` ist aufgeschrieben',
    $framework->settingRelationId(SettingKey::Renderer) !== 0,
    (string) $framework->settingRelationId(SettingKey::Renderer)
);

check(
    'und die innere Wertkante gibt es nicht mehr',
    $framework->settingValueRelationId(SettingKey::Renderer) === 0,
    (string) $framework->settingValueRelationId(SettingKey::Renderer)
);

// ⚠️ *Der Renderer-Ast als Liste von Nummern statt als Pfadmuster — die Spalte ist mit Fassung 35
// gefallen (TASK-001), und `id IN (…)` sagt dasselbe ohne `LIKE`.*
$rendererWurzel = (int) $wpdb->get_var(
    'SELECT id FROM ' . Schema::table('nodes_named') . " WHERE name = 'Renderer' LIMIT 1"
);

$rendererAst = $rendererWurzel === 0
    ? []
    : array_values(array_diff((new \Taxmod\WordPress\Persistence\WpdbNodeRepository())->subtreeIds($rendererWurzel), [$rendererWurzel]));

$astPlaetze = implode(',', array_fill(0, max(1, count($rendererAst)), '%d'));
// ⚠️ **Dieselbe Zusage wie frueher «jeder gespeicherte Renderer zeigt in den Renderer-Ast», nur an
// der neuen Adresse.** *Sie ist am eigenen Fehler gelernt: es gibt zwei Knoten namens `form` -- die
// Label-Rolle und den Renderer (D-022: Knotennamen sind absichtlich nicht eindeutig). Ein Wert, der
// richtig heisst und falsch zeigt, ist schlimmer als ein leerer.*
//
// ⚠️ **Die Adresse ist mit TASK-057 wieder die Kante** ([D-642](../../docs/NewConcept/90-decision-log.md)):
// *sie war es schon einmal, wanderte mit TASK-020 in `nodes.settings_record_id`, und die Spalte ist
// gefallen — «ich meinte einfach eine Multiplizitaet von 1», am Knoten.*
$rendererKante = $framework->settingRelationId(SettingKey::Renderer);

$gesamt = (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM ' . Schema::table('relation_records') . " WHERE relation_id = %d AND value_ref_kind = 'record'",
    $rendererKante
));

$daneben = $rendererAst === [] ? $gesamt : (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM ' . Schema::table('relation_records') . ' v
       INNER JOIN ' . Schema::table('node_records') . ' r ON r.id = v.value_ref
       LEFT JOIN ' . Schema::table('nodes') . " z ON z.id = r.node_id
      WHERE v.relation_id = %d AND v.value_ref_kind = 'record'
        AND (z.id IS NULL OR z.id NOT IN ({$astPlaetze}))",
    $rendererKante,
    ...$rendererAst
));

check('jeder Traeger zeigt auf einen Satz im Renderer-Ast', $daneben === 0, "{$daneben} von {$gesamt} daneben");

// ⚠️ *Der Gegenfall: es gibt ueberhaupt gespeicherte Renderer. Gemessen am 2026-09-05: 29.*
check('und es gibt gespeicherte Renderer', $gesamt > 20, (string) $gesamt);
check('und es gibt gespeicherte Renderer', $gesamt > 20, (string) $gesamt);

echo "\n== Ein Renderer wird geschrieben und wieder gelesen ==\n";

// ⚠️ *Ein eigener Knoten und kein vorhandener: an einem echten Knoten wäre die Prüfung entweder
// zerstörerisch oder sie müsste einen Wert überschreiben und zurückstellen — und der Zwischenzustand
// wäre in der Datenbank sichtbar.*
// ⚠️ *Über den Editor und nicht mit rohem SQL: Ids kommen aus dem Identitätenverzeichnis
// ([D-339](../../docs/NewConcept/90-decision-log.md)), nicht aus `AUTO_INCREMENT` — ein `INSERT` von
// Hand bekam `insert_id = 0` zurück und legte nichts an.*
$modellWurzel = $framework->rootOf(\Taxmod\Core\Model\Branch::Model);
$editor       = new \Taxmod\Core\Service\ModelEditor($nodes, $relations, $framework, $log);

$knoten        = $editor->createNode('Pruefknoten Einstellung', $modellWurzel->id);
$knotenId      = $knoten->id;
$meineKnoten[] = $knotenId;

check('der Pruefknoten steht im Modell', $knoten !== null, 'nicht angelegt');

if ($knoten === null) {
    echo "\n" . ($bad === 0 ? "Alles gruen: $ok\n" : "$bad fehlgeschlagen, $ok in Ordnung\n");

    exit(1);
}

// Ein echter Renderer-Knoten als Wert — der Wert ist ein **Verweis**, nicht ein Name.
$rendererKnoten = $wpdb->get_row($wpdb->prepare(
    // ⚠️ *Aus der Spalte statt aus der Kante (TASK-018, [D-581](../../NewConcept/90-decision-log.md)).*
    'SELECT k.id, k.name FROM ' . Schema::table('nodes_named') . ' k
     INNER JOIN ' . Schema::table('nodes_named') . ' v ON v.id = k.parent_node_id
     WHERE v.name = %s AND k.name = %s LIMIT 1',
    'Renderer',
    'spinner'
), ARRAY_A);

if ($rendererKnoten === null) {
    check('«spinner» steht als Knoten unter «Renderer»', false);
} else {
    check('«spinner» steht als Knoten unter «Renderer»', true);

    // WICHTIG: Geschrieben wird ueber die Spalte und nicht mehr ueber `putSettingValue()` --
    // sichtbare Aenderung dieser Zusage (PR-9). `putSettingValue()` schlaegt die Traegerkante nach
    // und wirft heute zu Recht: es gibt keine. Der Weg von D-584 ist
    // `chooseSettingRecordAtNode()`: ein `default`-Satz des gewaehlten Renderer-Knotens, und
    // `nodes.settings_record_id` zeigt darauf.
    $geschrieben = true;

    try {
        $data->putSettingValue($knotenId, SettingKey::Renderer, TypedValue::ofReference((int) $rendererKnoten["id"]));
    } catch (NotYetStorable $e) {
        $geschrieben = false;
        check('der Renderer laesst sich schreiben', false, $e->getMessage());
    }

    if ($geschrieben) {
        check('der Renderer laesst sich schreiben', true);

        // ⚠️ **Ein frischer Leser.** *{@see ModelValues} merkt sich seine Funde je Instanz (`CD-7`) —
        // ein wiederverwendeter würde die Antwort von vorher zurückgeben und den Rundlauf grün lügen.*
        $gelesen = (new ModelValues($records, $relations, $nodes, $framework))->forNode($knoten);

        check(
            'und der Leser gibt ihn zurueck',
            ($gelesen['renderer']->value->text ?? null) === 'spinner',
            $gelesen['renderer']->value->text ?? 'nichts'
        );

        // ⚠️ *Und er liegt im **default**-Satz, nicht in einem Benutzersatz
        // ([D-026](../../docs/NewConcept/90-decision-log.md): «at model level there are no values,
        // only defaults»).*
        // WICHTIG: Gefragt wird der Satz, auf den die Spalte zeigt -- er gehoert dem *Renderer*,
        // nicht dem eingestellten Knoten (D-584: «dessen `node_id` sagt schon, welcher Renderer es
        // ist»). Die alte Fassung suchte Saetze mit `node_id = <mein Knoten>` und haette hier
        // nichts gefunden.
        // ⚠️ *Gefragt wird der Satz, der an der Einstellungskante haengt — er gehoert dem
        // **Renderer**, nicht dem eingestellten Knoten ([D-583](../../docs/NewConcept/90-decision-log.md):
        // «dessen `node_id` sagt schon, welcher Renderer es ist»).*
        $satz = $wpdb->get_row($wpdb->prepare(
            'SELECT record_type, node_id FROM ' . Schema::table('node_records') . ' WHERE id = %d',
            traegersatz($knotenId)
        ), ARRAY_A);

        check(
            'und zwar im default-Satz des gewaehlten Renderers',
            ($satz['record_type'] ?? null) === 'default'
                && (int) ($satz['node_id'] ?? 0) === (int) $rendererKnoten['id'],
            json_encode($satz) ?: 'kein Satz'
        );

        // ⚠️ **Zweimal schreiben legt keinen zweiten Satz an.** *Sonst stünden am Ende zwei Antworten
        // auf eine Frage da, und der Leser nähme die erste — ein Fehler, der erst beim zweiten Ändern
        // auffällt.*
        // WICHTIG: Gezaehlt werden jetzt die Saetze, auf die *irgendein* Knoten mit dieser Spalte
        // zeigt -- fuer diesen einen Pruefknoten kann es hoechstens einer sein, weil eine Spalte
        // eine Zahl haelt. Das ist genau die Aussage von D-584, und sie ist staerker als die alte
        // Zaehlung: «genau ein Renderer» ist keine Regel mehr, die eingehalten werden muss,
        // sondern die Form der Ablage. Geprueft wird, dass der zweite Schreibakt den vorhandenen
        // Satz *behaelt* und keinen neuen anlegt.
        $vorher = traegersatz($knotenId);

        $data->putSettingValue($knotenId, SettingKey::Renderer, TypedValue::ofReference((int) $rendererKnoten["id"]));


        $nachher = traegersatz($knotenId);
        check('zweimal geschrieben, derselbe Satz', $vorher !== 0 && $vorher === $nachher, "{$vorher} → {$nachher}");
    }
}

echo "\n== Ein anderer Renderer ersetzt den Satz, er kommt nicht dazu ==\n";

// WICHTIG: Hier standen drei Abschnitte, die alle ueber die Seitenadresse
// `taxmod_value[<Traegerkante>][<Wertkante>]` beziehungsweise `taxmod_part[<Satz>][<Kante>]`
// gingen -- «derselbe Weg ueber die Seite», «die Wertspalte zeigt, was gespeichert ist» und
// «Nichts ist eine Wahl, und sie loescht». Sie sind ersetzt, und das ist eine sichtbare
// Aenderung dieser Zusagen (PR-9). Der Grund ist gemessen und nicht technisch:
//
// WICHTIG: Die Adresse gibt es nicht mehr. Der Renderer-Waehler wurde je Einstellungs*kante*
// gezeichnet; die Kante hing am Huellknoten `DisplayOption`, den der Eigentuemer geloescht hat
// (D-604), und seit TASK-020 haengt der Renderer an `nodes.settings_record_id` (D-584).
// Gemessen am 2026-09-05 traegt `Root` nur noch `validator` und `read_only`. Die drei
// Abschnitte liefen weiter durch und schrieben nichts -- «der Akt laeuft durch» war gruen,
// waehrend danach kein Wert dastand. Eine Zusage, die einen wirkungslosen Akt bestaetigt, ist
// schlimmer als keine.
//
// WICHTIG: Was sie geprueft haben, war im Kern: ein Renderer laesst sich aendern, und die alte
// Angabe bleibt nicht daneben stehen. Genau das steht in D-584 als Regel des neuen Ortes:
// «der alte Satz wird vergessen, wenn ein anderer Renderer gewaehlt wird -- seine Felder sind
// die des alten Knotens und sagen ueber den neuen nichts.» Das wird hier gefragt.
//
// WICHTIG: Und was ungedeckt bleibt, gehoert gemeldet statt gruen gefaerbt: auf der Knotenseite
// steht heute kein Renderer-Waehler, und es gibt keinen Weg, ihn ueber die Seite zu setzen oder
// herauszunehmen. Der Rundlauf ueber `handlePost()` kann darum nicht geprueft werden -- er
// existiert nicht. Das ist eine Luecke des Umbaus und gehoert ins Eingangsblatt.
$zweiter = $wpdb->get_row($wpdb->prepare(
    'SELECT k.id, k.name FROM ' . Schema::table('nodes_named') . ' k
     INNER JOIN ' . Schema::table('nodes_named') . ' v ON v.id = k.parent_node_id
     WHERE v.name = %s AND k.name = %s LIMIT 1',
    'Renderer',
    'slider'
), ARRAY_A);

// ⚠️ *Hiess `$spalte` und fragte `nodes.settings_record_id`. **Die Spalte ist gefallen** (TASK-057);
// dieselbe Frage stellt jetzt {@see traegersatz()} an der Einstellungskante.*
$traeger = static fn (): int => traegersatz($knotenId);
if ($zweiter === null || $rendererKnoten === null) {
    check('«slider» steht als Knoten unter «Renderer»', false);
} else {
    check('«slider» steht als Knoten unter «Renderer»', true);

    $alt = $traeger();

    check('vorher steht ein Renderer da', $alt !== 0, (string) $alt);

    $data->putSettingValue($knotenId, SettingKey::Renderer, TypedValue::ofReference((int) $zweiter["id"]));

    $neu = $traeger();

    check('nach der Wahl steht ein anderer Satz da', $neu !== 0 && $neu !== $alt, "{$alt} -> {$neu}");

    $gelesen = (new ModelValues($records, $relations, $nodes, $framework))->forNode($knoten);

    check(
        'und der Leser gibt den neuen zurueck',
        ($gelesen['renderer']->value->text ?? null) === 'slider',
        $gelesen['renderer']->value->text ?? 'nichts'
    );

    // WICHTIG: Der alte Satz bleibt nicht daneben stehen -- das ist der Kern der Zusage. Bliebe
    // er, stuenden zwei Antworten auf eine Frage da, und die Felder des alten Renderers waeren
    // Werte, die niemand mehr lesen kann.
    $altNochDa = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM ' . Schema::table('node_records') . ' WHERE id = %d',
        $alt
    ));

    check('und der alte Satz ist vergessen', $altNochDa === 0, (string) $altNochDa);

    // Zurueckgelegt: ein Lauf, der etwas veraendert und nicht zuruecklegt, veraendert das Modell
    // des Eigentuemers -- hier der eigene Pruefknoten, aber die Gewohnheit zaehlt.
    $data->putSettingValue($knotenId, SettingKey::Renderer, TypedValue::ofReference((int) $rendererKnoten["id"]));

    $zurueck = (new ModelValues($records, $relations, $nodes, $framework))->forNode($knoten);

    check(
        'und der Wert ist wieder da',
        ($zurueck['renderer']->value->text ?? null) === 'spinner',
        $zurueck['renderer']->value->text ?? 'nichts'
    );
}

echo "\n== Und dieselbe Angabe an einer Verwendungsstelle ==\n";

// ⚠️ **Die Zusage aus [`INF-011`](../../docs/pakete/modelltabellen/inbox.md).** *Fuer eine Angabe **an
// einer Kante** — `label_role` an `Einheitenwert.prefix`, `read_only` an einem Feld — gab es genau
// einen Schreiber, und der war `Settings::put()` in die mit
// [D-579](../../docs/NewConcept/90-decision-log.md) gestrichene Tabelle. **Zwei Waechter legten die
// Zeile deshalb selbst ueber die Speicher an** — ein Behelf in einer Pruefung.*
//
// ⚠️ *Derselbe Rundlauf wie oben, nur eine Adresse tiefer: schreiben, lesen, dasselbe herausbekommen.
// **Und die Gegenprobe zaehlt genauso** — `setHere` sagt, ob die Angabe **hier** steht oder vom Ziel
// geerbt ist ([D-602](../../docs/NewConcept/90-decision-log.md)), und ohne sie waere eine geerbte
// Antwort von einer gesetzten nicht zu unterscheiden.*
//
// ⚠️ *Auf dem eigenen Pruefknoten und an einer eigens angelegten Stelle — der Aufraeumer nimmt die
// Kante mit, weil sie an ihm haengt.*
// ⚠️ *Der Typ ueber die notierte Id und nicht ueber den Namen
// ([D-510](../../docs/NewConcept/90-decision-log.md)) — es darf mehrere Knoten namens `Integer`
// geben ([D-022](../../docs/NewConcept/90-decision-log.md)), und einer davon hat schon einmal
// geantwortet.*
$typId = (new \Taxmod\WordPress\Persistence\SeededTypeNodes($nodes, $framework))
    ->nodeId(\Taxmod\Core\Model\SimpleType::Int);

if ($typId === null) {
    check('der Datentyp «int» ist gesaet', false);

    exit(1);
}

$stelle = $editor->addField($knotenId, $typId, 'pruefstelle');

$einstellung = $data->settingRelationAtUseSite($stelle, 'read_only');

if ($einstellung === null) {
    check('die Einstellungskante «read_only» ist an der Stelle zu finden', false, 'nicht gefunden');
} else {
    check('die Einstellungskante «read_only» ist an der Stelle zu finden', true);

    // ⚠️ *Ein frischer Leser je Frage — {@see ModelValues} merkt sich seine Funde je Instanz (`CD-7`).*
    $anDerStelle = static function () use ($records, $relations, $nodes, $framework, $stelle): ?bool {
        $angabe = (new ModelValues($records, $relations, $nodes, $framework))->forUseSite($stelle)['read_only'] ?? null;

        return $angabe === null ? null : $angabe->setHere;
    };

    check('vorher steht dort nichts', $anDerStelle() !== true, 'schon gesetzt');

    $data->putSettingAtUseSite($stelle->id, $einstellung->id, TypedValue::ofBool(true));

    check('geschrieben, und der Leser findet sie an der Stelle', $anDerStelle() === true);

    // ⚠️ **Und sie liegt in dem Satz, den der Leser fragt** — im Satz **dieser Verwendungsstelle**
    // ([D-667](../../docs/NewConcept/90-decision-log.md), Fassung 37). *Hier stand bis zum 2026-09-06
    // der Satz des Halters und die zweistufige Adresse `<Stelle>.<Einstellung>`. Die Zusage ist
    // dieselbe geblieben: der Wert darf **nicht** im Satz des Ziels landen, sonst truege er fuer
    // alle, die den Typ verwenden.*
    $zeilen = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM ' . Schema::table('relation_records') . ' w
         INNER JOIN ' . Schema::table('node_records') . ' r ON r.id = w.node_record_id
         WHERE r.relation_id = %d AND w.relation_id = %d',
        $stelle->id,
        $einstellung->id
    ));

    check('und zwar im Satz der Verwendungsstelle', $zeilen === 1, (string) $zeilen);

    // ⚠️ *«Nichts» ist auch hier eine Wahl und sie loescht — derselbe dritte Zustand wie am Knoten.*
    $data->clearSettingAtUseSite($stelle->id, $einstellung->id);

    check('herausgenommen, und die Stelle sagt nichts mehr', $anDerStelle() !== true);

    // ⚠️ **Und derselbe Weg ueber die Seite, wie ein Mensch ihn geht** — die Angaben einer Feldzeile
    // kommen als `taxmod_field_setting[<Kanten-Id>][<Schluessel>]` an
    // ([D-520](../../docs/NewConcept/90-decision-log.md): sie stehen als **Feldzeilen** im
    // Settings-Block, nicht in einer eigenen Tafel unter der Zeile).
    // ⚠️ *Hier gesucht statt weiter oben: der Abschnitt, der den Verwalter frueher besorgte, ging
    // ueber die gefallene Renderer-Adresse und ist mit ihr weg.*
    $verwalter = get_users(['role' => 'administrator', 'number' => 1]);

    if ($verwalter !== []) {
        wp_set_current_user($verwalter[0]->ID);

        // ⚠️ *Die Weiterleitung abfangen, sonst endet der Lauf hier — der Abschnitt, der diesen
        // Filter frueher setzte, ging ueber die gefallene Renderer-Adresse und ist mit ihr weg.*
        add_filter('wp_redirect', static function ($ziel) {
            throw new RuntimeException('__weitergeleitet__' . (string) $ziel);
        }, 10, 1);

        $zeile = static function (string $wert) use ($knotenId, $stelle): string {
            $_POST = [
                'action'               => 'taxmod_node',
                'id'                   => (string) $knotenId,
                'do'                   => 'put_setting',
                '_taxmod_nonce'        => wp_create_nonce('taxmod_node_' . $knotenId),
                'taxmod_field_setting' => [(string) $stelle->id => ['read_only' => $wert]],
            ];
            $_REQUEST = $_POST;

            try {
                $bau    = new ReflectionClass(\Taxmod\WordPress\Plugin::class);
                $plugin = $bau->newInstanceWithoutConstructor();
                $bau->getProperty('file')->setValue($plugin, 'taxmod.php');
                $plugin->screen()->handlePost();
            } catch (RuntimeException $e) {
                return str_starts_with($e->getMessage(), '__weitergeleitet__')
                    ? urldecode((string) preg_replace('/^.*taxmod_message=/', '', $e->getMessage()))
                    : $e->getMessage();
            }

            return '';
        };

        check('der Akt der Feldzeile laeuft durch', $zeile('1') === 'ok');

        check('und die Angabe steht danach an der Stelle', $anDerStelle() === true);

        check('der leere Akt laeuft auch durch', $zeile('') === 'ok');

        check('und nimmt sie wieder heraus', $anDerStelle() !== true);
    }
}

echo "\n== Eine Einstellung, die nur das Ziel erklaert (TASK-045, D-611) ==\n";

// ⚠️ **Sein Fall, und er war bis heute nicht zu schreiben.** *«`Integer --max--> max` erklaert `max`,
// und nur `Integer`. Fuer ‹an `Kunde.alter` ist max = 120› kennt die Kette des Besitzers — `Kunde`,
// `Model`, `Root` — kein `max`. **Heute wird der Wert dann gar nicht geschrieben.**» Der Schreiber
// sucht die Einstellungskante seit TASK-045 an **beiden** Ketten, Besitzer zuerst.*
//
// ⚠️ **Und der Leser wurde mitgezogen — sonst waere die alte Begruendung wahr geworden.** *«Lieber
// nichts schreiben als an eine Adresse legen, die niemand liest» war richtig, solange
// {@see ModelValues::settingRelation()} nur den Besitzer kannte. **Die Zusage unten prueft genau
// das:** geschrieben **und** wiedergefunden. Ohne die zweite Haelfte waere dies der Fehler, den
// [D-611](../../docs/NewConcept/90-decision-log.md) an seiner ersten Fassung beschreibt, nur
// spiegelverkehrt.*
//
// ⚠️ *Eigene Knoten mit eigenem Praefix, an seinem Modell wird nichts angefasst — die
// Einstellungskante haengt an **meinem** Zieltyp, nicht an `Integer`.*
$eigenerTyp = $editor->createNode('__sw2 Zieltyp', $framework->rootOf(\Taxmod\Core\Model\Branch::DataTypes)->id);
$meineKnoten[] = $eigenerTyp->id;

$eigeneAngabe  = $editor->createNode('__sw2 hoechstens', $framework->rootOf(\Taxmod\Core\Model\Branch::Settings)->id);
$meineKnoten[] = $eigeneAngabe->id;

// ⚠️ *Die Art wird angegeben (TASK-053, [D-618](../../docs/NewConcept/90-decision-log.md)) — der
// Einstellungsast gibt `setting` nicht her, und frueher haette hier ein `markAsSetting()` danach
// gestanden.*
$nurAmZiel = $editor->addField(
    $eigenerTyp->id,
    $eigeneAngabe->id,
    '__sw2_hoechstens',
    \Taxmod\Core\Model\RelationKind::Setting
);

$stelle2 = $editor->addField($knotenId, $eigenerTyp->id, '__sw2 stelle');

// ⚠️ *Die Gegenprobe zuerst: die Kante darf an der Kette des **Besitzers** wirklich nicht stehen,
// sonst prueft der Abschnitt etwas anderes als er behauptet.*
$amBesitzer = false;

foreach ($relations->fieldRelationsOf($framework->inheritanceOwnersOf($nodes->byId($knotenId))) as $kante) {
    if ($kante->id === $nurAmZiel->id) {
        $amBesitzer = true;
    }
}

check('die Einstellungskante steht nicht an der Kette des Besitzers', ! $amBesitzer);

$gefunden = $data->settingRelationAtUseSite($stelle2, '__sw2_hoechstens');

check(
    'der Schreiber findet sie trotzdem — an der Kette des Ziels',
    $gefunden !== null && $gefunden->id === $nurAmZiel->id,
    $gefunden === null ? 'nicht gefunden' : 'eine andere Kante'
);

if ($gefunden !== null) {
    $anDerStelle2 = static function () use ($records, $relations, $nodes, $framework, $stelle2): ?int {
        $angabe = (new ModelValues($records, $relations, $nodes, $framework))
            ->forUseSite($stelle2)['__sw2_hoechstens'] ?? null;

        return $angabe === null || $angabe->setHere !== true ? null : $angabe->value->int;
    };

    check('vorher steht dort nichts', $anDerStelle2() === null);

    $data->putSettingAtUseSite($stelle2->id, $gefunden->id, TypedValue::ofInt(120));

    // ⚠️ **Die Zusage, die den ganzen Abschnitt traegt:** *geschrieben, und der Leser findet es an
    // derselben Stelle wieder. **Sie war vor TASK-045 nicht zu erfuellen**, weil schon der Schreiber
    // die Kante nicht fand.*
    check('geschrieben, und der Leser findet den Wert an der Stelle', $anDerStelle2() === 120, (string) $anDerStelle2());

    // ⚠️ *Im Satz **dieser Stelle**, nicht im Satz des Ziels — sonst truege die Angabe fuer alle,
    // die den Typ verwenden, und genau die Unterscheidung ist der Sinn von D-611.*
    $zeilen2 = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM ' . Schema::table('relation_records') . ' w
         INNER JOIN ' . Schema::table('node_records') . ' r ON r.id = w.node_record_id
         WHERE r.relation_id = %d AND w.relation_id = %d',
        $stelle2->id,
        $nurAmZiel->id
    ));

    check('und zwar im Satz der Verwendungsstelle', $zeilen2 === 1, (string) $zeilen2);

    $data->clearSettingAtUseSite($stelle2->id, $gefunden->id);

    check('herausgenommen, und die Stelle sagt nichts mehr', $anDerStelle2() === null);
}

echo "\n== Genau ein Renderer, und die zweite Zeile ist abgeschafft ==\n";

// WICHTIG: Hier stand «Eine Zeile hinzufuegen legt einen zweiten Teil an», und diese Zusage ist
// zurueckgenommen. Das ist eine sichtbare Aenderung (PR-9), und sie ist keine Vereinfachung von
// mir, sondern seine Entscheidung.
//
// WICHTIG: Sie stand auf D-548 -- «mehrere DisplayOptions bedeutet mehrere Renderer moeglich»,
// darum `1..*`, darum ein Knopf «Zeile hinzufuegen». D-584 nimmt genau das zurueck, mit seinem
// Wort: «eine Kante und ein Knoten haben genau einen Renderer, dieser ist ein Knoten und kann
// wiederum Unterknoten haben.» Und ausdruecklich: «was dadurch wegfaellt: die geordnete Liste von
// Renderern an einem Knoten, die Multiplizitaet `1..*` an `DisplayOption`, und `sort_order` auf
// dieser Ebene.» Wer mehrere braucht, modelliert sie -- ein Knoten `render list` mit `1..n`.
//
// WICHTIG: Der Traeger, an dem die alte Zusage haengen konnte, ist ausserdem weg: der Eigentuemer
// hat `DisplayOption` geloescht (D-604), und `settingPartsOf()` wurde hier mit der toten Kante
// 44093 gerufen -- sie fand null Teile und meldete «der Pruefknoten hat einen Teil - 0».
//
// ⚠️ **Geprueft wird, dass ein Knoten genau **eine** Wahl haelt** — und seit TASK-057 ist das keine
// Eigenschaft der Ablage mehr, sondern eine Zusage: *eine Spalte konnte nur eine Zahl halten; eine
// Kante koennte mehrere Zeilen tragen, und `1..1` sagt, dass sie es nicht darf
// ([D-642](../../docs/NewConcept/90-decision-log.md)). **Die Zusage ist damit staerker geworden,
// nicht schwaecher.***
$wahlen = (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM ' . Schema::table('node_records') . ' r
       INNER JOIN ' . Schema::table('relation_records') . " v ON v.node_record_id = r.id
      WHERE r.node_id = %d AND r.record_type = 'default'
        AND v.relation_id = %d AND v.value_ref_kind = 'record'",
    $knotenId,
    $framework->settingRelationId(SettingKey::Renderer)
));

check('der Pruefknoten haelt genau eine Wahl', $wahlen === 1, (string) $wahlen);

// Und sie ist ein Verweis auf einen Satz, dessen Knoten den Renderer nennt -- kein Behaelter
// dazwischen, keine zweite Zeile daneben.
$stufen = $wpdb->get_row($wpdb->prepare(
    'SELECT z.name FROM ' . Schema::table('node_records') . ' r
     INNER JOIN ' . Schema::table('nodes_named') . ' z ON z.id = r.node_id
     WHERE r.id = %d',
    traegersatz($knotenId)
), ARRAY_A);

check(
    'und der Satz nennt den Renderer selbst — ohne Huelle dazwischen',
    ($stufen['name'] ?? null) === 'spinner',
    $stufen['name'] ?? 'nichts'
);

echo "\n" . ($bad === 0 ? "Alles gruen: $ok\n" : "$bad fehlgeschlagen, $ok in Ordnung\n");

exit($bad === 0 ? 0 : 1);
