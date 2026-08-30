<?php declare(strict_types=1);
/**
 * Eine Angabe des Modells wird dort geschrieben, wo sie gelesen wird.
 *
 *     php scripts/dev/setting-write-check.php [path/to/wordpress]
 *
 * ⚠️ **Der Eigentümer hat es an der Oberfläche gesehen:** *«den Render kann ich noch nicht setzen.»*
 * *Und gemessen war es kein fehlender Renderer, sondern ein **Schreiber an der alten Stelle**: der
 * Wähler am Knoten schrieb in die `settings`-Tabelle, und {@see \Taxmod\Core\Service\ModelValues} liest
 * aus Datensätzen und **gewinnt** ({@see \Taxmod\Core\Service\Rendering::withModelValues()} verteilt das
 * Modell zuletzt). **Also blieb jede Wahl ohne Wirkung** — an 26 Knoten, die ihren Renderer im
 * Datensatz tragen, spurlos.*
 *
 * ⚠️ **Vierter Fall derselben Sache an einem Tag**: *Daten umgezogen, Schreiber stehengeblieben. Deshalb
 * heisst die Reihenfolge in diesem Projekt **Wächter, Leser, Daten** — und deshalb steht diese Prüfung
 * hier, bevor der Schirm umgestellt wird.*
 *
 * ⚠️ **Sein Zuschnitt, und er ist der richtige:** *«auch Settings sind etwas, das gerendert werden kann,
 * und die Renderer dafür existieren schon — der Unterschied: Eingabe geschieht im Modell und nicht im
 * Frontend.» Darum prüft dieser Lauf **keine neue Speicherform**, sondern einen Rundlauf durch die
 * vorhandene: schreiben, lesen, dasselbe herausbekommen.*
 *
 * ⚠️ *Der Lauf legt sich einen eigenen Knoten an und räumt ihn samt Datensätzen wieder weg, auch bei
 * einem Absturz. **Keine Prüfung darf ihren Müll liegen lassen** — sechs ältere tun es und werden
 * nachgezogen.*
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
use Taxmod\Core\Model\SettingKey;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Service\DataEntry;
use Taxmod\Core\Service\ModelValues;
use Taxmod\Core\Service\Settings;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\TableIdentityAllocator;
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
$framework = new SeededFrameworkNodes($nodes, $edges, new TableIdentityAllocator(), $log);
$settings  = new Settings(new WpdbSettingRepository(), $nodes, $framework);
$records   = new WpdbRecordRepository();
$data      = new DataEntry($records, $edges, $nodes, $framework, new SystemClock(), $settings);

/** @var list<int> Was dieser Lauf angelegt hat — Knoten und Datensätze. */
$meineKnoten  = [];
$meineSaetze  = [];

// ⚠️ *Vor der ersten Änderung angemeldet, nicht danach.*
register_shutdown_function(static function () use (&$meineKnoten, &$meineSaetze): void {
    global $wpdb;

    foreach ($meineSaetze as $id) {
        $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('record_values') . ' WHERE record_id = %d', $id));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('records') . ' WHERE id = %d', $id));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('record_values_history') . ' WHERE record_id = %d', $id));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('records_history') . ' WHERE id = %d', $id));
    }

    foreach ($meineKnoten as $id) {
        // ⚠️ *Auch die Datensätze, die der Lauf nicht selbst notiert hat — ein Teil entsteht innen
        // drin, und ein Teil ohne Besitzer wäre genau der Müll, den diese Prüfung nicht machen darf.*
        foreach ($wpdb->get_col($wpdb->prepare('SELECT id FROM ' . Schema::table('records') . ' WHERE node_id = %d', $id)) ?: [] as $satzId) {
            $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('record_values') . ' WHERE record_id = %d', (int) $satzId));
            $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('records') . ' WHERE id = %d', (int) $satzId));
        }

        $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('relations') . ' WHERE from_id = %d OR to_id = %d', $id, $id));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('nodes') . ' WHERE id = %d', $id));
    }
});

echo "\n== Die Saat hat die zwei Kanten aufgeschrieben ==\n";

$aussen = $framework->settingEdgeId(SettingKey::Renderer);
$innen  = $framework->settingValueEdgeId(SettingKey::Renderer);

check('die Traegerkante steht da', $aussen !== 0, (string) $aussen);
check('die Wertkante steht da', $innen !== 0, (string) $innen);

echo "\n== Ein Renderer wird geschrieben und wieder gelesen ==\n";

// ⚠️ *Ein eigener Knoten und kein vorhandener: an einem echten Knoten wäre die Prüfung entweder
// zerstörerisch oder sie müsste einen Wert überschreiben und zurückstellen — und der Zwischenzustand
// wäre in der Datenbank sichtbar.*
// ⚠️ *Über den Editor und nicht mit rohem SQL: Ids kommen aus dem Identitätenverzeichnis
// ([D-339](../../docs/NewConcept/90-decision-log.md)), nicht aus `AUTO_INCREMENT` — ein `INSERT` von
// Hand bekam `insert_id = 0` zurück und legte nichts an.*
$modellWurzel = $framework->rootOf(\Taxmod\Core\Model\Branch::Model);
$editor       = new \Taxmod\Core\Service\ModelEditor($nodes, $edges, new TableIdentityAllocator(), $framework, $log);

$knoten        = $editor->createNode('Pruefknoten Einstellung', $modellWurzel->id);
$knotenId      = $knoten->id;
$meineKnoten[] = $knotenId;

check('der Pruefknoten steht im Modell', $knoten !== null, 'nicht angelegt');

if ($knoten === null) {
    echo "\n" . ($bad === 0 ? "Alles gruen: $ok\n" : "$bad fehlgeschlagen, $ok in Ordnung\n");

    exit(1);
}

// Ein echter Renderer-Knoten als Wert — der Wert ist ein **Verweis**, nicht ein Name.
$rendererKnoten = $wpdb->get_row($wpdb->prepare(
    'SELECT k.id, k.name FROM ' . Schema::table('relations') . ' e
     INNER JOIN ' . Schema::table('nodes') . ' k ON k.id = e.to_id
     INNER JOIN ' . Schema::table('nodes') . ' v ON v.id = e.from_id
     WHERE v.name = %s AND e.kind = %s AND k.name = %s LIMIT 1',
    'Renderer',
    'inheritance',
    'spinner'
), ARRAY_A);

if ($rendererKnoten === null) {
    check('«spinner» steht als Knoten unter «Renderer»', false);
} else {
    check('«spinner» steht als Knoten unter «Renderer»', true);

    $geschrieben = true;

    try {
        $data->putSettingValue($knotenId, SettingKey::Renderer, TypedValue::ofReference((int) $rendererKnoten['id']));
    } catch (NotYetStorable $e) {
        $geschrieben = false;
        check('der Renderer laesst sich schreiben', false, $e->getMessage());
    }

    if ($geschrieben) {
        check('der Renderer laesst sich schreiben', true);

        // ⚠️ **Ein frischer Leser.** *{@see ModelValues} merkt sich seine Funde je Instanz (`CD-7`) —
        // ein wiederverwendeter würde die Antwort von vorher zurückgeben und den Rundlauf grün lügen.*
        $gelesen = (new ModelValues($records, $edges, $nodes, $framework))->forNode($knoten);

        check(
            'und der Leser gibt ihn zurueck',
            ($gelesen['renderer']->value->text ?? null) === 'spinner',
            $gelesen['renderer']->value->text ?? 'nichts'
        );

        // ⚠️ *Und er liegt im **default**-Satz, nicht in einem Benutzersatz
        // ([D-026](../../docs/NewConcept/90-decision-log.md): «at model level there are no values,
        // only defaults»).*
        $arten = $wpdb->get_col($wpdb->prepare(
            'SELECT kind FROM ' . Schema::table('records') . ' WHERE node_id = %d',
            $knotenId
        )) ?: [];

        check(
            'und zwar im default-Satz',
            $arten === ['default'],
            implode(', ', $arten) ?: 'kein Satz',
        );

        // ⚠️ **Zweimal schreiben legt keinen zweiten Satz an.** *Sonst stünden am Ende zwei Antworten
        // auf eine Frage da, und der Leser nähme die erste — ein Fehler, der erst beim zweiten Ändern
        // auffällt.*
        $data->putSettingValue($knotenId, SettingKey::Renderer, TypedValue::ofReference((int) $rendererKnoten['id']));

        $wieViele = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . Schema::table('records') . ' WHERE node_id = %d',
            $knotenId
        ));

        check('zweimal geschrieben, ein Satz', $wieViele === 1, (string) $wieViele);
    }
}

echo "\n" . ($bad === 0 ? "Alles gruen: $ok\n" : "$bad fehlgeschlagen, $ok in Ordnung\n");

exit($bad === 0 ? 0 : 1);
