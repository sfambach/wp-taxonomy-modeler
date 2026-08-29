<?php declare(strict_types=1);

/**
 * Does a **new** composed type work immediately, as C116 claims?
 *
 * The owner asked for the types the concept names — *could you put the composed types we described
 * yesterday into the tree, address and such.* [C116](../../docs/NewConcept/10-domain-core.md#c116--the-composed-type-is-the-unit-of-rendering)
 * makes a claim about them in as many words: *a new composed type works immediately: dimensions,
 * addresses, baking recipes.* **This check is that sentence, executed.**
 *
 * ⚠️ **Three rungs, because one example can only fail in one way**: simple members, composed members,
 * a collection of composed members. *A renderer that handles only the first would pass a one-example
 * check and fail the concept.*
 *
 * ⚠️ **It is a boundary check** because the question is about real nodes, real edges and real
 * settings resolving together — a core test with doubles would only prove the doubles agree with me.
 *
 * ⚠️ **It delivers the scaffold itself rather than assuming activation ran**, and `import()` is
 * find-or-create, so running this twice is also the idempotence test.
 *
 * Usage: php scripts/dev/composition-check.php C:/Devel/Wordpress
 *
 * @see docs/NewConcept/10-domain-core.md
 */

$wordpress = $argv[1] ?? 'C:/Devel/Wordpress';

define('WP_USE_THEMES', false);

require rtrim($wordpress, '/') . '/wp-load.php';
require __DIR__ . '/../../vendor/autoload.php';

use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\Multiplicity;
use Taxmod\Core\Model\SettingKey;
use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Renderer\Purpose;
use Taxmod\Core\Renderer\ShippedRenderers;
use Taxmod\Core\Service\Labels;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\Core\Service\Rendering;
use Taxmod\Core\Service\Settings;
use Taxmod\WordPress\Persistence\CompositionScaffold;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\SeededTypeNodes;
use Taxmod\WordPress\Persistence\TableIdentityAllocator;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbLabelRepository;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRelationRepository;
use Taxmod\WordPress\Persistence\WpdbSettingRepository;
use Taxmod\WordPress\SystemClock;

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

$ids       = new TableIdentityAllocator();
$log       = new WpdbChangelog(new SystemClock());
$nodes     = new WpdbNodeRepository();
$edges     = new WpdbRelationRepository();
$framework = new SeededFrameworkNodes($nodes, $edges, $ids, $log);
$settings  = new Settings(new WpdbSettingRepository(), $nodes, $framework, $log);
$labels    = new Labels(new WpdbLabelRepository(), $framework);
$editor    = new ModelEditor($nodes, $edges, $ids, $framework, $log);
$types     = new SeededTypeNodes($nodes, $framework);
$rendering = new Rendering($nodes, $framework, $settings, ShippedRenderers::registry(), $types, $labels);

$scaffold = new CompositionScaffold($editor, $framework, $settings, $types);

/** @return array<string, \Taxmod\Core\Model\Relation> The node's own attributes, by name. */
function membersOf(ModelEditor $editor, \Taxmod\Core\Model\Node $node): array
{
    $members = [];

    foreach ($editor->fieldsOf($node->id) as $edge) {
        if ($edge->fromId === $node->id) {
            $members[$edge->name] = $edge;
        }
    }

    return $members;
}

function childNamed(ModelEditor $editor, \Taxmod\Core\Model\Node $parent, string $name): ?\Taxmod\Core\Model\Node
{
    foreach ($editor->childrenOf($parent->id) as $child) {
        if ($child->name === $name) {
            return $child;
        }
    }

    return null;
}

echo "\n== the delivery ==\n";

$created = $scaffold->import();

echo '  angelegt: ' . ($created === [] ? '(alles war schon da)' : implode(', ', $created)) . "\n";

$compositions = $framework->rootOf(Branch::Compositions);

foreach (['Adresse', 'Dimension', 'Zutat', 'Backrezept'] as $name) {
    check("«{$name}» sits under Compositions", childNamed($editor, $compositions, $name) !== null);
}

$address   = childNamed($editor, $compositions, 'Adresse');
$dimension = childNamed($editor, $compositions, 'Dimension');
$ingredient = childNamed($editor, $compositions, 'Zutat');
$recipe    = childNamed($editor, $compositions, 'Backrezept');
$unitValue = childNamed($editor, $compositions, 'Einheitenwert');

if ($address === null || $dimension === null || $ingredient === null || $recipe === null || $unitValue === null) {
    echo "\nNothing more can be checked.\n";

    exit(1);
}

echo "\n== rung one · Adresse · simple members only ==\n";

$addressMembers = membersOf($editor, $address);

check('five members', count($addressMembers) === 5, implode(', ', array_keys($addressMembers)));

foreach (['strasse', 'hausnummer', 'plz', 'ort', 'land'] as $member) {
    check("  · {$member}", isset($addressMembers[$member]));
}

// ⚠️ **The modelling point of the example, asserted rather than commented.** `plz` as an integer
// would drop the leading zero of `01067` and refuse `12a` outright — so this asserts the *type*, not
// a rendering.
// ⚠️ *Compared through {@see SimpleType::fromNodeName()} rather than against the literal `'text'`
// ([D-428](../../docs/NewConcept/90-decision-log.md)): the node is called `Text` now, and what this
// assertion is about is the **type**, never its spelling.*
foreach (['plz', 'hausnummer'] as $identifier) {
    $target = isset($addressMembers[$identifier]) ? $nodes->byId($addressMembers[$identifier]->toId) : null;

    check(
        "{$identifier} is text, not a number",
        $target !== null && SimpleType::fromNodeName($target->name) === SimpleType::Text,
        $target?->name ?? '—'
    );
}

$drawnAddress = [];

foreach ($rendering->fieldsFor(
    array_values($addressMembers),
    [
        $addressMembers['strasse']->id    => TypedValue::ofText('Bahnhofstraße'),
        $addressMembers['hausnummer']->id => TypedValue::ofText('12a'),
        $addressMembers['plz']->id        => TypedValue::ofText('01067'),
        $addressMembers['ort']->id        => TypedValue::ofText('Dresden'),
        $addressMembers['land']->id       => TypedValue::ofText('Deutschland'),
    ],
    Purpose::Display
) as $field) {
    $drawnAddress[$field->edge->name] = strip_tags($field->result->markup);
}

check('all five members were drawn', count($drawnAddress) === 5, (string) count($drawnAddress));

// ⚠️ *The one that would have been silently wrong with an `int`.*
check(
    'the postcode keeps its leading zero',
    str_contains($drawnAddress['plz'] ?? '', '01067'),
    $drawnAddress['plz'] ?? '—'
);

check(
    'the house number keeps its letter',
    str_contains($drawnAddress['hausnummer'] ?? '', '12a'),
    $drawnAddress['hausnummer'] ?? '—'
);

echo '  as rendered: ' . trim(preg_replace('/\s+/', ' ', implode(' ', $drawnAddress))) . "\n";

echo "\n== rung two · Dimension · members that are themselves composed ==\n";

$dimensionMembers = membersOf($editor, $dimension);

check('three members', count($dimensionMembers) === 3, implode(', ', array_keys($dimensionMembers)));

foreach ($dimensionMembers as $name => $edge) {
    check("  · {$name} points at Einheitenwert", $edge->toId === $unitValue->id);
}

// ⚠️ **The measurement, and its outcome is not assumed.** A member that is itself a composed type
// has no value of its own to hand in — its value is a record. *What the descent does with that is
// exactly what C116's «works immediately» has to mean, so it is printed before it is asserted.*
$drawnDimension = [];

foreach ($rendering->fieldsFor(array_values($dimensionMembers), [], Purpose::Display) as $field) {
    $drawnDimension[$field->edge->name] = $field->result->markup;
}

check('all three members were drawn', count($drawnDimension) === 3, (string) count($drawnDimension));

// ⚠️ **The gap is asserted, not merely printed — and this assertion is meant to go red one day.**
// A composed member reaches {@see PlainRenderer}, the fallback that means *nothing draws this yet*
// ([R14b](../../docs/NewConcept/30-renderer.md)), because the **generic composite renderer of C116
// does not exist**. *When it is built this check must be rewritten to assert what it draws — not
// deleted. A check that only printed this would have stayed green through the whole build and said
// nothing about whether it worked.*
foreach ($drawnDimension as $name => $markup) {
    check(
        "  · {$name} falls back, because no renderer draws a composed value yet",
        str_contains($markup, 'taxmod-no-renderer')
    );
}

// ⚠️ **And this is the sharper half.** The marker is styled red, so a *value* it could not draw
// reads as a fault — but a composed member has no value of its own to carry, so the span comes out
// **empty**, and the colour of nothing is nothing. *That is the difference between a gap that is
// marked and a gap that is visible, and only the first of the two is built.*
foreach ($drawnDimension as $name => $markup) {
    check("  · {$name} has nothing in it to colour", strip_tags($markup) === '', strip_tags($markup));
}

foreach ($drawnDimension as $name => $markup) {
    echo "    {$name}: " . trim(preg_replace('/\s+/', ' ', $markup)) . "\n";
}

echo "\n== rung three · Backrezept · a collection of composed values ==\n";

$recipeMembers = membersOf($editor, $recipe);

check('four members', count($recipeMembers) === 4, implode(', ', array_keys($recipeMembers)));

foreach (['titel', 'backzeit', 'ofentemperatur', 'zutat'] as $member) {
    check("  · {$member}", isset($recipeMembers[$member]));
}

check('zutat points at Zutat', ($recipeMembers['zutat'] ?? null)?->toId === $ingredient->id);

$resolved = $settings->resolveForUseSites(array_values($recipeMembers));
$many     = ($resolved[$recipeMembers['zutat']->id][SettingKey::Multiplicity->value] ?? null)?->value->text;

check('zutat is 1..*, because a recipe with no ingredient is not one', $many === Multiplicity::OneToMany->value, (string) $many);

$ingredientMembers = membersOf($editor, $ingredient);

check('Zutat is an amount plus a name', count($ingredientMembers) === 2, implode(', ', array_keys($ingredientMembers)));
check('  · menge points at Einheitenwert', ($ingredientMembers['menge'] ?? null)?->toId === $unitValue->id);

$drawnRecipe = [];

foreach ($rendering->fieldsFor(array_values($recipeMembers), [], Purpose::Display) as $field) {
    $drawnRecipe[$field->edge->name] = $field->result->markup;
}

check('all four members were drawn', count($drawnRecipe) === 4, (string) count($drawnRecipe));

// ⚠️ **The counter-check that gives the two above their meaning**: `titel` is a simple member with
// no value, and it does **not** carry the marker. *So the marker is genuinely about «nothing draws
// this» and not about «nothing was handed in» — without this line the two assertions above would
// pass for the wrong reason.*
check(
    'titel is unvalued and still not marked as undrawable',
    isset($drawnRecipe['titel']) && ! str_contains($drawnRecipe['titel'], 'taxmod-no-renderer'),
    $drawnRecipe['titel'] ?? '—'
);

// ⚠️ *A collection of composed values is the same gap, one multiplicity wider: the multiplicity is
// resolved and correct, and there is still nothing that draws the members.*
check(
    'zutat falls back too, and being 1..* changes nothing about it',
    str_contains($drawnRecipe['zutat'] ?? '', 'taxmod-no-renderer')
);

foreach ($drawnRecipe as $name => $markup) {
    echo "    {$name}: " . trim(preg_replace('/\s+/', ' ', $markup)) . "\n";
}

echo "\n== the delivery is idempotent ==\n";

$again = $scaffold->import();

check('a second import creates nothing', $again === [], implode(', ', $again));
check('Adresse still has five members', count(membersOf($editor, $address)) === 5, (string) count(membersOf($editor, $address)));
check('Dimension still has three', count(membersOf($editor, $dimension)) === 3, (string) count(membersOf($editor, $dimension)));

printf("\n%d ok, %d failed\n", $passed, $failed);

exit($failed === 0 ? 0 : 1);
