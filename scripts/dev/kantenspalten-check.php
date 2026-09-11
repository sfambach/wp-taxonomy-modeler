<?php declare(strict_types=1);
/**
 * Multiplizität und `read_only` sind Spalten der Kante — keine Einstellungen (TASK-094, TASK-095).
 *
 *     php scripts/dev/kantenspalten-check.php [path/to/wordpress]
 *
 * ⚠️ **Schritt 2 des Bauplans** ([`einstellungen-bauplan.md`](../../docs/einstellungen-bauplan.md)),
 * [D-713](../../docs/NewConcept/90-decision-log.md), [D-714](../../docs/NewConcept/90-decision-log.md),
 * [`modell-anforderungen.md`](../../docs/modell-anforderungen.md) §1.2 und §1.3. Geprüft wird:
 *
 * 1. **Die Spalte `read_only` steht**, lebend und im Schatten; `multiplicity` ebenso.
 * 2. **Kein Schlüssel mehr**: `SettingKey` kennt weder `read_only` noch `multiplicity`; keine lebende
 *    Einstellungskante heisst so; kein lebender Einstellungssatz trägt eine Zeile dafür.
 * 3. **Auf der Wiese `__ks`**: der Dienst schreibt die Spalte; die Feldzeile zeichnet sie als Schalter
 *    und schickt sie zurück; ein nur lesbares Feld bietet beim Bearbeiten kein Eingabefeld an; die
 *    Kopie eines Knotens trägt die Spalte mit.
 * 4. **Bool nur `1..1`** (Modell 1.2.3): der Wähler bietet einem Feld auf `Boolean` nur `1..1` an,
 *    der Kern weist `0..*` ab; einem Feld auf `Integer` stehen alle vier offen.
 *
 * @see docs/modell-anforderungen.md
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

define('WP_ADMIN', true);
define('WP_USE_THEMES', false);
require $root . '/wp-load.php';

// ⚠️ **Kein Wächter schreibt in das Modell des Eigentümers** — die Klammer dreht am Ende
// alles zurück, auch nach einem Abbruch. Siehe `lib/no-write.php` und `tests/README.md`.
require __DIR__ . '/lib/no-write.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

wp_set_current_user(1);

use Taxmod\Core\Exception\MultiplicityNotAllowed;
use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\EdgeColumn;
use Taxmod\Core\Model\Multiplicity;
use Taxmod\Core\Model\SettingKey;
use Taxmod\Core\Model\Type\{BoolType, IntType};
use Taxmod\Core\Renderer\Purpose;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\Core\Converter\ShippedConverters;
use Taxmod\Core\Renderer\ShippedRenderers;
use Taxmod\Core\Service\{Labels, ModelValues, Rendering};
use Taxmod\WordPress\Admin\SettingsScreen;
use Taxmod\WordPress\Plugin;
use Taxmod\WordPress\Persistence\{Schema, SeededFrameworkNodes, SeededTypeNodes, WpdbChangelog, WpdbLabelRepository, WpdbNodeRepository, WpdbRecordRepository, WpdbRelationRepository};
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

function hatSpalte(string $tabelle, string $spalte): bool
{
    global $wpdb;

    return $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$tabelle} LIKE %s", $spalte)) !== null;
}

function abschicken(array $post): bool
{
    $_POST    = $post;
    $_REQUEST = $post;

    $gewandert = false;
    $GLOBALS['taxmod_letzte_adresse'] = '';

    $fang = static function (string $ort) use (&$gewandert): string {
        $gewandert = true;
        $GLOBALS['taxmod_letzte_adresse'] = $ort;
        throw new RuntimeException('redirect');
    };

    add_filter('wp_redirect', $fang, 1);

    $rc     = new ReflectionClass(Plugin::class);
    $plugin = $rc->newInstanceWithoutConstructor();
    $rc->getProperty('file')->setValue($plugin, dirname(__DIR__, 2) . '/wp-taxonomy-modeler.php');

    try {
        $plugin->screen()->handlePost();
    } catch (RuntimeException) {
    } finally {
        remove_filter('wp_redirect', $fang, 1);
        $_POST    = [];
        $_REQUEST = [];
    }

    return $gewandert;
}

$relations = Schema::table('relations');
$shadow    = Schema::table('relations_history');

echo "1 · Die Spalten stehen\n";

check('relations.read_only', hatSpalte($relations, 'read_only'));
check('relations_history.read_only', hatSpalte($shadow, 'read_only'), 'der Schatten zieht mit');
check('relations.multiplicity', hatSpalte($relations, 'multiplicity'));

if ($bad > 0) {
    echo "\nOhne die Spalten hat der Rest nichts zu prüfen.\n";

    exit(1);
}

echo "\n2 · Kein Schlüssel mehr\n";

check('SettingKey kennt read_only nicht', SettingKey::tryFrom(EdgeColumn::READ_ONLY) === null);
check('SettingKey kennt multiplicity nicht', SettingKey::tryFrom(EdgeColumn::MULTIPLICITY) === null);

$lebendeKanten = (int) $wpdb->get_var("SELECT COUNT(*) FROM " . Schema::table('relations_named') . " WHERE kind = 'setting' AND name IN ('read_only', 'multiplicity')");
check('keine lebende Einstellungskante heisst read_only oder multiplicity (Fassung 48)', $lebendeKanten === 0, (string) $lebendeKanten);

// ⚠️ *Der Schatten trägt keinen Namen für Zeilen, die seit Fassung 34 hinüberwandern — der Name
// steht in den Beschriftungen, also wird über sie gefunden.*
$marke = get_option('taxmod_fassung48_shape');
check('Fassung 48 hat ihre Marke hinterlassen und mindestens eine Kante geparkt', is_array($marke) && (int) ($marke['kanten_geparkt'] ?? 0) >= 1, is_array($marke) ? json_encode($marke) : 'keine Marke');

$geparkt = (int) $wpdb->get_var(
    "SELECT COUNT(DISTINCT h.id) FROM {$shadow} h
     WHERE h.kind = 'setting' AND h.parked_by_group_id IS NOT NULL AND h.deleted = 1
       AND NOT EXISTS (SELECT 1 FROM {$relations} l WHERE l.id = h.id)"
);
check('geparkte Einstellungskanten stehen im Schatten, mit ihrer Änderungsgruppe, und nicht mehr lebend', $geparkt >= (int) ($marke['kanten_geparkt'] ?? 1), (string) $geparkt);

echo "\n3 · Auf der Wiese\n";

$log       = new WpdbChangelog(new SystemClock());
$knoten    = new WpdbNodeRepository();
$kanten    = new WpdbRelationRepository();
$framework = new SeededFrameworkNodes($knoten, $kanten, $log);
$editor    = new ModelEditor($knoten, $kanten, $framework, $log);

$zeichner = new Rendering(
    $knoten,
    $framework,
    ShippedRenderers::registry(),
    new SeededTypeNodes($knoten, $framework),
    new Labels(new WpdbLabelRepository(), SettingsScreen::neutralLocale()),
    ShippedConverters::registry(),
    new ModelValues(new WpdbRecordRepository(), $kanten, $knoten, $framework),
    $kanten
);

$integer = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM " . Schema::table('nodes') . " WHERE implemented_by = %s ORDER BY id LIMIT 1", IntType::class));
$boolean = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM " . Schema::table('nodes') . " WHERE implemented_by = %s ORDER BY id LIMIT 1", BoolType::class));

$wiese = $editor->createNode('__ks Wiese', $framework->rootOf(Branch::Model)->id);
$zahl  = $editor->addField($wiese->id, $integer, '__ks zahl');
$flag  = $editor->addField($wiese->id, $boolean, '__ks flag');

check('ein frisches Feld ist änderbar', $zahl->readOnly === false);

$zahl = $editor->setReadOnly($wiese->id, $zahl->id, true);
check('der Dienst schreibt die Spalte', $zahl->readOnly === true && (int) $wpdb->get_var($wpdb->prepare("SELECT read_only FROM {$relations} WHERE id = %d", $zahl->id)) === 1);
check('frisch gelesen trägt die Kante die Spalte', $kanten->byId($zahl->id)->readOnly === true);
check('und die Fassung ist eine weiter, die alte im Schatten', $zahl->version === 2 && (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$shadow} WHERE id = %d AND version = 1", $zahl->id)) === 1);

$rows = [];
foreach ($zeichner->settingsFor($zahl, $zeichner->settingsForUseSites([$zahl])[$zahl->id], Purpose::Edit, 'taxmod_field_setting[' . $zahl->id . ']') as $row) {
    $rows[$row->key] = $row;
}
check('die Feldzeile zeichnet read_only als Schiebeschalter, aus der Spalte', isset($rows['read_only']) && $rows['read_only']->wasDrawn() && str_contains($rows['read_only']->result->markup, 'taxmod-toggle-track') && $rows['read_only']->setting->value->asBool());
check('und «wie oft» daneben', isset($rows['multiplicity']) && $rows['multiplicity']->wasDrawn());

$feld = $zeichner->fieldsFor([$zahl], [], Purpose::Edit, 'taxmod_value');
check('ein nur lesbares Feld bietet beim Bearbeiten kein Eingabefeld an', $feld !== [] && ! str_contains($feld[0]->result->markup, '<input type="text"') && ! str_contains($feld[0]->result->markup, '<input type="number"'));

abschicken(['do' => 'put_setting', 'id' => (string) $wiese->id, '_taxmod_nonce' => wp_create_nonce('taxmod_node_' . $wiese->id), 'taxmod_field_setting' => [(string) $zahl->id => ['read_only' => '0']]]);
check('das Formular schaltet die Spalte aus', $kanten->byId($zahl->id)->readOnly === false);

abschicken(['do' => 'put_setting', 'id' => (string) $wiese->id, '_taxmod_nonce' => wp_create_nonce('taxmod_node_' . $wiese->id), 'taxmod_field_setting' => [(string) $zahl->id => ['read_only' => '1']]]);
check('und wieder ein', $kanten->byId($zahl->id)->readOnly === true);

check('kein Einstellungssatz ist dabei entstanden', (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Schema::table('node_records') . " WHERE relation_id = %d", $zahl->id)) === 0);

$kopie = $editor->duplicate($wiese->id);
$kopierteFelder = array_values(array_filter($kanten->fieldRelationsOf([$kopie->id]), static fn ($k) => $k->fromNodeId === $kopie->id && $k->name === '__ks zahl'));
check('die Kopie eines Knotens trägt read_only mit', ($kopierteFelder[0] ?? null)?->readOnly === true);

echo "\n4 · Bool nur 1..1 (Modell 1.2.3)\n";

$flagRows = [];
foreach ($zeichner->settingsFor($flag, $zeichner->settingsForUseSites([$flag])[$flag->id], Purpose::Edit, 'taxmod_field_setting[' . $flag->id . ']') as $row) {
    $flagRows[$row->key] = $row;
}
$wahl = $flagRows['multiplicity']->result->markup ?? '';
check('ein Feld auf Boolean bekommt nur 1..1 angeboten', str_contains($wahl, 'value="1..1"') && ! str_contains($wahl, 'value="0..1"') && ! str_contains($wahl, 'value="0..*"') && ! str_contains($wahl, 'value="1..*"'), $wahl === '' ? 'kein Wähler' : '');

$abgewiesen = false;
try {
    $editor->setMultiplicity($wiese->id, $flag->id, Multiplicity::ZeroToMany);
} catch (MultiplicityNotAllowed) {
    $abgewiesen = true;
}
check('der Kern weist 0..* an einem Boolean ab', $abgewiesen);

$zahlRows = [];
foreach ($zeichner->settingsFor($zahl, $zeichner->settingsForUseSites([$zahl])[$zahl->id], Purpose::Edit, 'taxmod_field_setting[' . $zahl->id . ']') as $row) {
    $zahlRows[$row->key] = $row;
}
$wahlZahl = $zahlRows['multiplicity']->result->markup ?? '';
check('ein Feld auf Integer bekommt alle vier', str_contains($wahlZahl, 'value="0..1"') && str_contains($wahlZahl, 'value="1..1"') && str_contains($wahlZahl, 'value="0..*"') && str_contains($wahlZahl, 'value="1..*"'));
check('und der Kern nimmt 0..* an einem Integer', $editor->setMultiplicity($wiese->id, $zahl->id, Multiplicity::ZeroToMany)->multiplicity === Multiplicity::ZeroToMany);

printf("\n%d ok, %d fehlgeschlagen\n", $ok, $bad);
echo $bad === 0 ? "all green\n" : "$bad FEHLER\n";

exit($bad === 0 ? 0 : 1);
