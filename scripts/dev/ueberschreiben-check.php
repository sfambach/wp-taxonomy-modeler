<?php declare(strict_types=1);

/**
 * Überschreiben an der Kante — Listen schalten und ordnen (Schritt 6 des Bauplans).
 *
 *     php scripts/dev/ueberschreiben-check.php [path/to/wordpress]
 *
 * ⚠️ **Sein Wort** ([D-712](../../docs/NewConcept/90-decision-log.md)): *«Z3 ergänzen mit änderbarer
 * Reihenfolge»* und *«haken raus nicht mehr aktiv»*. Eine Liste (heute: die Renderer eines Knotens)
 * zeigt ihre Glieder mit Schalter und Stelle; an der Kante liegt ein geerbtes Glied darunter, und eine
 * Zeile mit Kante sagt dort, ob es an ist und wo es steht — **abschalten ist nicht löschen**. Gezeichnet
 * wird das erste aktive Glied ([`einstellungen-anforderungen.md`](../../docs/einstellungen-anforderungen.md) §5.5).
 *
 * ```mermaid
 * flowchart LR
 *   W["1 · die Wiese __ue"] --> K["2 · am Knoten: ein Glied aus, wieder an — die Zeile bleibt"]
 *   K --> E["3 · an der Kante: eigenes Glied vor dem geerbten, umordnen, das geerbte wieder an"]
 *   E --> U["4 · die Kante gilt über dem Knoten; Fremdes wird abgewiesen"]
 * ```
 *
 * Die Klammer `lib/no-write.php` dreht am Ende alles zurück; die Wiese trägt das Präfix `__ue`.
 *
 * @see docs/einstellungen-bauplan.md
 */

$wordpress = $argv[1] ?? (getenv('WP_ROOT') ?: 'C:/Devel/Wordpress');

define('WP_USE_THEMES', false);

require rtrim($wordpress, '/') . '/wp-load.php';
require __DIR__ . '/lib/no-write.php';
require __DIR__ . '/../../vendor/autoload.php';

use Taxmod\Core\Converter\ShippedConverters;
use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\Type\IntType;
use Taxmod\Core\Renderer\FieldRenderer;
use Taxmod\Core\Renderer\Purpose;
use Taxmod\Core\Renderer\ShippedRenderers;
use Taxmod\Core\Renderer\SpinnerRenderer;
use Taxmod\Core\Service\Labels;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\Core\Service\Rendering;
use Taxmod\Core\Service\SettingsResolver;
use Taxmod\WordPress\Admin\SettingsScreen;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\SeededTypeNodes;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbLabelRepository;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRecordRepository;
use Taxmod\WordPress\Persistence\WpdbRelationRepository;
use Taxmod\WordPress\Persistence\WpdbSettingsRepository;
use Taxmod\WordPress\Plugin;
use Taxmod\WordPress\SystemClock;

register_shutdown_function(static fn (): int => Schema::forgetOrphanLabels());

$passed = 0;
$failed = 0;

function check(string $what, bool $ok, string $detail = ''): void
{
    global $passed, $failed;

    if ($ok) {
        $passed++;
        echo "  ok   {$what}\n";

        return;
    }

    $failed++;
    echo "  FAIL {$what}" . ($detail === '' ? '' : " — {$detail}") . "\n";
}

global $wpdb;

$p         = $wpdb->prefix . 'taxmod_';
$log       = new WpdbChangelog(new SystemClock());
$nodes     = new WpdbNodeRepository();
$relations = new WpdbRelationRepository();
$rows      = new WpdbRecordRepository();
$framework = new SeededFrameworkNodes($nodes, $relations, $log);
$editor    = new ModelEditor($nodes, $relations, $framework, $log, new WpdbLabelRepository(), $rows);

/** Ein frischer Leser je Frage. */
$leser = static fn (): SettingsResolver => new SettingsResolver(new WpdbSettingsRepository(), $nodes, ShippedRenderers::registry(), ShippedConverters::registry());
/** Ein frischer Zeichner je Frage. */
$zeichner = static fn (): Rendering => new Rendering(
    $nodes,
    $framework,
    ShippedRenderers::registry(),
    new SeededTypeNodes($nodes, $framework),
    new Labels(new WpdbLabelRepository(), SettingsScreen::neutralLocale()),
    ShippedConverters::registry(),
    $relations,
    resolver: $leser()
);

wp_set_current_user(1);

function seite(int $nodeId, ?string $offen = null): string
{
    $_GET['page']        = 'taxmod-nodes';
    $_GET['taxmod_node'] = (string) $nodeId;

    if ($offen === null) {
        unset($_GET['taxmod_open_rows']);
    } else {
        $_GET['taxmod_open_rows'] = $offen;
    }

    $rc     = new ReflectionClass(Plugin::class);
    $plugin = $rc->newInstanceWithoutConstructor();
    $rc->getProperty('file')->setValue($plugin, __FILE__);

    $markup = $plugin->screen()->render();
    unset($_GET['taxmod_open_rows']);

    return $markup;
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
    $rc->getProperty('file')->setValue($plugin, __FILE__);

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

function letzteMeldung(): string
{
    parse_str((string) parse_url((string) $GLOBALS['taxmod_letzte_adresse'], PHP_URL_QUERY), $teile);

    return rawurldecode((string) ($teile['taxmod_message'] ?? ''));
}

/** Ob der letzte Akt gelang — «ok» ohne Schreiben, «Saved — n written» mit (D-683). */
function gelungen(): bool
{
    $m = letzteMeldung();

    return $m === 'ok' || str_starts_with($m, 'Saved');
}

function speichern(int $nodeId, array $angaben): bool
{
    return abschicken([
        'do'            => 'put_setting',
        'id'            => (string) $nodeId,
        '_taxmod_nonce' => wp_create_nonce('taxmod_node_' . $nodeId),
        ...$angaben,
    ]);
}

// ---------------------------------------------------------------------------------------------------

echo "== 1 · Die Wiese: ein Modell, ein eigener Zahltyp, zwei Verwendungen ==\n";

$integerId = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . Schema::table('nodes') . ' WHERE implemented_by = %s ORDER BY id LIMIT 1', IntType::class));
check('der Typknoten Integer steht', $integerId !== 0);

if ($integerId === 0) {
    echo "\nOhne Integer hat der Rest nichts zu prüfen.\n";
    exit(1);
}

$modell = $editor->createNode('__ue Modell', $framework->rootOf(Branch::Model)->id);
$zahl   = $editor->createNode('__ue Zahl', $integerId);
$eins   = $editor->addField($modell->id, $zahl->id, '__ue erste');
$zwei   = $editor->addField($modell->id, $zahl->id, '__ue zweite');

/** @return array<int, string> Kanten-Id => Name des Renderers, mit dem das Feld gezeichnet wird. */
$gezeichnet = static function (array $kanten) use ($zeichner): array {
    $aus = [];
    foreach ($zeichner()->fieldsFor($kanten, [], Purpose::Edit, 'taxmod_value') as $feld) {
        $aus[$feld->relation->id] = $feld->rendererName;
    }

    return $aus;
};
/** @return array<string, array{aktiv: bool, position: int, setHere: bool, rowId: int}> Wort => Glied */
$glieder = static function (?int $kanteId = null) use ($leser, $nodes, $relations, $zahl): array {
    $aus = [];
    foreach ($leser()->listOf($nodes->byId($zahl->id), 'renderer', $kanteId === null ? null : $relations->byId($kanteId)) as $g) {
        $aus[$g->word] = ['aktiv' => $g->aktiv, 'position' => $g->position, 'setHere' => $g->setHere, 'rowId' => $g->rowId];
    }

    return $aus;
};
$zeilenAnDerKante = static fn (int $kanteId): int => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}settings_value WHERE node_id = {$zahl->id} AND relation_id = {$kanteId} AND attribut = 'renderer'");

check('beide Verwendungen zeichnen mit dem Typstandard', $gezeichnet([$eins, $zwei]) === [$eins->id => FieldRenderer::NAME, $zwei->id => FieldRenderer::NAME]);
check('die Liste `renderer` ist leer, die Seite zeichnet keine Glieder', $glieder() === [] && ! str_contains(seite($zahl->id), 'taxmod-setting-list'));

// ---------------------------------------------------------------------------------------------------

echo "\n== 2 · Am Knoten: ein Glied aus, wieder an — die Zeile bleibt ==\n";

speichern($zahl->id, ['taxmod_setting' => ['renderer' => SpinnerRenderer::NAME]]);
$g = $glieder();
check('die Wahl ist ein Glied: an, hier gesetzt', count($g) === 1 && ($g['spinner']['aktiv'] ?? false) && ($g['spinner']['setHere'] ?? false), json_encode($g));
$zeile = (int) ($g['spinner']['rowId'] ?? 0);
$markup = seite($zahl->id);
check('die Seite zeichnet das Glied unter dem Wähler: Schalter an, Stelle', (bool) preg_match('/<input type="checkbox" name="taxmod_setting_list\[renderer\]\[' . $zeile . '\]\[aktiv\]" value="1" checked/', $markup) && str_contains($markup, 'name="taxmod_setting_list[renderer][' . $zeile . '][position]"'));
check('und der Schalter hängt am Seitenformular', (bool) preg_match('/name="taxmod_setting_list\[renderer\]\[' . $zeile . '\]\[aktiv\]" value="1" checked form="taxmod-page-' . $zahl->id . '"/', $markup));
speichern($zahl->id, ['taxmod_setting' => ['renderer' => SpinnerRenderer::NAME], 'taxmod_setting_list' => ['renderer' => [(string) $zeile => ['aktiv' => '0', 'position' => '0']]]]);
$g = $glieder();
check('Schalter aus: der Akt gelingt, das Glied ist aus', gelungen() && ($g['spinner']['aktiv'] ?? true) === false, letzteMeldung() . ' ' . json_encode($g));
check('die Zeile bleibt — abschalten ist nicht löschen', (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}settings_value WHERE id = {$zeile}") === 1);
check('nichts ist gewählt, beide Verwendungen zeichnen den Typstandard', ($zeichner()->settingsForNode($nodes->byId($zahl->id))['renderer'] ?? null)?->setHere === false && $gezeichnet([$eins, $zwei]) === [$eins->id => FieldRenderer::NAME, $zwei->id => FieldRenderer::NAME]);
check('die Seite zeigt den Schalter aus', (bool) preg_match('/name="taxmod_setting_list\[renderer\]\[' . $zeile . '\]\[aktiv\]" value="1" form=/', seite($zahl->id)));
speichern($zahl->id, ['taxmod_setting_list' => ['renderer' => [(string) $zeile => ['aktiv' => '0', 'position' => '0']]]]);
check('dasselbe noch einmal schreibt nichts', letzteMeldung() === 'ok', letzteMeldung());
speichern($zahl->id, ['taxmod_setting_list' => ['renderer' => [(string) $zeile => ['aktiv' => '1', 'position' => '0']]]]);
check('wieder an: spinner an beiden Verwendungen', gelungen() && $gezeichnet([$eins, $zwei]) === [$eins->id => SpinnerRenderer::NAME, $zwei->id => SpinnerRenderer::NAME], implode(',', $gezeichnet([$eins, $zwei])));

// ---------------------------------------------------------------------------------------------------

echo "\n== 3 · An der Kante: eigenes Glied vor dem geerbten, umordnen, das geerbte wieder an ==\n";

speichern($modell->id, ['taxmod_field_setting' => [(string) $eins->id => ['renderer' => 'slider']], 'taxmod_field_setting_override' => [(string) $eins->id => ['renderer' => '1']]]);
$g = $glieder($eins->id);
check('an der Kante zwei Glieder: slider eigen und an, spinner geerbt und aus', count($g) === 2 && ($g['slider']['aktiv'] ?? false) && ($g['slider']['setHere'] ?? false) && ($g['spinner']['aktiv'] ?? true) === false, json_encode($g));
check('slider steht vor spinner', array_keys($g) === ['slider', 'spinner'], implode(',', array_keys($g)));
check('gezeichnet: slider an der Kante, spinner an der anderen Verwendung', $gezeichnet([$eins, $zwei]) === [$eins->id => 'slider', $zwei->id => SpinnerRenderer::NAME], implode(',', $gezeichnet([$eins, $zwei])));
$auf = seite($modell->id, (string) $eins->id);
$sliderZeile  = (int) $g['slider']['rowId'];
$spinnerZeile = (int) $g['spinner']['rowId'];
check('die aufgeklappte Feldzeile zeichnet beide Glieder mit Schalter und Stelle', (bool) preg_match('/name="taxmod_field_setting_list\[' . $eins->id . '\]\[renderer\]\[' . $sliderZeile . '\]\[aktiv\]" value="1" checked/', $auf) && (bool) preg_match('/name="taxmod_field_setting_list\[' . $eins->id . '\]\[renderer\]\[' . $spinnerZeile . '\]\[aktiv\]" value="1" form=/', $auf), (string) preg_match_all('/taxmod_field_setting_list/', $auf));
speichern($modell->id, ['taxmod_field_setting' => [(string) $eins->id => ['renderer' => 'slider']], 'taxmod_field_setting_list' => [(string) $eins->id => ['renderer' => [(string) $spinnerZeile => ['aktiv' => '1', 'position' => '0'], (string) $sliderZeile => ['aktiv' => '1', 'position' => '1']]]]]);
$g = $glieder($eins->id);
check('spinner wieder an und nach vorn: der Akt gelingt, die Reihenfolge ist spinner, slider', gelungen() && array_keys($g) === ['spinner', 'slider'] && ($g['spinner']['aktiv'] ?? false), letzteMeldung() . ' ' . implode(',', array_keys($g)));
check('gezeichnet wird das erste aktive Glied: spinner — an beiden Verwendungen', $gezeichnet([$eins, $zwei]) === [$eins->id => SpinnerRenderer::NAME, $zwei->id => SpinnerRenderer::NAME], implode(',', $gezeichnet([$eins, $zwei])));
check('an der Kante stehen zwei Zeilen, keine dritte — das geerbte Glied hat dort seine eigene', $zeilenAnDerKante($eins->id) === 2, (string) $zeilenAnDerKante($eins->id));
check('der Knoten selbst ist unberührt: ein Glied, spinner, an', count($glieder()) === 1 && ($glieder()['spinner']['aktiv'] ?? false));
speichern($modell->id, ['taxmod_field_setting' => [(string) $eins->id => ['renderer' => 'slider']], 'taxmod_field_setting_list' => [(string) $eins->id => ['renderer' => [(string) $spinnerZeile => ['aktiv' => '0', 'position' => '0']]]]]);
check('spinner an der Kante wieder aus: slider zeichnet', $gezeichnet([$eins])[$eins->id] === 'slider', $gezeichnet([$eins])[$eins->id]);

// ---------------------------------------------------------------------------------------------------

echo "\n== 4 · Die Kante gilt über dem Knoten; Fremdes wird abgewiesen ==\n";

speichern($modell->id, ['taxmod_field_setting' => [(string) $eins->id => ['renderer' => 'slider']], 'taxmod_field_setting_list' => [(string) $eins->id => ['renderer' => [(string) $spinnerZeile => ['aktiv' => '1', 'position' => '0']]]]]);
speichern($zahl->id, ['taxmod_setting_list' => ['renderer' => [(string) $zeile => ['aktiv' => '0', 'position' => '0']]]]);
check('am Knoten aus, an der Kante an: die Kante zeichnet spinner, die andere Verwendung den Typstandard', $gezeichnet([$eins, $zwei]) === [$eins->id => SpinnerRenderer::NAME, $zwei->id => FieldRenderer::NAME], implode(',', $gezeichnet([$eins, $zwei])));
speichern($zahl->id, ['taxmod_setting_list' => ['renderer' => [(string) $zeile => ['aktiv' => '1', 'position' => '0']]]]);
$fremd = $editor->createNode('__ue fremd', $integerId);
speichern($fremd->id, ['taxmod_setting_list' => ['renderer' => [(string) $zeile => ['aktiv' => '0', 'position' => '0']]]]);
check('eine Zeile eines anderen Knotens wird abgewiesen, nichts geschrieben', ! gelungen() && ($glieder()['spinner']['aktiv'] ?? false), letzteMeldung());
speichern($zahl->id, ['taxmod_setting_list' => ['renderer' => [(string) $zeile => ['aktiv' => '1', 'position' => 'viele']]]]);
check('eine Stelle, die keine Zahl ist, ändert nichts', letzteMeldung() === 'ok' && ($glieder()['spinner']['position'] ?? -1) === 0, letzteMeldung());

// ---------------------------------------------------------------------------------------------------

// ⚠️ **Hinzugefügt am 2026-09-14 mit [D-798](../../docs/NewConcept/90-decision-log.md), sichtbar:** *sein Befund «override renderer does
// not save» — der Haken mit demselben Renderer wie geerbt legte keine Zeile an, und nach dem Speichern war alles wieder gesperrt.*
echo "\n== 5 · Der Haken hält auch den geerbten Renderer an der Kante fest (D-798) ==\n";

$vorher = $zeilenAnDerKante($zwei->id);
speichern($modell->id, ['taxmod_field_setting' => [(string) $zwei->id => ['renderer' => SpinnerRenderer::NAME]], 'taxmod_field_setting_override' => [(string) $zwei->id => ['renderer' => '1']]]);
$mitHaken = $zeilenAnDerKante($zwei->id);
check('mit Haken und demselben Renderer wie geerbt: eine eigene Zeile an der Kante', gelungen() && $mitHaken > $vorher, "{$vorher} → {$mitHaken} · " . letzteMeldung());
speichern($modell->id, ['taxmod_field_setting' => [(string) $zwei->id => ['renderer' => SpinnerRenderer::NAME]]]);
check('dasselbe Speichern ohne Haken legt keine weitere Zeile an', $zeilenAnDerKante($zwei->id) === $mitHaken, (string) $zeilenAnDerKante($zwei->id));

echo "\n{$passed} ok, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
