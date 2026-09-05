<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Model\IdentitySpace;
use Taxmod\Core\Model\Label;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\SeededRole;
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

    /**
     * ⚠️ **Diese Zusicherung hiess einmal «eine Beschriftung für **ein Feld** wandert genauso mit».**
     * *Sie prüfte, dass `copyLabels()` den `path` einer Beschriftung auf die Kantennummer der Kopie
     * umschreibt. **`labels.path` gibt es seit TASK-019 nicht mehr**
     * ([D-580](../../../docs/NewConcept/90-decision-log.md)): der Verweis zeigt vom Knoten auf die
     * Beschriftung, und eine Beschriftung hat damit genau einen Eigentümer und keine Stelle darin.*
     *
     * ⚠️ *Was bleibt, ist die Frage, um die es dem Eigentümer ging — «eine Kopie, die anders heisst
     * als ihr Original, ist keine Kopie»: **jede Rolle und jede Sprache wandert mit.***
     */
    #[Test]
    public function every_role_and_every_locale_travels_to_the_copy(): void
    {
        $part = $this->thing('Part');

        $this->labelStore->put(new Label($part->id, IdentitySpace::Node, SeededRole::Form, 'one', 'de_DE', 'Stückzahl'));
        $this->labelStore->put(new Label($part->id, IdentitySpace::Node, SeededRole::Symbol, 'one', 'en_US', 'St'));

        $copy = $this->editor->duplicate($part->id);

        $gefunden = [];

        foreach ($this->labelStore->forOwners([$copy->id], IdentitySpace::Node) as $one) {
            $gefunden[$one->role->value . '·' . $one->locale] = $one->text;
        }

        self::assertSame('Stückzahl', $gefunden['form·de_DE'] ?? null);
        self::assertSame('St', $gefunden['symbol·en_US'] ?? null);
    }

}
