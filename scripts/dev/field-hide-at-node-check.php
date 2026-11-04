<?php declare(strict_types=1);

/**
 * `hidden` — ein Knoten versteckt ein geerbtes Feld für sich und seinen Unterbaum, die Kante des Besitzers bleibt.
 *
 *     php scripts/dev/field-hide-at-node-check.php [path/to/wordpress]
 *
 * ⚠️ **[D-1144](../../docs/NewConcept/90-decision-log.md), sein Wort:** *«die relation müsste überschreibbar sein»* —
 * an Lagerorten standen Herkunft und Bilder, weil sie an «Model» erklärt sind.
 *
 * ```mermaid
 * flowchart LR
 *   R["Root --hidden (1..1)--> Boolean"] --> A["Besitzer A: f1, f2"]
 *   A --> B["Kind B: Auge an f1 → Satz B × f1 · hidden = 1"]
 *   B --> C["Enkel C: f1 versteckt · zeigt es wieder"]
 *   A --> D["Geschwister D: f1 sichtbar"]
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
use Taxmod\Core\Service\FieldVisibility;
use Taxmod\Core\Model\Type\BoolType;
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
$sicht     = new FieldVisibility($rows, $relations, $nodes, $framework);

wp_set_current_user(1);

function seite(int $nodeId): string
{
    // ⚠️ *Geändert am 2026-09-19 mit D-871, sichtbar: geerbte Zeilen stehen erst auf Wunsch da — die Prüfung klappt sie auf.*
    $_GET = ['page' => 'taxmod-nodes', 'taxmod_node' => (string) $nodeId, 'taxmod_inherited' => '1'];

    $rc     = new ReflectionClass(Plugin::class);
    $plugin = $rc->newInstanceWithoutConstructor();
    $rc->getProperty('file')->setValue($plugin, __FILE__);

    $markup = $plugin->screen()->render();
    $_GET   = [];

    return $markup;
}

function auge(int $nodeId, int $relationId, string $richtung = 'toggle_field_hide'): void
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


/** @return array<string, bool> Name => versteckt, an diesem Knoten, ohne die Einstellungen der Wurzel. */
$zustand = static function (int $nodeId) use ($editor): array {
    $aus = [];
    foreach ($editor->fieldsOf($nodeId) as $r) {
        if (! $r->isSetting()) {
            $aus[$r->name] = $r->hide;
        }
    }
    return $aus;
};

/** Ob die Vorschau des Knotens das Feld als Beschriftung zeichnet. */
$inDerVorschau = static function (int $nodeId, string $name): bool {
    $markup = seite($nodeId);
    $von    = strpos($markup, 'class="taxmod-preview"');
    return $von !== false && preg_match('/(?:<span class="taxmod-form-label">|<th class="taxmod-table-head" scope="(?:col|row)">)' . preg_quote($name, '/') . '(?:<\/span>|<\/th>|<span class="taxmod-hint)/', $markup, $t, 0, $von) === 1;
};

echo "== 1. Fassung 57: die Einstellung `hidden` steht an der Wurzel ==\n";

$kante   = $sicht->hiddenRelation();
$boolean = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . Schema::table('nodes') . ' WHERE implemented_by = %s ORDER BY id LIMIT 1', BoolType::class));
$text    = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . Schema::table('nodes') . ' WHERE implemented_by = %s ORDER BY id LIMIT 1', TextType::class));

check('die Kante `hidden` ist an der Wurzel erklärt, als Einstellung', $kante !== null && $kante->fromNodeId === $framework->root()->id && $kante->isSetting());
check('auf `Boolean`, mit `1..1` wie die Zielklasse es will (D-713)', $kante !== null && $kante->multiplicity === Multiplicity::ExactlyOne && $kante->toNodeId === $boolean);

if ($kante === null || $text === 0) {
    echo "\n{$passed} ok, {$failed} failed\n";
    exit(1);
}

echo "\n== 2. der Aufbau: Besitzer A mit f1, f2 — Kind B — Enkel C — Geschwister D ==\n";

$a  = $editor->createNode('__fh Besitzer', $framework->root()->id);
$f1 = $editor->addField($a->id, $text, 'f1');
$f2 = $editor->addField($a->id, $text, 'f2');
$b  = $editor->createNode('__fh Kind', $a->id);
$c  = $editor->createNode('__fh Enkel', $b->id);
$d  = $editor->createNode('__fh Geschwister', $a->id);

check('B sieht f1 und f2 sichtbar, solange nichts gesetzt ist', $zustand($b->id) === ['f1' => false, 'f2' => false], json_encode($zustand($b->id)));

$markup = seite($b->id);
$knopf  = (bool) preg_match('/<input type="hidden" name="relation" value="' . $f1->id . '">(?:(?!<\/form>).)*?name="do" value="toggle_field_hide"([^>]*)>/s', $markup, $treffer);
check('die geerbte Zeile f1 hat an B ein Auge, das nicht ausgegraut ist', $knopf && ! str_contains($treffer[1], 'disabled'), $knopf ? $treffer[1] : 'kein Knopf');

echo "\n== 3. das Auge an B: f1 ist an B und darunter versteckt, sonst nicht ==\n";

auge($b->id, $f1->id);
check('an B: f1 versteckt, f2 sichtbar', $zustand($b->id) === ['f1' => true, 'f2' => false], json_encode($zustand($b->id)));
check('am Enkel C ebenso (Unterbaum)', ($zustand($c->id)['f1'] ?? null) === true);
check('am Geschwister D sichtbar', ($zustand($d->id)['f1'] ?? null) === false);
check('am Besitzer A sichtbar', ($zustand($a->id)['f1'] ?? null) === false);
check('und die Spalte `hide` der Kante blieb 0', $relations->byId($f1->id)->hide === false);
$satz = $rows->ofRelationAt($b->id, $f1->id);
check('geschrieben steht es im Satz B × f1, als settings (D-667)', $satz !== null && $satz->recordType === RecordType::Settings);
check('die Vorschau von B zeichnet f1 nicht, f2 schon', ! $inDerVorschau($b->id, 'f1') && $inDerVorschau($b->id, 'f2'));
check('die Vorschau von D zeichnet f1', $inDerVorschau($d->id, 'f1'));

echo "\n== 4. näher schlägt ferner: C zeigt f1 wieder ==\n";

auge($c->id, $f1->id);
check('an C: f1 sichtbar', ($zustand($c->id)['f1'] ?? null) === false);
check('an B weiter versteckt', ($zustand($b->id)['f1'] ?? null) === true);

echo "\n== 5. am Besitzer schaltet das Auge weiter die Spalte ==\n";

auge($a->id, $f2->id);
check('A: f2 versteckt über die Spalte, ohne Satz A × f2', $relations->byId($f2->id)->hide === true && $rows->ofRelationAt($a->id, $f2->id) === null);
check('und B erbt es: f2 versteckt', ($zustand($b->id)['f2'] ?? null) === true);
auge($b->id, $f2->id);
check('B zeigt f2 wieder für sich, die Spalte bleibt 1', ($zustand($b->id)['f2'] ?? null) === false && $relations->byId($f2->id)->hide === true);

echo "\n{$passed} ok, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
