<?php declare(strict_types=1);
/**
 * Package 7 acceptance check — the fields look like their type, against a real database.
 *
 *     php scripts/dev/package7-check.php [path/to/wordpress]
 *
 * The owner's test: a date is a date field, a switch is a switch, and a value typed into either
 * comes back unchanged after a reload.
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

use Taxmod\Core\Exception\CannotWiden;
use Taxmod\Core\Exception\NotAValueOfThatType;
use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\SettingKey;
use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Renderer\DateTimeRenderer;
use Taxmod\Core\Renderer\FieldRenderer;
use Taxmod\Core\Renderer\PlainRenderer;
use Taxmod\Core\Renderer\Purpose;
use Taxmod\Core\Renderer\ShippedRenderers;
use Taxmod\Core\Renderer\SliderRenderer;
use Taxmod\Core\Renderer\SpinnerRenderer;
use Taxmod\Core\Renderer\SwitchRenderer;
use Taxmod\Core\Service\DataEntry;
use Taxmod\Core\Service\Labels;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\Core\Service\Rendering;
use Taxmod\Core\Service\Settings;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\TableIdentityAllocator;
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
    if ($passed) { $ok++; echo "  OK   $what\n"; }
    else { $bad++; echo "  FAIL $what" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

Schema::install();
update_option(Schema::VERSION_OPTION, Schema::VERSION, true);

$nodes     = new WpdbNodeRepository();
$edges     = new WpdbRelationRepository();
$ids       = new TableIdentityAllocator();
$log       = new WpdbChangelog(new SystemClock());
$framework = new SeededFrameworkNodes($nodes, $edges, $ids, $log);
$framework->seed();

$editor    = new ModelEditor($nodes, $edges, $ids, $framework, $log);
$settings  = new Settings(new WpdbSettingRepository(), $nodes, $framework);
$data      = new DataEntry(new WpdbRecordRepository(), $edges, $nodes, $framework, new SystemClock());
$labels    = new Labels(new WpdbLabelRepository(), $framework);
$rendering = new Rendering($nodes, $framework, $settings, ShippedRenderers::registry(), $labels);

$dataTypes = $framework->rootOf(Branch::DataTypes)->id;

// ⚠️ The seeded simple types are found by name rather than made again — they are content that
// ships once (D-119), and a second `int` beside the real one would be a different node.
$seeded = [];
foreach ($nodes->childrenOf($framework->rootOf(Branch::DataTypes)) as $child) {
    $type = SimpleType::tryFrom($child->name);
    if ($type !== null) { $seeded[$child->name] = $child; }
}

$part = $editor->createNode('__p7 Part', $framework->rootOf(Branch::Model)->id);

echo "\n== 0. The base scaffold shipped the simple types ==\n";
foreach (['int', 'decimal', 'text', 'bool', 'email', 'datetime', 'color'] as $name) {
    // ⚠️ Braces, not bare interpolation: PHP takes the bytes of `»` as part of the variable name.
    check("«{$name}» is in the tree", isset($seeded[$name]));
}

if (count($seeded) < 7) {
    echo "\nThe simple types are not seeded — run the base scaffold first.\n";
    echo "\n---- $ok passed, $bad failed ----\n";
    exit(1);
}

$count  = $editor->addAttribute($part->id, $seeded['int']->id, '__p7 count');
$weight = $editor->addAttribute($part->id, $seeded['decimal']->id, '__p7 weight');
$label  = $editor->addAttribute($part->id, $seeded['text']->id, '__p7 label');
$stock  = $editor->addAttribute($part->id, $seeded['bool']->id, '__p7 in stock');
$mail   = $editor->addAttribute($part->id, $seeded['email']->id, '__p7 contact');
$when   = $editor->addAttribute($part->id, $seeded['datetime']->id, '__p7 checked');
$colour = $editor->addAttribute($part->id, $seeded['color']->id, '__p7 body colour');

$every = [$count, $weight, $label, $stock, $mail, $when, $colour];

echo "\n== 1. Every attribute finds the renderer of its type ==\n";
$fields = [];
foreach ($rendering->fieldsFor($every, [], Purpose::Edit, 'taxmod_value') as $field) {
    $fields[$field->edge->id] = $field;
}

check('seven fields drawn', count($fields) === 7, (string) count($fields));
check('an int gets the plain field', $fields[$count->id]->rendererName === FieldRenderer::NAME, $fields[$count->id]->rendererName);
check('a bool gets the switch', $fields[$stock->id]->rendererName === SwitchRenderer::NAME, $fields[$stock->id]->rendererName);
check('a datetime gets the date renderer', $fields[$when->id]->rendererName === DateTimeRenderer::NAME, $fields[$when->id]->rendererName);
check('and none of them is the fallback', count(array_filter($fields, static fn ($f): bool => $f->hasNoRenderer())) === 0);

echo "\n== 2. The controls are the controls, not text boxes ==\n";
check('the switch is a checkbox', str_contains($fields[$stock->id]->result->markup, 'type="checkbox"'));
check('the switch submits false when unticked', str_contains($fields[$stock->id]->result->markup, 'type="hidden"'));
check('the date is a date control', str_contains($fields[$when->id]->result->markup, 'type="datetime-local"'));
check('the address is an address control', str_contains($fields[$mail->id]->result->markup, 'type="email"'));
check('the colour is a picker', str_contains($fields[$colour->id]->result->markup, 'type="color"'));
check(
    'every field is keyed by its edge, never by position',
    str_contains($fields[$label->id]->result->markup, 'name="taxmod_value[' . $label->id . ']"')
);

// ⚠️ R28: a control offers only real choices. The browser carries the **same** rule the core
// applies, so a field cannot accept what the save will refuse (D-356).
check(
    'an int field does not offer letters it will then refuse',
    str_contains($fields[$count->id]->result->markup, 'pattern="' . SimpleType::Int->pattern() . '"'),
    $fields[$count->id]->result->markup
);
check(
    'and a text field is given no pattern it has no business having',
    ! str_contains($fields[$label->id]->result->markup, 'pattern')
);

echo "\n== 3. A choice at the use site beats the type default ==\n";
$settings->put(
    $settings->chainForUseSite($count),
    SettingKey::Renderer->value,
    TypedValue::ofText(SpinnerRenderer::NAME)
);
// ⚠️ **The bounds are narrowed relative to whatever is already inherited, not set to fixed
// numbers.** A real installation may carry a `range_min` on the seeded `int` — this one did, put
// there by the owner clicking around — and a bound may only ever be tightened (D-312). A check
// that assumed an empty chain was testing a clean database rather than the rule.
$inherited = $settings->resolve($settings->chainForUseSite($count));
$floor     = (int) ($inherited[SettingKey::RangeMin->value]->value->int ?? 0);
$ceiling   = (int) ($inherited[SettingKey::RangeMax->value]->value->int ?? $floor + 100);

$min = $floor + 1;
$max = $ceiling - 1;

$settings->put($settings->chainForUseSite($count), SettingKey::RangeMin->value, TypedValue::ofInt($min));
$settings->put($settings->chainForUseSite($count), SettingKey::RangeMax->value, TypedValue::ofInt($max));

$chosen = $rendering->fieldsFor([$count], [], Purpose::Edit, 'taxmod_value')[0];
check('the spinner was chosen', $chosen->rendererName === SpinnerRenderer::NAME, $chosen->rendererName);
check(
    'and it carries the bounds the chain resolved',
    str_contains($chosen->result->markup, 'min="' . $min . '"')
        && str_contains($chosen->result->markup, 'max="' . $max . '"'),
    $chosen->result->markup
);

try {
    $settings->put($settings->chainForUseSite($count), SettingKey::RangeMin->value, TypedValue::ofInt($floor - 1));
    check('a bound may not be widened at a use site (D-312)', false);
} catch (CannotWiden $e) {
    check('a bound may not be widened at a use site (D-312)', true);
}

echo "\n== 4. A choice at the type reaches every use of it ==\n";
$settings->put(
    $settings->chainFor($nodes->byId($seeded['decimal']->id)),
    SettingKey::Renderer->value,
    TypedValue::ofText(SliderRenderer::NAME)
);
$atType = $rendering->fieldsFor([$weight], [], Purpose::Edit, 'taxmod_value')[0];
check('the slider was chosen at the type', $atType->rendererName === SliderRenderer::NAME, $atType->rendererName);
check('and a decimal slider does not step by one', str_contains($atType->result->markup, 'step="any"'));

echo "\n== 5. Values go in as their type and come back unchanged ==\n";
$record = $data->create($part->id);

$typed = [
    [$count,  '42',                 static fn ($v): bool => $v->int === 42],
    [$weight, '2.50',               static fn ($v): bool => $v->decimal === '2.5000000000'],
    [$label,  '4k7',                static fn ($v): bool => $v->text === '4k7'],
    [$stock,  '1',                  static fn ($v): bool => $v->asBool() === true],
    [$mail,   'a@b.example',        static fn ($v): bool => $v->text === 'a@b.example'],
    [$when,   '2026-08-25T14:32',   static fn ($v): bool => $v->date === '2026-08-25 14:32:00'],
    [$colour, '#663399',            static fn ($v): bool => $v->text === '#663399'],
];

$types = $rendering->typesFor($every);

foreach ($typed as [$edge, $characters, $expected]) {
    $data->put($record->id, $edge->id, $types[$edge->id]->valueFrom($characters));
}

$back = [];
foreach ($data->valuesOf($record->id) as $value) { $back[$value->edgeId] = $value->value; }

foreach ($typed as [$edge, $characters, $expected]) {
    check(
        "«{$edge->name}» survives a round trip",
        isset($back[$edge->id]) && $expected($back[$edge->id]),
        isset($back[$edge->id]) ? $back[$edge->id]->describe() : 'missing'
    );
}

echo "\n== 6. Each value is in the column its type says (D-071) ==\n";
$row = $wpdb->get_row($wpdb->prepare(
    'SELECT value_int, value_decimal, value_text, value_date FROM ' . Schema::table('record_values')
    . ' WHERE record_id = %d AND edge_id = %d',
    $record->id,
    $when->id
), ARRAY_A);
check('a datetime lands in value_date and nowhere else',
    $row['value_date'] !== null && $row['value_int'] === null && $row['value_text'] === null,
    json_encode($row));

$row = $wpdb->get_row($wpdb->prepare(
    'SELECT value_int, value_text FROM ' . Schema::table('record_values')
    . ' WHERE record_id = %d AND edge_id = %d',
    $record->id,
    $stock->id
), ARRAY_A);
check('a bool lands in value_int as 1 (D-315)', (int) $row['value_int'] === 1 && $row['value_text'] === null, json_encode($row));

echo "\n== 7. What was stored is what the control shows again ==\n";
$reloaded = [];
foreach ($rendering->fieldsFor($every, $back, Purpose::Edit, 'taxmod_value') as $field) {
    $reloaded[$field->edge->id] = $field->result->markup;
}
check('the switch comes back ticked', str_contains($reloaded[$stock->id], 'checked'));
check('the date comes back in the control format', str_contains($reloaded[$when->id], 'value="2026-08-25T14:32"'), $reloaded[$when->id]);
check('the address comes back', str_contains($reloaded[$mail->id], 'value="a@b.example"'));
check('the colour comes back', str_contains($reloaded[$colour->id], 'value="#663399"'));

echo "\n== 8. Nothing is coerced ==\n";
try { $types[$count->id]->valueFrom('abc'); check('a word is not stored as the number zero', false); }
catch (NotAValueOfThatType $e) { check('a word is not stored as the number zero', true); }
try { $types[$when->id]->valueFrom('25.08.2026'); check('a date in another notation is refused, not guessed', false); }
catch (NotAValueOfThatType $e) { check('a date in another notation is refused, not guessed', true); }
check('an empty field is unanswered rather than zero', $types[$count->id]->valueFrom('')->isNothing());

echo "\n== 9. A subtype of a type is still that type ==\n";
$description = $editor->createNode('__p7 Description', $seeded['text']->id);
$notes       = $editor->addAttribute($part->id, $description->id, '__p7 notes');
$sub         = $rendering->fieldsFor([$notes], [], Purpose::Edit, 'taxmod_value')[0];
check('an authored subtype inherits its type', $sub->type === SimpleType::Text, $sub->type?->value ?? 'null');
check('and therefore its renderer', $sub->rendererName === FieldRenderer::NAME, $sub->rendererName);

echo "\n== 10. A constant is drawn as its name, not as its id (D-105, D-232) ==\n";
$gram = $editor->createNode('__p7 Gramm', $framework->rootOf(Branch::Constants)->id);
$unit = $editor->addAttribute($part->id, $gram->id, '__p7 unit');

$named = $rendering->fieldsFor(
    [$unit],
    [$unit->id => TypedValue::ofReference($gram->id)],
    Purpose::Display,
    ''
)[0];

check('the branch decides it is a reference', $named->type === SimpleType::NodeRef, $named->type?->value ?? 'null');
check('drawn by the reference renderer', $named->rendererName === 'reference', $named->rendererName);
check('showing the name', str_contains($named->result->markup, '__p7 Gramm'), $named->result->markup);
check('and not the id', ! str_contains($named->result->markup, (string) $gram->id));

// ⚠️ The gap that is left, and it stays visible: changing a reference means picking a node, which
// is the chooser — decided (D-244) and not built. So the edit purpose falls back and says so.
$editing = $rendering->fieldsFor(
    [$unit],
    [$unit->id => TypedValue::ofReference($gram->id)],
    Purpose::Edit,
    'taxmod_value'
)[0];
check('editing a reference still has no renderer, and says so', $editing->hasNoRenderer());
check('marked in the markup rather than merely tidy (R14b)', str_contains($editing->result->markup, 'taxmod-no-renderer'));

echo "\n== 11. Hide and read-only close a field wherever it is drawn ==\n";
$settings->put($settings->chainForUseSite($label), SettingKey::Hide->value, TypedValue::ofBool(true));
$settings->put($settings->chainForUseSite($mail), SettingKey::ReadOnly->value, TypedValue::ofBool(true));

$closed = [];
foreach ($rendering->fieldsFor([$label, $mail], $back, Purpose::Edit, 'taxmod_value') as $field) {
    $closed[$field->edge->id] = $field;
}
check('a hidden attribute draws nothing at all', $closed[$label->id]->isHidden());
check('it stays in the list, so hidden is not mistaken for missing', count($closed) === 2);
check('a read-only address is shown, not offered', ! str_contains($closed[$mail->id]->result->markup, '<input'));
check('and it is still a link', str_contains($closed[$mail->id]->result->markup, 'mailto:'));

echo "\n== 12. Not searchable is a missing capability, not a special case ==\n";
check('no attribute is offered for search yet (D-217)', $rendering->fieldsFor($every, [], Purpose::Search, 'q') === []);
check('a value is never dropped the same way', count($rendering->fieldsFor($every, [], Purpose::Display, '')) === 7);

echo "\n== 13. The whole form costs a fixed number of queries (CD-7) ==\n";
$before = $wpdb->num_queries;
$rendering->fieldsFor($every, $back, Purpose::Edit, 'taxmod_value');
$spent = $wpdb->num_queries - $before;
check('seven fields do not cost seven walks', $spent <= 4, "$spent queries for 7 fields");

echo "\n== 14. The renderer is chosen, never typed (D-358) ==\n";
$offeredForInt = array_map(
    static fn ($r): string => $r->name(),
    $rendering->choicesForNode($nodes->byId($seeded['int']->id))
);
sort($offeredForInt);
check('an int is offered exactly its three ways', $offeredForInt === ['field', 'slider', 'spinner'], implode(', ', $offeredForInt));
// ⚠️ This read *nothing rather than everything* until the form renderer existed. A thing wants a
// **structural** renderer — chosen for what it is — and now there is one. Offering a spinner for a
// supplier is still the mistake it always was.
check(
    'a thing under Model is offered the structural renderers only',
    array_map(static fn ($r): string => $r->name(), $rendering->choicesForNode($nodes->byId($part->id))) === ['form'],
    implode(', ', array_map(static fn ($r): string => $r->name(), $rendering->choicesForNode($nodes->byId($part->id))))
);
check(
    'a bool is not offered a spinner',
    ! in_array('spinner', array_map(
        static fn ($r): string => $r->name(),
        $rendering->choicesForNode($nodes->byId($seeded['bool']->id))
    ), true)
);

// ⚠️ Offered and allowed are two questions (D-360). The list is what makes sense; a deliberate
// exception is somebody's special case, and only a name nothing answers to is an error.
check('what is not offered is still not forbidden', $rendering->knowsRenderer('switch'));
check('a name nothing answers to is refused', ! $rendering->knowsRenderer('__p7 no such renderer'));
check('and the fallback is not choosable at all', ! $rendering->knowsRenderer('plain'));

echo "\n== 15. The settings side is drawn, not printed (R20a) ==\n";
$intNode = $nodes->byId($seeded['int']->id);
$settings->put($settings->chainFor($intNode), SettingKey::Mandatory->value, TypedValue::ofBool(true));

$rows = [];
foreach ($rendering->settingsFor($intNode, $settings->resolve($settings->chainFor($intNode))) as $row) {
    $rows[$row->key] = $row;
}

check('a boolean setting is drawn as a switch',
    isset($rows['mandatory']) && $rows['mandatory']->wasDrawn()
        && str_contains($rows['mandatory']->result->markup, 'type="checkbox"'),
    isset($rows['mandatory']) ? ($rows['mandatory']->result->markup ?? 'undrawn') : 'missing');
check('a borrowing key takes the type of the node it sits on',
    isset($rows['range_step']) ? $rows['range_step']->type === SimpleType::Int : true);
check('a choice is left undrawn rather than faked as a field',
    ! isset($rows['renderer']) || (! $rows['renderer']->wasDrawn() && $rows['renderer']->shape->isAChoice()));

// ⚠️ The last guesser: a setting now reads back as the type its key declares, not by regex.
check('mandatory reads back as a boolean, not as the number one',
    $settings->resolve($settings->chainFor($intNode))['mandatory']->value->asBool() === true);

$settings->reset($intNode->id, SettingKey::Mandatory->value);

echo "\n== 16. A node is drawn by a container, not by a screen (D-098, R46, R75) ==\n";
$formed = $rendering->nodeAsForm(
    $nodes->byId($part->id),
    $every,
    $back,
    Purpose::Edit,
    'taxmod_value'
);

check('the form drew something', str_contains($formed->markup, 'taxmod-form'));
check('every member is in it', count(array_filter(
    $every,
    static fn ($e): bool => str_contains($formed->markup, $e->name)
)) === count($every) - 1, 'one is hidden by a setting from section 11');
check('and it says which edges went into it (D-021)', $formed->usedEdges !== []);

// R75: read-only values first. `__p7 contact` was made read-only in section 11.
$readOnlyAt = strpos($formed->markup, '__p7 contact');
$ordinaryAt = strpos($formed->markup, '__p7 count');
check('read-only comes before the ordinary fields', $readOnlyAt !== false && $readOnlyAt < $ordinaryAt, "$readOnlyAt vs $ordinaryAt");

// R75: booleans collected after the ordinary fields.
$boolAt = strpos($formed->markup, '__p7 in stock');
check('booleans are collected after them', $boolAt !== false && $boolAt > $ordinaryAt, "$boolAt vs $ordinaryAt");

echo "\n== 17. The screen renders at all ==\n";

// ⚠️ **The check that was missing.** A fatal error reached the screen today — a control group handed
// in as a string where a list was expected — and nothing guarded it: the boundary runs exercise
// services, and `render()` had no check of any kind. `PR-9` asks every package to add to the net.
// `Plugin` is constructed by `boot()` alone, so the screen is wired here the way it wires it —
// which is itself worth having: if the two ever drift, this check says so.
try {
    $screen = new Taxmod\WordPress\Admin\NodesScreen(
        $editor,
        new Taxmod\Core\Service\Tree($nodes, $edges),
        $settings,
        $labels,
        $data,
        $framework,
        $rendering
    );

    $markup = $screen->render();

    check('render() returns markup rather than dying', str_contains($markup, '<div class="wrap">'));
    check('the tree is drawn by the cell', str_contains($markup, 'taxmod-tree-node'));
    check('a row carries its controls', str_contains($markup, 'value="add_child_here"'));
    check('and its write count', str_contains($markup, 'taxmod-tree-writes'));
} catch (Throwable $e) {
    check('render() returns markup rather than dying', false, $e->getMessage());
}

echo "\n== 18. Clearing up ==\n";
foreach ($data->recordsOf($part->id) as $r) {
    $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('record_values') . ' WHERE record_id = %d', $r->id));
    $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('records') . ' WHERE id = %d', $r->id));
}

// ⚠️ The settings written onto the seeded types must go too, or the next run inherits a slider
// on every decimal in the installation.
foreach ([$seeded['decimal']->id] as $owner) {
    $settings->reset($owner, SettingKey::Renderer->value);
}

// ⚠️ **By name, not by the ids of this run.** A run that dies before this point — one did, on a
// `range_min` the owner had set by hand — leaves its scratch nodes behind, and the next run then
// reports them as its own failure. Cleaning up by name makes the check self-healing.
$scratchIds = $wpdb->get_col(
    'SELECT id FROM ' . Schema::table('nodes') . ' WHERE name LIKE "__p7%" ORDER BY LENGTH(path) DESC'
);

foreach ($scratchIds as $scratch) {
    $node = $nodes->find((int) $scratch);
    if ($node !== null) { $edges->purgeEdgesTouching($node->id); $nodes->purgeSubtree($node); }
}

$wpdb->query('DELETE FROM ' . Schema::table('relations') . ' WHERE name LIKE "__p7%"');
$wpdb->query('DELETE FROM ' . Schema::table('changelog') . ' WHERE after_state LIKE "%__p7%"');

$left = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Schema::table('nodes') . ' WHERE name LIKE "__p7%"');
check('scratch nodes are gone', $left === 0, "$left left");

$stray = (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM ' . Schema::table('settings') . ' WHERE owner_id = %d',
    $seeded['decimal']->id
));
check('no renderer choice is left on a seeded type', $stray === 0, "$stray left");

echo "\n---- $ok passed, $bad failed ----\n";
exit($bad === 0 ? 0 : 1);
