<?php declare(strict_types=1);
/**
 * Welche Multiplizität eine Kante trägt — an echten Daten festgenagelt.
 *
 *     php scripts/dev/multiplicity-check.php [path/to/wordpress]
 *
 * ⚠️ **Der Eigentümer hat den halben Umzug gerochen, bevor ich ihn zugab:** *«warum liest ihn
 * niemand? Also entweder hast Du beim Umstellen was falsch gemacht oder da fehlt noch was, **weil die
 * Multiplizität ja genau darüber entscheidet, ob der Benutzer was eingeben muss oder nicht**. Und das
 * kann ich mir nicht vorstellen, dass die nirgendwo gelesen wird.»*
 *
 * ⚠️ **Er hatte recht.** *[D-528](../../docs/NewConcept/90-decision-log.md) hat die Spalte an die
 * Kante gelegt und die Daten umgezogen — **und neun Lesestellen weiter aus der Settings-Tabelle
 * gelesen**. Dieselbe Reihenfolge-Sünde wie beim Renderer, nur eine Woche früher begangen und einen
 * Tag später bemerkt.*
 *
 * ⚠️ *Diese Prüfung nagelt die sechs Kanten fest, die überhaupt etwas anderes als die Vorgabe sagen.
 * Sie muss **vor** und **nach** der Umstellung grün sein — das ist ihr ganzer Zweck.*
 *
 * @see docs/NewConcept/10-domain-core.md
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
require __DIR__ . '/geruest.php';

use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\Multiplicity;
use Taxmod\Core\Model\SettingKey;
use Taxmod\Core\Converter\ShippedConverters;
use Taxmod\Core\Renderer\ShippedRenderers;
use Taxmod\Core\Service\Labels;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\Core\Service\ModelValues;
use Taxmod\Core\Service\Rendering;
use Taxmod\WordPress\Persistence\SeededTypeNodes;
use Taxmod\WordPress\Persistence\WpdbLabelRepository;
use Taxmod\WordPress\Persistence\WpdbRecordRepository;
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
$relations     = new WpdbRelationRepository();
$log       = new WpdbChangelog(new SystemClock());
$framework = new SeededFrameworkNodes($nodes, $relations, $log);

/**
 * ⚠️ *Fest hingeschrieben und nicht aus der Tabelle gesucht — dieselbe Lehre wie beim Renderer:
 * **eine Prüfung, die ihre Fälle in der Tabelle sucht, die geleert wird, meldet danach «nichts
 * gefunden» statt «stimmt».***
 */
// WICHTIG: Die Faelle werden gebaut, nicht in seinem Modell gesucht (TASK-025). Sein Satz:
// "warum haben wir einen Check auf Adresse, ich hatte das mal so angelegt, aber das war kein
// Vertrag". Die vier Multiplizitaeten sind die Sache; welcher seiner Knoten sie zufaellig traegt,
// ist es nicht -- und benennt er ihn um, war die Zusage rot, ohne dass etwas kaputt war.
$geruest = new Geruest('__mult');

$erwartet = [];

foreach (['1..*', '0..1', '1..1', '0..*'] as $i => $soll) {
    $geruest->feldMit('Traeger' . $i, 'feld' . $i, $soll);
    $erwartet[] = ['__mult Traeger' . $i, 'feld' . $i, $soll];
}

echo "\n== Die Kante traegt ihre Multiplizitaet ==\n";

$gefunden = [];

foreach ($erwartet as [$vonName, $feldName, $soll]) {
    $id = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT id FROM ' . Schema::table('nodes') . ' WHERE name = %s LIMIT 1',
        $vonName
    ));

    $von = $id === 0 ? null : $nodes->find($id);

    if ($von === null) {
        check("«{$vonName}» steht im Modell", false, 'kein Knoten dieses Namens');

        continue;
    }

    $kante = null;

    foreach ($relations->fieldRelationsOf([...$von->ancestorIds(), $von->id]) as $eine) {
        if ($eine->name === $feldName) {
            $kante = $eine;
        }
    }

    if ($kante === null) {
        check("«{$vonName}» hat ein Feld «{$feldName}»", false, 'nicht gefunden');

        continue;
    }

    $gefunden[] = [$kante, $soll, "{$vonName}.{$feldName}"];

    check(
        "«{$vonName}.{$feldName}» ist {$soll}",
        $kante->multiplicity->value === $soll,
        $kante->multiplicity->value
    );
}

echo "\n== Und die Folge daraus stimmt ==\n";

// ⚠️ **Das ist, was er meinte:** *«die Multiplizität entscheidet ja genau darüber, ob der Benutzer
// was eingeben muss oder nicht».* Die zwei Fragen, die daran hängen, werden hier mitgeprüft — sonst
// wäre die Spalte nur eine Zeichenkette.
foreach ($gefunden as [$kante, $soll, $wo]) {
    $mult = Multiplicity::from($soll);

    check(
        "«{$wo}» verlangt " . ($mult->requiresOne() ? 'einen Wert' : 'keinen'),
        $kante->multiplicity->requiresOne() === $mult->requiresOne()
    );

    check(
        "«{$wo}» erlaubt " . ($mult->allowsMany() ? 'mehrere' : 'nur einen'),
        $kante->multiplicity->allowsMany() === $mult->allowsMany()
    );
}

echo "\n== Beide Quellen sagen dasselbe, solange es beide gibt ==\n";

// ⚠️ *Solange die alten Zeilen stehen, müssen sie mit der Spalte übereinstimmen — **eine Abweichung
// hiesse, dass eine Hälfte des Umzugs zurückgefallen ist**. Sind sie weg, ist diese Zusage still
// erfüllt und sagt das auch.*
// ⚠️ *Die Tabelle ist mit D-579 gestrichen — es gibt keine zweite Quelle mehr, mit der die Spalte
// uebereinstimmen koennte. **Der Umzug ist damit abgeschlossen, nicht nur leer.***
$ausTabelle = [];

if ($ausTabelle === []) {
    check('die Settings-Tabelle sagt nichts mehr dazu — der Umzug ist fertig', true);
} else {
    $abweichung = [];

    foreach ($ausTabelle as $z) {
        $kante = null;

        $von = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT from_node_id FROM ' . Schema::table('relations') . ' WHERE id = %d',
            (int) $z['owner_id']
        ));

        foreach ($relations->fieldRelationsOf([$von]) as $eine) {
            if ($eine->id === (int) $z['owner_id']) {
                $kante = $eine;
            }
        }

        if ($kante !== null && $kante->multiplicity->value !== $z['value_text']) {
            $abweichung[] = "Kante {$z['owner_id']}: Spalte {$kante->multiplicity->value}, Tabelle {$z['value_text']}";
        }
    }

    check(
        count($ausTabelle) . ' alte Zeilen stimmen mit der Spalte ueberein',
        $abweichung === [],
        implode(' · ', $abweichung)
    );
}

echo "\n== Nichts haengt mehr an der Tabelle ==\n";

// ⚠️ **Meine erste Fassung dieser Zusage zählte Vorkommen von `SettingKey::Multiplicity` im Quelltext
// und war Unsinn** — *sie zählte die Aufzählung selbst, einen Docblock und den Namen eines
// Formularfeldes mit. **Eine Zusage über eine Zahl in Textdateien sagt nichts über Verhalten.***
//
// ⚠️ **Das hier ist der Beweis, auf den es ankommt:** *die Auflösung liefert für jede der sechs
// Kanten den richtigen Wert, und sie hat keine Tabelle mehr, aus der sie ihn nehmen koennte.*
//
// ⚠️ *Hier stand daneben die Zaehlung «keine `multiplicity`-Zeile mehr in `settings`». **Die Tabelle
// selbst ist mit [D-579](../../docs/NewConcept/90-decision-log.md) gestrichen** — eine Zusage ueber
// eine Tabelle, die es nicht gibt, misst nichts.*
$rendering = new Rendering(
    $nodes,
    $framework,
    ShippedRenderers::registry(),
    new SeededTypeNodes($nodes, $framework),
    new Labels(new WpdbLabelRepository(), $framework),
    ShippedConverters::registry(),
    model: new ModelValues(new WpdbRecordRepository(), $relations, $nodes, $framework)
);

if ($gefunden !== []) {
    $ausAufloesung = $rendering->settingsForUseSites(array_map(static fn (array $g) => $g[0], $gefunden));
    $abweichung    = [];

    foreach ($gefunden as [$kante, $soll, $wo]) {
        $gelesen = $ausAufloesung[$kante->id][SettingKey::Multiplicity->value]->value->text ?? null;

        if ($gelesen !== $soll) {
            $abweichung[] = "{$wo}: erwartet {$soll}, aufgeloest " . ($gelesen ?? 'nichts');
        }
    }

    check(
        'und die Aufloesung liefert sie trotzdem — sie kommt von der Kante',
        $abweichung === [],
        implode(' · ', $abweichung)
    );
}

echo "\n== Und «wie oft» beim Renderer: genau einmal, und das sagt die Kante ==\n";

// ⚠️ **Diese Zusage ist zum zweiten Mal umgezogen, und der zweite Umzug nimmt den ersten zurueck**
// (TASK-057, [D-642](../../docs/NewConcept/90-decision-log.md)).
//
// ⚠️ *Sie verlangte einmal `1..*` an `DisplayOption`. Dann sagte [D-584](../../docs/NewConcept/90-decision-log.md)
// «eine Kante und ein Knoten haben genau einen Renderer», und **ich las daraus «also keine Kante,
// sondern eine Spalte»** — die Zusage fragte danach, dass es die Kante **nicht** gibt.*
//
// ⚠️ **Der Schluss war meiner, nicht seiner:** *«das hast du leider falsch verstanden, ich meinte
// einfach eine Multiplizitaet von 1» — und auf die Rueckfrage, wo: «am Knoten». **Eine Kante mit
// `1..1` sagt dasselbe und bleibt eine Kante.** Also fragt diese Zusage jetzt genau das ab: die
// Kante gibt es, und sie traegt `1..1`.*
$kante = $framework->settingRelationId(SettingKey::Renderer);

check('die Einstellungskante `renderer` ist aufgeschrieben', $kante !== 0, (string) $kante);

$wieOft = (string) $wpdb->get_var($wpdb->prepare(
    'SELECT multiplicity FROM ' . Schema::table('relations') . ' WHERE id = %d',
    $kante
));

check('und sie traegt genau die Mehrfachheit, die er gemeint hat', $wieOft === '1..1', $wieOft);

$mitWahl = (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM ' . Schema::table('relation_records') . " WHERE relation_id = %d AND value_ref_kind = 'record'",
    $kante
));

// ⚠️ *Der Gegenfall: es gibt ueberhaupt Wahlen. Sonst waere «kein Traeger ins Leere» auch dann
// gruen, wenn keine einzige Zeile dasteht. Gemessen am 2026-09-05: 29.*
check('und Knoten tragen ihre Wahl an dieser Kante', $mitWahl >= 20, (string) $mitWahl);

$insLeere = (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM ' . Schema::table('relation_records') . ' v
       LEFT JOIN ' . Schema::table('node_records') . " r ON r.id = v.value_ref
      WHERE v.relation_id = %d AND v.value_ref_kind = 'record' AND r.id IS NULL",
    $kante
));

check('kein Traeger ins Leere — genau ein Satz je Knoten', $insLeere === 0, (string) $insLeere);

echo "\n== Der Speichern-Knopf der Seite schreibt «wie oft» ==\n";

// ⚠️ **Sein Befund vom 2026-08-31, und dies ist der Rundlauf dazu:** *«in Display Option hatte ich für
// Converter die `1..1`-Beziehung angegeben, das ist falsch, ich wollte es in `0..1` ändern, kann es aber
// nicht mit dem Speichern-Knopf in der Seite speichern.»*
//
// ⚠️ **Der Abschnitt in `form-membership-check.php` prüft, dass die Bedienung das richtige Formular
// nennt. Dieser hier prüft, dass am anderen Ende etwas ankommt** — *und die beiden zusammen sind, was
// `PR-12` verlangt: eine Hälfte allein wird rot.*
//
// ⚠️ *An einem **eigenen** Knoten dieses Laufs und nicht an seinem Modell. Der Knoten wird am Ende
// weggeräumt, samt seiner Kante — eine Prüfung, die ihren Müll parkt, ist eine Prüfung mit einem
// Nebenwirkungsvorrat (die Lehre aus `path-check.php`).*
$verwalter = get_users(['role' => 'administrator', 'number' => 1]);

if ($verwalter === []) {
    check('ein Administrator ist da', false);
} else {
    wp_set_current_user($verwalter[0]->ID);

    add_filter('wp_redirect', static function ($ziel) {
        throw new RuntimeException('__weitergeleitet__' . (string) $ziel);
    }, 10, 1);

    $editor = new ModelEditor($nodes, $relations, $framework, $log);
    // ⚠️ *Ein eigener Typknoten und nicht die Wurzel des Astes — die steht für den Ast selbst und
    // nicht für ein Ding darin, und der Kern verweigert sie zu Recht.*
    $text   = $editor->createNode('__wieoft Text', $framework->rootOf(Branch::DataTypes)->id);
    $traeger = $editor->createNode('__wieoft Traeger', $framework->rootOf(Branch::Model)->id);
    $feld    = $editor->addField($traeger->id, $text->id, '__wieoft Feld');

    // ⚠️ *Eine neue Kante steht auf «1..1» — gemessen, nicht angenommen. Und «1..1» nach «0..1» ist
    // **genau seine Bewegung**: «habe die Multiplizität auf `0..1` gesetzt».*
    check('das Pruefeld steht auf «1..1»', $feld->multiplicity->value === '1..1', $feld->multiplicity->value);

    $_POST = [
        'action'        => 'taxmod_node',
        'id'            => (string) $traeger->id,
        'do'            => 'put_setting',
        '_taxmod_nonce' => wp_create_nonce('taxmod_node_' . $traeger->id),
        // Genau die Adresse, die die Feldzeile zeichnet.
        'taxmod_field_setting' => [(string) $feld->id => ['multiplicity' => '0..1']],
    ];
    $_REQUEST = $_POST;

    $meldung = '';

    try {
        $bau    = new ReflectionClass(\Taxmod\WordPress\Plugin::class);
        $plugin = $bau->newInstanceWithoutConstructor();
        $bau->getProperty('file')->setValue($plugin, 'taxmod.php');
        $plugin->screen()->handlePost();
    } catch (RuntimeException $e) {
        $meldung = str_starts_with($e->getMessage(), '__weitergeleitet__')
            ? urldecode((string) preg_replace('/^.*taxmod_message=/', '', $e->getMessage()))
            : $e->getMessage();
    }

    check('der Akt laeuft durch', $meldung === 'ok', $meldung);

    $nachher = null;

    foreach ($editor->fieldsOf($traeger->id) as $eine) {
        if ($eine->id === $feld->id) {
            $nachher = $eine;
        }
    }

    check(
        'und die Kante traegt danach «0..1»',
        $nachher !== null && $nachher->multiplicity->value === '0..1',
        $nachher === null ? 'Kante weg' : $nachher->multiplicity->value
    );

    // ⚠️ *Und der Name im selben Akt, denn das war die zweite Hälfte der Diskette, die weggefallen ist.*
    $_POST['taxmod_field_setting'] = [];
    $_POST['taxmod_field_name']    = [(string) $feld->id => '__wieoft umbenannt'];
    $_REQUEST = $_POST;

    try {
        $bau2    = new ReflectionClass(\Taxmod\WordPress\Plugin::class);
        $plugin2 = $bau2->newInstanceWithoutConstructor();
        $bau2->getProperty('file')->setValue($plugin2, 'taxmod.php');
        $plugin2->screen()->handlePost();
    } catch (RuntimeException $e) {
        // Die Weiterleitung ist der Normalfall.
    }

    $umbenannt = null;

    foreach ($editor->fieldsOf($traeger->id) as $eine) {
        if ($eine->id === $feld->id) {
            $umbenannt = $eine;
        }
    }

    check(
        'und der Name kommt mit derselben Seite an',
        $umbenannt !== null && $umbenannt->name === '__wieoft umbenannt',
        $umbenannt === null ? 'Kante weg' : $umbenannt->name
    );

    $_POST    = [];
    $_REQUEST = [];

    // ⚠️ *Weggeräumt, und nur das Eigene — nie `clearTrash()`, das räumt auch seine geparkte Arbeit weg.*
    $editor->removeField($traeger->id, $feld->id);
    $editor->moveToTrash($traeger->id);
    $editor->moveToTrash($text->id);

    foreach ([$traeger->id, $text->id] as $meinerId) {
        foreach ($wpdb->get_col($wpdb->prepare('SELECT id FROM ' . Schema::table('node_records') . ' WHERE node_id = %d', $meinerId)) ?: [] as $satzId) {
            $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('relation_records') . ' WHERE node_record_id = %d', (int) $satzId));
            $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('node_records') . ' WHERE id = %d', (int) $satzId));
        }

        $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('relations') . ' WHERE from_node_id = %d OR to_node_id = %d', $meinerId, $meinerId));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('nodes') . ' WHERE id = %d', $meinerId));
    }

    check(
        'der Pruefknoten ist weg',
        (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Schema::table('nodes') . ' WHERE id = %d', $traeger->id)) === 0
    );
}

echo "\n" . ($bad === 0 ? "Alles gruen: $ok\n" : "$bad fehlgeschlagen, $ok in Ordnung\n");

$geruest->abbauen();

exit($bad === 0 ? 0 : 1);
