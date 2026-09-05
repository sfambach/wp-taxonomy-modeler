<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Model\Label;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\Tests\Core\Fake\CountingIdentities;
use Taxmod\Tests\Core\Fake\FixedFramework;
use Taxmod\Tests\Core\Fake\InMemoryLabels;
use Taxmod\Tests\Core\Fake\InMemoryNodes;
use Taxmod\Tests\Core\Fake\InMemoryRelations;
use Taxmod\Tests\Core\Fake\RecordedChanges;

/**
 * Copying a node: what travels with it, and to which address.
 *
 * ⚠️ **`duplicate()` had no test at all**, which is [list row 43](../../../docs/NewConcept/97-implementation-plan.md#the-working-list)'s
 * real cause: the `path` argument was simply not passed, so a copy kept the node's own values and
 * dropped everything it said about its **individual attributes** — and nothing noticed for days.
 *
 * ⚠️ **Von fuenf Zusicherungen sind vier mit der `settings`-Tabelle gegangen** (D-579). *Uebrig ist
 * die fuer das **Label** an einer einzelnen Kante — und sie ist die, die auch mit zurueckgebauter
 * Fehlerlage rot wird: sie prueft dieselbe Adressrechnung `remapPath()`, an der der Befund von
 * Zeile 43 haengt.*
 *
 * @see docs/NewConcept/10-domain-core.md
 */
final class DuplicateTest extends TestCase
{
    private const INSTALLATION = 999000;

    private InMemoryNodes $nodes;
    private InMemoryRelations $relations;
    private InMemoryLabels $labelStore;
    private ModelEditor $editor;
    /** @var array<string,Node> */
    private array $branchRoot = [];

    protected function setUp(): void
    {
        $this->relations      = new InMemoryRelations();
        $this->nodes      = new InMemoryNodes($this->relations);
        $this->labelStore = new InMemoryLabels();
        $identities       = new CountingIdentities();

        $make = function (string $name, ?Node $parent) use ($identities): Node {
            // ⚠️ *Eine Zeile statt zweier, seit TASK-018* ([D-581](../../../docs/NewConcept/90-decision-log.md)).
            $node = Node::create(
                $identities->next(),
                $name,
                $parent?->path,
                $parent?->id,
                $parent === null ? 0 : $this->nodes->nextPositionUnder($parent->id)
            );
            $this->nodes->add($node);

            return $node;
        };

        $root  = $make('Root', null);
        $trash = $make('Trash', $root);

        $this->branchRoot['model']        = $make('Model', $root);
        $this->branchRoot['compositions'] = $make('Compositions', $root);

        $primitives = $make('Primitives', $root);

        $this->branchRoot['data-types'] = $make('Data Types', $primitives);
        $this->branchRoot['constants']  = $make('Constants', $primitives);

        $framework = new FixedFramework($root, $trash, $this->branchRoot, self::INSTALLATION);

        $this->editor = new ModelEditor(
            $this->nodes,
            $this->relations,
            $framework,
            new RecordedChanges(),
            $this->labelStore
        );
    }

    private function thing(string $name): Node
    {
        return $this->editor->createNode($name, $this->branchRoot['model']->id);
    }

    private function type(string $name): Node
    {
        return $this->editor->createNode($name, $this->branchRoot['data-types']->id);
    }

    /** The copy's own attribute of that name — the relation the remap has to have produced. */
    private function fieldNamed(Node $node, string $name): Relation
    {
        foreach ($this->relations->fieldRelationsOf([$node->id]) as $relation) {
            if ($relation->name === $name) {
                return $relation;
            }
        }

        self::fail(sprintf('«%s» has no attribute named «%s»', $node->name, $name));
    }

    /*
     * Hier standen vier Zusicherungen darueber, dass die **Einstellungen** einer Kopie mitwandern —
     * je eine fuer die Adresse am Feld, die Adresse des Originals, den leeren Pfad und die geerbte
     * Kante. **Sie pruefen die `settings`-Tabelle, und die ist mit D-579 gestrichen**, samt
     * `ModelEditor::copySettings()`. *Was eine Kopie heute mitnimmt, sind ihre Kanten und ihre
     * Labels; die Zusicherung fuer die Labels steht unveraendert darunter und deckt dieselbe
     * Adressrechnung ab (`remapPath()`), die diese vier mitgeprueft haben.*
     */

    #[Test]
    public function a_label_written_for_one_attribute_travels_the_same_way(): void
    {
        $part  = $this->thing('Part');
        $count = $this->editor->addField($part->id, $this->type('int')->id, 'count');

        // ⚠️ *`labels.path` addresses a place exactly as `settings.path` does, and `copyLabels()` had
        // the identical fault one line over — unmentioned by the row that found the first one.*
        $this->labelStore->put(new Label($part->id, (string) $count->id, 901, '', 'de_DE', 'Stückzahl'));

        $copy     = $this->editor->duplicate($part->id);
        $copyRelation = $this->fieldNamed($copy, 'count');

        $paths = array_map(
            static fn (Label $one): string => $one->path,
            array_values($this->labelStore->forOwners([$copy->id]))
        );

        self::assertContains((string) $copyRelation->id, $paths);
        self::assertNotContains((string) $count->id, $paths);
    }

}
