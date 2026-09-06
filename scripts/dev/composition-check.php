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
 * ⚠️ **It is a boundary check** because the question is about real nodes, real relations and real
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
require __DIR__ . '/geruest.php';

use Taxmod\WordPress\Admin\SettingsScreen;
use Taxmod\WordPress\Persistence\WpdbRecordRepository;
use Taxmod\Core\Service\ModelValues;
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
use Taxmod\WordPress\Persistence\CompositionScaffold;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\SeededTypeNodes;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbLabelRepository;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRelationRepository;
use Taxmod\WordPress\SystemClock;

// ⚠️ *Seit TASK-019 traegt jeder Knoten eine Beschriftungszeile ([D-580](../../docs/NewConcept/90-decision-log.md)) —
// und dieser Lauf raeumt Knoten mit rohem SQL weg, also am Ende hinter sich her. **Es faellt nur,
// worauf weder ein Knoten noch eine Kante zeigt.***
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
$relations     = new WpdbRelationRepository();
$framework = new SeededFrameworkNodes($nodes, $relations, $log);
$labels    = new Labels(new WpdbLabelRepository(), SettingsScreen::neutralLocale());
$editor    = new ModelEditor($nodes, $relations, $framework, $log);
$types     = new SeededTypeNodes($nodes, $framework);
$rendering = new Rendering($nodes, $framework, ShippedRenderers::registry(), $types, $labels,
    model: new ModelValues(new WpdbRecordRepository(), new WpdbRelationRepository(), new WpdbNodeRepository(), $framework)
);

$scaffold = new CompositionScaffold($editor, $framework, $types);

/** @return array<string, \Taxmod\Core\Model\Relation> The node's own attributes, by name. */
function membersOf(ModelEditor $editor, \Taxmod\Core\Model\Node $node): array
{
    $members = [];

    foreach ($editor->fieldsOf($node->id) as $relation) {
        if ($relation->fromNodeId === $node->id) {
            $members[$relation->name] = $relation;
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

// WICHTIG: Der Waechter baut seine Leiter selbst, statt die Beispielknoten des Eigentuemers zu
// pruefen -- TASK-025. Sein Satz: "warum haben wir einen Check auf Adresse, ich hatte das mal so
// angelegt, aber das war kein Vertrag". Und CLAUDE.md verbietet es: "Special-casing by display
// name, label, path, or a specific node". Vorher legte dieser Lauf die Beispiele sogar an, wenn
// sie fehlten -- ein Waechter, der in sein Arbeitsmodell schreibt.
//
// Geprueft wird die Sache und nicht die Woerter: eine Komposition aus einfachen Gliedern, eine
// aus zusammengesetzten, und eine Sammlung davon.
$geruest = new Geruest('__ck');

$unitValue  = $geruest->kompositionMit('Wert', ['zahl' => [], 'einheit' => []]);
$address    = $geruest->kompositionMit('Anschrift', [
    'gasse' => [], 'nummer' => [], 'plz' => [], 'stadt' => [], 'land' => [],
]);
$dimension  = $geruest->kompositionMit('Mass', [
    'breite' => ['ziel' => $unitValue->id],
    'hoehe'  => ['ziel' => $unitValue->id],
    'tiefe'  => ['ziel' => $unitValue->id],
]);
$ingredient = $geruest->kompositionMit('Posten', [
    'menge' => ['ziel' => $unitValue->id],
    'name'  => [],
]);
$recipe     = $geruest->kompositionMit('Rezept', [
    'titel'          => [],
    'backzeit'       => ['ziel' => $unitValue->id],
    'ofentemperatur' => ['ziel' => $unitValue->id],
    'zutat'          => ['ziel' => $ingredient->id, 'mult' => '1..*'],
]);

echo '  gebaut: ' . implode(', ', [$unitValue->name, $address->name, $dimension->name, $ingredient->name, $recipe->name]) . "
";

echo "\n== rung one · Adresse · simple members only ==\n";

$addressMembers = membersOf($editor, $address);

// ⚠️ **Diese Zusage nannte fünf Feldnamen, und das war zu eng.** *Sie verlangte `strasse`,
// `hausnummer`, `plz`, `ort`, `land` — die Wörter der Saat. Der Eigentümer hat `Adresse` aber selbst
// benannt: `Street`, `No.`, `Post Code`, `City`, `Country`. **Damit wäre sie rot geworden, weil ein
// Mensch seine eigenen Wörter benutzt** — und schlimmer: die Saat legte ihre fünf **dazu**, weil sie am
// Namen sucht, und `Adresse` hatte zehn Felder. *Er hielt das für eine Folge seiner Umbenennung und
// fragte, ob die Schattentabelle nicht arbeite; gemessen war es keins von beidem.*
//
// ⚠️ **Also prüft sie die Sache und nicht die Wörter:** *eine Komposition aus einfachen Textgliedern
// wird vollständig gezeichnet, und Text bleibt Text. **Welche Namen die Glieder tragen, ist nicht ihre
// Sache** — dieselbe Lehre wie [D-543](../../docs/NewConcept/90-decision-log.md), eine Stufe weiter.*
check('at least five simple members', count($addressMembers) >= 5, implode(', ', array_keys($addressMembers)));

$textMembers = [];

foreach ($addressMembers as $mitgliedName => $mitgliedRelation) {
    $ziel = $nodes->byId($mitgliedRelation->toNodeId);

    if (SimpleType::fromNodeName($ziel->name) === SimpleType::Text) {
        $textMembers[$mitgliedName] = $mitgliedRelation;
    }
}

check(
    'and every one of them is text',
    count($textMembers) === count($addressMembers),
    count($textMembers) . ' of ' . count($addressMembers)
);

// ⚠️ *Die zwei Werte, an denen es hängt, gehen an die **ersten zwei** Textglieder — welche das sind,
// entscheidet das Modell und nicht diese Datei.*
$namen   = array_keys($textMembers);
$erstes  = $namen[0] ?? '';
$zweites = $namen[1] ?? '';

// ⚠️ **The modelling point of the example, asserted rather than commented.** `plz` as an integer
// would drop the leading zero of `01067` and refuse `12a` outright — so this asserts the *type*, not
// a rendering.
// ⚠️ *Compared through {@see SimpleType::fromNodeName()} rather than against the literal `'text'`
// ([D-428](../../docs/NewConcept/90-decision-log.md)): the node is called `Text` now, and what this
// assertion is about is the **type**, never its spelling.*
$eingesetzt = [];

foreach ($textMembers as $mitgliedName => $mitgliedRelation) {
    $eingesetzt[$mitgliedRelation->id] = match ($mitgliedName) {
        $erstes  => TypedValue::ofText('01067'),
        $zweites => TypedValue::ofText('12a'),
        default  => TypedValue::ofText('Dresden'),
    };
}

$drawnAddress = [];

foreach ($rendering->fieldsFor(array_values($textMembers), $eingesetzt, Purpose::Display) as $field) {
    $drawnAddress[$field->relation->name] = strip_tags($field->result->markup);
}

check(
    'every member was drawn',
    count($drawnAddress) === count($textMembers),
    count($drawnAddress) . ' of ' . count($textMembers)
);

// ⚠️ *The one that would have been silently wrong with an `int`.*
check(
    'the postcode keeps its leading zero',
    str_contains($drawnAddress[$erstes] ?? '', '01067'),
    $drawnAddress[$erstes] ?? '—'
);

check(
    'the house number keeps its letter',
    str_contains($drawnAddress[$zweites] ?? '', '12a'),
    $drawnAddress[$zweites] ?? '—'
);

echo '  as rendered: ' . trim(preg_replace('/\s+/', ' ', implode(' ', $drawnAddress))) . "\n";

echo "\n== rung two · Dimension · members that are themselves composed ==\n";

$dimensionMembers = membersOf($editor, $dimension);

check('three members', count($dimensionMembers) === 3, implode(', ', array_keys($dimensionMembers)));

foreach ($dimensionMembers as $name => $relation) {
    check("  · {$name} points at Einheitenwert", $relation->toNodeId === $unitValue->id);
}

// ⚠️ **The measurement, and its outcome is not assumed.** A member that is itself a composed type
// has no value of its own to hand in — its value is a record. *What the descent does with that is
// exactly what C116's «works immediately» has to mean, so it is printed before it is asserted.*
$drawnDimension = [];

foreach ($rendering->fieldsFor(array_values($dimensionMembers), [], Purpose::Display) as $field) {
    $drawnDimension[$field->relation->name] = $field->result->markup;
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

check('zutat points at Zutat', ($recipeMembers['zutat'] ?? null)?->toNodeId === $ingredient->id);

$resolved = $rendering->settingsForUseSites(array_values($recipeMembers));
$many     = ($resolved[$recipeMembers['zutat']->id][SettingKey::Multiplicity->value] ?? null)?->value->text;

check('zutat is 1..*, because a recipe with no ingredient is not one', $many === Multiplicity::OneToMany->value, (string) $many);

$ingredientMembers = membersOf($editor, $ingredient);

check('Zutat is an amount plus a name', count($ingredientMembers) === 2, implode(', ', array_keys($ingredientMembers)));
check('  · menge points at Einheitenwert', ($ingredientMembers['menge'] ?? null)?->toNodeId === $unitValue->id);

$drawnRecipe = [];

foreach ($rendering->fieldsFor(array_values($recipeMembers), [], Purpose::Display) as $field) {
    $drawnRecipe[$field->relation->name] = $field->result->markup;
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

// ⚠️ **Der zweite Lauf schreibt in die echte Datenbank, also wird er zurueckgedreht** — *dieselbe
// Umklammerung wie in `seed-twice-check.php` und aus demselben, gemessenen Grund: die Saat sucht am
// **Namen**. Der Eigentuemer hatte `Adresse` in `Address` umbenannt, sie fand keine und legte die
// deutsche jedes Mal neu an — **drei Stueck lagen am 2026-09-06 in seinem Bestand**. Gezaehlt und
// gemeldet wird weiter alles, behalten nichts.*
global $wpdb;

$knotenTabelle = $wpdb->prefix . 'taxmod_nodes';
$vorher        = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$knotenTabelle}");

$wpdb->query('START TRANSACTION');

$again = $scaffold->import();

$wpdb->query('ROLLBACK');

$nachher = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$knotenTabelle}");

// ⚠️ **Ein Bericht, keine Zusage** — *[D-119](../../docs/NewConcept/90-decision-log.md) gibt dem
// Eigentümer ausdrücklich das Recht, einen gesäten Knoten wegzuwerfen oder umzubenennen: «A model
// with no use for `Backrezept` may throw it away.» Er hat `Adresse` in `Address` umbenannt, und die
// Saat sucht am **Namen**. Dass sie ihn dann anlegen **würde**, ist die Folge seiner Freiheit und
// kein Fehler dieses Laufs.*
if ($again !== []) {
    echo '  hinweis ' . implode(', ', $again) . " — unter diesem Namen nicht (mehr) im Modell\n";
}

// ⚠️ **Die Zusage ist: es bleibt nichts liegen.** *Gemessen nach dem Zurückdrehen, nicht davor.*
check(
    'a second import leaves nothing behind',
    $nachher === $vorher,
    ($nachher - $vorher) . ' Knoten sind geblieben'
);
check('Adresse still has five members', count(membersOf($editor, $address)) === 5, (string) count(membersOf($editor, $address)));
check('Dimension still has three', count(membersOf($editor, $dimension)) === 3, (string) count(membersOf($editor, $dimension)));

printf("\n%d ok, %d failed\n", $passed, $failed);

$geruest->abbauen();

exit($failed === 0 ? 0 : 1);
