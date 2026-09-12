<?php declare(strict_types=1);

/**
 * `position` — ein Kind ordnet geerbte Felder an, an derselben Adresse wie seine anderen Einstellungen.
 *
 *     php scripts/dev/field-order-check.php [path/to/wordpress]
 *
 * ⚠️ **[D-698](../../docs/NewConcept/90-decision-log.md), sein Wort:** *«würde sagen kind darf felder
 * neu anordnen»* — auf *«ich müsste prefix vor unit in with prefix knoten schieben».*
 *
 * ```mermaid
 * flowchart LR
 *   R["Root --position (0..1)--> Integer"] --> B["Besitzer A: f1, f2"]
 *   B --> K["Kind B: f1, f2, g — Knöpfe an jeder Zeile"]
 *   K -->|"g hoch, zweimal"| S["Sätze B × f1, B × f2, B × g · settings · position"]
 *   S --> E["Enkel C liest g, f1, f2 · eigenes h danach"]
 *   S -.->|"unberührt"| B
 * ```
 *
 * @see docs/NewConcept/20-interaction.md
 */

$wordpress = $argv[1] ?? (getenv('WP_ROOT') ?: 'C:/Devel/Wordpress');

define('WP_USE_THEMES', false);

require rtrim($wordpress, '/') . '/wp-load.php';

// ⚠️ **Kein Wächter schreibt in das Modell des Eigentümers** — die Klammer dreht am Ende alles zurück.
require __DIR__ . '/lib/no-write.php';
require __DIR__ . '/../../vendor/autoload.php';

use Taxmod\Core\Model\Multiplicity;
use Taxmod\Core\Model\RecordType;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\Type\IntType;
use Taxmod\Core\Model\Type\TextType;
use Taxmod\Core\Service\FieldOrder;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
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
$ordnung   = new FieldOrder($rows, $relations, $nodes, $framework);

wp_set_current_user(1);

function seite(int $nodeId): string
{
    $_GET = ['page' => 'taxmod-nodes', 'taxmod_node' => (string) $nodeId];

    $rc     = new ReflectionClass(Plugin::class);
    $plugin = $rc->newInstanceWithoutConstructor();
    $rc->getProperty('file')->setValue($plugin, __FILE__);

    $markup = $plugin->screen()->render();
    $_GET   = [];

    return $markup;
}

function verschieben(int $nodeId, int $relationId, string $richtung): void
{
    $post = [
        'do'            => $richtung,
        'id'            => (string) $nodeId,
        'relation'      => (string) $relationId,
        '_taxmod_nonce' => wp_create_nonce('taxmod_node_' . $nodeId),
    ];
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

/** @return list<string> Die Namen der Feldzeilen in der Reihenfolge, die der Kern liefert — ohne die Einstellungen der Wurzel. */
$namen = static fn (int $nodeId): array => array_values(array_map(
    static fn (Relation $r): string => $r->name,
    array_filter($editor->fieldsOf($nodeId), static fn (Relation $r): bool => ! $r->isSetting())
));

/** @return list<string> Die Namen in der Reihenfolge, in der die Seite die Zeilen zeichnet. */
$aufDerSeite = static function (int $nodeId) use ($editor): array {
    $markup = seite($nodeId);
    $stellen = [];

    foreach ($editor->fieldsOf($nodeId) as $kante) {
        if ($kante->isSetting()) {
            continue;
        }

        $wo = strpos($markup, '<input type="hidden" name="relation" value="' . $kante->id . '">');

        if ($wo !== false) {
            $stellen[$kante->name] = $wo;
        }
    }

    asort($stellen);

    return array_keys($stellen);
};

echo "== 1. Fassung 45: die Einstellung `position` steht an der Wurzel ==\n";

$positionKante = $ordnung->positionRelation();
$integer       = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . Schema::table('nodes') . ' WHERE implemented_by = %s ORDER BY id LIMIT 1', IntType::class));
$text          = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . Schema::table('nodes') . ' WHERE implemented_by = %s ORDER BY id LIMIT 1', TextType::class));

check('die Kante `position` ist an der Wurzel erklärt, als Einstellung', $positionKante !== null && $positionKante->fromNodeId === $framework->root()->id && $positionKante->isSetting());
check('mit `0..1` auf `Integer`', $positionKante !== null && $positionKante->multiplicity === Multiplicity::ZeroToOne && $positionKante->toNodeId === $integer, $positionKante === null ? 'keine' : $positionKante->multiplicity->value);
check('sie heisst, wie der Schlüssel im Kern heisst', $positionKante !== null && $positionKante->name === 'position');

if ($positionKante === null || $text === 0) {
    echo "\n{$passed} ok, {$failed} failed\n";
    exit(1);
}

echo "\n== 2. der Aufbau: Besitzer A mit f1, f2 — Kind B mit g — Enkel C ==\n";

$a  = $editor->createNode('__fo Besitzer', $framework->root()->id);
$f1 = $editor->addField($a->id, $text, 'f1');
$f2 = $editor->addField($a->id, $text, 'f2');
$b  = $editor->createNode('__fo Kind', $a->id);
$g  = $editor->addField($b->id, $text, 'g');
$c  = $editor->createNode('__fo Enkel', $b->id);

check('B sieht f1, f2, g — die Reihenfolge der Besitzer, solange nichts gesetzt ist', $namen($b->id) === ['f1', 'f2', 'g'], implode(',', $namen($b->id)));
check('und an B gilt noch keine Anordnung', ! $ordnung->isArrangedAt($b->id, $editor->fieldsOf($b->id)));

$markup = seite($b->id);
// ⚠️ *Ein Knopf, der nicht wirken kann, wird ausgegraut gezeichnet — «kann» heisst hier: steht und ist nicht `disabled`.*
$knopf  = static fn (string $akt, int $kante): bool => (bool) preg_match(
    '/<input type="hidden" name="relation" value="' . $kante . '">(?:(?!<\/form>).)*?name="do" value="' . $akt . '"([^>]*)>/s',
    $markup,
    $treffer
) && ! str_contains($treffer[1], 'disabled');
check('die geerbte Zeile f2 hat an B einen Knopf «hoch» und einen «runter»', $knopf('field_up', $f2->id) && $knopf('field_down', $f2->id));
check('die erste Zeile f1 hat keinen Knopf «hoch», die letzte g keinen «runter» (D-429)', ! $knopf('field_up', $f1->id) && ! $knopf('field_down', $g->id));

echo "\n== 3. g zweimal hoch an B: die Anordnung wohnt in den Sätzen B × Kante ==\n";

verschieben($b->id, $g->id, 'field_up');
check('nach einem Schritt: f1, g, f2', $namen($b->id) === ['f1', 'g', 'f2'], implode(',', $namen($b->id)));

verschieben($b->id, $g->id, 'field_up');
check('nach dem zweiten: g, f1, f2', $namen($b->id) === ['g', 'f1', 'f2'], implode(',', $namen($b->id)));

$satzF1 = $rows->ofRelationAt($b->id, $f1->id);
check('der Satz B × f1 liegt vor, als settings (D-667, D-704)', $satzF1 !== null && $satzF1->recordType === RecordType::Settings, $satzF1 === null ? 'kein Satz' : $satzF1->recordType->value);
$positionen = $ordnung->positionsAt($b->id, $editor->fieldsOf($b->id));
check('und die Positionen lauten g 0, f1 1, f2 2', ($positionen[$g->id] ?? null) === 0 && ($positionen[$f1->id] ?? null) === 1 && ($positionen[$f2->id] ?? null) === 2, json_encode($positionen));
check('die Seite von B zeichnet die Zeilen so', $aufDerSeite($b->id) === ['g', 'f1', 'f2'], implode(',', $aufDerSeite($b->id)));

// ⚠️ **Die Vorschau zeichnet die Felder in derselben Reihenfolge wie die Feldliste** ([D-744](../../docs/NewConcept/90-decision-log.md)) —
// sein Befund am 2026-09-12: «Reihenfolge stimmt nicht», Liste Name–Vorname, Vorschau Vorname–Name. *Das Formular sortierte
// nach `sort_order` der Kante und warf die Anordnung des Kindes weg.*
$inDerVorschau = static function (int $nodeId) use ($editor): array {
    $markup = seite($nodeId);
    $von    = strpos($markup, 'class="taxmod-preview"');
    $stellen = [];
    foreach ($editor->fieldsOf($nodeId) as $kante) {
        if ($kante->isSetting()) { continue; }
        $wo = $von === false ? false : strpos($markup, '<span class="taxmod-form-label">' . $kante->name . '</span>', $von);
        if ($wo !== false) { $stellen[$kante->name] = $wo; }
    }
    asort($stellen);
    return array_keys($stellen);
};
check('und die Vorschau von B zeichnet sie in derselben Reihenfolge (D-744)', $inDerVorschau($b->id) === ['g', 'f1', 'f2'], implode(',', $inDerVorschau($b->id)));

echo "\n== 4. der Besitzer bleibt, wie er ist ==\n";

$f1Jetzt = $relations->byId($f1->id);
$f2Jetzt = $relations->byId($f2->id);
check('A sieht weiter f1, f2', $namen($a->id) === ['f1', 'f2'], implode(',', $namen($a->id)));
check('und `sort_order` der beiden Kanten hat sich nicht bewegt', $f1Jetzt->sortOrder === $f1->sortOrder && $f2Jetzt->sortOrder === $f2->sortOrder);

echo "\n== 5. der Enkel erbt die Anordnung, sein eigenes Feld kommt danach — und er darf wieder umstellen ==\n";

check('C liest g, f1, f2', $namen($c->id) === ['g', 'f1', 'f2'], implode(',', $namen($c->id)));
$h = $editor->addField($c->id, $text, 'h');
check('mit eigenem h danach: g, f1, f2, h', $namen($c->id) === ['g', 'f1', 'f2', 'h'], implode(',', $namen($c->id)));

verschieben($c->id, $f2->id, 'field_up');
check('f2 hoch an C: g, f2, f1, h', $namen($c->id) === ['g', 'f2', 'f1', 'h'], implode(',', $namen($c->id)));
check('B bleibt bei g, f1, f2 — näher schlägt ferner, nicht umgekehrt (D-602)', $namen($b->id) === ['g', 'f1', 'f2'], implode(',', $namen($b->id)));

echo "\n== 6. gilt an einem Knoten eine Anordnung, geht auch die eigene Zeile über sie ==\n";

$gVorher = $relations->byId($g->id)->sortOrder;
verschieben($b->id, $g->id, 'field_down');
check('g runter an B: f1, g, f2', $namen($b->id) === ['f1', 'g', 'f2'], implode(',', $namen($b->id)));
check('und `sort_order` von g blieb — geschrieben wurde die Position', $relations->byId($g->id)->sortOrder === $gVorher);

echo "\n== 7. beim Besitzer ohne Anordnung bleibt es D-435: die eigene Zeile wandert über `sort_order` ==\n";

$f2Vorher = $relations->byId($f2->id)->sortOrder;
verschieben($a->id, $f2->id, 'field_up');
check('f2 hoch an A: f2, f1', $namen($a->id) === ['f2', 'f1'], implode(',', $namen($a->id)));
check('über `sort_order`, ohne Satz A × f2', $relations->byId($f2->id)->sortOrder !== $f2Vorher && $rows->ofRelationAt($a->id, $f2->id) === null);
check('B hält seine eigene Anordnung dagegen: f1, g, f2', $namen($b->id) === ['f1', 'g', 'f2'], implode(',', $namen($b->id)));

echo "\n{$passed} ok, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
