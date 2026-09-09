<?php declare(strict_types=1);

/**
 * Geerbt ist gesperrt, unzulässig geerbt ist ein Konflikt, und die Sperre fällt im Konflikt von selbst.
 *
 *     php scripts/dev/setting-lock-check.php [path/to/wordpress]
 *
 * ⚠️ **Drei Sätze des Eigentümers an einem Tag, 2026-09-09** ([D-687](../../docs/NewConcept/90-decision-log.md),
 * [D-688](../../docs/NewConcept/90-decision-log.md), [D-689](../../docs/NewConcept/90-decision-log.md)):
 * *«es entstehen immer wieder fehler dadurch das in der gui eine default schalterstellung steht diese
 * aber nicht gespeichert ist»* — *«gerade wenn ein vererbter renderer nicht zulässig ist müsste auch
 * ein zulässiger gewählt werden das gilt für alle einstellung»* — *«sperren finde ich gut, aktiv sagen
 * hier überschreibe ich, muss automatisch gemacht werden wenn der renderer (oder anderes) für kind
 * nicht mehr zulässig ist».*
 *
 * ```mermaid
 * flowchart LR
 *   E["__lk Eltern · renderer = compact"] --> K["__lk Kind"]
 *   K -->|"Wertspalte"| G["gesperrt · Haken · «inherited from __lk Eltern»"]
 *   G -->|"speichern ohne Haken"| N["kein eigener Wert"]
 *   G -->|"speichern mit Haken"| J["eigener Wert"]
 *   I["Integer · renderer = compact"] --> Z["__lk Zahl (int)"]
 *   Z -->|"compact zeichnet keine Zahl"| A["automatisch · Haken gesetzt · Typ-Standard gilt"]
 * ```
 *
 * ⚠️ **Gemessen am Markup und am Bestand, nicht an einer Klasse.** *Was der Mensch sieht, ist die
 * Zusage: eine gesperrte Zeile, ein Haken, ein Satz in Worten. Und was der Rand schreibt, ist die
 * andere Hälfte: ohne Haken nichts — genau der Fehler, den er beschrieb, war ein Speichern, das jeden
 * gezeigten Wert zum eigenen machte.*
 *
 * ⚠️ *Beide Adressen ([D-685](../../docs/NewConcept/90-decision-log.md)): die Wertspalte einer
 * Einstellungszeile am Knoten, und die Tafel unter einer Feldzeile an der Kante.*
 *
 * @see docs/NewConcept/20-interaction.md
 * @see tests/Core/InheritedSettingLockTest.php
 */

$wordpress = $argv[1] ?? (getenv('WP_ROOT') ?: 'C:/Devel/Wordpress');

define('WP_USE_THEMES', false);

require rtrim($wordpress, '/') . '/wp-load.php';

// ⚠️ **Kein Wächter schreibt in das Modell des Eigentümers** — die Klammer dreht am Ende alles zurück.
require __DIR__ . '/lib/no-write.php';
require __DIR__ . '/../../vendor/autoload.php';

use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\RelationKind;
use Taxmod\Core\Model\SettingKey;
use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Model\Type\IntType;
use Taxmod\Core\Model\Type\TextType;
use Taxmod\Core\Renderer\CompactRenderer;
use Taxmod\Core\Renderer\ShippedRenderers;
use Taxmod\Core\Service\DataEntry;
use Taxmod\Core\Service\Labels;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\Core\Service\ModelValues;
use Taxmod\Core\Service\Rendering;
use Taxmod\WordPress\Admin\SettingsScreen;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\SeededTypeNodes;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbLabelRepository;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRecordRepository;
use Taxmod\WordPress\Persistence\WpdbRelationRepository;
use Taxmod\WordPress\Plugin;
use Taxmod\WordPress\SystemClock;

register_shutdown_function(static fn (): int => \Taxmod\WordPress\Persistence\Schema::forgetOrphanLabels());

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

$log       = new WpdbChangelog(new SystemClock());
$nodes     = new WpdbNodeRepository();
$relations = new WpdbRelationRepository();
$rows      = new WpdbRecordRepository();
$framework = new SeededFrameworkNodes($nodes, $relations, $log);
$editor    = new ModelEditor($nodes, $relations, $framework, $log, new WpdbLabelRepository(), $rows);
$data      = new DataEntry($rows, $relations, $nodes, $framework, new SystemClock());

/** Ein frischer Zeichner je Frage — er merkt sich Ketten, und zwischen zwei Fragen wird geschrieben. */
$zeichner = static fn (): Rendering => new Rendering(
    $nodes,
    $framework,
    ShippedRenderers::registry(),
    new SeededTypeNodes($nodes, $framework),
    new Labels(new WpdbLabelRepository(), SettingsScreen::neutralLocale()),
    null,
    new ModelValues($rows, $relations, $nodes, $framework),
    $relations
);

wp_set_current_user(1);

/** Die Seite als Markup, so wie ein Browser sie bekommt — mit der Zeile `$offen` aufgeklappt. */
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

/** Den Akt abschicken, wie das Seitenformular ihn abschickt — und die Weiterleitung abfangen. */
function abschicken(array $post): void
{
    $_POST    = $post;
    $_REQUEST = $post;

    $fang = static function (string $ort): string {
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
}

/** Das Seitenspeichern eines Knotens mit genau diesen Angaben. */
function speichern(int $nodeId, array $angaben): void
{
    abschicken([
        'do'            => 'put_setting',
        'id'            => (string) $nodeId,
        '_taxmod_nonce' => wp_create_nonce('taxmod_node_' . $nodeId),
        ...$angaben,
    ]);
}

/**
 * Die Wertspalte einer Einstellungszeile — vom Anfang der Sperre bis zu ihrem Satz, oder nur das
 * Steuerelement, wo nichts gesperrt ist.
 */
function wertspalte(string $markup, int $kante): string
{
    $wo = strpos($markup, 'name="taxmod_value[' . $kante . ']"');

    if ($wo === false) {
        return '';
    }

    // Die äussere Sperre, nicht der innere `…-locked-control` — beide beginnen mit demselben Wort.
    preg_match_all('/<span class="taxmod-setting-locked(?: taxmod-setting-automatic)?">/', substr($markup, 0, $wo), $treffer, PREG_OFFSET_CAPTURE);
    $letzter = end($treffer[0]);
    $anfang  = $letzter === false ? false : (int) $letzter[1];
    $zelle   = strrpos(substr($markup, 0, $wo), 'taxmod-field-value');

    // Die Sperre gehört zu dieser Zelle nur, wenn sie **nach** dem Zellenanfang beginnt.
    if ($anfang === false || ($zelle !== false && $anfang < $zelle)) {
        return substr($markup, $wo, 400);
    }

    $ende = strpos($markup, '</em></span>', $wo);

    return substr($markup, $anfang, ($ende === false ? $wo + 400 : $ende + 12) - $anfang);
}

$renderKante = $framework->settingRelationId(SettingKey::Renderer);
$compact     = $editor->nodeImplementing(CompactRenderer::class);
$integer     = $editor->nodeImplementing(IntType::class);
$text        = $editor->nodeImplementing(TextType::class);

if ($renderKante === 0 || $compact === null || $integer === null || $text === null) {
    echo "  FAIL das Gerüst fehlt: Renderer-Kante, `compact`, `Integer` oder `Text`\n";

    exit(1);
}

echo "== 1. geerbt heisst gesperrt, in Worten, mit Haken (D-689) ==\n";

$eltern = $editor->createNode('__lk Eltern', $framework->rootOf(Branch::Model)->id);
$kind   = $editor->createNode('__lk Kind', $eltern->id);

speichern($eltern->id, ['taxmod_value' => [(string) $renderKante => (string) $compact->id]]);
check('die Eltern tragen `compact` als eigenen Wert', isset($data->settingValuesOf($eltern->id, [$renderKante])[$renderKante]));

$zeile = wertspalte(seite($kind->id), $renderKante);
check('das Kind zeigt die Zeile gesperrt', str_contains($zeile, 'taxmod-setting-locked'), substr($zeile, 0, 160));
check('und nicht als automatisch — `compact` ist unter `Model` zulässig', ! str_contains($zeile, 'taxmod-setting-automatic'));
check('mit dem Haken «hier überschreibe ich»', str_contains($zeile, 'name="taxmod_value_override[' . $renderKante . ']"'));
check('der Haken ist nicht gesetzt', ! str_contains($zeile, 'value="1" checked'));
check('und die Herkunft steht in Worten', str_contains($zeile, 'inherited from __lk Eltern'), substr(strip_tags($zeile), -120));
check('kein Pfeil mehr', ! str_contains($zeile, '↑'));
check('das Steuerelement zeigt `compact` als gewählt', (bool) preg_match('/<option value="' . $compact->id . '"[^>]*selected/', $zeile));

echo "\n== 2. ohne Haken schreibt das Speichern die geerbte Zeile nicht — mit Haken schon ==\n";

speichern($kind->id, ['taxmod_value' => [(string) $renderKante => (string) $compact->id]]);
check('ohne Haken: das Kind hat weiter keinen eigenen Renderer', ! isset($data->settingValuesOf($kind->id, [$renderKante])[$renderKante]));

speichern($kind->id, [
    'taxmod_value'          => [(string) $renderKante => (string) $compact->id],
    'taxmod_value_override' => [(string) $renderKante => '1'],
]);
check('mit Haken: der Wert ist ein eigener', isset($data->settingValuesOf($kind->id, [$renderKante])[$renderKante]));

$zeile = wertspalte(seite($kind->id), $renderKante);
check('und die Zeile ist nicht mehr gesperrt', ! str_contains($zeile, 'taxmod-setting-locked'));
check('und trägt keinen Haken mehr', ! str_contains($zeile, 'taxmod_value_override'));

echo "\n== 3. ein geerbter Renderer, der hier nicht zulässig ist, ist ein Konflikt (D-687, D-688) ==\n";

$zahl = $editor->createNode('__lk Zahl', $integer->id);
speichern($integer->id, ['taxmod_value' => [(string) $renderKante => (string) $compact->id]]);
check('`Integer` trägt `compact` als eigene Wahl', isset($data->settingValuesOf($integer->id, [$renderKante])[$renderKante]));
check('und zeichnet damit — hier gewählt ist Rat, kein Zaun (D-360)', $zeichner()->rendererNameFor($integer) === CompactRenderer::NAME, (string) $zeichner()->rendererNameFor($integer));

$zeile = wertspalte(seite($zahl->id), $renderKante);
check('das Kind zeigt die Zeile als automatisch', str_contains($zeile, 'taxmod-setting-automatic'), substr($zeile, 0, 160));
check('und nicht gesperrt — im Konflikt fällt die Sperre von selbst (D-689)', ! str_contains($zeile, 'taxmod-setting-locked"'));
check('der Haken ist vom System gesetzt', str_contains($zeile, 'value="1" checked'));
check('und der Satz nennt, was ersetzt wurde', str_contains($zeile, 'chosen automatically') && str_contains($zeile, 'compact'), substr(strip_tags($zeile), -160));
$standard = ShippedRenderers::registry()->defaultFor(SimpleType::Int)->name();
preg_match('/<select\b[^>]*>.*?<\/select>/s', $zeile, $wahl);
preg_match('/<option value="[^"]*"[^>]*\bselected\b[^>]*>([^<]*)</', $wahl[0] ?? '', $gewaehlt);
check('als gewählt steht die Vorgabe, der Typ-Standard `' . $standard . '` — nicht `compact`', ($gewaehlt[1] ?? '') === $standard, $gewaehlt[1] ?? 'nichts gewählt');
check('und es gilt der Typ-Standard `' . $standard . '`, nicht `compact`', $zeichner()->rendererNameFor($zahl) === $standard, (string) $zeichner()->rendererNameFor($zahl));

echo "\n== 4. dieselbe Sperre in der Tafel unter einer Feldzeile (D-685: die Adresse an der Kante) ==\n";

$feld   = $editor->addField($eltern->id, $text->id, '__lk Feld', RelationKind::Composition);
$markup = seite($eltern->id, (string) $feld->id);

preg_match_all('/name="taxmod_field_setting_override\[' . $feld->id . '\]\[([^\]]+)\]"/', $markup, $haken);
$schluessel = array_values(array_unique($haken[1] ?? []));
check('die aufgeklappte Zeile trägt gesperrte Einstellungen mit Haken', $schluessel !== [], 'keine gefunden');

if ($schluessel !== []) {
    $erster = $schluessel[0];
    check('die erste gesperrte Zeile (`' . $erster . '`) nennt ihre Herkunft', (bool) preg_match('/taxmod-setting-locked.*?inherited from [^<]+/s', $markup));

    $wert = match ($erster) {
        'read_only', 'with_label' => '1',
        'display_size'            => '42',
        default                   => '1',
    };

    speichern($eltern->id, ['taxmod_field_setting' => [(string) $feld->id => [$erster => $wert]]]);
    $angabe = $zeichner()->settingsForUseSites([$feld])[$feld->id][$erster] ?? null;
    check('ohne Haken bleibt `' . $erster . '` an der Stelle geerbt', $angabe !== null && ! $angabe->setHere, $angabe === null ? 'keine Angabe' : 'setHere');

    speichern($eltern->id, [
        'taxmod_field_setting'          => [(string) $feld->id => [$erster => $wert]],
        'taxmod_field_setting_override' => [(string) $feld->id => [$erster => '1']],
    ]);
    $angabe = $zeichner()->settingsForUseSites([$feld])[$feld->id][$erster] ?? null;
    check('mit Haken ist `' . $erster . '` an der Stelle gesetzt', $angabe !== null && $angabe->setHere, $angabe === null ? 'keine Angabe' : 'geerbt');
}

echo "\n{$passed} ok, {$failed} failed\n";

exit($failed === 0 ? 0 : 1);
