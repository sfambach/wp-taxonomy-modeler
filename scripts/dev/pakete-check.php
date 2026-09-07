<?php declare(strict_types=1);
/**
 * Der Paketlauf — **ein Weg von aussen, wie ein Mensch ihn geht**, gegen eine echte Datenbank.
 *
 *     php scripts/dev/pakete-check.php [pfad/zum/wordpress]
 *
 * ⚠️ **Warum es ihn gibt, und was er ersetzt.** *Am 2026-09-06 sind sechzehn Wächterläufe
 * gestrichen worden, auf sein Wort: «hast so viel checks aber trotzdem läuft dauernd etwas schief,
 * ich fände es besser weniger check dafür aber richtige zu haben». **127 der 152 verlorenen Zusagen
 * sassen in `package1`, `package2`, `package5` und `package6`.** Ihre einzelnen Aussagen stehen
 * verteilt weiter — `shadow-shape`, `label-space`, `id-space`, `path`, `sort-order`,
 * `collapsed-default`, `restore`, `parked-in-shadow`, `record-on-first-write`, `several-values`,
 * `edge-class` und der Kernlauf. **Was fehlte, ist die Stelle, an der ein Paket als Ganzes noch
 * einmal durchgespielt wird** — genau das, was
 * [`waechter-bestand.md`](../../docs/pakete/modelltabellen/waechter-bestand.md) als den bezahlten
 * Preis benannt hat.*
 *
 * ⚠️ **Also nicht vier Läufe wiederbelebt, sondern einer gebaut** — und er misst am **Ergebnis**,
 * nicht an einer Dienstmethode: anlegen, benennen, ein Feld daran, ein Datensatz, ein Wert hinein,
 * wieder heraus, umbenennen, verschieben, ordnen, parken, wiederherstellen, löschen. **Nach jedem
 * Schritt zwei Fragen: steht, was stehen soll — und ist nichts liegengeblieben?**
 *
 * ```mermaid
 * flowchart LR
 *   A["Knoten anlegen"] --> B["Feld daran"]
 *   B --> C["Datensatz, Wert hinein"]
 *   C --> D["umbenennen, verschieben, ordnen"]
 *   D --> E["parken"]
 *   E --> F["wiederherstellen"]
 *   F --> G["loeschen"]
 *   G --> H{"nichts liegengeblieben?"}
 * ```
 *
 * ⚠️ **Er baut sich seine eigene Wiese, Präfix `__pk`, und findet seine Knoten nie über einen
 * Namen aus dem Modell des Eigentümers** ([D-613](../../docs/NewConcept/90-decision-log.md),
 * [D-614](../../docs/NewConcept/90-decision-log.md)) — die Astwurzeln kommen über ihre Rolle.
 *
 * ⚠️ **Und er schreibt nicht in das Modell des Eigentümers.** *Die Klammer aus
 * [`lib/no-write.php`](lib/no-write.php) dreht am Herunterfahren alles zurück, auch nach einem
 * Abbruch. Das eigene Aufräumen bleibt trotzdem drin — es ist hier **selbst ein Prüfschritt**:
 * «löschen» ist Teil des Weges, den ein Mensch geht.* Bewacht von `no-model-write-check.php`.
 *
 * ⚠️ **Kein `Schema::install()`.** *Eine DDL-Anweisung bestätigt in MySQL stillschweigend die
 * offene Umklammerung — der Lauf würde genau den Rückstand festschreiben, den die Klammer
 * verhindert. Die vier gestrichenen Paketläufe riefen sie alle vier auf; hier wird nur **geprüft**,
 * dass die Tabellen stehen.*
 *
 * @see tests/README.md
 * @see docs/pakete/modelltabellen/waechter-bestand.md
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

use Taxmod\Core\Exception\DomainError;
use Taxmod\Core\Exception\ImpossibleMove;
use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\IdentitySpace;
use Taxmod\Core\Model\Label;
use Taxmod\Core\Model\Multiplicity;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\RelationKind;
use Taxmod\Core\Model\SeededRole;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Service\DataEntry;
use Taxmod\Core\Service\Labels;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\WordPress\Admin\SettingsScreen;
use Taxmod\WordPress\Persistence\Query;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
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
$relations = new WpdbRelationRepository();
$log       = new WpdbChangelog(new SystemClock());
$framework = new SeededFrameworkNodes($nodes, $relations, $log);
$records   = new WpdbRecordRepository();
$data      = new DataEntry($records, $relations, $nodes, $framework, new SystemClock());
$texte     = new WpdbLabelRepository();
// ⚠️ **Mit Beschriftungen *und* Datensätzen gebaut, und beide sind hier nicht schmückend.** *Die
// vier gestrichenen Paketläufe bauten den Dienst mit vier Argumenten; `clearTrash()` fasst dann
// weder Beschriftungen noch Datensätze an, und die Zusage «nichts bleibt liegen» wäre grün, weil
// sie nie geräumt hat. **Beim ersten Lauf hier war sie darum rot** — der Aufbau war der Fehler,
// nicht das Aufräumen.*
$editor = new ModelEditor($nodes, $relations, $framework, $log, $texte, $records);
$labels    = new Labels($texte, SettingsScreen::neutralLocale());

/** @var list<int> Jeder Knoten, den dieser Lauf angelegt hat — für das Aufräumen nach einem Abbruch. */
$meine = [];

// ⚠️ *Die Klammer dreht ohnehin zurück; das hier ist der Gürtel zum Hosenträger, für den Fall,
// dass ein Lauf einmal ohne sie gefahren wird.*
register_shutdown_function(static function () use (&$meine): void {
    global $wpdb;

    foreach (array_reverse($meine) as $id) {
        $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . Schema::table('relation_records') . ' WHERE node_record_id IN
             (SELECT id FROM ' . Schema::table('node_records') . ' WHERE node_id = %d)',
            $id
        ));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('node_records') . ' WHERE node_id = %d', $id));
        $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . Schema::table('relations') . ' WHERE from_node_id = %d OR to_node_id = %d',
            $id,
            $id
        ));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('nodes') . ' WHERE id = %d', $id));
    }
});

/** Anlegen und merken — ein Knoten, den dieser Lauf hinterher wieder wegräumt. */
$anlegen = static function (string $name, int $vaterId) use ($editor, &$meine): Node {
    $knoten  = $editor->createNode($name, $vaterId);
    $meine[] = $knoten->id;

    return $knoten;
};

$modell = $framework->rootOf(Branch::Model);
$typen  = $framework->rootOf(Branch::DataTypes);
$korb   = $framework->trash();

echo "\n== 0 · Die Wiese steht ==\n";

// ⚠️ *Aggregiert und nicht dreizehnmal einzeln — die Aussage ist «das Schema steht», nicht
// «Tabelle x steht». Welche fehlt, sagt der Zusatz.*
$fehlend = [];

foreach (Schema::tableNames() as $name) {
    $tabelle = Schema::table($name);

    if (Query::value("Tabelle {$name} suchen", $wpdb->prepare('SHOW TABLES LIKE %s', $tabelle)) !== $tabelle) {
        $fehlend[] = $name;
    }
}

check('jede Tabelle des Schemas steht in der Datenbank (D-007)', $fehlend === [], implode(', ', $fehlend));

if ($fehlend !== []) {
    echo "\n$ok OK, $bad FAIL\n";

    exit(1);
}

$einfacherTyp = $editor->childrenOf($typen->id);
check('unter den Datentypen steht ein Typ bereit', $einfacherTyp !== [], count($einfacherTyp) . ' Typen');

if ($einfacherTyp === []) {
    echo "\n$ok OK, $bad FAIL\n";

    exit(1);
}

$text = $einfacherTyp[0];

echo "\n== 1 · Anlegen ==\n";

$ding = $anlegen('  __pk Platine  ', $modell->id);

check('der Name kommt beschnitten an (D-436)', $ding->name === '__pk Platine', "«{$ding->name}»");
check('die Version beginnt bei 1 (D-634)', $ding->version === 1, (string) $ding->version);
check('der Knoten haengt am gewaehlten Vater (D-581)', $nodes->byId($ding->id)->parentNodeId === $modell->id);
check(
    'und die Einordnung legt keine Kante an (D-581)',
    (int) Query::value('Kanten auf den neuen Knoten zaehlen', $wpdb->prepare(
        'SELECT COUNT(*) FROM ' . Schema::table('relations') . ' WHERE to_node_id = %d',
        $ding->id
    )) === 0
);
check(
    'der Weg wird aus den Vaetern gerechnet, nicht gespeichert',
    $ding->path === $modell->path . '.' . $ding->id,
    $ding->path
);

$leererName = false;

try {
    $editor->createNode('   ', $modell->id);
} catch (DomainError) {
    $leererName = true;
}

check('ein leerer Name wird abgelehnt', $leererName);

echo "\n== 2 · Umbenennen ==\n";

$umbenannt = $editor->rename($ding->id, '__pk Leiterplatte');

check('das Umbenennen hebt die Version (D-053)', $umbenannt->version === 2, (string) $umbenannt->version);
check('der Verweis bleibt derselbe — die Nummer aendert sich nicht (D-053)', $umbenannt->id === $ding->id);

$nochmal = $editor->rename($ding->id, '__pk Leiterplatte');

check('eine unveraenderte Speicherung hebt sie nicht (D-282)', $nochmal->version === 2, (string) $nochmal->version);

$buch = array_map(
    static fn (array $z): string => (string) $z['what'],
    Query::rows('Aenderungsbuch des Knotens lesen', $wpdb->prepare(
        'SELECT what FROM ' . Schema::table('changelog') . ' WHERE owner_id = %d ORDER BY id',
        $ding->id
    ))
);

check(
    'das Aenderungsbuch nennt Anlegen und Umbenennen — und die unveraenderte Speicherung nicht (D-282)',
    $buch === ['created', 'renamed'],
    implode(', ', $buch)
);

echo "\n== 3 · Beschriftung ==\n";

check('ohne gespeicherte Beschriftung antwortet der Knotenname', $labels->of($ding) === '__pk Leiterplatte', $labels->of($ding));

$texte->put(new Label($ding->id, IdentitySpace::Node, SeededRole::Help, 'one', 'de_DE', '__pk der lange Hilfetext'));

check(
    'eine ungepflegte Rolle erbt den Hilfetext nicht, sondern faellt auf den Namen (D-598)',
    $labels->of($ding, SeededRole::Table, 'de_DE') === '__pk Leiterplatte',
    $labels->of($ding, SeededRole::Table, 'de_DE')
);
check(
    'und der Hilfetext antwortet, wo nach ihm gefragt wird (D-598)',
    $labels->of($ding, SeededRole::Help, 'de_DE') === '__pk der lange Hilfetext'
);

$texte->put(new Label($ding->id, IdentitySpace::Node, SeededRole::Form, 'one', 'de_DE', '__pk Leiterplatte'));
$texte->put(new Label($ding->id, IdentitySpace::Node, SeededRole::Form, 'one', 'en_US', '__pk Circuit board'));

check('dasselbe Ding heisst auf deutsch anders (D-646)', $labels->of($ding, SeededRole::Form, 'de_DE') === '__pk Leiterplatte');
check('und auf englisch anders (D-646)', $labels->of($ding, SeededRole::Form, 'en_US') === '__pk Circuit board');

$standard = SettingsScreen::neutralLocale();

check(
    'eine ungepflegte Sprache faellt auf die Standardsprache zurueck, nicht auf eine neutrale Zeile (D-387, D-645)',
    $labels->of($ding, SeededRole::Form, 'fr_FR') === $labels->of($ding, SeededRole::Form, $standard),
    'fr_FR: ' . $labels->of($ding, SeededRole::Form, 'fr_FR') . ", Standard {$standard}: " . $labels->of($ding, SeededRole::Form, $standard)
);

$texte->put(new Label($ding->id, IdentitySpace::Node, SeededRole::Form, 'one', 'de_DE', '__pk zweimal geschrieben'));

$zeilen = (int) Query::value('Beschriftungszeilen des Knotens zaehlen', $wpdb->prepare(
    'SELECT COUNT(*) FROM ' . Schema::table('label_texts') . ' t
     JOIN ' . Schema::table('nodes') . ' n ON n.label_id = t.label_id
     WHERE n.id = %d AND t.number = %s AND t.locale = %s',
    $ding->id,
    'one',
    'de_DE'
));

check('zweimal schreiben laesst eine Zeile stehen (D-580)', $zeilen === 1, "$zeilen Zeilen");
check('und die zweite Schreibung gewinnt', $labels->of($ding, SeededRole::Form, 'de_DE') === '__pk zweimal geschrieben');

echo "\n== 4 · Ein Feld daran ==\n";

$feld = $editor->addField($ding->id, $text->id, '__pk Bezeichnung');

$eigene = array_map(static fn ($e): int => $e->id, $editor->fieldsOf($ding->id));

check('das Feld steht an seinem Besitzer', in_array($feld->id, $eigene, true), implode(', ', $eigene));
check('es zeigt auf den gewaehlten Typ', $feld->toNodeId === $text->id);
check('die Kante traegt eine der drei Klassen (D-639)', in_array($feld->kind, RelationKind::cases(), true), $feld->kind->value);
check('die Multiplizitaet steht an der Kante und ist vorgegeben 1 (D-528)', $feld->multiplicity === Multiplicity::ExactlyOne, $feld->multiplicity->value);

$kind    = $anlegen('__pk Widerstand', $ding->id);
$geerbte = array_map(static fn ($e): int => $e->id, $editor->fieldsOf($kind->id));

check('das Kind erbt das Feld seines Vaters (D-581)', in_array($feld->id, $geerbte, true), implode(', ', $geerbte));

echo "\n== 5 · Ein Datensatz, und ein Wert hinein ==\n";

$satz = $data->create($ding->id);

check('der Datensatz gehoert dem Knoten (D-667)', $satz->nodeId === $ding->id);
check(
    'und er haelt die Modellversion fest, gegen die er geschrieben wurde (D-210)',
    $satz->nodeVersion === $nodes->byId($ding->id)->version,
    $satz->nodeVersion . ' gegen ' . $nodes->byId($ding->id)->version
);
check('ein Knoten mit Feldern darf Datensaetze tragen (D-522)', $editor->fieldsOf($ding->id) !== []);

$data->put($satz->id, $feld->id, TypedValue::ofText('__pk ein Widerstand'));

$werte = $data->valuesOf($satz->id);
$meins = array_values(array_filter($werte, static fn ($w): bool => $w->relationId === $feld->id));

check('genau eine Wertzeile an diesem Feld', count($meins) === 1, count($meins) . ' Zeilen');
check('und der Wert kommt zurueck, wie er hineinging', count($meins) === 1 && $meins[0]->value->text === '__pk ein Widerstand');

$spalten = Query::row('Wertspalten lesen', $wpdb->prepare(
    'SELECT value_text, value_ref FROM ' . Schema::table('relation_records') . '
     WHERE node_record_id = %d AND relation_id = %d',
    $satz->id,
    $feld->id
));

check(
    'er liegt in der Spalte seines Typs und nicht in einer Sammelspalte (D-577, D-578)',
    $spalten !== null && $spalten['value_text'] === '__pk ein Widerstand' && $spalten['value_ref'] === null,
    json_encode($spalten)
);

$data->put($satz->id, $feld->id, TypedValue::ofText('__pk neu geschrieben'));

$danach = array_values(array_filter($data->valuesOf($satz->id), static fn ($w): bool => $w->relationId === $feld->id));

check('zweimal schreiben laesst eine Wertzeile stehen', count($danach) === 1, count($danach) . ' Zeilen');
check('und die zweite Schreibung gewinnt', count($danach) === 1 && $danach[0]->value->text === '__pk neu geschrieben');

$gefunden = array_map(static fn ($r): int => $r->id, $data->findByValue($feld->id, TypedValue::ofText('__pk neu geschrieben')));

check('ueber den Wert ist der Satz wiederzufinden', $gefunden === [$satz->id], implode(', ', $gefunden));

$unterDemKnoten = array_map(static fn ($r): int => $r->id, $data->recordsOf($ding->id));

check('und er steht unter seinem Knoten', in_array($satz->id, $unterDemKnoten, true), implode(', ', $unterDemKnoten));

// ⚠️ **Das Leeren wird hier an einem *zweiten* Feld gemessen, und nur, weil das erste noch
// gebraucht wird.** *Bis zum 2026-09-06 stand hier ein anderer Grund: der Lauf ging dem Fall «erst
// leeren, dann parken» aus dem Weg, weil `INF-062` offen war. **`INF-062` ist entschieden**
// ([D-676](../../docs/NewConcept/90-decision-log.md)), und Abschnitt 8 misst den Fall jetzt selbst —
// an genau dem Feld, das dort geparkt wird.*
$notiz = $editor->addField($ding->id, $text->id, '__pk Notiz');

$data->put($satz->id, $notiz->id, TypedValue::ofText('__pk zu leeren'));

check(
    'ein zweites Feld traegt seinen eigenen Wert',
    count(array_filter($data->valuesOf($satz->id), static fn ($w): bool => $w->relationId === $notiz->id)) === 1
);

$data->clear($satz->id, $notiz->id);

check(
    'das Leeren laesst das Feld unbeantwortet und schreibt keine leere Zeile (D-610)',
    array_filter($data->valuesOf($satz->id), static fn ($w): bool => $w->relationId === $notiz->id) === []
);
check(
    'und es laesst den Wert des Nachbarfeldes stehen',
    count(array_filter($data->valuesOf($satz->id), static fn ($w): bool => $w->relationId === $feld->id)) === 1
);

echo "\n== 6 · Verschieben und ordnen ==\n";

$anderer = $anlegen('__pk Baugruppe', $modell->id);
$tief    = $anlegen('__pk Lage', $kind->id);

$verschoben = $editor->move($kind->id, $anderer->id);

check('das Verschieben zeigt den Knoten auf den neuen Vater um', $nodes->byId($kind->id)->parentNodeId === $anderer->id);
check('der Weg zieht mit', $verschoben->path === $anderer->path . '.' . $kind->id, $verschoben->path);
check(
    'und der Teilbaum kommt mit',
    $nodes->byId($tief->id)->path === $verschoben->path . '.' . $tief->id,
    $nodes->byId($tief->id)->path
);

$imKreis = false;

try {
    $editor->move($anderer->id, $tief->id);
} catch (ImpossibleMove) {
    $imKreis = true;
}

check('ein Knoten kann nicht in seinen eigenen Teilbaum wandern', $imKreis);

$eins = $anlegen('__pk 1', $anderer->id);
$zwei = $anlegen('__pk 2', $anderer->id);

$reihe = static fn (): array => array_map(
    static fn (Node $n): string => $n->name,
    array_values(array_filter(
        $editor->childrenOf($anderer->id),
        static fn (Node $n): bool => str_starts_with($n->name, '__pk ')
    ))
);

check('die Kinder stehen in der Anlegereihenfolge', $reihe() === ['__pk Widerstand', '__pk 1', '__pk 2'], implode(', ', $reihe()));

$editor->moveUp($zwei->id);

check('nach oben schieben vertauscht sie (D-581)', $reihe() === ['__pk Widerstand', '__pk 2', '__pk 1'], implode(', ', $reihe()));

$editor->moveDown($zwei->id);

check('nach unten schieben stellt sie zurueck (D-581)', $reihe() === ['__pk Widerstand', '__pk 1', '__pk 2'], implode(', ', $reihe()));

echo "\n== 7 · Parken und wiederherstellen ==\n";

$warWeg  = $nodes->byId($kind->id)->path;
$warTief = $nodes->byId($tief->id)->path;

$geparkt = $editor->moveToTrash($kind->id);

check('der geparkte Knoten haengt im Papierkorb', $nodes->byId($kind->id)->parentNodeId === $korb->id);
check('und sein Kind ist mitgegangen', str_starts_with($nodes->byId($tief->id)->path, $geparkt->path . '.'), $nodes->byId($tief->id)->path);

$editor->restore($kind->id);

check('das Wiederherstellen setzt ihn genau dorthin zurueck, wo er war', $nodes->byId($kind->id)->path === $warWeg, $nodes->byId($kind->id)->path);
check('und sein Kind mit ihm', $nodes->byId($tief->id)->path === $warTief, $nodes->byId($tief->id)->path);

// ⚠️ **Die Frage, die keiner der Einzelläufe stellt: übersteht ein *Wert* die Reise?** *Ein Knoten,
// der zurückkommt, und ein leerer Datensatz wären zwei grüne Zusagen und ein kaputtes Paket.*
$editor->moveToTrash($ding->id);
$editor->restore($ding->id);

$nachReise = array_values(array_filter($data->valuesOf($satz->id), static fn ($w): bool => $w->relationId === $feld->id));

check(
    'der Wert am Datensatz hat Parken und Wiederherstellen ueberstanden',
    count($nachReise) === 1 && $nachReise[0]->value->text === '__pk neu geschrieben',
    count($nachReise) . ' Zeilen'
);

echo "\n== 8 · Ein Feld parken und zurueckholen ==\n";

// ⚠️ **Erst leeren, dann neu schreiben — und das ist seit dem 2026-09-07 der Kern dieses
// Abschnitts.** *Der Lauf ging diesem Fall aus dem Weg, solange `INF-062` offen war: gemessen kamen
// **zwei** Wertzeilen zurück statt einer, weil das Zurückholen jede Schattenzeile mit `deleted = 1`
// wiederbelebte — auch die, die ein Mensch gelöscht hatte.
// [D-676](../../docs/NewConcept/90-decision-log.md) hat entschieden, wie es stattdessen geht:
// **zurück kommt, was beim Parken lebendig war**, und die Klammer dafür ist die Änderungsgruppe.*
$data->clear($satz->id, $feld->id);
$data->put($satz->id, $feld->id, TypedValue::ofText('__pk nach dem Leeren'));

check(
    'ein geleertes und neu beschriebenes Feld traegt vor dem Parken genau eine Wertzeile',
    count(array_filter($data->valuesOf($satz->id), static fn ($w): bool => $w->relationId === $feld->id)) === 1
);

$editor->removeField($ding->id, $feld->id);

check(
    'die lebende Kantenzeile ist fort',
    Query::value('lebende Kante suchen', $wpdb->prepare(
        'SELECT id FROM ' . Schema::table('relations') . ' WHERE id = %d',
        $feld->id
    )) === null
);

$schatten = Query::row('Kante im Schatten suchen', $wpdb->prepare(
    'SELECT parked_by_group_id FROM ' . Schema::table('relations_history') . '
     WHERE id = %d ORDER BY version DESC LIMIT 1',
    $feld->id
));

check(
    'sie liegt im Schatten, mit ihrer Aenderungsgruppe im Gepaeck (D-575)',
    $schatten !== null && (int) ($schatten['parked_by_group_id'] ?? 0) > 0,
    'Gruppe: ' . (string) ($schatten['parked_by_group_id'] ?? 'keine')
);

check(
    'ihre Wertzeile ist lebend fort und steht im Schatten (D-619)',
    Query::rows('lebende Wertzeilen suchen', $wpdb->prepare(
        'SELECT id FROM ' . Schema::table('relation_records') . ' WHERE relation_id = %d',
        $feld->id
    )) === []
    && Query::rows('Wertzeilen im Schatten suchen', $wpdb->prepare(
        'SELECT id FROM ' . Schema::table('relation_records_history') . ' WHERE relation_id = %d AND deleted = 1',
        $feld->id
    )) !== []
);

// ⚠️ **Zwei Schattenzeilen, und nur eine davon hat das Parken dorthin gebracht** (`INF-062`,
// [D-676](../../docs/NewConcept/90-decision-log.md)). *Die andere hat das Leeren dorthin gebracht;
// vor Fassung 40 war sie von ihr nicht zu unterscheiden — an `deleted` nicht, an `version` nicht
// und an `archived_at` auch nicht.*
$schattenwerte = Query::rows('Schattenwertzeilen zaehlen', $wpdb->prepare(
    'SELECT parked_by_group_id FROM ' . Schema::table('relation_records_history') . '
     WHERE relation_id = %d AND deleted = 1',
    $feld->id
));

$mitGruppe = array_values(array_filter(
    $schattenwerte,
    static fn (array $r): bool => (int) ($r['parked_by_group_id'] ?? 0) > 0
));

check(
    'im Schatten liegen beide Zeilen, aber nur die geparkte traegt eine Aenderungsgruppe (D-676)',
    count($schattenwerte) === 2 && count($mitGruppe) === 1,
    count($schattenwerte) . ' Schattenzeilen, ' . count($mitGruppe) . ' mit Gruppe'
);

check(
    'und es ist dieselbe Gruppe, mit der die Kante geparkt wurde (D-575, D-676)',
    count($mitGruppe) === 1 && $schatten !== null
        && (int) $mitGruppe[0]['parked_by_group_id'] === (int) ($schatten['parked_by_group_id'] ?? 0),
    count($mitGruppe) === 1 ? (string) $mitGruppe[0]['parked_by_group_id'] : 'keine'
);

$editor->restoreField($ding->id, $feld->id);

$zurueck = array_values(array_filter($data->valuesOf($satz->id), static fn ($w): bool => $w->relationId === $feld->id));

check(
    'das Zurueckholen bringt Kante und Wert zurueck, mit demselben Inhalt (D-619)',
    count($zurueck) === 1 && $zurueck[0]->value->text === '__pk nach dem Leeren',
    count($zurueck) . ' Zeilen'
);

// ⚠️ **Die Zusage, die `INF-062` gekostet hat.** *Gemessen am 2026-09-06 standen hier **zwei**
// Zeilen: `__pk neu geschrieben` kam mit zurück, obwohl ein Mensch ihn gelöscht hatte.*
check(
    'der zuvor geleerte Wert bleibt geleert — zurueck kommt nur, was beim Parken lebendig war (D-676)',
    count($zurueck) === 1,
    implode(' | ', array_map(static fn ($w): string => (string) $w->value->text, $zurueck))
);

echo "\n== 9 · Loeschen — und nichts bleibt liegen ==\n";

$alleMeine = $meine;

foreach ([$ding->id, $anderer->id] as $wurzelId) {
    $editor->moveToTrash($wurzelId);
}

$editor->clearTrash([$ding->id, $anderer->id]);

$uebrig = array_values(array_filter($alleMeine, static fn (int $id): bool => $nodes->find($id) !== null));

check('nach dem Leeren des Papierkorbs steht keiner der eigenen Knoten mehr da', $uebrig === [], implode(', ', $uebrig));

check(
    'keine Kante zeigt auf einen Knoten, den es nicht mehr gibt',
    (int) Query::value('haengende Kanten zaehlen', 'SELECT COUNT(*) FROM ' . Schema::table('relations') . ' r
     WHERE NOT EXISTS (SELECT 1 FROM ' . Schema::table('nodes') . ' n WHERE n.id = r.to_node_id)
        OR NOT EXISTS (SELECT 1 FROM ' . Schema::table('nodes') . ' n WHERE n.id = r.from_node_id)') === 0
);

check(
    'kein Datensatz gehoert einem Knoten, den es nicht mehr gibt (D-667)',
    (int) Query::value('Saetze ohne Knoten zaehlen', 'SELECT COUNT(*) FROM ' . Schema::table('node_records') . ' r
     WHERE NOT EXISTS (SELECT 1 FROM ' . Schema::table('nodes') . ' n WHERE n.id = r.node_id)') === 0
);

check(
    'keine Wertzeile gehoert einem Datensatz, den es nicht mehr gibt (D-578)',
    (int) Query::value('Wertzeilen ohne Satz zaehlen', 'SELECT COUNT(*) FROM ' . Schema::table('relation_records') . ' v
     WHERE NOT EXISTS (SELECT 1 FROM ' . Schema::table('node_records') . ' r WHERE r.id = v.node_record_id)') === 0
);

check(
    'keine Beschriftungszeile steht ohne Knoten und ohne Kante da (D-580)',
    (int) Query::value('Beschriftungen ohne Besitzer zaehlen', 'SELECT COUNT(*) FROM ' . Schema::table('labels') . ' l
     WHERE NOT EXISTS (SELECT 1 FROM ' . Schema::table('nodes') . ' n WHERE n.label_id = l.id)
       AND NOT EXISTS (SELECT 1 FROM ' . Schema::table('relations') . ' r WHERE r.label_id = l.id)') === 0
);

// ⚠️ *Die Gegenprobe zur eigenen Wiese: nicht «meine Nummern sind weg», sondern «der Präfix ist
// weg». **Wer nur nach seinen eigenen Nummern fragt, sieht den Rückstand eines früheren Laufs
// nicht** — genau der Rückstand, der am 2026-09-06 auf dem Bildschirm des Eigentümers stand.*
$restKnoten = (int) Query::value('Knoten mit dem Praefix zaehlen', 'SELECT COUNT(*) FROM ' . Schema::table('nodes_named') . ' WHERE name LIKE "\\_\\_pk%"');
$restKanten = (int) Query::value('Kanten mit dem Praefix zaehlen', 'SELECT COUNT(*) FROM ' . Schema::table('relations_named') . ' WHERE name LIKE "\\_\\_pk%"');

check('kein Knoten mit dem Praefix dieses Laufs bleibt stehen', $restKnoten === 0, "$restKnoten Knoten");
check('und keine Kante', $restKanten === 0, "$restKanten Kanten");

echo "\n$ok OK, $bad FAIL\n";

exit($bad === 0 ? 0 : 1);
