<?php declare(strict_types=1);
/**
 * Die Beschriftungen in ihren zwei Tabellen — an gezaehlten Zahlen, nicht an Namen.
 *
 *     php scripts/dev/label-space-check.php [path/to/wordpress]
 *
 * ⚠️ **Die Zusage ist eine einzige:** *jede Beschriftung liefert nach dem Umbau **denselben Text an
 * derselben Stelle**.*
 *
 * ⚠️ **Seit TASK-019 zeigt der Verweis in die Gegenrichtung** ([D-580](../../docs/NewConcept/90-decision-log.md)):
 * *`nodes.label_id` und `relations.label_id` statt `labels.owner_id`. **Damit ist der Fehler von
 * `INF-035` nicht mehr abzufangen, sondern unmoeglich** — Knoten 5 und Kante 5 zeigen auf zwei
 * verschiedene Zeilen, weil der Verweis aus zwei verschiedenen Tabellen kommt. *Hier stand die
 * Nachstellung dieses Fehlers; sie prueft jetzt, dass die Struktur ihn ausschliesst.*
 *
 * ⚠️ *`labels.owner_kind` und `labels.version` bleiben ([D-640](../../docs/NewConcept/90-decision-log.md),
 * [D-641](../../docs/NewConcept/90-decision-log.md)) — die Zeile sagt weiter selbst, an welchem Raum
 * sie haengt, und traegt ihre Nummer.*
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

// ⚠️ **Kein Wächter schreibt in das Modell des Eigentümers** — die Klammer dreht am Ende
// alles zurück, auch nach einem Abbruch. Siehe `lib/no-write.php` und `tests/README.md`.
require __DIR__ . '/lib/no-write.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\IdentitySpace;
use Taxmod\Core\Model\Label;
use Taxmod\Core\Model\SeededRole;
use Taxmod\Core\Service\Labels;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\WordPress\Admin\SettingsScreen;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbLabelRepository;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRelationRepository;
use Taxmod\WordPress\SystemClock;

// ⚠️ *Seit TASK-019 traegt jeder Knoten eine Beschriftungszeile ([D-580](../../docs/NewConcept/90-decision-log.md)) —
// und dieser Lauf raeumt Knoten mit rohem SQL weg, also am Ende hinter sich her. **Es faellt nur,
// worauf weder ein Knoten noch eine Kante zeigt.***
register_shutdown_function(static fn (): int => \Taxmod\WordPress\Persistence\Schema::forgetOrphanLabels());

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
$texts     = Schema::table('label_texts');
$nodes     = Schema::table('nodes');
$relations = Schema::table('relations');
$standard  = SettingsScreen::neutralLocale();

echo "\n1 · Die Spalten stehen\n";

$spaltenVon = static function (string $tabelle) use ($wpdb): array {
    return $wpdb->get_col($wpdb->prepare(
        'SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
        $tabelle
    ));
};

$anLabels = $spaltenVon($labels);
$anTexten = $spaltenVon($texts);

$check('labels traegt owner_kind', in_array('owner_kind', $anLabels, true));
$check('labels traegt version', in_array('version', $anLabels, true));
$check('labels traegt icon und keinen Text', in_array('icon', $anLabels, true) && ! in_array('text', $anLabels, true));
$check('labels traegt kein owner_id mehr', ! in_array('owner_id', $anLabels, true));

foreach (SeededRole::cases() as $rolle) {
    $check(
        "label_texts traegt die Spalte der Rolle «{$rolle->value}»",
        in_array(WpdbLabelRepository::columnFor($rolle), $anTexten, true)
    );
}

$check('nodes zeigt mit label_id', in_array('label_id', $spaltenVon($nodes), true));
$check('relations zeigt mit label_id', in_array('label_id', $spaltenVon($relations), true));
$check('nodes traegt keinen Namen mehr', ! in_array('name', $spaltenVon($nodes), true));
$check('relations traegt keinen Namen mehr', ! in_array('name', $spaltenVon($relations), true));

echo "\n2 · Die Wanderung hat nichts verloren\n";

// ⚠️ *Die Zahlen, die die Wanderung selbst festgehalten hat — verglichen statt nachgerechnet. **Wer
// sie nachrechnet, misst dieselbe Datenbank ein zweites Mal und bemerkt nichts.***
$gemerkt = get_option('taxmod_labeltexts_shape', null);

$mitNamen = static fn (string $tabelle): int => (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$tabelle} o
     JOIN " . Schema::table('label_texts') . " t ON t.label_id = o.label_id
     WHERE t.text_name IS NOT NULL AND t.text_name <> ''"
);

if (! is_array($gemerkt)) {
    echo "  --   die Wanderung hat hier keine Zahlen hinterlassen (frische Installation)\n";
} else {
    printf(
        "       bei der Wanderung: %d Knotennamen, %d Kantennamen, %d Beschriftungen, Standardsprache %s\n",
        (int) ($gemerkt['nodes'] ?? -1),
        (int) ($gemerkt['relations'] ?? -1),
        (int) ($gemerkt['texts'] ?? -1),
        (string) ($gemerkt['locale'] ?? '?')
    );

    // ⚠️ **Die Zusage stand auf «nie weniger als bei der Wanderung» und war damit falsch**
    // (umgeschrieben am 2026-09-05, `PR-9`). *Sie las die Zahl der Knoten **mit Namen** als Mass
    // dafuer, dass keiner verlorenging — aber **ein geloeschter Knoten nimmt seinen Namen
    // mit, und das ist richtig so.** An diesem Abend wurden drei liegengebliebene Waechterknoten
    // weggeraeumt; danach meldete sie «135 gegen 137» als Ausfall, obwohl genau das Gewollte
    // geschehen war.*
    //
    // ⚠️ **Was gemeint war, ist enger und haelt bei jedem Bestand:** *kein **lebender** Knoten steht
    // ohne Namen da. Ein Name geht dann verloren, wenn sein Knoten bleibt und die Beschriftung
    // fehlt — nicht, wenn beide zusammen gehen.*
    $lebende = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Schema::table('nodes'));

    $check(
        'kein lebender Knoten steht ohne Namen da',
        $mitNamen($nodes) === $lebende,
        $mitNamen($nodes) . ' von ' . $lebende . ' (bei der Wanderung: ' . (int) ($gemerkt['nodes'] ?? 0) . ')'
    );

    $check(
        'die Standardsprache der Wanderung ist noch dieselbe',
        (string) ($gemerkt['locale'] ?? '') === $standard,
        (string) ($gemerkt['locale'] ?? '') . ' gegen ' . $standard
    );
}

echo "\n3 · Kein Knoten ohne Beschriftung, keine Beschriftung ohne Text\n";

$ohneLabel = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$nodes} WHERE label_id = 0 OR label_id IS NULL");
$check('kein Knoten ohne label_id', $ohneLabel === 0, "$ohneLabel Knoten");

$ohneNamen = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$nodes} n
     WHERE NOT EXISTS (
       SELECT 1 FROM {$texts} t
       WHERE t.label_id = n.label_id AND t.text_name IS NOT NULL AND t.text_name <> ''
     )"
);
$check('kein Knoten ohne Namen', $ohneNamen === 0, "$ohneNamen Knoten");

$ohneVersion = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$labels} WHERE version < 1");
$check('jede Beschriftung hat eine Version', $ohneVersion === 0, "$ohneVersion ohne Version");

$ohneRaum = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$labels} WHERE owner_kind = ''");
$check('keine Beschriftung ohne Raum', $ohneRaum === 0, "$ohneRaum Zeilen");

echo "\n4 · Knoten und Kante teilen keine Beschriftung, weil sie es nicht koennen\n";

// ⚠️ **Der gemessene Fehler von `INF-035`, und warum er nicht mehr eintreten kann.** *Ein Knoten und
// eine Kante bekommen zwei verschiedene Beschriftungen, denn jede der beiden Tabellen zeigt mit
// ihrer eigenen `label_id`.*
$nodeRepo  = new WpdbNodeRepository();
$relRepo   = new WpdbRelationRepository();
$log       = new WpdbChangelog(new SystemClock());
$framework = new SeededFrameworkNodes($nodeRepo, $relRepo, $log);
$editor    = new ModelEditor($nodeRepo, $relRepo, $framework, $log);
$ablage    = new WpdbLabelRepository();

$traeger = $editor->createNode('__ls Traeger', $framework->rootOf(Branch::Model)->id);
$ziel    = $editor->createNode('__ls Ziel', $framework->rootOf(Branch::Model)->id);
$kante   = $editor->addField($traeger->id, $ziel->id, '__ls Feld');

$ablage->put(new Label($traeger->id, IdentitySpace::Node, SeededRole::Form, 'one', 'de_DE', '__ls am Knoten'));
$ablage->put(new Label($kante->id, IdentitySpace::Relation, SeededRole::Form, 'one', 'de_DE', '__ls an der Kante'));

$nurForm = static fn (array $zeilen): array => array_values(array_filter(
    $zeilen,
    static fn (Label $l): bool => $l->role === SeededRole::Form
));

$amKnoten   = $nurForm($ablage->forOwners([$traeger->id], IdentitySpace::Node));
$anDerKante = $nurForm($ablage->forOwners([$kante->id], IdentitySpace::Relation));

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

$check(
    'und die beiden Verweise zeigen auf verschiedene Zeilen',
    (int) $wpdb->get_var($wpdb->prepare("SELECT label_id FROM {$nodes} WHERE id = %d", $traeger->id))
        !== (int) $wpdb->get_var($wpdb->prepare("SELECT label_id FROM {$relations} WHERE id = %d", $kante->id))
);

echo "\n5 · Der Rueckfall geht auf die Standardsprache\n";

// ⚠️ **Die Gegenprobe zu [D-387](../../docs/NewConcept/90-decision-log.md) und
// [D-645](../../docs/NewConcept/90-decision-log.md):** *eine Sprache, fuer die nichts gepflegt ist,
// bekommt den Text der Standardsprache — und **nicht** eine leere Zelle.*
$leser = new Labels($ablage, $standard);

$ablage->put(new Label($traeger->id, IdentitySpace::Node, SeededRole::Form, 'one', $standard, '__ls in der Standardsprache'));

$check(
    'eine Sprache, fuer die nichts gepflegt ist, bekommt den Text der Standardsprache',
    $leser->of($nodeRepo->byId($traeger->id), SeededRole::Form, 'fr_FR') === '__ls in der Standardsprache',
    $leser->of($nodeRepo->byId($traeger->id), SeededRole::Form, 'fr_FR')
);

// ⚠️ *Und der Name ist selbst eine Beschriftung je Sprache (D-646): steht er in der angefragten
// Sprache da, schlaegt er die Rollenbeschriftung der Standardsprache.*
$ablage->put(new Label($traeger->id, IdentitySpace::Node, SeededRole::Name, 'one', 'de_DE', '__ls deutscher Name'));

$check(
    'der Name der angefragten Sprache schlaegt die Rolle der Standardsprache',
    $leser->of($nodeRepo->byId($traeger->id), SeededRole::Table, 'de_DE') === '__ls deutscher Name',
    $leser->of($nodeRepo->byId($traeger->id), SeededRole::Table, 'de_DE')
);

echo "\n6 · Die Version steigt beim Ueberschreiben\n";

$versionAm = static fn (int $id): int => (int) $wpdb->get_var($wpdb->prepare(
    "SELECT l.version FROM {$labels} l JOIN {$nodes} n ON n.label_id = l.id WHERE n.id = %d",
    $id
));

$vorher = $versionAm($traeger->id);

$ablage->put(new Label($traeger->id, IdentitySpace::Node, SeededRole::Form, 'one', 'de_DE', '__ls am Knoten, anders'));

$nachher = $versionAm($traeger->id);

$check('ein Schreiben hebt die Version', $nachher === $vorher + 1, "$vorher -> $nachher");

echo "\n7 · Der Knotenname wird in der gewaehlten Sprache gelesen und geschrieben\n";

// ⚠️ **Sein Befund vom 2026-09-06** (TASK-061): *«node name ist noch nicht sprachabhaengig, obwohl du
// geschrieben hast, dass label gebaut wurde».* **Gespeichert war er es**, `label_texts` traegt
// `locale` — **der Weg dorthin fehlte**: der Leser gab fest die Standardsprache in den Verbund, der
// Schreiber nahm keine Sprache entgegen. *Darum stand sein deutscher Text in der englischen Zeile.*
//
// ⚠️ **Drei Zusagen, und die dritte ist die, die ohne sie still kaputtgeht.**

$deutsch  = new WpdbNodeRepository('de_DE');
$imStand  = new WpdbNodeRepository($standard);
$franzoes = new WpdbNodeRepository('fr_FR');

// `$traeger` traegt seit Abschnitt 5 zwei Sprachen: den Namen aus dem Anlegen und den deutschen.
$check(
    'ein Knoten mit zwei Sprachen liefert der Standardsprache ihren Text',
    $imStand->byId($traeger->id)->name === '__ls Traeger',
    $imStand->byId($traeger->id)->name
);

$check(
    'und derselbe Knoten liefert der zweiten Sprache ihren',
    $deutsch->byId($traeger->id)->name === '__ls deutscher Name',
    $deutsch->byId($traeger->id)->name
);

// ⚠️ *`$ziel` hat nur den Namen aus dem Anlegen, also nur die Standardsprache.*
$check(
    'wo eine Sprache fehlt, kommt die Standardsprache',
    $franzoes->byId($ziel->id)->name === '__ls Ziel',
    $franzoes->byId($ziel->id)->name
);

$textIn = static fn (int $id, string $sprache): ?string => $wpdb->get_var($wpdb->prepare(
    "SELECT t.text_name FROM {$nodes} n JOIN {$texts} t ON t.label_id = n.label_id
     WHERE n.id = %d AND t.locale = %s AND t.number = 'one'",
    $id,
    $sprache
));

// ⚠️ **Speichern in Sprache A laesst Sprache B unangetastet.** *Umbenannt wird durch einen Bearbeiter,
// dessen Speicher auf Deutsch steht — die englische Zeile darf das nicht merken.*
$aufDeutsch = new ModelEditor($deutsch, $relRepo, $framework, $log);
$aufDeutsch->rename($traeger->id, '__ls deutsch umbenannt');

$check(
    'ein Speichern in der zweiten Sprache schreibt dort',
    $textIn($traeger->id, 'de_DE') === '__ls deutsch umbenannt',
    (string) $textIn($traeger->id, 'de_DE')
);

$check(
    'und laesst die Standardsprache unangetastet',
    $textIn($traeger->id, $standard) === '__ls Traeger',
    (string) $textIn($traeger->id, $standard)
);

// ⚠️ **Und der heikle Teil: der Rueckfall wird nicht festgeschrieben.** *`$ziel` hat keinen
// franzoesischen Namen und **zeigt** deshalb den der Standardsprache. Wird an ihm auf Franzoesisch
// irgendetwas anderes gespeichert — hier das Verstecken —, faehrt genau dieser angezeigte Text als
// Name mit. **Schriebe der Speicher ihn blind, waere die englische Beschriftung stillschweigend zur
// franzoesischen geworden**, und ein spaeteres Umbenennen des Originals liesse sie stehen.*
$vorSchreiben = $franzoes->byId($ziel->id);
$franzoes->save($vorSchreiben->withHide(true), $vorSchreiben->version);

$check(
    'ein Speichern auf dem Rueckfall legt keine Zeile in der gewaehlten Sprache an',
    $textIn($ziel->id, 'fr_FR') === null,
    'fr_FR traegt jetzt «' . (string) $textIn($ziel->id, 'fr_FR') . '»'
);

$check(
    'und die Standardsprache steht unveraendert da',
    $textIn($ziel->id, $standard) === '__ls Ziel',
    (string) $textIn($ziel->id, $standard)
);

// ⚠️ *Die Gegenprobe, damit die Zusage oben nicht bloss «es wird nie geschrieben» heisst: ein
// **anderer** Text in derselben Sprache legt die Zeile sehr wohl an.*
$aufFranzoesisch = new ModelEditor($franzoes, $relRepo, $framework, $log);
$aufFranzoesisch->rename($ziel->id, '__ls cible');

$check(
    'ein wirklich anderer Text legt die Zeile der gewaehlten Sprache an',
    $textIn($ziel->id, 'fr_FR') === '__ls cible',
    (string) $textIn($ziel->id, 'fr_FR')
);

$check(
    'und auch dabei bleibt die Standardsprache stehen',
    $textIn($ziel->id, $standard) === '__ls Ziel',
    (string) $textIn($ziel->id, $standard)
);

echo "\n8 · Auch der Kantenname wird in der gewaehlten Sprache gelesen und geschrieben\n";

// ⚠️ **Der Rest von TASK-061, als TASK-065 nachgeholt.** *Der Knotenname folgte seit dem 2026-09-07
// der gewaehlten Sprache, der Kantenname nicht — `nameArgs()` gab fest die Standardsprache in den
// Verbund. **Ein Feld hiess damit in jeder Sprache gleich**, auch wo der Knoten dahinter uebersetzt
// war.*
//
// ⚠️ **Gemessen wird hier an einer Kante und nicht an einem Knoten**, weil die Kante einen Fall hat,
// den der Knoten nicht hat: *sie darf **namenlos** sein
// ([D-580](../../docs/NewConcept/90-decision-log.md)) — dann hat sie gar keine Beschriftungszeile,
// und der Rueckfall muss das aushalten, **ohne eine anzulegen**.*

$kantenImStand  = new WpdbRelationRepository($standard);
$kantenDeutsch  = new WpdbRelationRepository('de_DE');
$kantenFranzoes = new WpdbRelationRepository('fr_FR');

$textAnKante = static fn (int $id, string $sprache): ?string => $wpdb->get_var($wpdb->prepare(
    "SELECT t.text_name FROM {$relations} r JOIN {$texts} t ON t.label_id = r.label_id
     WHERE r.id = %d AND t.locale = %s AND t.number = 'one'",
    $id,
    $sprache
));

$check(
    'eine frisch angelegte Kante traegt ihren Namen in der Standardsprache',
    $kantenImStand->byId($kante->id)?->name === '__ls Feld',
    (string) $kantenImStand->byId($kante->id)?->name
);

// ⚠️ **Speichern in Sprache A laesst Sprache B unangetastet** — *umbenannt wird durch einen
// Bearbeiter, dessen Kantenspeicher auf Deutsch steht.*
$kantenAufDeutsch = new ModelEditor($deutsch, $kantenDeutsch, $framework, $log);
$kantenAufDeutsch->renameField($traeger->id, $kante->id, '__ls Feld deutsch');

$check(
    'ein Umbenennen in der zweiten Sprache schreibt dort',
    $textAnKante($kante->id, 'de_DE') === '__ls Feld deutsch',
    (string) $textAnKante($kante->id, 'de_DE')
);

$check(
    'und laesst die Standardsprache der Kante unangetastet',
    $textAnKante($kante->id, $standard) === '__ls Feld',
    (string) $textAnKante($kante->id, $standard)
);

$check(
    'die Kante liefert jeder der beiden Sprachen ihren Text',
    $kantenDeutsch->byId($kante->id)?->name === '__ls Feld deutsch'
        && $kantenImStand->byId($kante->id)?->name === '__ls Feld',
    $kantenDeutsch->byId($kante->id)?->name . ' / ' . $kantenImStand->byId($kante->id)?->name
);

$check(
    'wo eine Sprache an der Kante fehlt, kommt die Standardsprache',
    $kantenFranzoes->byId($kante->id)?->name === '__ls Feld',
    (string) $kantenFranzoes->byId($kante->id)?->name
);

// ⚠️ **Der heikle Teil, derselbe wie am Knoten: der Rueckfall wird nicht festgeschrieben.** *Die
// Kante hat keinen franzoesischen Namen und **zeigt** deshalb den der Standardsprache. Wird an ihr
// auf Franzoesisch etwas anderes gespeichert — hier das Verstecken —, faehrt genau dieser angezeigte
// Text als Name mit.*
$kantenAufFranzoesisch = new ModelEditor($franzoes, $kantenFranzoes, $framework, $log);
$kantenAufFranzoesisch->hideField($traeger->id, $kante->id, true);

$check(
    'ein Speichern auf dem Rueckfall legt an der Kante keine Zeile in der gewaehlten Sprache an',
    $textAnKante($kante->id, 'fr_FR') === null,
    'fr_FR traegt jetzt «' . (string) $textAnKante($kante->id, 'fr_FR') . '»'
);

$check(
    'und die Standardsprache der Kante steht unveraendert da',
    $textAnKante($kante->id, $standard) === '__ls Feld',
    (string) $textAnKante($kante->id, $standard)
);

// ⚠️ *Die Gegenprobe: ein **anderer** Text in derselben Sprache legt die Zeile sehr wohl an.*
$kantenAufFranzoesisch->renameField($traeger->id, $kante->id, '__ls champ');

$check(
    'ein wirklich anderer Text legt die Zeile der gewaehlten Sprache an der Kante an',
    $textAnKante($kante->id, 'fr_FR') === '__ls champ',
    (string) $textAnKante($kante->id, 'fr_FR')
);

$check(
    'und dabei bleiben die beiden anderen Sprachen der Kante stehen',
    $textAnKante($kante->id, $standard) === '__ls Feld'
        && $textAnKante($kante->id, 'de_DE') === '__ls Feld deutsch',
    (string) $textAnKante($kante->id, $standard) . ' / ' . (string) $textAnKante($kante->id, 'de_DE')
);

// ⚠️ **Und der Fall, den es am Knoten nicht gibt: die namenlose Kante** (D-580). *Sie hat in keiner
// Sprache eine Zeile, `COALESCE` macht daraus die leere Zeichenkette — und ein Speichern in einer
// anderen Sprache darf ihr **keine** anlegen. **Ohne die Sicherung waere hier die leere Zeichenkette
// als franzoesischer Name eingetragen worden**, oder schlimmer: der Text, den ein spaeterer Rueckfall
// geliefert haette.*
$namenlos = $editor->addField($traeger->id, $ziel->id, '__ls Namenlos');
$ablage->forget($namenlos->id, IdentitySpace::Relation, SeededRole::Name, Label::BASE_NUMBER, $standard);

$zeilenAn = static fn (int $id): int => (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$relations} r JOIN {$texts} t ON t.label_id = r.label_id
     WHERE r.id = %d AND t.text_name IS NOT NULL AND t.text_name <> ''",
    $id
));

$check(
    'eine namenlose Kante kommt in jeder Sprache namenlos an',
    $kantenImStand->byId($namenlos->id)?->name === ''
        && $kantenFranzoes->byId($namenlos->id)?->name === '',
    '«' . (string) $kantenImStand->byId($namenlos->id)?->name . '» / «'
        . (string) $kantenFranzoes->byId($namenlos->id)?->name . '»'
);

$kantenAufFranzoesisch->hideField($traeger->id, $namenlos->id, true);

$check(
    'und ein Speichern in einer anderen Sprache legt ihr keine Beschriftungszeile an',
    $zeilenAn($namenlos->id) === 0,
    $zeilenAn($namenlos->id) . ' Zeile(n)'
);

echo "\n9 · Der Lauf raeumt hinter sich auf\n";

$relRepo->purgeRelationsTouching($traeger->id);
$ablage->forgetOwners([$traeger->id, $ziel->id], IdentitySpace::Node);
$ablage->forgetOwners([$kante->id, $namenlos->id], IdentitySpace::Relation);

foreach ([$traeger, $ziel] as $weg) {
    $stand = $nodeRepo->find($weg->id);

    if ($stand !== null) {
        $nodeRepo->purgeSubtree($stand);
    }
}

$wpdb->query('DELETE FROM ' . Schema::table('changelog') . ' WHERE after_state LIKE "%__ls %"');

$rest = (int) $wpdb->get_var("SELECT COUNT(*) FROM " . Schema::table('nodes_named') . " WHERE name LIKE '\\_\\_ls %'");

$check('die Wiese ist wieder weg', $rest === 0, "$rest Zeile(n) uebrig");

echo "\n" . ($bad === 0 ? "Alles gruen: $ok\n" : "$bad fehlgeschlagen, $ok in Ordnung\n");

exit($bad === 0 ? 0 : 1);
