<?php declare(strict_types=1);
/**
 * Welchen Namen ein Feld zeichnet — die Rolle, an echten Daten festgenagelt.
 *
 *     php scripts/dev/label-role-check.php [path/to/wordpress]
 *
 * ⚠️ **[D-539](../../docs/NewConcept/90-decision-log.md), seine Berichtigung meines Vorschlags:**
 * *«das hatten wir ja, um zu sagen, dass ein Renderer ein spezielles Label verwenden soll … wenn ich
 * eine Auswahlliste habe, zum Beispiel für die Präfixe, dann soll er doch bitte das **Symbol**
 * nehmen. **Also war das eher eine Eigenschaft des Renderers.**»*
 *
 * ⚠️ **Was hier gleich bleiben muss:** *`Einheitenwert.einheit` und `.prefix` zeichnen mit `symbol` —
 * heute über eine Setting-Zeile, danach über ein Feld von `DisplayOption`. **Ohne das stünde «4 kilo
 * Ohm» statt «4 kΩ».***
 *
 * ⚠️ *Gemessen tragen die Knoten ihre Symbole: `kilo` → `k`, `Ohm` → `Ω`, `Gramm` → `g`. Die Rolle
 * entscheidet nur, welcher der Namen genommen wird — **sie erfindet keinen.***
 *
 * @see docs/NewConcept/40-i18n.md
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

use Taxmod\Core\Model\SeededRole;
use Taxmod\Core\Renderer\ShippedRenderers;
use Taxmod\Core\Service\Labels;
use Taxmod\Core\Service\ModelValues;
use Taxmod\Core\Service\Rendering;
use Taxmod\Core\Service\Settings;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\SeededTypeNodes;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbLabelRepository;
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
$model     = new ModelValues($records, $edges, $nodes, $framework);

$rendering = new Rendering(
    $nodes,
    $framework,
    $settings,
    ShippedRenderers::registry(),
    new SeededTypeNodes($nodes, $framework),
    new Labels(new WpdbLabelRepository(), $framework),
    null,
    $model
);

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

echo "\n== Die Rollen sind Knoten und werden benutzt ==\n";

$rollen = $wpdb->get_col(
    "SELECT kn.name FROM " . Schema::table('nodes') . " kn
     WHERE kn.path LIKE (SELECT CONCAT(p.path, '.%') FROM " . Schema::table('nodes') . " p
                         WHERE p.name = 'Label roles' AND p.id = 731)"
) ?: [];

foreach (['form', 'table', 'select', 'symbol', 'help'] as $rolle) {
    check("die Rolle «{$rolle}» ist ein Knoten", in_array($rolle, $rollen, true));
}

echo "\n== Und die Knoten tragen ihre Symbole ==\n";

foreach (['kilo' => 'k', 'Ohm' => 'Ω', 'Gramm' => 'g'] as $name => $symbol) {
    $text = $wpdb->get_var($wpdb->prepare(
        'SELECT lb.text FROM ' . Schema::table('labels') . ' lb
         JOIN ' . Schema::table('nodes') . ' kn ON kn.id = lb.owner_id
         JOIN ' . Schema::table('nodes') . " ro ON ro.id = lb.role_id
         WHERE kn.name = %s AND ro.name = 'symbol' LIMIT 1",
        $name
    ));

    check("«{$name}» hat das Symbol «{$symbol}»", $text === $symbol, (string) ($text ?? 'nichts'));
}

echo "\n== Welche Rolle ein Feld zeichnet ==\n";

// ⚠️ *Fest hingeschrieben, nicht aus der Tabelle gesucht — sonst meldet die Prüfung nach dem Umzug
// «nichts gefunden» statt «stimmt». Dieselbe Lehre wie beim Renderer.*
$erwartet = [
    ['Einheitenwert', 'einheit', SeededRole::Symbol],
    ['Einheitenwert', 'prefix', SeededRole::Symbol],
    ['Einheitenwert', 'wert', SeededRole::Form],
];

foreach ($erwartet as [$vonName, $feldName, $soll]) {
    $kante = feldVon($vonName, $feldName);

    if ($kante === null) {
        check("«{$vonName}.{$feldName}» steht im Modell", false, 'nicht gefunden');

        continue;
    }

    $ist = $rendering->labelRoleFor($kante);

    check(
        "«{$vonName}.{$feldName}» zeichnet die Rolle «{$soll->value}»",
        $ist === $soll,
        $ist->value
    );
}

echo "\n== Woher die Auskunft kommt ==\n";

$ausTabelle = (int) $wpdb->get_var(
    'SELECT COUNT(*) FROM ' . Schema::table('settings') . " WHERE setting_key = 'label_role'"
);

if ($ausTabelle > 0) {
    check("noch {$ausTabelle} Zeilen in der Tabelle — der Umzug laeuft", true);
} else {
    check('die Tabelle sagt nichts mehr zur Rolle', true);

    // ⚠️ *Nach dem Umzug muss `DisplayOption` das Feld tragen — sonst wäre oben grün aus einem Grund,
    // den es nicht mehr gibt.*
    $feld = feldVon('DisplayOption', 'label_role');

    check('und «DisplayOption» traegt ein Feld «label_role»', $feld !== null);

    if ($feld !== null) {
        $ziel = $nodes->find($feld->toId);

        check(
            'das auf «Label roles» zeigt',
            ($ziel?->name ?? null) === 'Label roles',
            $ziel?->name ?? 'nichts'
        );
    }
}

echo "\n" . ($bad === 0 ? "Alles gruen: $ok\n" : "$bad fehlgeschlagen, $ok in Ordnung\n");

exit($bad === 0 ? 0 : 1);
