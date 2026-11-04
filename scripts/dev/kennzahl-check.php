<?php declare(strict_types=1);
/**
 * Die Kennzahl am Knoten gegen die echte Datenbank (D-1149): zählen und summieren je Monat, Unterbaum, Filter, Datum über einen
 * Verweis, Summe über einen Teil, der letzte von zwei Werten, und die gemeinsame Kachel.
 *
 *     php scripts/dev/kennzahl-check.php [path/to/wordpress]
 *
 * ```mermaid
 * flowchart LR
 *   W["Wiese __kz: Vorgang, Kauf, Bestellung"] --> A["Zusatzfunktion «metric» gesetzt"]
 *   A --> Q["Tiles::metrics(): eine Abfrage je Kennzahl"]
 *   Q --> K["Kachel: Zahl, Vormonat, Balken"]
 * ```
 *
 * @see docs/pakete/kacheln/konzept.md
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
use Taxmod\Core\Model\RelationKind;
use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Tile\Period;
use Taxmod\Core\Tile\PeriodSpan;
use Taxmod\Core\Tile\TileTone;
use Taxmod\WordPress\Admin\Tiles;
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

$dezimal = $typen->nodeId(SimpleType::Decimal);
$text    = $typen->nodeId(SimpleType::Text);
$datum   = $typen->nodeId(SimpleType::DateTime);
check('Dezimal, Text und Datum sind gesät', $dezimal !== null && $text !== null && $datum !== null);

if ($dezimal === null || $text === null || $datum === null) {
    echo "\n$ok ok, $bad failed\n";
    exit(1);
}

$wertIn = static function (int $satz, int $feld) use ($records): ?TypedValue {
    foreach ($records->valuesOf($satz) as $zeile) {
        if ($zeile->relationId === $feld) {
            return $zeile->value;
        }
    }

    return null;
};

$monat = static fn (int $zurueck): string => gmdate('Y-m-15 12:00:00', (int) strtotime(current_time('Y-m') . '-01 -' . $zurueck . ' months'));

echo "Wiese\n";

$model   = $framework->rootOf(Branch::Model);
$vorgang = $editor->createNode('__kz Vorgang', $model->id);
$unter   = $editor->createNode('__kz Wareneingang', $vorgang->id);
$art     = $editor->addField($vorgang->id, $text, '__kz Art');
$am      = $editor->addField($vorgang->id, $datum, '__kz Datum');

$vorgangAm = static function (int $knoten, string $wann, string $wie) use ($daten, $art, $am): int {
    $satz = $daten->create($knoten)->id;
    $daten->put($satz, $art->id, TypedValue::ofText($wie));
    $daten->put($satz, $am->id, TypedValue::ofDate($wann));

    return $satz;
};

$vorgangAm($vorgang->id, $monat(0), 'WE');
$vorgangAm($unter->id, $monat(0), 'WE');
$vorgangAm($vorgang->id, $monat(0), 'WA');
$vorgangAm($vorgang->id, $monat(1), 'WE');
$vorgangAm($vorgang->id, $monat(14), 'WE');

$screen->setAddons($vorgang->id, [['name' => 'metric', 'fields' => ['title' => '__kz Wareneingänge', 'date_field' => (string) $am->id, 'filter_field' => (string) $art->id, 'filter_value' => 'WE', 'months' => '12']]]);

$kachel = static function (string $titel) use (&$alle): ?\Taxmod\Core\Tile\Tile {
    foreach ($alle as $k) {
        if ($k->label === $titel) {
            return $k;
        }
    }

    return null;
};

$alle = Tiles::metrics();
$we   = $kachel('__kz Wareneingänge');
check('die Kennzahl erscheint als Kachel', $we !== null);
check('dieser Monat zählt den Knoten und seinen Unterbaum, nur «WE»', $we?->figure === '2', (string) $we?->figure);
check('der Vormonat steht als Zeile', in_array(sprintf(__('Previous month: %s', 'taxmod'), '1'), $we?->lines ?? [], true), implode(' | ', $we?->lines ?? []));
check('zwölf Balken, der letzte ist dieser Monat; älter als das Fenster zählt nicht', count($we?->bars ?? []) === 12 && ($we?->bars[11] ?? null) === 2.0 && array_sum($we?->bars ?? []) === 3.0, implode(',', $we?->bars ?? []));
check('die Kachel führt zum Knoten', str_contains((string) $we?->url, 'taxmod_node=' . $vorgang->id));
$nurHier = static fn (array $kacheln): bool => array_filter($kacheln, static fn ($k): bool => $k->label === '__kz Wareneingänge') !== [];
check('eingegrenzt auf einen Ast: der eigene zeigt sie, ein fremder nicht', $nurHier(Tiles::metrics([$vorgang->id])) && ! $nurHier(Tiles::metrics([$editor->createNode('__kz leer', $model->id)->id])));

echo "Zeitraum (D-1167)\n";
$imZeitraum = static function (Period $p) use ($vorgang): ?\Taxmod\Core\Tile\Tile {
    foreach (Tiles::metrics([$vorgang->id], $p) as $k) {
        if ($k->label === '__kz Wareneingänge') {
            return $k;
        }
    }

    return null;
};
$diesen = Period::of(PeriodSpan::Month, current_time('Y-m') . '-15');
$m      = $imZeitraum($diesen);
check('Monat: dieser Monat 2, der davor 1 als Zeile', $m?->figure === '2' && in_array(sprintf(__('Before: %s', 'taxmod'), '1'), $m->lines, true), (string) $m?->figure . ' ' . implode(' | ', $m?->lines ?? []));
check('Monat: ein Balken je Tag, beide am 15.', count($m?->bars ?? []) === count($diesen->steps()) && ($m?->bars[14] ?? null) === 2.0, implode(',', $m?->bars ?? []));
$t = $imZeitraum(Period::of(PeriodSpan::Day, current_time('Y-m') . '-15'));
check('Tag: der 15. zählt 2, der 14. nichts; vierzehn Balken', $t?->figure === '2' && in_array(sprintf(__('Before: %s', 'taxmod'), '0'), $t->lines, true) && count($t->bars) === Period::DAY_STEPS, (string) $t?->figure);
$j = $imZeitraum(Period::of(PeriodSpan::Year, current_time('Y-m') . '-15'));
check('Jahr: zwölf Balken, die Summe ist das Jahr', count($j?->bars ?? []) === 12 && (string) array_sum($j?->bars ?? []) === (string) $j?->figure, (string) $j?->figure);
check('ein Tag ohne Sätze ist grau', $imZeitraum(Period::of(PeriodSpan::Day, '2001-01-01'))?->tone === TileTone::Empty);
$leiste = Tiles::periodBar($diesen, admin_url('admin.php?page=x'));
check('die Leiste: Tag, Monat, Jahr, zurück, vor, heute — der gewählte markiert', substr_count($leiste, '<a class="button') === 6 && str_contains($leiste, 'aria-current="true"') && str_contains($leiste, 'day=' . $diesen->previous()->start()), $leiste);

echo "Summe über einen Teil, Datum über einen Verweis\n";

$betrag     = $editor->createNode('__kz Betrag', $model->id);
$wert       = $editor->addField($betrag->id, $dezimal, '__kz wert');
$bestellung = $editor->createNode('__kz Bestellung', $model->id);
$bestellt   = $editor->addField($bestellung->id, $datum, '__kz bestellt am');
$kauf       = $editor->createNode('__kz Kauf', $model->id);
$preis      = $editor->addField($kauf->id, $betrag->id, '__kz Preis', RelationKind::Composition);
$von        = $editor->addField($kauf->id, $bestellung->id, '__kz Bestellung', RelationKind::Aggregation);

$order = $daten->create($bestellung->id)->id;
$daten->put($order, $bestellt->id, TypedValue::ofDate($monat(0)));

foreach (['10.50', '4.25'] as $betragText) {
    $satz = $daten->create($kauf->id)->id;
    $daten->put($satz, $von->id, TypedValue::ofRecordReference($order));
    $teil = (int) $wertIn($satz, $preis->id)?->reference;
    $daten->put($teil, $wert->id, TypedValue::ofDecimal($betragText));
}

$screen->setAddons($kauf->id, [['name' => 'metric', 'fields' => ['title' => '__kz Ausgaben', 'measure' => 'sum', 'value_field' => (string) $preis->id, 'date_field' => (string) $von->id]]]);
$alle     = Tiles::metrics();
$ausgaben = $kachel('__kz Ausgaben');
$teile = (static function () use ($screen, $betrag): ?\Taxmod\Core\Tile\Tile {
    $screen->setAddons($betrag->id, [['name' => 'metric', 'fields' => ['title' => '__kz Teile']]]);

    foreach (Tiles::metrics([$betrag->id]) as $k) {
        if ($k->label === '__kz Teile') {
            return $k;
        }
    }

    return null;
})();
check('auch Teile sind Sätze ihres Knotens: zwei Beträge', $teile?->figure === '2', (string) $teile?->figure);
check('die Summe nimmt die Zahl im Teil, der Monat kommt von der Bestellung', $ausgaben?->figure === number_format_i18n(14.75, 2), (string) $ausgaben?->figure);

echo "Leer\n";

$screen->setAddons($editor->createNode('__kz ohne Sätze', $model->id)->id, [['name' => 'metric', 'fields' => ['title' => '__kz nichts']]]);
$alle  = Tiles::metrics();
$leer  = $kachel('__kz nichts');
check('ohne Sätze ist die Kachel die kleine graue', $leer?->tone === TileTone::Empty && $leer->figure === '0', var_export([$leer?->tone, $leer?->figure], true));
check('das Raster zeichnet sie mit der Überschrift', str_contains(Tiles::metricsGrid(), '<span class="taxmod-tile-label">__kz nichts</span>') && str_contains(Tiles::metricsGrid(), 'class="taxmod-tiles"'));

echo "\n$ok ok, $bad failed\n";
exit($bad === 0 ? 0 : 1);
