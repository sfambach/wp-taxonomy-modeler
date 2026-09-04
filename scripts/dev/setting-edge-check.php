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
use Taxmod\Core\Service\Settings;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRecordRepository;
use Taxmod\WordPress\Persistence\WpdbRelationRepository;
use Taxmod\WordPress\Persistence\WpdbSettingRepository;
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
$settings  = new Settings(new WpdbSettingRepository(), $nodes, $framework);
$records   = new WpdbRecordRepository();
$data      = new DataEntry($records, $edges, $nodes, $framework, new SystemClock(), $settings);

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

    $kiloId = (int) $wpdb->get_var(
        'SELECT id FROM ' . Schema::table('nodes') . " WHERE name = 'kilo' LIMIT 1"
    );

    if ($kiloId === 0) {
        check('«kilo» steht im Modell', false);
    } else {
        $satz    = $data->create($kiloId, RecordKind::User);
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
        $normal = feldVon('Passiv', 'Tolerance');

        if ($normal === null) {
            check('ein gewoehnliches Feld zum Vergleich gefunden', false, '«Passiv.Tolerance» fehlt');
        } else {
            check('ein gewoehnliches Feld zum Vergleich gefunden', true);

            $satz2    = $data->create($normal->fromId, RecordKind::User);
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
// ([D-538](../../docs/NewConcept/90-decision-log.md)). Der Exponent von `kilo` steht als Vorgabe da
// und muss es bleiben — sonst hätte die Verweigerung zu viel weggenommen.*
$kiloExponent = null;

if ($exponent !== null) {
    $kiloId = (int) $wpdb->get_var(
        'SELECT id FROM ' . Schema::table('nodes') . " WHERE name = 'kilo' LIMIT 1"
    );

    foreach ($records->ofNode($kiloId) as $satz) {
        if ($satz->kind !== RecordKind::Default) {
            continue;
        }

        foreach ($records->valuesOf($satz->id) as $wert) {
            if ($wert->path === (string) $exponent->id) {
                $kiloExponent = $wert->value->int;
            }
        }
    }
}

check('«kilo» traegt seinen Exponenten 3 als Vorgabe', $kiloExponent === 3, (string) ($kiloExponent ?? 'nichts'));

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
    $settings,
    ShippedRenderers::registry(),
    new SeededTypeNodes($nodes, $framework),
    new Labels(new WpdbLabelRepository(), $framework),
    null,
    new ModelValues($records, $edges, $nodes, $framework)
);

foreach (['Passiv', 'Dimension', 'Integer'] as $name) {
    $id = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT id FROM ' . Schema::table('nodes') . ' WHERE name = %s LIMIT 1',
        $name
    ));

    if ($id === 0) {
        check("«{$name}» steht im Modell", false);

        continue;
    }

    $knoten = $nodes->byId($id);
    $kanten = $edges->fieldEdgesOf([...$knoten->ancestorIds(), $knoten->id]);
    $sicht  = $rendering->previewVisibilityFor($kanten, $settings->resolveForUseSites($kanten));

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

$ausTabelle = (int) $wpdb->get_var(
    'SELECT COUNT(*) FROM ' . Schema::table('settings') . " WHERE setting_key = 'persistent'"
);

if ($ausTabelle > 0) {
    check("noch {$ausTabelle} Zeilen in der Tabelle — der Umzug laeuft", true);
} else {
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

echo "\n" . ($bad === 0 ? "Alles gruen: $ok\n" : "$bad fehlgeschlagen, $ok in Ordnung\n");

exit($bad === 0 ? 0 : 1);
