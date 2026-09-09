<?php declare(strict_types=1);

/**
 * `allowed` — welche Kinder des Ziels an einem Knoten für ein geerbtes Auswahlfeld erlaubt sind.
 *
 *     php scripts/dev/allowed-check.php [path/to/wordpress]
 *
 * ⚠️ **[D-697](../../docs/NewConcept/90-decision-log.md), seine Sätze:** *«nun sagt aber jeder knoten ich
 * erlaube nur spezielle unterknoten von präfix» — «eigentlich sage ich welche kindknoten von präfix für
 * gramm erlaubt sind» — «ok gefällt mir».*
 *
 * ```mermaid
 * flowchart LR
 *   P["Prefixes --allowed (0..*)--> Node reference"] --> G["Gramm · Zeile Präfix · Hakenliste, alle an"]
 *   G -->|"speichern: kilo, milli"| S["Satz Gramm × Präfix · zwei Verweise"]
 *   S --> E["Einheitenwert-Teil mit einheit = Gramm · prefix bietet zwei an"]
 *   S --> O["einheit = Ohm · prefix bietet alle an"]
 *   S --> K["Kind von Gramm erbt die Liste · nie weiter"]
 * ```
 *
 * @see docs/NewConcept/10-domain-core.md
 */

$wordpress = $argv[1] ?? (getenv('WP_ROOT') ?: 'C:/Devel/Wordpress');

define('WP_USE_THEMES', false);

require rtrim($wordpress, '/') . '/wp-load.php';

// ⚠️ **Kein Wächter schreibt in das Modell des Eigentümers** — die Klammer dreht am Ende alles zurück.
require __DIR__ . '/lib/no-write.php';
require __DIR__ . '/../../vendor/autoload.php';

use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Renderer\ShippedRenderers;
use Taxmod\Core\Service\DataEntry;
use Taxmod\Core\Service\Labels;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\Core\Service\ModelValues;
use Taxmod\Core\Service\Rendering;
use Taxmod\WordPress\Admin\SettingsScreen;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\SeededTypeNodes;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbLabelRepository;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRecordRepository;
use Taxmod\WordPress\Persistence\WpdbRelationRepository;
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

$log       = new WpdbChangelog(new SystemClock());
$nodes     = new WpdbNodeRepository();
$relations = new WpdbRelationRepository();
$rows      = new WpdbRecordRepository();
$framework = new SeededFrameworkNodes($nodes, $relations, $log);
$editor    = new ModelEditor($nodes, $relations, $framework, $log, new WpdbLabelRepository(), $rows);
$data      = new DataEntry($rows, $relations, $nodes, $framework, new SystemClock(), $log);

/** Ein frischer Zeichner je Frage — er merkt sich Ketten und Sätze. */
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
$modell = static fn (): ModelValues => new ModelValues(new WpdbRecordRepository(), $relations, $nodes, $framework);

wp_set_current_user(1);

function seite(int $nodeId, ?string $offen = null): string
{
    $_GET = ['page' => 'taxmod-nodes', 'taxmod_node' => (string) $nodeId];

    if ($offen !== null) {
        $_GET['taxmod_open_rows'] = $offen;
    }

    $rc     = new ReflectionClass(Plugin::class);
    $plugin = $rc->newInstanceWithoutConstructor();
    $rc->getProperty('file')->setValue($plugin, __FILE__);

    $markup = $plugin->screen()->render();
    $_GET   = [];

    return $markup;
}

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

// ⚠️ *Über die Notiz des Gerüsts, nicht über den Namen (D-709, TASK-049) — `Einheitenwert` hat seine eigene.*
$idVon = static fn (string $name): int => (int) ($name === 'Einheitenwert' ? \Taxmod\WordPress\Persistence\UnitScaffold::unitValueId() : \Taxmod\WordPress\Persistence\UnitScaffold::nodeId($name)) ?? 0;

$prefixes = $idVon('Prefixes');
$gramm    = $idVon('Gramm');
$ohm      = $idVon('Ohm');
$kilo     = $idVon('kilo');
$milli    = $idVon('milli');
$einheitenwert = $idVon('Einheitenwert');

if (min($prefixes, $gramm, $ohm, $kilo, $milli, $einheitenwert) === 0) {
    echo "  FAIL das Gerüst der Einheiten fehlt (Prefixes, Gramm, Ohm, kilo, milli, Einheitenwert)\n";

    exit(1);
}

echo "== 1. die Kante `allowed` steht an `Prefixes` (Fassung 44) ==\n";

$praefixKante = null;

foreach ($editor->fieldsOf($gramm) as $kante) {
    if ($kante->toNodeId === $prefixes && ! $kante->isSetting()) {
        $praefixKante = $kante;
    }
}

check('`Gramm` erbt ein Feld auf `Prefixes` — sein Aufbau «With prefix --Präfix--> Prefixes»', $praefixKante !== null);

$liste = $praefixKante === null ? null : $modell()->allowedRelationFor($praefixKante);
check('an dieser Stelle ist die Kante `allowed` erklärt: setting, mehrere Werte, Ziel `Node reference`', $liste !== null && $liste->fromNodeId === $prefixes && $liste->multiplicity->allowsMany(), $liste === null ? 'keine' : $liste->name);

if ($praefixKante === null || $liste === null) {
    echo "\n{$passed} ok, {$failed} failed\n";

    exit(1);
}

echo "\n== 2. die Zeile `Präfix` an `Gramm` zeigt die Hakenliste, alle gesetzt ==\n";

$markup = seite($gramm, (string) $praefixKante->id);
$name   = 'taxmod_field_setting[' . $praefixKante->id . '][' . $liste->id . '][]';
$haken  = preg_match_all('/<input type="checkbox" name="' . preg_quote($name, '/') . '" value="(\d+)"([^>]*)>/', $markup, $treffer);
check('die Hakenliste steht in der aufgeklappten Zeile', $haken > 0, 'keine Haken unter ' . $name);
// ⚠️ *Je angebotenem Präfix — das Angebot des Feldes, nicht alle Kinder: versteckte zählen nicht.*
$praefixe = count($zeichner()->offeredFor($praefixKante));
check('mit einem Haken je angebotenem Präfix', $praefixe > 0 && $haken === $praefixe, "{$haken} Haken, {$praefixe} angeboten");
check('und alle gesetzt, weil nichts gespeichert ist', $haken > 0 && count(array_filter($treffer[2], static fn (string $rest): bool => str_contains($rest, 'checked'))) === $haken);

echo "\n== 3. speichern: kilo und milli — der Satz Gramm × Präfix trägt zwei Verweise ==\n";

abschicken([
    'do'                   => 'put_setting',
    'id'                   => (string) $gramm,
    '_taxmod_nonce'        => wp_create_nonce('taxmod_node_' . $gramm),
    'taxmod_field_setting' => [(string) $praefixKante->id => [(string) $liste->id => [(string) $kilo, (string) $milli]]],
]);

$satz = $rows->ofRelationAt($gramm, $praefixKante->id);
check('der Satz liegt an der Adresse Gramm × Präfix, als settings', $satz !== null && $satz->recordType->value === 'settings', $satz === null ? 'kein Satz' : $satz->recordType->value);
check('mit genau den zwei Verweisen', $satz !== null && array_map(static fn ($w): ?int => $w->value->reference, $rows->valuesOf($satz->id)) === [$kilo, $milli]);
check('`allowedAt(Gramm)` liest sie', $modell()->allowedAt($gramm, $praefixKante) === [$kilo, $milli]);
check('`allowedAt(Ohm)` ist leer — alle erlaubt', $modell()->allowedAt($ohm, $praefixKante) === []);

$markup = seite($gramm, (string) $praefixKante->id);
preg_match_all('/<input type="checkbox" name="' . preg_quote($name, '/') . '" value="(\d+)"([^>]*)>/', $markup, $treffer);
$gesetzt = [];

foreach ($treffer[1] as $i => $id) {
    if (str_contains($treffer[2][$i], 'checked')) {
        $gesetzt[] = (int) $id;
    }
}

sort($gesetzt);
check('und die Zeile zeigt jetzt genau die zwei gesetzt', $gesetzt === [min($kilo, $milli), max($kilo, $milli)], implode(',', $gesetzt));

echo "\n== 4. das Angebot des Feldes `prefix` hängt am gewählten Nachbarn `einheit` ==\n";

$felder = $editor->fieldsOf($einheitenwert);
$einheitKante = null;
$prefixKante  = null;

foreach ($felder as $kante) {
    if ($kante->name === 'einheit') {
        $einheitKante = $kante;
    }

    if ($kante->name === 'prefix') {
        $prefixKante = $kante;
    }
}

check('`Einheitenwert` hat `einheit` und `prefix`', $einheitKante !== null && $prefixKante !== null);

if ($einheitKante !== null && $prefixKante !== null) {
    // ⚠️ *Gemessen am Angebot, das der Abstieg dem Wähler gibt — derselbe Weg wie beim Zeichnen.*
    $angebot = static fn (int $einheit): int => count(
        $zeichner()->offerIn($felder, [$einheitKante->id => TypedValue::ofReference($einheit)])[$prefixKante->id] ?? []
    );

    $alle = count($zeichner()->offeredFor($prefixKante));
    check('mit `einheit = Gramm` bietet `prefix` genau zwei an', $angebot($gramm) === 2, (string) $angebot($gramm));
    check("mit `einheit = Ohm` bietet `prefix` alle {$alle} an", $angebot($ohm) === $alle, (string) $angebot($ohm));
}

echo "\n== 5. ein Kind von `Gramm` erbt die Liste — verengt, nie geweitet (D-221) ==\n";

$kind = $editor->createNode('__al Gramm-Kind', $gramm);
check('das Kind sieht die Liste seines Vaters', $modell()->allowedAt($kind->id, $praefixKante) === [$kilo, $milli]);

echo "\n== 6. alle Haken gesetzt heisst: nichts gespeichert ==\n";

$alleIds = array_map(static fn ($k): string => (string) $k->id, $nodes->childrenOf($nodes->find($prefixes)));
abschicken([
    'do'                   => 'put_setting',
    'id'                   => (string) $gramm,
    '_taxmod_nonce'        => wp_create_nonce('taxmod_node_' . $gramm),
    'taxmod_field_setting' => [(string) $praefixKante->id => [(string) $liste->id => $alleIds]],
]);
check('die Liste an `Gramm` ist wieder leer', $modell()->allowedAt($gramm, $praefixKante) === []);

echo "\n{$passed} ok, {$failed} failed\n";

exit($failed === 0 ? 0 : 1);
