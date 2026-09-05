<?php declare(strict_types=1);
/**
 * Zwei weitere Einstellungen an Renderern: `with_label` und `orientation`.
 *
 *     php scripts/add-renderer-settings.php            (Probelauf)
 *     php scripts/add-renderer-settings.php --write    (baut)
 *
 * ⚠️ **Auf sein Wort:** *«wir haben `with label` als bool für `form`, `table` und `compact`, für
 * `compact` haben wir noch einen horizontal/vertical-Umschalter».*
 *
 * ⚠️ **`table` ist nicht dabei, und das ist gemessen:** *unter `Renderer` liegen sechzehn Knoten, und
 * `table` ist keiner davon — es existiert nur als **Namensrolle** unter `Label roles`. Auch im Code
 * gibt es keinen `table`-Renderer. Auf seine Rückfrage: nein.*
 *
 * ⚠️ *`with_label` bekommt eine Gruppe, weil zwei Renderer sie teilen; `orientation` hängt direkt an
 * `compact`, weil nur er sie braucht — **eine Gruppe für einen einzigen wäre ein Name ohne
 * Wirkung**, und danach hat er selbst gefragt.*
 *
 * ⚠️ **Und die Grenze, die dabei sichtbar bleibt:** *ein Knoten hat **einen** Vater. Braucht später ein
 * Renderer `with_label` **und** `label_role`, kann der Baum das nicht ausdrücken — dann muss die
 * Angabe an den Renderer selbst statt an eine Gruppe. Heute überschneidet sich nichts.*
 */

$schreiben = in_array('--write', $argv, true);
$root      = getenv('WP_ROOT') ?: null;

if ($root === null) {
    $dir = getcwd();
    while ($dir !== '' && ! is_readable($dir . '/wp-load.php')) {
        $up  = dirname($dir);
        $dir = $up === $dir ? '' : $up;
    }
    $root = $dir;
}

if ($root === '' || ! is_readable($root . '/wp-load.php')) {
    fwrite(STDERR, "Cannot find wp-load.php.\n");
    exit(2);
}

define('WP_USE_THEMES', false);
require $root . '/wp-load.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Taxmod\Core\Model\Branch;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRecordRepository;
use Taxmod\WordPress\Persistence\WpdbRelationRepository;
use Taxmod\WordPress\SystemClock;

global $wpdb;

$nodes  = new WpdbNodeRepository();
$edges  = new WpdbRelationRepository();
$log    = new WpdbChangelog(new SystemClock());
$fw     = new SeededFrameworkNodes($nodes, $edges, $log);
$editor = new ModelEditor($nodes, $edges, $fw, $log, records: new WpdbRecordRepository());

$n = Schema::table('nodes');

function knotenNamens(string $name, string $unterPfad = ''): ?int
{
    global $wpdb, $n;

    $sql = "SELECT id FROM {$n} WHERE name = %s";
    $args = [$name];

    if ($unterPfad !== '') {
        $sql   .= ' AND path LIKE %s';
        $args[] = $wpdb->esc_like($unterPfad . '.') . '%';
    }

    $id = (int) $wpdb->get_var($wpdb->prepare($sql . ' LIMIT 1', ...$args));

    return $id === 0 ? null : $id;
}

/** Ein Feld anlegen, falls es fehlt, und als Einstellungskante markieren. */
function feld(ModelEditor $editor, RelationRepositoryHolder $r, int $ownerId, int $zielId, string $name): string
{
    foreach ($r->edges->fieldEdgesOf([$ownerId]) as $eine) {
        if ($eine->name === $name && $eine->fromNodeId === $ownerId) {
            return "stand schon ({$eine->id})";
        }
    }

    $neu = $editor->addField($ownerId, $zielId, $name);
    $editor->markAsSetting($ownerId, $neu->id, true);

    return "angelegt ({$neu->id}), Einstellungskante";
}

/** Kleiner Halter, damit die Funktion oben keine Globalen braucht. */
final class RelationRepositoryHolder
{
    public function __construct(public readonly WpdbRelationRepository $edges)
    {
    }
}

$halter    = new RelationRepositoryHolder($edges);
$settings  = $fw->rootOf(Branch::Settings);
$rendererId = knotenNamens('Renderer');
$booleanId  = knotenNamens('Boolean');

if ($rendererId === null || $booleanId === null) {
    fwrite(STDERR, "«Renderer» oder «Boolean» fehlt.\n");
    exit(1);
}

$rendererPfad = (string) $wpdb->get_var("SELECT path FROM {$n} WHERE id = {$rendererId}");

$formId    = knotenNamens('form', $rendererPfad);
$compactId = knotenNamens('compact', $rendererPfad);

echo "Settings = {$settings->id}, Renderer = {$rendererId}, Boolean = {$booleanId}\n";
echo 'form = ' . ($formId ?? 'FEHLT') . ', compact = ' . ($compactId ?? 'FEHLT') . "\n\n";

if ($formId === null || $compactId === null) {
    fwrite(STDERR, "«form» oder «compact» fehlt unter Renderer.\n");
    exit(1);
}

echo "  Menge «Orientation» unter Settings, mit «horizontal» und «vertical»\n";
echo "  Gruppe «render with label» unter Renderer, darunter form und compact\n";
echo "  Feld «with_label» -> Boolean an der Gruppe\n";
echo "  Feld «orientation» -> Orientation an compact\n";

if (! $schreiben) {
    echo "\nProbelauf. Mit --write bauen.\n";
    exit(0);
}

echo "\n";

// ── Die Auswahlmenge ───────────────────────────────────────────────────────
$orientation = knotenNamens('Orientation');

if ($orientation === null) {
    $orientation = $editor->createNode('Orientation', $settings->id)->id;
    echo "  angelegt      «Orientation» ({$orientation})\n";
}

foreach (['horizontal', 'vertical'] as $wahl) {
    if (knotenNamens($wahl) === null) {
        $id = $editor->createNode($wahl, $orientation)->id;
        echo "  angelegt      «{$wahl}» ({$id})\n";
    }
}

// ── Die Gruppe ─────────────────────────────────────────────────────────────
$gruppe = knotenNamens('render with label');

if ($gruppe === null) {
    $gruppe = $editor->createNode('render with label', $rendererId)->id;
    echo "  angelegt      «render with label» ({$gruppe})\n";
}

foreach (['form' => $formId, 'compact' => $compactId] as $name => $id) {
    $editor->move($id, $gruppe);
    echo "  umgezogen     {$name} -> render with label\n";
}

// ── Die zwei Felder ────────────────────────────────────────────────────────
echo '  with_label:   ' . feld($editor, $halter, $gruppe, $booleanId, 'with_label') . "\n";
echo '  orientation:  ' . feld($editor, $halter, $compactId, $orientation, 'orientation') . "\n";

echo "\nUnter Renderer jetzt:\n";

foreach ($wpdb->get_results($wpdb->prepare(
    "SELECT id, name, path FROM {$n} WHERE path LIKE %s ORDER BY path",
    $wpdb->esc_like($rendererPfad . '.') . '%'
), ARRAY_A) ?: [] as $z) {
    $tiefe = substr_count((string) $z['path'], '.') - substr_count($rendererPfad, '.') - 1;
    printf("  %s%-22s %s\n", str_repeat('  ', $tiefe), $z['name'], $z['id']);
}
