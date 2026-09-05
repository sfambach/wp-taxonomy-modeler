<?php declare(strict_types=1);
/**
 * Eine Einstellungskante nimmt keinen Benutzerwert an.
 *
 *     php scripts/dev/setting-edge-check.php [path/to/wordpress]
 *
 * ⚠️ **[D-538](../../docs/NewConcept/90-decision-log.md): «nicht speichernd» und «ist eine
 * Einstellung» sind dieselbe Aussage.** *Der Eigentümer hat es hergeleitet: «für den Benutzer werden
 * ja nur die **Felder** gespeichert, nicht die Settings, weil die Settings Eigenschaften des Modells
 * sind.» **Zwei Angaben, die nie widersprechen können, sind eine** — und der Schlüssel `persistent`
 * fällt zugunsten der Relationsart.*
 *
 * ⚠️ **Diese Prüfung hält fest, was dabei gleich bleiben muss**, *und sie ist vor der Umstellung
 * geschrieben: der Exponent der Präfixe verweigert einen Benutzerwert — heute über eine Setting-Zeile,
 * danach über die Art seiner Kante. **Ändert sich das Verhalten, wird sie rot; ändert sich nur der
 * Weg, bleibt sie grün.***
 *
 * ⚠️ *Und der Gegenfall gehört dazu: ein **gewöhnliches** Feld nimmt seinen Wert an. Eine Prüfung, die
 * nur Verweigerungen kennt, wäre auch grün, wenn gar nichts mehr ginge.*
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

require __DIR__ . '/geruest.php';

use Taxmod\Core\Exception\NotYetStorable;
use Taxmod\Core\Model\RecordKind;
use Taxmod\Core\Model\RelationKind;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Service\DataEntry;
use Taxmod\Core\Renderer\ShippedRenderers;
use Taxmod\Core\Service\Labels;
use Taxmod\Core\Service\ModelValues;
use Taxmod\Core\Service\Rendering;
use Taxmod\WordPress\Persistence\SeededTypeNodes;
use Taxmod\WordPress\Persistence\WpdbLabelRepository;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\WpdbChangelog;
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
$edges     = new WpdbRelationRepository();
$log       = new WpdbChangelog(new SystemClock());
$framework = new SeededFrameworkNodes($nodes, $edges, $log);
$records   = new WpdbRecordRepository();
$data      = new DataEntry($records, $edges, $nodes, $framework, new SystemClock());
$geruest   = new Geruest('__se');

/** Ein Feld eines Knotens über seinen Namen. */
function feldVon(string $knotenName, string $feldName): ?\Taxmod\Core\Model\Relation
{
    global $wpdb, $nodes, $edges;

    $id = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT id FROM ' . Schema::table('nodes') . ' WHERE name = %s LIMIT 1',
        $knotenName
    ));

    $knoten = $id === 0 ? null : $nodes->find($id);

    if ($knoten === null) {
        return null;
    }

    foreach ($edges->fieldEdgesOf([...$knoten->ancestorIds(), $knoten->id]) as $eine) {
        if ($eine->name === $feldName) {
            return $eine;
        }
    }

    return null;
}

/**
 * Der erste Knoten unter einem gegebenen — über die Id gefunden, nie über einen Namen.
 *
 * ⚠️ *Das ist die Ersetzung für ein halbes Dutzend `WHERE name = …` in dieser Datei
 * ([D-613](../../docs/NewConcept/90-decision-log.md)). Wessen Kinder gemeint sind, sagt die Id des
 * Elternteils; **wie sie heissen, geht die Prüfung nichts an.***
 */
function ersterUnter(int $elternId): int
{
    global $wpdb, $nodes;

    $eltern = $nodes->find($elternId);

    if ($eltern === null) {
        return 0;
    }

    return (int) $wpdb->get_var($wpdb->prepare(
        'SELECT id FROM ' . Schema::table('nodes') . ' WHERE path LIKE %s ORDER BY id LIMIT 1',
        $wpdb->esc_like($eltern->path . '.') . '%'
    ));
}

/** @var list<int> Was dieser Lauf angelegt hat. */
$meine = [];

register_shutdown_function(static function () use (&$meine): void {
    global $wpdb;

    foreach ($meine as $id) {
        $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('record_values') . ' WHERE record_id = %d', $id));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('records') . ' WHERE id = %d', $id));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('record_values_history') . ' WHERE record_id = %d', $id));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('records_history') . ' WHERE id = %d', $id));
    }
});

echo "\n== Der Exponent nimmt keinen Benutzerwert an ==\n";

$exponent = feldVon('Prefixes', 'exponent');

if ($exponent === null) {
    check('das Feld «Prefixes.exponent» steht im Modell', false, 'nicht gefunden');
} else {
    check('das Feld «Prefixes.exponent» steht im Modell', true);

    // ⚠️ **Nicht «kilo», sondern «irgendein Träger dieses Feldes»**
    // ([D-613](../../docs/NewConcept/90-decision-log.md), vollzieht [D-022](../../docs/NewConcept/90-decision-log.md)).
    // *`kilo` ist sein Inhalt und darf sich jederzeit ändern; **`Prefixes` ist Rahmenwerk** und
    // steht hier als Besitzer der Kante, nicht als gesuchter Name. Gefragt wird der Baum: welcher
    // Knoten erbt dieses Feld? Der erste, den es gibt, beantwortet die Frage genauso gut.*
    $traegerId = ersterUnter($exponent->fromNodeId);

    if ($traegerId === 0) {
        check('ein Knoten erbt «Prefixes.exponent»', false);
    } else {
        check('ein Knoten erbt «Prefixes.exponent»', true, '#' . $traegerId);

        $satz    = $data->create($traegerId, RecordKind::User);
        $meine[] = $satz->id;

        $verweigert = false;

        try {
            $data->put($satz->id, $exponent->id, TypedValue::ofInt(99));
        } catch (NotYetStorable) {
            $verweigert = true;
        }

        check('ein Benutzerwert am Exponenten wird verweigert', $verweigert);

        // ⚠️ **Der Gegenfall.** *Eine Prüfung, die nur Verweigerungen kennt, wäre auch dann grün, wenn
        // überhaupt nichts mehr gespeichert werden könnte.*
        //
        // ⚠️ *Und das gewöhnliche Feld wird **gebaut, nicht gesucht** ([D-613](../../docs/NewConcept/90-decision-log.md)):
        // hier stand `Passiv.Tolerance` — sein Modellinhalt, den er jederzeit umbenennen darf.*
        $gebaut = $geruest->feldMit('Vergleich', 'gewoehnlich', '1');
        $normal = null;

        foreach ($edges->fieldEdgesOf([$gebaut['von']]) as $eine) {
            if ($eine->id === $gebaut['kante']) {
                $normal = $eine;
            }
        }

        if ($normal === null) {
            check('ein gewoehnliches Feld zum Vergleich gefunden', false, 'das Geruest hat keines geliefert');
        } else {
            check('ein gewoehnliches Feld zum Vergleich gefunden', true);

            $satz2    = $data->create($normal->fromNodeId, RecordKind::User);
            $meine[]  = $satz2->id;
            $ging     = true;

            try {
                $data->put($satz2->id, $normal->id, TypedValue::ofText('5%'));
            } catch (NotYetStorable) {
                $ging = false;
            }

            check('und es nimmt seinen Wert an', $ging);
        }
    }
}

echo "\n== Und die Vorgabe bleibt lesbar ==\n";

// ⚠️ *Verweigert heisst «kein **Benutzer**wert» und nicht «kein Wert»
// ([D-538](../../docs/NewConcept/90-decision-log.md)). Der Exponent steht als Vorgabe da und muss es
// bleiben — sonst hätte die Verweigerung zu viel weggenommen.*
//
// ⚠️ **Hier stand «`kilo` trägt seinen Exponenten 3»** ([D-613](../../docs/NewConcept/90-decision-log.md)).
// *Das war zweimal sein Inhalt: der Name **und** die Zahl. Die Zusage, um die es geht, ist keine von
// beiden, sondern: **die Vorgaben unter `Prefixes` überleben die Verweigerung.** Wie viele es sind
// und welche Zahl darin steht, entscheidet er.*
$mitVorgabe = 0;

if ($exponent !== null && ($eltern = $nodes->find($exponent->fromNodeId)) !== null) {
    $kinder = array_map(intval(...), $wpdb->get_col($wpdb->prepare(
        'SELECT id FROM ' . Schema::table('nodes') . ' WHERE path LIKE %s',
        $wpdb->esc_like($eltern->path . '.') . '%'
    )));

    foreach ($kinder as $kindId) {
        foreach ($records->ofNode($kindId) as $satz) {
            if ($satz->kind !== RecordKind::Default) {
                continue;
            }

            foreach ($records->valuesOf($satz->id) as $wert) {
                if ($wert->path === (string) $exponent->id && $wert->value->int !== null) {
                    ++$mitVorgabe;
                }
            }
        }
    }
}

check('die Exponenten stehen als Vorgabe da', $mitVorgabe > 0, (string) $mitVorgabe);

echo "\n== Die Vorschau zeigt keine Einstellungen ==\n";

// ⚠️ **Der Eigentümer hat es am Knoten `Kontakt` gesehen, und ich hatte es zweimal übersehen.**
// *Dort stand «Fields: None yet» und die Vorschau zeigte trotzdem drei Zeilen — die geerbten
// Einstellungskanten der Wurzel, zwei davon als nacktes Textfeld mit `taxmod-no-renderer`, über der
// Zeile «nothing has been entered against this node yet».*
//
// ⚠️ **Seine Diagnose war die richtige:** *«du renderst die Settings, und dort solltest du eigentlich
// die Settings nicht rendern — also haben wir das im Grunde schon, es ist nur fehlgeleitet.»*
$rendering = new Rendering(
    $nodes,
    $framework,
    ShippedRenderers::registry(),
    new SeededTypeNodes($nodes, $framework),
    new Labels(new WpdbLabelRepository(), $framework),
    null,
    new ModelValues($records, $edges, $nodes, $framework)
);

// ⚠️ **Drei gebaute Knoten statt `Passiv`, `Dimension`, `Integer`**
// ([D-613](../../docs/NewConcept/90-decision-log.md)). *Was die Zusage braucht, ist ein Knoten, der
// die Einstellungskanten der Wurzel **erbt** — und das tut jeder, der im Modell hängt. Seine drei
// waren nur zufällig zur Hand, und zwei von ihnen sind sein Inhalt.*
$vorschauKnoten = [
    $geruest->feldMit('Vorschau eins', 'a', '1')['von'],
    $geruest->feldMit('Vorschau zwei', 'b', '1')['von'],
    $geruest->feldMit('Vorschau drei', 'c', '1')['von'],
];

foreach ($vorschauKnoten as $id) {
    $name   = '#' . $id;
    $knoten = $nodes->byId($id);
    $kanten = $edges->fieldEdgesOf([...$knoten->ancestorIds(), $knoten->id]);
    $sicht  = $rendering->previewVisibilityFor($kanten, $rendering->settingsForUseSites($kanten));

    $einstellungen = 0;

    foreach ($sicht['shown'] as $kante) {
        if ($kante->kind->isSetting()) {
            ++$einstellungen;
        }
    }

    check(
        "«{$name}»: keine Einstellungskante in der Vorschau",
        $einstellungen === 0,
        "{$einstellungen} von " . count($sicht['shown'])
    );

    // ⚠️ *Und der Gegenfall: **echte Felder bleiben.** Eine Prüfung, die nur wegnimmt, wäre auch dann
    // grün, wenn die Vorschau gar nichts mehr zeigte.*
    $eigene = 0;

    foreach ($kanten as $kante) {
        if (! $kante->kind->isSetting() && ! $kante->hide) {
            ++$eigene;
        }
    }

    check(
        "«{$name}»: seine {$eigene} echten Felder stehen noch da",
        count($sicht['shown']) === $eigene,
        count($sicht['shown']) . ' statt ' . $eigene
    );
}

echo "\n== Woher die Auskunft kommt ==\n";

// ⚠️ *Hier wurde gezaehlt, ob die `settings`-Tabelle noch `persistent`-Zeilen haelt. **Es gibt sie
// nicht mehr** (D-579) — geblieben ist die Frage, ob die Art der Kante es sagt.*
{
    // ⚠️ *Nach dem Umzug muss die Art der Kante es sagen — sonst wäre die Verweigerung oben aus einem
    // Grund grün, den es nicht mehr gibt.*
    check(
        'die Tabelle sagt nichts mehr, und die Kante ist eine Einstellung',
        $exponent !== null && $exponent->kind === RelationKind::Setting,
        $exponent?->kind->value ?? 'keine Kante'
    );

    check(
        'und eine Einstellung ist eine Komposition (D-526)',
        RelationKind::Setting->isComposition()
    );
}

$geruest->abbauen();

echo "\n" . ($bad === 0 ? "Alles gruen: $ok\n" : "$bad fehlgeschlagen, $ok in Ordnung\n");

exit($bad === 0 ? 0 : 1);
