<?php declare(strict_types=1);

/**
 * Does `2.7 kΩ` come out of the descent?
 *
 * The owner asked for exactly this: *put value + optional prefix + unit into the tree once, and then
 * a preview render, so that we see whether that works too.* It is a **boundary** check because the
 * labels, the settings and the constants all have to be really stored for the question to mean
 * anything — a core test with a double would only prove that the double agrees with me.
 *
 * ⚠️ **A preview needs no record**, which is what makes this checkable today: the descent takes
 * values keyed by edge id, so example values can be handed in directly. *Storing one is a separate
 * matter and is refused on purpose — see the last section of the output.*
 *
 * Usage: php scripts/dev/unitvalue-check.php C:/Devel/Wordpress
 *
 * @see docs/NewConcept/30-renderer.md
 */

$wordpress = $argv[1] ?? 'C:/Devel/Wordpress';

define('WP_USE_THEMES', false);

require rtrim($wordpress, '/') . '/wp-load.php';
require __DIR__ . '/../../vendor/autoload.php';

use Taxmod\WordPress\Persistence\WpdbRecordRepository;
use Taxmod\Core\Service\ModelValues;
use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\Multiplicity;
use Taxmod\Core\Model\SeededRole;
use Taxmod\Core\Model\SettingKey;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Renderer\Purpose;
use Taxmod\Core\Renderer\ShippedRenderers;
use Taxmod\Core\Service\Labels;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\Core\Service\DataEntry;
use Taxmod\Core\Service\Rendering;
use Taxmod\Core\Service\Settings;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\TableIdentityAllocator;
use Taxmod\WordPress\Persistence\WpdbLabelRepository;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRelationRepository;
use Taxmod\WordPress\Persistence\WpdbSettingRepository;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\SeededTypeNodes;
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
$settings  = new Settings(new WpdbSettingRepository(), $nodes, $framework);
$labels    = new Labels(new WpdbLabelRepository(), $framework);
$editor    = new ModelEditor($nodes, $edges, $ids, $framework, $log);
$data      = new DataEntry(new \Taxmod\WordPress\Persistence\WpdbRecordRepository(), $edges, $nodes, $framework, new SystemClock(), $settings);
$rendering = new Rendering($nodes, $framework, $settings, ShippedRenderers::registry(), new SeededTypeNodes($nodes, $framework), $labels,
    model: new ModelValues(new WpdbRecordRepository(), new WpdbRelationRepository(), new WpdbNodeRepository(), $framework)
);

echo "\n== the type is in the tree ==\n";

$compositions = $framework->rootOf(Branch::Compositions);
$unitValue    = null;

foreach ($editor->childrenOf($compositions->id) as $child) {
    if ($child->name === 'Einheitenwert') {
        $unitValue = $child;
    }
}

check('«Einheitenwert» sits under Compositions', $unitValue !== null);

if ($unitValue === null) {
    echo "\nNothing more can be checked. Run the unit scaffold first.\n";

    exit(1);
}

// ⚠️ **Was die Wurzel erklärt, gehört keinem Knoten weiter unten** — und diese Zeile gibt es, weil
// diese Prüfung am 2026-08-29 rot wurde, ohne dass jemand sie oder ihr Versprechen angefasst hat.
// *Der Eigentümer hat an den Wurzelknoten `renderer` und `validator` gehängt
// ([D-514](../../docs/NewConcept/90-decision-log.md)). `ModelEditor::fieldsOf()` sammelt
// `[...ancestorIds(), id]` — **also erben alle 124 Knoten sie**, und jede Zusicherung der Form
// «dieser Knoten hat genau diese Felder» wurde falsch.*
//
// ⚠️ **Abgezogen, nicht abgeschwächt.** *«Nicht mehr und nicht weniger» bleibt eine echte Zusage:
// eigene Felder plus genau das, was die Wurzel erklärt. Ein Feld, das von irgendwo sonst kommt,
// lässt die Prüfung weiter fallen. **Was hier fehlt, ist nicht Strenge, sondern D-508s Angabe «wo
// liegt der Wert» — Datensatz oder Modell.** Solange die fehlt, liegen Autoren- und Benutzerdaten in
// **einer** Liste, und das ist die Lücke, die der Eigentümer selbst benannt hat: «die Felder, die wir
// hier definieren, definieren Daten des Modells und nicht Daten, die durch den Benutzer eingegeben
// werden».*
$vonDerWurzel = [];

foreach ($editor->fieldsOf($framework->root()->id) as $edge) {
    $vonDerWurzel[$edge->name] = true;
}

$members = [];

foreach ($editor->fieldsOf($unitValue->id) as $edge) {
    if (isset($vonDerWurzel[$edge->name])) {
        continue;
    }

    $members[$edge->name] = $edge;
}

echo '  (von der Wurzel geerbt und darum nicht gezählt: '
    . (implode(', ', array_keys($vonDerWurzel)) ?: 'nichts') . ")\n";

// D-039: a unit value is value + optional prefix + unit. Three members, no more and no fewer.
check('it has exactly the three members of D-039', count($members) === 3, implode(', ', array_keys($members)));
check('  · wert', isset($members['wert']));
check('  · prefix', isset($members['prefix']));
check('  · einheit', isset($members['einheit']));

echo "\n== the prefix is optional, because D-039 says so ==\n";

$resolved = $settings->resolveForUseSites(array_values($members));

$many = ($resolved[$members['prefix']->id][SettingKey::Multiplicity->value] ?? null)?->value->text;

check('prefix is 0..1 and not mandatory', $many === Multiplicity::ZeroToOne->value, (string) $many);

echo "\n== the constants carry their symbols as labels ==\n";

$constants = $framework->rootOf(Branch::Constants);
$prefixes  = null;
$base      = null;

foreach ($editor->childrenOf($constants->id) as $child) {
    if ($child->name === 'Prefixes') {
        $prefixes = $child;
    }

    if ($child->name === 'Base units') {
        $base = $child;
    }
}

check('Prefixes and Base units are there', $prefixes !== null && $base !== null);

$kilo = null;
$ohm  = null;

foreach ($editor->childrenOf($prefixes->id) as $child) {
    if ($child->name === 'kilo') {
        $kilo = $child;
    }
}

foreach ($editor->childrenOf($base->id) as $group) {
    foreach ($editor->childrenOf($group->id) as $child) {
        if ($child->name === 'Ohm') {
            $ohm = $child;
        }
    }
}

check('kilo and Ohm are there', $kilo !== null && $ohm !== null);

if ($kilo === null || $ohm === null) {
    echo "\nStopping: the units are missing.\n";

    exit(1);
}

$symbols = $labels->forNodes([$kilo, $ohm], SeededRole::Symbol);

check('kilo has the symbol k', ($symbols[$kilo->id] ?? '') === 'k', $symbols[$kilo->id] ?? '—');
check('Ohm has the symbol Ω', ($symbols[$ohm->id] ?? '') === 'Ω', $symbols[$ohm->id] ?? '—');

// ⚠️ The counter-check that gives the one above its meaning: the **form** role still answers with
// the node's own name, so the two roles are genuinely different rows and not one row read twice.
$plain = $labels->forNodes([$kilo, $ohm], SeededRole::Form);

check('the form role still says kilo', ($plain[$kilo->id] ?? '') === 'kilo', $plain[$kilo->id] ?? '—');

echo "\n== the preview: 2.7 kΩ ==\n";

$fields = $rendering->fieldsFor(
    [$members['wert'], $members['prefix'], $members['einheit']],
    [
        $members['wert']->id    => TypedValue::ofDecimal('2.7'),
        $members['prefix']->id  => TypedValue::ofReference($kilo->id),
        $members['einheit']->id => TypedValue::ofReference($ohm->id),
    ],
    Purpose::Display
);

check('all three members were drawn', count($fields) === 3, (string) count($fields));

$drawn = [];

foreach ($fields as $field) {
    $drawn[$field->edge->name] = $field->result->markup;
}

check(
    'the value reads 2.7',
    isset($drawn['wert']) && str_contains($drawn['wert'], '2.7'),
    $drawn['wert'] ?? '—'
);

// ⚠️ **This is the whole point of the exercise.** Before the label role became a setting the descent
// resolved every reference with `SeededRole::Form`, so this cell read *kilo* and the preview of
// `2k7` came out as `2.7 kilo Ohm`.
check(
    'the prefix reads k and not kilo',
    isset($drawn['prefix']) && str_contains($drawn['prefix'], '>k<') && ! str_contains($drawn['prefix'], 'kilo'),
    strip_tags($drawn['prefix'] ?? '—')
);

check(
    'the unit reads Ω and not Ohm',
    isset($drawn['einheit']) && str_contains($drawn['einheit'], 'Ω'),
    strip_tags($drawn['einheit'] ?? '—')
);

echo "\n  as rendered: " . trim(preg_replace('/\s+/', ' ', strip_tags(implode(' ', $drawn)))) . "\n";

echo "\n== and the prefix may be left out, which is what 0..1 means ==\n";

$withoutPrefix = $rendering->fieldsFor(
    [$members['wert'], $members['prefix'], $members['einheit']],
    [
        $members['wert']->id    => TypedValue::ofDecimal('12'),
        $members['einheit']->id => TypedValue::ofReference($ohm->id),
    ],
    Purpose::Display
);

$second = [];

foreach ($withoutPrefix as $field) {
    $second[$field->edge->name] = strip_tags($field->result->markup);
}

// ⚠️ *Nothing is nothing* (D-232): an unanswered reference is not an empty string and not a zero.
check(
    'the missing prefix draws as nothing rather than as a dangling reference',
    ! str_contains($second['prefix'] ?? '', 'k'),
    $second['prefix'] ?? '—'
);

echo "\n  as rendered: " . trim(preg_replace('/\s+/', ' ', implode(' ', $second))) . "\n";


echo "\n== a composed value is stored, and read back ==\n";

// ⚠️ **The whole reason the unit value exists**: `2.7 kΩ` has to survive a save.
//
// ⚠️ *Reused, like everything else this check touches. Purging does not exist (D-123 second stage), so
// a check that makes a record on every run fills the screen with its own leftovers — which is exactly
// what the owner opened and found.*
$held    = $data->recordsOf($unitValue->id);
$holder  = $held === [] ? $data->create($unitValue->id) : $held[0];

check('a record can be started against a composed type', $holder->id > 0);

// ⚠️ **A refusal from the core is a finding, not a crash.** This died with an uncaught
// `NotYetStorable` on 2026-08-26 — *«einheit» is not persistent* — because the **owner** had set
// `persistent = 0` on `Base units` while experimenting, and `Base units` is the *target* of that
// attribute, so the chain carried it down to the edge. The core was right and the check was brittle.
//
// ⚠️ *That is worth knowing rather than hiding: a setting put on a **constant** reaches every
// attribute that points at it. Setting `persistent = 0` there means «nothing typed as a base unit is
// stored», which is a coherent thing to say and a surprising thing to have said by accident.*
foreach ([
    ['wert', TypedValue::ofDecimal('2.7')],
    ['prefix', TypedValue::ofReference($kilo->id)],
    ['einheit', TypedValue::ofReference($ohm->id)],
] as [$member, $value]) {
    try {
        $data->put($holder->id, $members[$member]->id, $value);
        check("«{$member}» is written", true);
    } catch (Taxmod\Core\Exception\NotYetStorable $refused) {
        // ⚠️ **A stated refusal passes; a silent one would not.** The core declining with a reason is
        // it working, and the reason is printed so a person can see *which* setting did it. *Asserting
        // «the write succeeded» would turn a deliberate model choice into a red run and teach whoever
        // sees it to loosen the check — which is how a bug gets written down as a rule.*
        check("«{$member}» is written, or refused with a reason", true, 'refused: ' . $refused->getMessage());
    }
}

$held = [];

foreach ($data->valuesOf($holder->id) as $value) {
    $held[$value->edgeId] = $value->value;
}

check('all three members were kept', count($held) === 3, (string) count($held));

// ⚠️ **The padding comes off on read** (D-394). `decimal(30,10)` returns `2.7000000000`, and it was
// reaching the screen: `2.7 kΩ` read back as `2.7000000000 k Ω`. *Found by storing a real value —
// no test with a double would have shown it, because no double pads.*
check(
    'a decimal reads back as it was written and not as ten zeros',
    ($held[$members['wert']->id] ?? null)?->decimal === '2.7',
    ($held[$members['wert']->id] ?? null)?->decimal ?? '—'
);

$readBack = [];

foreach ($rendering->fieldsFor(array_values($members), $held, Purpose::Display) as $field) {
    $readBack[$field->edge->name] = trim(strip_tags($field->result->markup));
}

check('and it draws as 2.7 k Ω', ($readBack['wert'] ?? '') === '2.7'
    && ($readBack['prefix'] ?? '') === 'k'
    && ($readBack['einheit'] ?? '') === 'Ω',
    implode(' ', $readBack));

echo "\n== a holder points at a part with a record of its own ==\n";

// ⚠️ **D-232, and it supersedes D-133**: a target in `Compositions` gets **its own records**. So a
// model attribute pointing at `Einheitenwert` cannot hold the value inline — it holds a reference to
// a record that belongs to it.
// ⚠️ **Idempotent, and the first attempt at that was not enough.** The check reused the scratch **node**
// but still added an attribute, a part and a record on **every run** — the owner opened the screen and
// found `__uv Resistor` carrying three identical `resistance` attributes and nine records. *Reusing one
// thing and creating three others is not idempotence; it is the same bug with a smaller footprint.*
//
// ⚠️ *Purging does not exist yet (D-123's second stage), so a check that cannot clean up after itself
// must not make anything it would have to clean up. **Everything below is found first and made only if
// it is absent.***
$thing = null;

foreach ($editor->childrenOf($framework->rootOf(Branch::Model)->id) as $candidate) {
    if ($candidate->name === '__uv Resistor') {
        $thing = $candidate;
    }
}

$thing ??= $editor->createNode('__uv Resistor', $framework->rootOf(Branch::Model)->id);

$has = null;

foreach ($editor->fieldsOf($thing->id) as $edge) {
    if ($edge->name === 'resistance') {
        $has = $edge;
    }
}

$has ??= $editor->addField($thing->id, $unitValue->id, 'resistance');

$existing = $data->recordsOf($thing->id);
$owner    = $existing === [] ? $data->create($thing->id) : $existing[0];

// ⚠️ *A reference whose record is gone counts as absent.* The first run after I cleared the litter by
// hand found a dangling `value_ref` and handed `null` on to `put()`. **A check that trusts its own
// leftovers is a check that fails once and then hides it by healing itself.**
$parts = $data->partsOf($owner->id);
$part  = $parts === [] ? null : $data->find((int) reset($parts));
$part ??= $data->createPart($owner->id, $has->id);

check('a part is a record of the composed type', $part->nodeId === $unitValue->id);
check('and the holder points at it', in_array($part->id, $data->partsOf($owner->id), true));

// ⚠️ **Asking again makes another part**, which is the point of it having an identity: two positions
// on an order are two positions. *Checked without keeping the second one — the act is what is being
// checked, not the state, and the state would pile up.*
$before = count($data->partsOf($owner->id));
$second = $data->createPart($owner->id, $has->id, $has->id . '.1');

check('asking again makes another one', $second->id !== $part->id);
check('and both are reachable', count($data->partsOf($owner->id)) === $before + 1);

// ⚠️ **Only the second one goes back, and the first is left alone.** My first attempt cleared the
// holder by **edge** — which removes the *first* part's row, path `<edge>` — and then tried to write
// the reference back with `put()`, which refuses a composed target on purpose. *The reference is
// written by `createPart()` and by nothing else, so undoing it means removing the row it wrote.*
$data->clearPath($owner->id, $has->id . '.1');
$GLOBALS['wpdb']->query('DELETE FROM ' . $GLOBALS['wpdb']->prefix . 'taxmod_records WHERE id = ' . (int) $second->id);

$data->put($part->id, $members['wert']->id, TypedValue::ofDecimal('4.7'));
check('a part holds its own values', count($data->valuesOf($part->id)) === 1);
// ⚠️ *One value on the holder: the reference to its part. Nothing of the part's own leaks upward,
// which is what «a record of its own» means.*
check(
    'and the holder keeps only the reference',
    count($data->valuesOf($owner->id)) === 1,
    (string) count($data->valuesOf($owner->id))
);

// ⚠️ **Refused where it is not a composition**, because anywhere else the value belongs *in* the
// record and a part would be a second home for it.
try {
    $data->createPart($holder->id, $members['wert']->id);
    check('a part is refused where the branch is not Compositions', false, 'it was allowed');
} catch (Throwable $e) {
    check('a part is refused where the branch is not Compositions', true);
}

echo "\n== clearing up ==\n";

// ⚠️ *Left where it is on purpose: parking it would put a fifth one in the trash next run. It is
// named `__uv…` so it is recognisable, and it goes when purging exists.*
check('the scratch model is reused rather than piled up', $thing->name === '__uv Resistor');
echo "\n== what is honestly not there yet ==\n";

// ⚠️ **What was missing here this morning is built** (D-394): a composed value gets its own
// record, and `2.7 kΩ` survives a save. *The note that stood here cited D-133 against the code and
// was wrong twice over — D-232 supersedes it, and the own record turned out to be the real gap.*
echo "  Deleting a record does not exist at all, so a part cannot yet die with its holder\n";
echo "  (C12). That is the same missing act as purging a parked node, D-123 second stage,\n";
echo "  and it is one piece of work rather than two.\n";

echo "\n" . ($failed === 0 ? "all {$passed} checks passed\n" : "{$passed} passed, {$failed} FAILED\n");

exit($failed === 0 ? 0 : 1);
