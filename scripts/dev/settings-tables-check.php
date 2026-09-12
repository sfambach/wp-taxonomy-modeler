<?php declare(strict_types=1);
/**
 * Die zwei Tabellen des Einstellungsmodells stehen, leer, mit Schatten und Fremdschlüsseln (TASK-096 a).
 *
 *     php scripts/dev/settings-tables-check.php [path/to/wordpress]
 *
 * ⚠️ **Schritt 3 des Bauplans** ([`einstellungen-bauplan.md`](../../docs/einstellungen-bauplan.md)),
 * [D-712](../../docs/NewConcept/90-decision-log.md), [D-717](../../docs/NewConcept/90-decision-log.md),
 * [`einstellungen-anforderungen.md`](../../docs/einstellungen-anforderungen.md) §4. Geprüft wird:
 *
 * 1. **Form** — `settings_object`, `settings_value` und ihre Schatten mit jeder Spalte.
 * 2. **Fremdschlüssel** — fünf Verweisspalten, jede von der Datenbank geprüft (4.2.3).
 * 3. **Leer** — nichts ist hineingewandert (D-717).
 * 4. **Auf der Wiese `__st`** — ein Objekt, Zeilen jeder Wertsorte, die Kante als Zusatz; lesen nach
 *    Träger; ändern hebt die alte Fassung auf; wandern ist wandern; ein Objekt nimmt seine Zeilen mit;
 *    ein Verweis ins Leere wird von der Datenbank abgewiesen; die vier Zusagen der Zeile.
 *
 * @see docs/einstellungen-anforderungen.md
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

wp_set_current_user(1);

use Taxmod\Core\Exception\MalformedSettingsValue;
use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\Setting\{SettingsObject, SettingsValue};
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\WordPress\Persistence\{Schema, SeededFrameworkNodes, WpdbChangelog, WpdbNodeRepository, WpdbRelationRepository, WpdbSettingsRepository};
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

function spalten(string $tabelle): array
{
    global $wpdb;

    return array_map(static fn ($z): string => (string) $z, $wpdb->get_col($wpdb->prepare(
        'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s ORDER BY ORDINAL_POSITION',
        $tabelle
    )) ?: []);
}

$objects = Schema::table('settings_object');
$values  = Schema::table('settings_value');

echo "1 · Form\n";

$objektSpalten = ['id', 'version', 'klasse'];
$zeilenSpalten = ['id', 'version', 'node_id', 'settings_object_id', 'relation_id', 'klasse', 'attribut', 'position', 'aktiv', 'wert_int', 'wert_decimal', 'wert_text', 'wert_knoten_id', 'wert_settings_object_id', 'wert_kante_id'];

check('settings_object hat seine drei Spalten', spalten($objects) === $objektSpalten, implode(',', spalten($objects)));
check('settings_value hat seine vierzehn Spalten', spalten($values) === $zeilenSpalten, implode(',', spalten($values)));
check('settings_object_history trägt dieselben, dazu deleted und archived_at', spalten(Schema::table('settings_object_history')) === [...$objektSpalten, 'deleted', 'archived_at']);
check('settings_value_history trägt dieselben, dazu deleted und archived_at', spalten(Schema::table('settings_value_history')) === [...$zeilenSpalten, 'deleted', 'archived_at']);
check('beide stehen in der Schattenliste', in_array('settings_value', Schema::LIVE_TABLES, true) && in_array('settings_object', Schema::LIVE_TABLES, true));

if ($bad > 0) {
    echo "\nOhne die Form hat der Rest nichts zu prüfen.\n";

    exit(1);
}

echo "\n2 · Fremdschlüssel\n";

foreach (['node_id' => 'nodes', 'settings_object_id' => 'settings_object', 'relation_id' => 'relations', 'wert_knoten_id' => 'nodes', 'wert_settings_object_id' => 'settings_object', 'wert_kante_id' => 'relations'] as $spalte => $ziel) {
    $verweist = (string) $wpdb->get_var($wpdb->prepare(
        'SELECT REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE
         WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s AND REFERENCED_TABLE_NAME IS NOT NULL',
        $values,
        $spalte
    ));

    check("{$spalte} → {$ziel}, von der Datenbank geprüft", $verweist === Schema::table($ziel), $verweist === '' ? 'kein Fremdschlüssel' : $verweist);
}

echo "\n3 · Leer bis auf das Einheitengerüst (D-717, Schritt 7)\n";

$store = new WpdbSettingsRepository();
// ⚠️ *«wir beginnen leer» (D-717) — und seit Fassung 5 des Einheitengerüsts (Schritt 7 des Bauplans) schreibt es die
// Umrechnungssätze der Präfixe und von Celsius und «mit Präfix» der Einheiten hinein: Zeilen an Knoten unter `Constants`
// und die Zeilen ihrer Objekte, sonst nichts.*
// ⚠️ *Und was der Eigentümer seither setzt, steht daneben — gemessen am 2026-09-11 an seiner ersten Wahl: die Zusage
// «nur das Gerüst» war einen Abend lang wahr.*
printf("  --   %d Zeilen, %d Objekte\n", $store->countValues(), $store->countObjects());
check('und jedes Objekt wird von einer Zeile genannt', (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Schema::table('settings_object') . ' o WHERE NOT EXISTS (SELECT 1 FROM ' . Schema::table('settings_value') . ' v WHERE v.wert_settings_object_id = o.id)') === 0);

echo "\n4 · Auf der Wiese\n";

$log       = new WpdbChangelog(new SystemClock());
$knoten    = new WpdbNodeRepository();
$kanten    = new WpdbRelationRepository();
$framework = new SeededFrameworkNodes($knoten, $kanten, $log);
$editor    = new ModelEditor($knoten, $kanten, $framework, $log);

$wiese = $editor->createNode('__st Wiese', $framework->rootOf(Branch::Model)->id);
$ziel  = $editor->createNode('__st Ziel', $framework->rootOf(Branch::Model)->id);
$kante = $editor->addField($wiese->id, $ziel->id, 'ziel');

$objekt = $store->addObject(SettingsObject::create('CompactRenderer'));
check('ein Objekt bekommt seine Id aus der eigenen Tabelle', $objekt->id > 0 && $store->findObject($objekt->id)?->klasse === 'CompactRenderer');

$int     = $store->addValue(SettingsValue::atNode($wiese->id, 'Node', 'display_size', TypedValue::ofInt(40)));
$bool    = $store->addValue(SettingsValue::atNode($wiese->id, 'Node', 'flag', TypedValue::ofBool(true)));
$decimal = $store->addValue(SettingsValue::atNode($wiese->id, 'Umrechnung', 'factor', TypedValue::ofDecimal('0.001')));
$text    = $store->addValue(SettingsValue::atNode($wiese->id, 'CompactRenderer', 'orientation', TypedValue::ofText('vertical')));
$verweis = $store->addValue(SettingsValue::atNode($wiese->id, 'Einheitswert', 'erlaubte_praefixe', TypedValue::ofReference($ziel->id), position: 1));
$objRef  = $store->addValue(SettingsValue::objectAtNode($wiese->id, 'Node', 'renderer', $objekt->id, position: 1));
$innen   = $store->addValue(SettingsValue::inObject($objekt->id, 'CompactRenderer', 'withLabel', TypedValue::ofBool(false)));
$anKante = $store->addValue(SettingsValue::atNode($wiese->id, 'Node', 'display_size', TypedValue::ofInt(20), relationId: $kante->id));

check('sechs Zeilen am Knoten, eine davon an der Kante', count($store->valuesOfNodes([$wiese->id])[$wiese->id]) === 7);

$gelesen = [];

foreach ($store->valuesOfNodes([$wiese->id])[$wiese->id] as $zeile) {
    $gelesen[$zeile->id] = $zeile;
}

check('int kommt als int zurück', $gelesen[$int->id]->value->int === 40);
check('bool kommt als 1 in wert_int zurück und liest sich als wahr', $gelesen[$bool->id]->value->int === 1 && $gelesen[$bool->id]->value->asBool());
check('decimal kommt unverändert zurück', $gelesen[$decimal->id]->value->decimal === '0.001', (string) $gelesen[$decimal->id]->value->decimal);
check('text kommt zurück', $gelesen[$text->id]->value->text === 'vertical');
check('ein Verweis zeigt auf den Knoten', $gelesen[$verweis->id]->value->reference === $ziel->id && $gelesen[$verweis->id]->position === 1);
check('ein komplexer Wert zeigt auf das Objekt', $gelesen[$objRef->id]->valueObjectId === $objekt->id && $gelesen[$objRef->id]->value->isNothing());
check('die Zeile an der Kante trägt die Kante als Zusatz', $gelesen[$anKante->id]->relationId === $kante->id && $gelesen[$anKante->id]->nodeId === $wiese->id);
check('im Objekt steht seine eigene Zeile', ($store->valuesOfObjects([$objekt->id])[$objekt->id][0] ?? null)?->id === $innen->id);
check('der Verweis wird vom Knoten aus gefunden', count($store->valuesReferring([$ziel->id])) === 1);

$schattenVorher = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Schema::table('settings_value_history'));
$store->saveValue($int->withValue(TypedValue::ofInt(41)), $int->version);
$schattenNachher = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Schema::table('settings_value_history'));

check('ändern schreibt Version 2 und hebt Version 1 im Schatten auf', $store->findValue($int->id)?->version === 2 && $store->findValue($int->id)?->value->int === 41 && $schattenNachher === $schattenVorher + 1);
check('der Schatten trägt den alten Wert', (int) $wpdb->get_var($wpdb->prepare('SELECT wert_int FROM ' . Schema::table('settings_value_history') . ' WHERE id = %d AND version = 1', $int->id)) === 40);

$abgelehnt = false;

try {
    $store->saveValue($int->withValue(TypedValue::ofInt(1)), 1);
} catch (\Taxmod\Core\Exception\ConcurrentChange) {
    $abgelehnt = true;
}

check('eine veraltete Fassung wird abgewiesen', $abgelehnt);

$store->forgetValue($text->id);
check('wandern: die Zeile ist weg und steht mit deleted = 1 im Schatten', $store->findValue($text->id) === null && (int) $wpdb->get_var($wpdb->prepare('SELECT deleted FROM ' . Schema::table('settings_value_history') . ' WHERE id = %d ORDER BY version DESC LIMIT 1', $text->id)) === 1);

$store->forgetValue($objRef->id);
$store->forgetObject($objekt->id);
check('ein Objekt nimmt seine Zeilen mit in den Schatten', $store->findObject($objekt->id) === null && $store->findValue($innen->id) === null && (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Schema::table('settings_object_history') . ' WHERE id = %d AND deleted = 1', $objekt->id)) === 1);

$verweigert = false;

try {
    $store->addValue(SettingsValue::atNode($wiese->id, 'Einheitswert', 'erlaubte_praefixe', TypedValue::ofReference(999999999)));
} catch (\RuntimeException) {
    $verweigert = true;
}

check('ein Verweis auf einen Knoten, den es nicht gibt, wird von der Datenbank abgewiesen', $verweigert);

$verweigert = false;

try {
    $store->addValue(SettingsValue::atNode(999999999, 'Node', 'x', TypedValue::ofInt(1)));
} catch (\RuntimeException) {
    $verweigert = true;
}

check('ein Träger, den es nicht gibt, ebenso', $verweigert);

foreach ([
    'kein Träger'          => static fn () => SettingsValue::fromStorage(0, 1, null, null, null, 'Node', 'x', 0, true, 1, null, null, null, null),
    'zwei Träger'          => static fn () => SettingsValue::fromStorage(0, 1, 1, 1, null, 'Node', 'x', 0, true, 1, null, null, null, null),
    'die Kante allein'     => static fn () => SettingsValue::fromStorage(0, 1, null, null, 5, 'Node', 'x', 0, true, 1, null, null, null, null),
    'kein Wert'            => static fn () => SettingsValue::fromStorage(0, 1, 1, null, null, 'Node', 'x', 0, true, null, null, null, null, null),
    'zwei Werte'           => static fn () => SettingsValue::fromStorage(0, 1, 1, null, null, 'Node', 'x', 0, true, 1, null, null, null, 3),
] as $fall => $bau) {
    $abgewiesen = false;

    try {
        $bau();
    } catch (MalformedSettingsValue) {
        $abgewiesen = true;
    }

    check("die Zeile weist ab: {$fall}", $abgewiesen);
}

printf("\n%d ok, %d fehlgeschlagen\n", $ok, $bad);
echo $bad === 0 ? "all green\n" : "$bad FEHLER\n";

exit($bad === 0 ? 0 : 1);
