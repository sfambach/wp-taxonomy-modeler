<?php declare(strict_types=1);

/**
 * The Feldkatalog holds ([D-1553](../../docs/NewConcept/90-decision-log.md)): every target has its role, no module makes a field
 * of its own, and the relations that still point at a base type never grow.
 *
 * ```mermaid
 * flowchart LR
 *   C["code of every wp_tax_* beside the modeller"] --> M["no add_field / addField in src/"]
 *   S["setup: element below Integer, composite with a part"] --> R["fields: target_role per row"]
 *   L["all live fields"] --> B["relations to a base type ≤ ceiling"]
 * ```
 *
 * ⚠️ **It grows with every step of the Bauliste** (konzept.md §0, Prüftor 10:01): relation carries no setting but the summary
 * (step 4), relation points at no base type (step 2, now a ceiling that only sinks), module makes no field (here), one id = one
 * role name (step 5).
 *
 *     php scripts/dev/field-catalog-check.php
 */

define('WP_USE_THEMES', false);

final class FieldCatalogStop extends \RuntimeException
{
}

require (getenv('WP_ROOT') ?: 'C:/Devel/Wordpress') . '/wp-load.php';
require __DIR__ . '/lib/no-write.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\CatalogueRole;
use Taxmod\Core\Model\Type\IntType;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;

wp_set_current_user(1);

$failed = 0;

$say = static function (bool $ok, string $what, string $detail = '') use (&$failed): void {
    printf("  %-4s %s%s\n", $ok ? 'ok' : 'FAIL', $what, $ok || $detail === '' ? '' : ' — ' . $detail);

    if (! $ok) {
        ++$failed;
    }
};

echo "== a module makes no field of its own ==\n";

// *Read from the tokens of each module's src/, so a comment or a word in a string is no finding; their checks (scripts/dev)
// build their own test models and are left out.*
$funde = [];

foreach (glob(dirname(__DIR__, 3) . '/wp_tax_*/src') ?: [] as $src) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));

    foreach ($it as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        foreach (PhpToken::tokenize((string) file_get_contents($file->getPathname())) as $t) {
            if (($t->is(T_CONSTANT_ENCAPSED_STRING) && trim($t->text, '\'"') === 'add_field') || ($t->is(T_STRING) && $t->text === 'addField')) {
                $funde[] = substr($file->getPathname(), strlen(dirname(__DIR__, 3)) + 1) . ':' . $t->line;
            }
        }
    }
}

$say($funde === [], 'no add_field or addField below any wp_tax_*/src', implode(', ', $funde));

$probe = PhpToken::tokenize("<?php \$x = ['op' => 'add_field'];");
$say(array_filter($probe, static fn (PhpToken $t): bool => $t->is(T_CONSTANT_ENCAPSED_STRING) && trim($t->text, '\'"') === 'add_field') !== [], 'the probe: a call written like a module would write it is seen');

echo "\n== every target has its role ==\n";

$r      = new ReflectionClass(\Taxmod\WordPress\Plugin::class);
$plugin = $r->newInstanceWithoutConstructor();
$r->getProperty('file')->setValue($plugin, dirname(__DIR__, 2) . '/wp-taxonomy-modeler.php');

$fw  = (new ReflectionMethod($plugin, 'frameworkNodes'))->invoke($plugin);
$int = (new WpdbNodeRepository())->byImplementations([IntType::class])[IntType::class] ?? null;

if ($int === null) {
    echo "  FAIL no integer type in this model\n";
    exit(1);
}

$apply = static function (array $changes): array {
    $antwort = wp_get_ability('taxmod/apply')->execute(['changes' => $changes]);

    if (is_wp_error($antwort)) {
        echo '  FAIL setup refused: ' . $antwort->get_error_message() . "\n";
        exit(1);
    }

    return $antwort['refs'];
};

$fields = wp_get_ability('taxmod/fields');
$rows   = static function (array $input) use ($fields): array {
    $antwort = $fields->execute($input);

    return is_array($antwort) ? array_column(array_map(static fn (array $z): array => array_combine($antwort['columns'], $z), $antwort['rows']), null, 'id') : [];
};

// *Counted before the setup, so the ceiling is the model's own number.*
$vorher = $fields->execute(['target_role' => CatalogueRole::BaseType->value, 'limit' => 1]);
$aufGrundtyp = is_array($vorher) ? (int) $vorher['total'] : -1;

$refs = $apply([
    ['op' => 'create_node', 'ref' => 'jahr', 'name' => '__fc Baujahr', 'parent' => $int->id],
    ['op' => 'create_node', 'ref' => 'mass', 'name' => '__fc Maß', 'parent' => $fw->rootOf(Branch::Model)->id, 'class' => 'CompositeType'],
    ['op' => 'create_node', 'ref' => 'pos', 'name' => '__fc Position', 'parent' => $fw->rootOf(Branch::Model)->id],
    ['op' => 'add_field', 'ref' => 'teil', 'node' => '@mass', 'target' => '@jahr', 'name' => '__fc Länge'],
    ['op' => 'create_node', 'ref' => 'ding', 'name' => '__fc Ding', 'parent' => $fw->rootOf(Branch::Model)->id],
    ['op' => 'add_field', 'ref' => 'f1', 'node' => '@ding', 'target' => '@mass', 'name' => '__fc Abmessung', 'kind' => 'composition'],
    ['op' => 'add_field', 'ref' => 'f2', 'node' => '@ding', 'target' => '@jahr', 'name' => '__fc Jahr'],
    ['op' => 'add_field', 'ref' => 'f3', 'node' => '@ding', 'target' => $int->id, 'name' => '__fc Zahl'],
    ['op' => 'add_field', 'ref' => 'pteil', 'node' => '@pos', 'target' => '@jahr', 'name' => '__fc Menge'],
    ['op' => 'add_field', 'ref' => 'f4', 'node' => '@ding', 'target' => '@pos', 'name' => '__fc Positionen', 'kind' => 'composition'],
]);

$zeilen = $rows(['node' => (int) $refs['ding']]);

$say(($zeilen[(int) $refs['f1']]['target_role'] ?? '') === 'composite', 'a node of the class «composite data type» is a composite', wp_json_encode($zeilen[(int) $refs['f1']] ?? null));
$say(($zeilen[(int) $refs['f4']]['target_role'] ?? '') === 'outside', 'a node merely held by composition (positions) is not one', wp_json_encode($zeilen[(int) $refs['f4']] ?? null));
$say(($zeilen[(int) $refs['f2']]['target_role'] ?? '') === 'data_element', 'a node below Integer is a data element', wp_json_encode($zeilen[(int) $refs['f2']] ?? null));
$say(($zeilen[(int) $refs['f3']]['target_role'] ?? '') === 'base_type', 'Integer itself is a base type', wp_json_encode($zeilen[(int) $refs['f3']] ?? null));

$nurGrund = $rows(['node' => (int) $refs['ding'], 'target_role' => 'base_type']);
$say(array_keys($nurGrund) === [(int) $refs['f3']], 'the target_role filter keeps only the field on the base type', wp_json_encode(array_keys($nurGrund)));

echo "\n== the node page: no field on a base type, similar elements first (D-1553, D-1555) ==\n";

$aktion     = (string) (new ReflectionClass(\Taxmod\WordPress\Admin\NodesScreen::class))->getConstant('ACTION');
$umgeleitet = '';

add_filter('wp_redirect', static function (string $location) use (&$umgeleitet): string {
    $umgeleitet = $location;

    throw new FieldCatalogStop();
});

/** @return array{message: string, node: int} what the page said, and where it stands afterwards */
$abschicken = static function (int $knoten, string $do, array $post = []) use ($plugin, $aktion, &$umgeleitet): array {
    $post += ['action' => $aktion, 'id' => (string) $knoten, 'do' => $do, '_taxmod_nonce' => wp_create_nonce($aktion . '_' . $knoten)];
    $_POST      = wp_slash($post);
    $_REQUEST   = $_POST;
    $umgeleitet = '';

    ob_start();

    try {
        $plugin->screen()->handlePost();
    } catch (FieldCatalogStop) {
    } catch (\Throwable $fehler) {
        ob_end_clean();

        return ['message' => 'THROWN ' . get_class($fehler) . ': ' . $fehler->getMessage(), 'node' => 0];
    }

    ob_end_clean();
    parse_str((string) parse_url($umgeleitet, PHP_URL_QUERY), $teile);

    return ['message' => rawurldecode((string) ($teile['taxmod_message'] ?? '')), 'node' => (int) ($teile['taxmod_node'] ?? 0)];
};

$ding      = (int) $refs['ding'];
$feldZahl  = static fn (): int => count($rows(['node' => $ding]));
$vorFelder = $feldZahl();

$grund = $abschicken($ding, 'add_field', ['field_target' => (string) $int->id, 'name' => '__fc Grundtyp direkt']);
$say(str_contains($grund['message'], $int->name) && $feldZahl() === $vorFelder, 'a field on Integer itself is refused, naming it, and nothing is written', $grund['message']);

$element = $abschicken($ding, 'add_field', ['field_target' => (string) $refs['jahr'], 'name' => '__fc Jahr zwei']);
$say($feldZahl() === $vorFelder + 1, 'a field on a data element is made', $element['message']);

$kinderVorher = count((new WpdbNodeRepository())->childrenOf($int));
$aehnlich     = $abschicken($int->id, 'add_child', ['name' => '__fc Baujahr']);
$say(str_contains($aehnlich['message'], '__fc Baujahr') && count((new WpdbNodeRepository())->childrenOf($int)) === $kinderVorher, 'a new node below Integer with a similar name shows the similar element and is not made', $aehnlich['message']);

$bewusst = $abschicken($int->id, 'add_child', ['name' => '__fc Baujahr', 'create_anyway' => '1']);
$say(count((new WpdbNodeRepository())->childrenOf($int)) === $kinderVorher + 1, 'ticked «Create anyway», it is made', $bewusst['message']);

// D-1555 in the dialog: the list follows the typed name over taxmod:chosen (D-1551); «Create anyway» only when something is similar.
$imDialog = static function (string $name) use ($plugin, $int): string {
    $_GET['taxmod_chosen'] = wp_slash((string) wp_json_encode(['name' => $name]));
    $html = (string) (new \ReflectionMethod($plugin->screen(), 'similarInDialog'))->invoke($plugin->screen(), $int, 'fc-dialog');
    unset($_GET['taxmod_chosen']);

    return $html;
};
$mitAehnlichen = $imDialog('__fc Baujahr');
$say(str_contains($mitAehnlichen, 'id="fc-dialog-similar"') && str_contains($mitAehnlichen, 'data-taxmod-follows="name"') && str_contains($mitAehnlichen, '__fc Baujahr') && str_contains($mitAehnlichen, 'taxmod_node=') && str_contains($mitAehnlichen, 'name="create_anyway"'), 'the dialog lists the similar ones while typing, each with «Use», then «Create anyway»', $mitAehnlichen);
$ohne = $imDialog('Zzqx Vvkw');
$say(! str_contains($ohne, 'create_anyway') && str_contains($ohne, 'data-taxmod-follows="name"'), 'nothing similar: no list and no «Create anyway»', $ohne);

$frei = $abschicken((int) $refs['ding'], 'add_child', ['name' => '__fc Baujahr']);
$say($frei['node'] > 0, 'outside the catalogue a similar name is no reason to ask', $frei['message']);

echo "\n== the module door shows the catalogue (Logistik page «Feldkatalog», D-1555) ==\n";

$eintraege = array_column((new \Taxmod\WordPress\ModelDoor())->catalogue(), null, 'id');
$say(($eintraege[$int->id]['role'] ?? '') === 'base_type', 'Integer stands in it as base type');
$say(($eintraege[(int) $refs['jahr']]['role'] ?? '') === 'data_element' && ($eintraege[(int) $refs['jahr']]['uses'] ?? 0) >= 2, 'a data element with how many fields point at it', wp_json_encode($eintraege[(int) $refs['jahr']] ?? null));
$say(($eintraege[(int) $refs['mass']]['role'] ?? '') === 'composite', 'a composite under Model stands in it too');
$say(! isset($eintraege[(int) $refs['pos']]) && ! isset($eintraege[$ding]), 'ordinary nodes do not');

echo "\n== a child data element only narrows its parent (D-1559) ==\n";

$knotenRepo = new WpdbNodeRepository();
$aufloesung = static fn (): \Taxmod\Core\Service\SettingsResolver => new \Taxmod\Core\Service\SettingsResolver(
    new \Taxmod\WordPress\Persistence\WpdbSettingsRepository(),
    $knotenRepo,
    \Taxmod\Core\Renderer\ShippedRenderers::registry()
);
$einheitenListe = $knotenRepo->ofClass(\Taxmod\Core\Model\NodeClass\Unit::class);
$wertId         = \Taxmod\WordPress\Persistence\UnitScaffold::unitValueId();

if ($wertId === null || count($einheitenListe) < 2) {
    $say(false, 'the model has the unit value and two units', (string) count($einheitenListe));
} else {
    [$e1, $e2] = [$einheitenListe[0]->id, $einheitenListe[1]->id];
    $kinder    = $apply([
        ['op' => 'create_node', 'ref' => 'groesse', 'name' => '__fc Größe', 'parent' => (string) $wertId, 'class' => 'UnitValue'],
        ['op' => 'set_list', 'node' => '@groesse', 'attribute' => 'erlaubte_einheiten', 'members' => [(string) $e1]],
        ['op' => 'create_node', 'ref' => 'bedeutung', 'name' => '__fc Bedeutung', 'parent' => '@groesse', 'class' => 'UnitValue'],
        ['op' => 'create_node', 'ref' => 'weiter', 'name' => '__fc Weiter', 'parent' => '@groesse', 'class' => 'UnitValue'],
        ['op' => 'set_list', 'node' => '@weiter', 'attribute' => 'erlaubte_einheiten', 'members' => [(string) $e1, (string) $e2]],
    ]);
    $bedeutung = $knotenRepo->byId((int) $kinder['bedeutung']);
    $geerbt    = array_map(static fn ($g): int => (int) $g->reference, $aufloesung()->allowedUnitsOf($bedeutung));
    $say($geerbt === [$e1], 'a child without a list of its own allows the units of its parent', wp_json_encode($geerbt));
    $say($aufloesung()->unitsBeyondParent($knotenRepo->byId((int) $kinder['weiter'])) === [$e2], 'a child that allows more than its parent is seen');

    // *The test children above widen on purpose; the scan runs over every other data element of the class.*
    $test      = [(int) $kinder['groesse'], (int) $kinder['bedeutung'], (int) $kinder['weiter']];
    $erweitert = [];
    $einmal    = $aufloesung();

    foreach ($knotenRepo->ofClass(\Taxmod\Core\Model\NodeClass\UnitValue::class) as $element) {
        if (! in_array($element->id, $test, true) && $einmal->unitsBeyondParent($element) !== []) {
            $erweitert[] = $element->id . ' ' . $element->name;
        }
    }

    $say($erweitert === [], 'no data element allows more units than its parent', implode(', ', $erweitert));
}

echo "\n== relations to a base type never grow ==\n";

// ⚠️ *The ceiling is the number of a fresh install (the seeded scaffold) and only ever lowered. Run against a copy of the live
// model, the number is printed for the measurement and the ceiling of the copy is passed in FIELD_CATALOG_CEILING.*
$decke = getenv('FIELD_CATALOG_CEILING') !== false ? (int) getenv('FIELD_CATALOG_CEILING') : (int) json_decode((string) file_get_contents(__DIR__ . '/lib/field-catalog-ceiling.json'), true)['base_type_relations'];

printf("  info relations to a base type: %d (ceiling %d)\n", $aufGrundtyp, $decke);
$say($aufGrundtyp >= 0 && $aufGrundtyp <= $decke, 'not above the ceiling', $aufGrundtyp . ' > ' . $decke);

echo $failed === 0 ? "\nfield-catalog-check: all good\n" : "\nfield-catalog-check: {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
