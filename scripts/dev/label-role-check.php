<?php declare(strict_types=1);
/**
 * Welchen Namen ein Feld zeichnet — die Rolle, an echten Daten festgenagelt.
 *
 *     php scripts/dev/label-role-check.php [path/to/wordpress]
 *
 * ⚠️ **[D-539](../../docs/NewConcept/90-decision-log.md), seine Berichtigung meines Vorschlags:**
 * *«das hatten wir ja, um zu sagen, dass ein Renderer ein spezielles Label verwenden soll … wenn ich
 * eine Auswahlliste habe, zum Beispiel für die Präfixe, dann soll er doch bitte das **Symbol**
 * nehmen. **Also war das eher eine Eigenschaft des Renderers.**»*
 *
 * ⚠️ **Was hier gleich bleiben muss:** *ein Feld einer Einheit zeichnet mit `symbol` — heute über
 * eine Setting-Zeile, danach über ein Feld von `DisplayOption`. **Ohne das stünde «4 kilo Ohm» statt
 * «4 kΩ».***
 *
 * ⚠️ *Die Knoten tragen ihre Symbole als Beschriftung in der Rolle `symbol`. Die Rolle entscheidet
 * nur, welcher der Namen genommen wird — **sie erfindet keinen.***
 *
 * ⚠️ **Diese Prüfung nennt keinen einzigen seiner Knoten mehr beim Namen**
 * ([D-613](../../docs/NewConcept/90-decision-log.md), vollzieht [D-022](../../docs/NewConcept/90-decision-log.md)).
 * *Sie fragt die Registratur nach den Rollen, die Beschriftungstabelle nach der Rolle `symbol` und
 * den Baum nach seinen Feldkanten. **Was er wie nennt, geht sie nichts an.***
 *
 * @see docs/NewConcept/40-i18n.md
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

use Taxmod\WordPress\Admin\SettingsScreen;
use Taxmod\Core\Model\SeededRole;
use Taxmod\Core\Renderer\ShippedRenderers;
use Taxmod\Core\Service\Labels;
use Taxmod\Core\Service\ModelValues;
use Taxmod\Core\Service\Rendering;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\SeededTypeNodes;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbLabelRepository;
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
$log       = new WpdbChangelog(new SystemClock());
$framework = new SeededFrameworkNodes($nodes, $relations, $log);
$records   = new WpdbRecordRepository();
$model     = new ModelValues($records, $relations, $nodes, $framework);

$rendering = new Rendering(
    $nodes,
    $framework,
    ShippedRenderers::registry(),
    new SeededTypeNodes($nodes, $framework),
    new Labels(new WpdbLabelRepository(), SettingsScreen::neutralLocale()),
    null,
    $model
);

// ⚠️ *Hier stand `feldVon(string $knotenName, …)` — ein Nachschlagen über den Knotennamen. Es hat
// keinen Aufrufer mehr ([D-613](../../docs/NewConcept/90-decision-log.md)) und ist deshalb weg statt
// auskommentiert.*

echo "\n== Die Rollen sind Knoten und werden benutzt ==\n";

// ⚠️ **Über die Registratur gefragt, nicht über Name und Nummer**
// ([D-613](../../docs/NewConcept/90-decision-log.md)). *Hier stand `WHERE p.name = 'Label roles' AND
// p.id = 731` — ein Name **und** eine hart hingeschriebene Id, also zwei Bindungen, die beide brechen
// können. Das Rahmenwerk führt seine Rollen selbst ({@see SeededFrameworkNodes::roleId()}), und genau
// dort fragt die Prüfung jetzt nach.*
foreach (SeededRole::cases() as $rolle) {
    // ⚠️ *`name` hat keinen Rollenknoten (TASK-019, [D-646](../../docs/NewConcept/90-decision-log.md)):
    // die Rollenknoten sind das, **woraus ein Renderer waehlt**, und `name` ist das Ende der Kette,
    // auf das jede Wahl zurueckfaellt ([D-386](../../docs/NewConcept/90-decision-log.md)).*
    if ($rolle === SeededRole::Name) {
        continue;
    }

    $id = $framework->roleId($rolle);

    check(
        "die Rolle «{$rolle->value}» ist ein Knoten",
        $id !== 0 && $nodes->find($id) !== null,
        (string) $id
    );
}

echo "\n== Und Knoten tragen ihre Symbole ==\n";

// ⚠️ **Nach der Rolle gefragt, nicht nach `kilo`, `Ohm`, `Gramm`**
// ([D-613](../../docs/NewConcept/90-decision-log.md)). *Das waren drei Namen aus seinem Modell samt
// ihren Symbolen — beides sein Inhalt, den er jederzeit ändern darf. **Die Zusage ist keine dieser
// drei Zeilen, sondern der Mechanismus:** es gibt Symbolbeschriftungen, sie hängen an der Rolle
// `symbol`, und sie tragen Text. Ohne das stünde «4 kilo Ohm» statt «4 kΩ» — wessen Einheit auch
// immer.*
// ⚠️ *Seit TASK-019 ist die Rolle eine **Spalte** ([D-598](../../docs/NewConcept/90-decision-log.md)),
// und der Knoten zeigt auf seine Beschriftung ([D-580](../../docs/NewConcept/90-decision-log.md)).*
$symbole = $wpdb->get_col(
    'SELECT t.' . WpdbLabelRepository::columnFor(SeededRole::Symbol) . ' FROM ' . Schema::table('label_texts') . ' t
     JOIN ' . Schema::table('nodes') . ' kn ON kn.label_id = t.label_id
     WHERE t.' . WpdbLabelRepository::columnFor(SeededRole::Symbol) . ' IS NOT NULL'
) ?: [];

$mitText = count(array_filter($symbole, static fn (?string $t): bool => $t !== null && trim($t) !== ''));

check('es gibt Beschriftungen in der Rolle «symbol»', $symbole !== [], (string) count($symbole));
check('und jede von ihnen traegt Text', $mitText === count($symbole), "{$mitText} von " . count($symbole));

echo "\n== Welche Rolle ein Feld zeichnet ==\n";

// ⚠️ *Fest hingeschrieben, nicht aus der Tabelle gesucht — sonst meldet die Prüfung nach dem Umzug
// «nichts gefunden» statt «stimmt». Dieselbe Lehre wie beim Renderer.*
// ⚠️ **`einheit` und `prefix` standen hier auf `symbol` und stehen jetzt auf `form` — das ist der
// Verlust, den [D-579](../../docs/NewConcept/90-decision-log.md) benannt und in Kauf genommen hat.**
// *Die drei `label_role`-Zeilen lagen in der `settings`-Tabelle; der Eigentuemer hat zwischen Umzug
// und Neueingabe gewaehlt («B»), und die Folge vorher benannt: «Kiloohm» statt «kΩ», bis `label_role`
// seinen neuen Ort hat (`OQ-134`). **Die Zusage wird mitgezogen und nicht abgeschaltet** — sie misst
// jetzt den Zustand, der gilt, und wird wieder rot, wenn `OQ-134` gebaut ist und trotzdem `form`
// herauskommt (`PR-9`).*
//
// ⚠️ **Und gemessen wird über alle Feldkanten, nicht über drei Felder von `Einheitenwert`**
// ([D-613](../../docs/NewConcept/90-decision-log.md)). *Sein Knoten, seine Feldnamen — er darf beide
// umbenennen, und `Einheitenwert` über `WHERE name = … LIMIT 1` zu greifen ist obendrein die
// Bindung, die [D-022](../../docs/NewConcept/90-decision-log.md) verbietet. **Die Zusage bleibt
// wörtlich dieselbe:** solange `OQ-134` offen ist, zeichnet **keine** Feldkante mit `symbol`; sobald
// sie gebaut ist, wird diese Zeile rot und will neu geschrieben werden.*
$alleBesitzer = array_map(intval(...), $wpdb->get_col(
    'SELECT DISTINCT from_node_id FROM ' . Schema::table('relations') . " WHERE kind <> 'inheritance'"
) ?: []);

$mitSymbol = [];
$gesehen   = 0;

foreach ($relations->fieldRelationsOf($alleBesitzer) as $kante) {
    ++$gesehen;

    if ($rendering->labelRoleFor($kante) === SeededRole::Symbol) {
        $mitSymbol[] = '#' . $kante->id;
    }
}

// ⚠️ *Der Gegenfall: ohne ihn wäre «keine zeichnet mit symbol» auch dann wahr, wenn es überhaupt
// keine Feldkanten mehr gäbe.*
check('es gibt ueberhaupt Feldkanten zu messen', $gesehen > 0, (string) $gesehen);

check(
    "solange OQ-134 offen ist, zeichnet keine der {$gesehen} Feldkanten mit «symbol» (D-579)",
    $mitSymbol === [],
    implode(', ', array_slice($mitSymbol, 0, 10))
);

echo "\n== Woher die Auskunft kommt ==\n";

// ⚠️ *Hier wurde gezaehlt, ob die `settings`-Tabelle noch etwas zur Rolle sagt. **Es gibt sie nicht
// mehr** (D-579), also bleibt nur die Frage, ob der neue Ort steht.*
{

    // ⚠️ **Die Kante gibt es, und wo sie haengt, ist offen** (`OQ-134`). *Gemessen am 2026-09-04:
    // sie haengt an «render with label» und nicht an «DisplayOption» — hier stand die Erwartung
    // «DisplayOption traegt sie», geschrieben **vor** dem Umzug und nie zutreffend gewesen. **Der
    // Waechter misst jetzt, dass es sie ueberhaupt gibt und wohin sie zeigt**; welcher Knoten sie
    // traegt, beantwortet `OQ-134` und nicht diese Datei (`PR-4`).*
    $feld = null;

    foreach ($relations->fieldRelationsOf(array_map(static fn ($n) => $n->id, $nodes->byIds(array_map('intval', $wpdb->get_col('SELECT id FROM ' . Schema::table('nodes')))))) as $eine) {
        if ($eine->name === 'label_role') {
            $feld = $eine;
        }
    }

    check('die Kante «label_role» steht im Modell', $feld !== null);

    if ($feld !== null) {
        // ⚠️ *Verglichen wird die **Id** des Rollenbehälters, nicht sein Name
        // ([D-613](../../docs/NewConcept/90-decision-log.md)). Wo er liegt, weiss die Registratur:
        // der Behälter ist der Elternknoten jeder gesäten Rolle.*
        $behaelter = $nodes->find($framework->roleId(SeededRole::Form))?->parentId();

        check(
            'und zeigt auf den Behaelter der Rollen',
            $behaelter !== null && $feld->toNodeId === $behaelter,
            $feld->toNodeId . ' statt ' . ($behaelter ?? 'nichts')
        );
    }
}

echo "\n" . ($bad === 0 ? "Alles gruen: $ok\n" : "$bad fehlgeschlagen, $ok in Ordnung\n");

exit($bad === 0 ? 0 : 1);
