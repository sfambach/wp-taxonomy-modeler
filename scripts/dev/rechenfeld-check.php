<?php declare(strict_types=1);
/**
 * Ein gerechnetes Feld gegen die echte Datenbank: Rechenschritte an der Kante, gesperrt, nach jedem Schreiben neu (D-1022).
 *
 *     php scripts/dev/rechenfeld-check.php [path/to/wordpress]
 *
 * ```mermaid
 * flowchart LR
 *   F["Schritte gesetzt"] --> S["Feld gesperrt, alle Sätze gerechnet"]
 *   W["ein Anteil geschrieben"] --> N["Einstandspreis neu, im selben Lauf"]
 *   E["zwei Einheiten"] --> L["nicht rechenbar: leer"]
 * ```
 *
 * @see docs/NewConcept/90-decision-log.md D-1022
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

// ⚠️ **Kein Wächter schreibt in das Modell des Eigentümers** — siehe `lib/no-write.php`.
require __DIR__ . '/lib/no-write.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\Multiplicity;
use Taxmod\Core\Model\RelationKind;
use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Model\TypedValue;
use Taxmod\WordPress\Persistence\WpdbRecordRepository;

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

$admin = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
wp_set_current_user((int) ($admin[0] ?? 1));

$plugin = (new ReflectionClass(\Taxmod\WordPress\Plugin::class))->newInstanceWithoutConstructor();
(new ReflectionProperty($plugin, 'file'))->setValue($plugin, dirname(__DIR__, 2) . '/wp-taxonomy-modeler.php');
$framework = (new ReflectionMethod($plugin, 'frameworkNodes'))->invoke($plugin);
$screen    = $plugin->screen();
$editor    = $plugin->editor();
$daten     = $screen->entries();
$typen     = (new ReflectionMethod($plugin, 'typeNodes'))->invoke($plugin);
$records   = new WpdbRecordRepository();

$wertIn = static function (int $satz, int $feld) use ($records): ?TypedValue {
    foreach ($records->valuesOf($satz) as $zeile) {
        if ($zeile->relationId === $feld) {
            return $zeile->value;
        }
    }

    return null;
};

$dezimal = $typen->nodeId(SimpleType::Decimal);
$text    = $typen->nodeId(SimpleType::Text);
$ganz    = $typen->nodeId(SimpleType::Int);
check('Dezimal, Text und Ganzzahl sind gesät', $dezimal !== null && $text !== null && $ganz !== null);

if ($dezimal === null || $text === null || $ganz === null) {
    echo "\n$ok ok, $bad failed\n";
    exit(1);
}

echo "Wiese\n";

$model    = $framework->rootOf(Branch::Model);
$betrag   = $editor->createNode('__rf Betrag', $model->id);
$wert     = $editor->addField($betrag->id, $dezimal, '__rf wert');
$einheit  = $editor->addField($betrag->id, $text, '__rf einheit');
$kauf     = $editor->createNode('__rf Kauf', $model->id);
$anzahl   = $editor->addField($kauf->id, $ganz, '__rf Anzahl');
$preis    = $editor->addField($kauf->id, $betrag->id, '__rf Preis je Stück', RelationKind::Composition);
$versand  = $editor->addField($kauf->id, $betrag->id, '__rf Versand anteilig', RelationKind::Composition);
$einstand = $editor->addField($kauf->id, $betrag->id, '__rf Einstandspreis je Stück', RelationKind::Composition);
$editor->setMultiplicity($kauf->id, $versand->id, Multiplicity::ZeroToOne);
$editor->setMultiplicity($kauf->id, $einstand->id, Multiplicity::ZeroToOne);

$satz = $daten->create($kauf->id)->id;
$daten->put($satz, $anzahl->id, TypedValue::ofInt(4));
$preisTeil = (int) $wertIn($satz, $preis->id)?->reference;
$daten->put($preisTeil, $wert->id, TypedValue::ofDecimal('6.99'));
$daten->put($preisTeil, $einheit->id, TypedValue::ofText('EUR'));

check('ohne Schritte wird nichts gerechnet', $wertIn($satz, $einstand->id) === null);

echo "Schritte setzen\n";

$schritt = static fn (string $art, int $feld): array => ['name' => 'calculate', 'fields' => ['operation' => $art, 'field' => (string) $feld]];
$screen->setAddons($betrag->id, [$schritt('plus', $versand->id), $schritt('divided_by', $anzahl->id), $schritt('plus', $preis->id)], $einstand->id);

check('das gerechnete Feld ist gesperrt', $editor->relationById($einstand->id)?->readOnly === true);
$teil = (int) $wertIn($satz, $einstand->id)?->reference;
check('jeder Satz ist gerechnet: ohne Anteil der Stückpreis', $teil > 0 && $wertIn($teil, $wert->id)?->decimal === '6.99' && $wertIn($teil, $einheit->id)?->text === 'EUR', (string) $wertIn($teil, $wert->id)?->decimal);

echo "Nach dem Schreiben\n";

$anteil = $daten->createPart($satz, $versand->id)->id;
$daten->put($anteil, $wert->id, TypedValue::ofDecimal('-4'));
$daten->put($anteil, $einheit->id, TypedValue::ofText('EUR'));
$teil = (int) $wertIn($satz, $einstand->id)?->reference;
check('ein Anteil im Teil rechnet den Satz neu: 6.99 + (−4 ÷ 4)', $wertIn($teil, $wert->id)?->decimal === '5.99', (string) $wertIn($teil, $wert->id)?->decimal);

$daten->put($anteil, $einheit->id, TypedValue::ofText('USD'));
check('zwei Einheiten: nicht rechenbar, der Teil geht', $wertIn($satz, $einstand->id) === null);

echo "Schritte weg\n";

$screen->setAddons($betrag->id, [], $einstand->id);
check('ohne Schritte ist das Feld wieder frei', $editor->relationById($einstand->id)?->readOnly === false);

echo "\n$ok ok, $bad failed\n";
exit($bad === 0 ? 0 : 1);
