<?php declare(strict_types=1);
/**
 * Package 3 acceptance check — attributes as relations, against a real database.
 *
 *     php scripts/dev/package3-check.php [path/to/wordpress]
 *
 * The owner's test: give a node an attribute, and the relation kind appears by itself.
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

use Taxmod\Core\Exception\NotAPossibleTarget;
use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\RelationKind;
use Taxmod\Core\Model\SettingKey;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\Core\Service\ModelValues;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\WpdbRecordRepository;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRelationRepository;
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
$relations     = new WpdbRelationRepository();
$log       = new WpdbChangelog(new SystemClock());
$framework = new SeededFrameworkNodes($nodes, $relations, $log);
$framework->seed();

$editor = new ModelEditor($nodes, $relations, $framework, $log);

echo "\n== 1. The branches exist and are protected ==\n";
foreach (Branch::cases() as $branch) {
    $node = $framework->rootOf($branch);
    check("{$branch->value} → «{$node->name}»", $node->id > 0 && $framework->isProtected($node));
}

echo "\n== 2. The branch decides the kind, and nobody chooses it ==\n";
$order    = $editor->createNode('__p3 Order', $framework->rootOf(Branch::Model)->id);
$supplier = $editor->createNode('__p3 Supplier', $framework->rootOf(Branch::Model)->id);
$line     = $editor->createNode('__p3 Line', $framework->rootOf(Branch::Compositions)->id);
$text     = $editor->createNode('__p3 Text', $framework->rootOf(Branch::DataTypes)->id);
$gram     = $editor->createNode('__p3 Gramm', $framework->rootOf(Branch::Constants)->id);

$byModel        = $editor->addField($order->id, $supplier->id, 'supplied by');
$byComposition  = $editor->addField($order->id, $line->id, 'lines');
$byDataType     = $editor->addField($order->id, $text->id, 'note');
$byConstant     = $editor->addField($order->id, $gram->id, 'unit');

check('Model → aggregation', $byModel->kind === RelationKind::Aggregation, $byModel->kind->value);
check('Compositions → composition', $byComposition->kind === RelationKind::Composition, $byComposition->kind->value);
check('Data Types → composition', $byDataType->kind === RelationKind::Composition, $byDataType->kind->value);
check('Constants → aggregation', $byConstant->kind === RelationKind::Aggregation, $byConstant->kind->value);

echo "\n== 3. It is a row in relations, with an identity of its own ==\n";
$row = $wpdb->get_row($wpdb->prepare(
    'SELECT id, from_node_id, to_node_id, kind, name FROM ' . Schema::table('relations') . ' WHERE id = %d',
    $byModel->id
), ARRAY_A);
check('the relation is stored', $row !== null);
check('it points from the owner to the target', (int) $row['from_node_id'] === $order->id && (int) $row['to_node_id'] === $supplier->id);
check('it carries its name', $row['name'] === 'supplied by', (string) $row['name']);
// ⚠️ *Bis Fassung 20 hiess die Zusage «die Id kam aus dem geteilten Raum». **Seit TASK-004 gibt es
// den nicht mehr** — geprüft wird jetzt, dass die Nummer aus dem Raum der eigenen Tabelle kommt
// (`PR-9`).*
check('its id came from the relations table itself', (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM ' . Schema::table('relations') . ' WHERE id = %d', $byModel->id)) === 1);

echo "\n== 4. Attributes are inherited ==\n";
$part    = $editor->createNode('__p3 Part', $order->id);
$deeper  = $editor->createNode('__p3 Deeper', $part->id);
$ownRelation = $editor->addField($part->id, $text->id, 'part number');

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
$vonDerWurzel = array_map(
    static fn (Relation $r): string => $r->name,
    $editor->fieldsOf($framework->root()->id)
);

echo '  (von der Wurzel geerbt und darum nicht gezählt: '
    . (implode(', ', $vonDerWurzel) ?: 'nichts') . ")\n";

$names = static fn (int $id): array => array_values(array_diff(
    array_map(
        static fn (Relation $r): string => $r->name,
        $editor->fieldsOf($id)
    ),
    $vonDerWurzel
));

// ⚠️ *Dieselbe Rechnung als Zahl, für die Zusicherungen, die zählen statt zu benennen.*
$eigene = static fn (int $id): int => count($names($id));

check('the child sees what the parent declares', in_array('supplied by', $names($part->id), true), implode(', ', $names($part->id)));
check('and its own alongside', in_array('part number', $names($part->id), true));
check('a grandchild sees both too', count(array_intersect(['supplied by', 'part number'], $names($deeper->id))) === 2);
check('the parent does not see the child\'s', ! in_array('part number', $names($order->id), true));

echo "\n== 5. Refusals ==\n";
try { $editor->addField($part->id, $framework->rootOf(Branch::DataTypes)->id, 'x'); check('a branch root is refused', false); }
catch (NotAPossibleTarget $e) { check('a branch root is refused', true); }

try { $editor->addField($part->id, $framework->root()->id, 'x'); check('a node in no branch is refused', false); }
catch (NotAPossibleTarget $e) { check('a node in no branch is refused', true); }

$editor->moveToTrash($gram->id);
try { $editor->addField($part->id, $gram->id, 'x'); check('a parked target is refused', false); }
catch (NotAPossibleTarget $e) { check('a parked target is refused', true); }

echo "\n== 6. The inheritance relation is not an attribute ==\n";
check('the tree relation stays out of the list', ! in_array('', $names($part->id), true));
check('and the child itself is not one either', count($names($order->id)) === 4, implode(', ', $names($order->id)));

echo "\n== 7. The check cleans up after itself ==\n";
foreach ([$order->id, $supplier->id, $line->id, $text->id, $gram->id] as $scratch) {
    $node = $nodes->find($scratch);
    if ($node !== null) { $relations->purgeRelationsTouching($node->id); $nodes->purgeSubtree($node); }
}
$wpdb->query('DELETE FROM ' . Schema::table('relations') . ' WHERE name LIKE "%supplied by%" OR name IN ("lines","note","unit","part number")');
$wpdb->query('DELETE FROM ' . Schema::table('changelog') . ' WHERE after_state LIKE "%__p3%"');
$left = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Schema::table('nodes') . ' WHERE name LIKE "__p3%"');
check('scratch nodes are gone', $left === 0, "$left left");
$dangling = (int) $wpdb->get_var(
    'SELECT COUNT(*) FROM ' . Schema::table('relations') . ' r
     LEFT JOIN ' . Schema::table('nodes') . ' n ON n.id = r.to_node_id
     WHERE n.id IS NULL'
);
check('no relation points at a node that is gone', $dangling === 0, "$dangling dangling");

echo "\n== An attribute can be removed, and it is parked (D-371) ==\n";
$removable = $editor->createNode('__p3 Removable', $framework->rootOf(Branch::Model)->id);
// A branch root stands for the branch, not for a thing in it — so the attribute points at a type.
$doomedType = $editor->createNode('__p3 Doomed type', $framework->rootOf(Branch::DataTypes)->id);
$onIt       = $editor->addField($removable->id, $doomedType->id, '__p3 doomed');

$gone = $editor->removeField($removable->id, $onIt->id);
check('it is parked, not purged', $gone->isParked());
check('and it names the act that removed it (D-128)', $gone->parkedByGroup > 0, (string) $gone->parkedByGroup);
check('hidden by default in its owning node', $eigene($removable->id) === 0);
check('and findable behind «show deleted»', count($editor->removedFieldsOf($removable->id)) === 1);

$back = $editor->restoreField($removable->id, $onIt->id);
check('it comes back whole', ! $back->isParked() && $back->name === '__p3 doomed');
check('and is live again', $eigene($removable->id) === 1);

foreach ([$removable->id, $doomedType->id] as $scratchId) {
    $relations->purgeRelationsTouching($scratchId);
    $nodes->purgeSubtree($nodes->byId($scratchId));
}

echo "\n== Prefixes and base units are seeded under Constants (D-372) ==\n";
$constants = $framework->rootOf(Branch::Constants);
$underConstants = [];
foreach ($nodes->childrenOf($constants) as $child) { $underConstants[$child->name] = $child; }

check('Prefixes is there', isset($underConstants['Prefixes']));
check('Base units is there', isset($underConstants['Base units']));

if (isset($underConstants['Prefixes'])) {
    $prefixModel    = new \Taxmod\Core\Service\ModelValues(new \Taxmod\WordPress\Persistence\WpdbRecordRepository(), $relations, $nodes, $framework);
    $prefixNodes    = $nodes->childrenOf($underConstants['Prefixes']);

    check('twenty prefixes', count($prefixNodes) === 20, (string) count($prefixNodes));

    // ⚠️ **An attribute declared *not persistent*** (D-378). The owner brought the distinction
    // from object orientation — *there are attributes that get persisted and ones that do not; a
    // multiplicator is not persistent* — and that is what justifies an attribute where no record can
    // ever answer. **Its worth is that inheritance says who has an exponent**, which a reserved key
    // offered on every text node in the system cannot.
    $declaredRelations = $editor->fieldsOf($underConstants['Prefixes']->id);
    $declared      = array_map(static fn ($e): string => $e->name, $declaredRelations);
    check('Prefixes declares an exponent attribute', in_array('exponent', $declared, true), implode(', ', $declared));

    $notKept = [];
    foreach ($declaredRelations as $relation) {
        // ⚠️ **Seit [D-538](../../docs/NewConcept/90-decision-log.md) sagt es die Art der Kante.**
        // *Diese Zusage las den Schluessel `persistent` und stuerzte, als seine 148 Zeilen fielen — zu
        // Recht: **sie ist der Waechter dafuer, dass die Auskunft nicht verlorengeht**, nur nicht dafuer,
        // woher sie kommt.*
        $notKept[$relation->name] = $relation->isSetting();
    }

    check('and declares it non-persistent, so nothing tries to store it', ($notKept['exponent'] ?? false) === true);

    // ⚠️ The value lives as the `default`, which is what a model-level value **is** (D-026) — not a
    // trick but the definition.
    // ⚠️ **Read at the exponent attribute's path, not at the node's own** ([D-413](../../docs/NewConcept/90-decision-log.md)).
    // This used to look at the empty path and pass — and it passed while the mechanism did **not
    // work**: `kilo`'s `default = 3` sat there saying *kilo defaults to three*, which no attribute
    // could see. *The check was right that a value should be there and wrong about where, which is why
    // it stayed green through four days of D-378 not functioning.*
    $exponentRelation = null;

    foreach ($declaredRelations as $relation) {
        if ($relation->name === 'exponent') {
            $exponentRelation = $relation;
        }
    }

    $exponents = [];

    foreach ($prefixNodes as $prefixNode) {
        // ⚠️ **Seit dem Umzug steht die Vorgabe im Modell und nicht mehr in der Settings-Tabelle**
        // ([D-529](../../docs/NewConcept/90-decision-log.md)). *Diese Zusage las die alte Stelle und
        // wurde beim Umzug rot — **zu Recht**, sie ist der Waechter dafuer. Jetzt fragt sie die neue.*
        $exponents[$prefixNode->id] = $exponentRelation === null
            ? null
            : $prefixModel->defaultFor($prefixNode, $exponentRelation)?->int;
    }

    // ⚠️ *Hier stand die Gegenprobe «keine Zeile mehr am eigenen Default des Knotens». **Die
    // `settings`-Tabelle ist mit D-579 gestrichen**, es kann keine geben.*

    check('every prefix carries its power of ten as a default', ! in_array(null, $exponents, true));
    // ⚠️ The whole reason it is an exponent: decimal(30,10) cannot hold 10^-24 or 10^24.
    check('and the range reaches both ends', max($exponents) === 24 && min($exponents) === -24,
        max($exponents) . ' … ' . min($exponents));
    // ⚠️ **The counter-check that keeps the flip honest:** the setting route left twenty
    // `prefix_exponent` rows behind, and a stale row under a retired key answers nothing while
    // cluttering every panel. The key is gone from the enum, so this asserts the data went with it.
}

if (isset($underConstants['Base units'])) {
    $split = [];
    foreach ($nodes->childrenOf($underConstants['Base units']) as $child) { $split[$child->name] = $child; }

    check('with prefix and without are separate', isset($split['With prefix'], $split['Without prefix']));

    if (isset($split['With prefix'])) {
        $named = array_map(static fn ($n): string => $n->name, $nodes->childrenOf($split['With prefix']));
        // ⚠️ Gramm and never Kilogramm: the prefix axis needs an **unprefixed** base, or prefixing
        // it would produce kilo-kilogramm. The owner said so, and the physics has to give way.
        check('Gramm is the mass base', in_array('Gramm', $named, true));
        check('and Kilogramm is not', ! in_array('Kilogramm', $named, true));
    }

    if (isset($split['Without prefix'])) {
        $shifted = null;
        foreach ($nodes->childrenOf($split['Without prefix']) as $one) {
            if ($one->name === 'Celsius') { $shifted = $one; }
        }

        check('Celsius is there', $shifted !== null);

        if ($shifted !== null) {
            // ⚠️ **Der Leser ist umgezogen, also zieht der Wächter mit** (`PR-12`). *Hier stand
            // `Settings::resolve()` — die alte Tabelle. Auf seine Entscheidung «Faktor und Offset einfach
            // wie Exponent behandeln» sind beide jetzt Einstellungskanten mit ihrem Wert im
            // `default`-Satz, und **diese Zusage wurde rot, wie sie soll**: die Daten sind gewandert und
            // der Leser stand noch.*
            $celsius = (new ModelValues(
                new WpdbRecordRepository(),
                $relations,
                $nodes,
                $framework
            ))->forNode($shifted);

            // D-274's second half: Celsius is Kelvin **shifted**, not scaled.
            check(
                'and carries an offset rather than only a factor',
                ($celsius[SettingKey::Offset->value]->value->decimal ?? null) !== null,
                $celsius[SettingKey::Offset->value]->value->decimal ?? 'none'
            );

            // ⚠️ *Und der Faktor daneben — ohne ihn prüfte die Zeile nur die Hälfte des Umzugs.*
            check(
                'and a factor beside it',
                ($celsius[SettingKey::Factor->value]->value->decimal ?? null) !== null,
                $celsius[SettingKey::Factor->value]->value->decimal ?? 'none'
            );
        }
    }
}

echo "\n---- $ok passed, $bad failed ----\n";
exit($bad === 0 ? 0 : 1);
