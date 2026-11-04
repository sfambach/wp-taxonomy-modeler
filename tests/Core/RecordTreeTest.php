<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\RecordInTree;
use Taxmod\Core\Model\RecordType;
use Taxmod\Core\Model\NodeRecord;
use Taxmod\Core\Model\RelationRecord;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Renderer\ChooserRenderer;
use Taxmod\Core\Renderer\ShippedRenderers;
use Taxmod\Core\Service\RecordTree;
use Taxmod\Core\Service\Rendering;
use Taxmod\Tests\Core\Fake\FixedFramework;
use Taxmod\Tests\Core\Fake\InMemoryNodes;
use Taxmod\Tests\Core\Fake\InMemoryRecords;
use Taxmod\Tests\Core\Fake\InMemoryRelations;
use Taxmod\Tests\Core\Fake\RememberedTypeNodes;

/**
 * The records of one node as a tree by their «Übergeordnet» field, in the node chooser's view ([D-1084](../../docs/NewConcept/90-decision-log.md)).
 *
 * ⚠️ *His word: «ja sätze im baum passen». Rules the walk keeps because the data cannot: a missing parent puts a record at the top,
 * a cycle does not hang, an excluded record takes its subtree with it.*
 */
final class RecordTreeTest extends TestCase
{
    /** @return list<array{int, int, bool}> id, depth, hasChildren */
    private static function shape(array $rows): array
    {
        return array_map(static fn (array $r): array => [$r['record']->id, $r['depth'], $r['hasChildren']], $rows);
    }

    /** @return list<RecordInTree> Haus(1) › Keller(2) › Regal(3) › Kiste(4); Garage(5) › Werkbank(6) */
    private static function lager(): array
    {
        return [
            new RecordInTree(4, 3, 'Kiste'),
            new RecordInTree(1, null, 'Haus'),
            new RecordInTree(3, 2, 'Regal'),
            new RecordInTree(2, 1, 'Keller'),
            new RecordInTree(5, null, 'Garage'),
            new RecordInTree(6, 5, 'Werkbank'),
        ];
    }

    #[Test]
    public function records_are_nested_by_their_parent_and_siblings_read_in_order(): void
    {
        self::assertSame(
            [[5, 0, true], [6, 1, false], [1, 0, true], [2, 1, true], [3, 2, true], [4, 3, false]],
            self::shape(RecordTree::walk(self::lager()))
        );
    }

    #[Test]
    public function siblings_sort_naturally(): void
    {
        $rows = RecordTree::walk([new RecordInTree(1, null, 'Fach 10'), new RecordInTree(2, null, 'Fach 2'), new RecordInTree(3, null, 'fach 1')]);

        self::assertSame([3, 2, 1], array_map(static fn (array $r): int => $r['record']->id, $rows));
    }

    #[Test]
    public function a_record_whose_parent_is_missing_stands_at_the_top(): void
    {
        $rows = RecordTree::walk([new RecordInTree(1, 999, 'Waise'), new RecordInTree(2, 1, 'Kind'), new RecordInTree(3, 3, 'Selbst')]);

        self::assertSame([[3, 0, false], [1, 0, true], [2, 1, false]], self::shape($rows), 'Selbst before Waise, by their words');
    }

    #[Test]
    public function a_cycle_does_not_hang_and_every_record_in_it_is_placed_once(): void
    {
        $rows = RecordTree::walk([
            new RecordInTree(7, 9, 'C'),
            new RecordInTree(8, 7, 'A'),
            new RecordInTree(9, 8, 'B'),
            new RecordInTree(1, null, 'Haus'),
        ]);

        self::assertSame([[1, 0, false], [7, 0, true], [8, 1, true], [9, 2, false]], self::shape($rows));
    }

    #[Test]
    public function an_excluded_record_takes_its_subtree_with_it(): void
    {
        $ids = array_map(static fn (array $r): int => $r['record']->id, RecordTree::walk(self::lager(), null, [2, 5]));

        self::assertSame([1], $ids);
    }

    #[Test]
    public function an_excluded_record_hides_what_lies_below_it_even_inside_a_cycle(): void
    {
        $ids = array_map(static fn (array $r): int => $r['record']->id, RecordTree::walk([
            new RecordInTree(1, 3, 'A'),
            new RecordInTree(2, 1, 'B'),
            new RecordInTree(3, 2, 'C'),
        ], null, [2]));

        self::assertSame([], $ids, 'C hangs under the excluded B and A under C — the whole cycle lies below B');
    }

    #[Test]
    public function root_gives_that_record_and_what_lies_below_it(): void
    {
        self::assertSame([[2, 0, true], [3, 1, true], [4, 2, false]], self::shape(RecordTree::walk(self::lager(), 2)));
        self::assertSame([], RecordTree::walk(self::lager(), 99));
        self::assertSame([], RecordTree::walk(self::lager(), 2, [2]));
    }

    #[Test]
    public function the_parent_links_come_from_the_reference_field_and_settings_records_stay_out(): void
    {
        $records = new InMemoryRecords();
        $haus    = $records->add(new NodeRecord(0, 50, 1, '2026-11-02'));
        $keller  = $records->add(new NodeRecord(0, 50, 1, '2026-11-02'));
        $records->add(new NodeRecord(0, 50, 1, '2026-11-02', RecordType::Settings));
        $fremd   = $records->add(new NodeRecord(0, 51, 1, '2026-11-02'));
        $records->putValue(new RelationRecord($keller, 77, '', TypedValue::ofRecordReference($haus)));
        $records->putValue(new RelationRecord($haus, 78, '', TypedValue::ofRecordReference($keller)));

        self::assertSame([$haus => null, $keller => $haus], $records->parentLinksOf(50, 77));
        self::assertArrayNotHasKey($fremd, $records->parentLinksOf(50, 77));
    }

    #[Test]
    public function the_chooser_draws_them_with_the_node_choosers_dialog_rows_and_search_field(): void
    {
        $html = $this->rendering()->recordChooser(
            RecordTree::walk(self::lager(), null, [5]),
            'lager[ort]',
            3,
            [1],
            '',
            formId: 'lager-form',
            settings: ChooserRenderer::asDialog()
        )->markup;

        self::assertStringContainsString('taxmod-dialog', $html);
        self::assertStringContainsString('taxmod-tree-filter', $html);
        self::assertSame(4, substr_count($html, 'class="taxmod-tree-row"'));
        self::assertStringNotContainsString('Garage', $html);
        self::assertMatchesRegularExpression('~<input type="radio" name="lager\[ort\]" value="3" checked form="lager-form"~', $html);
        self::assertStringNotContainsString('value="1"', $html, 'Haus is shown but cannot be picked');
        self::assertStringContainsString('taxmod-chooser-fixed', $html);
        self::assertMatchesRegularExpression('~data-depth="3" style="display:none"~', $html, 'Kiste lies under the chosen, closed Regal');
        self::assertDoesNotMatchRegularExpression('~data-depth="2" style="display:none"~', $html, 'the way to the chosen record stands open');
        // His report 2026-11-04: «nach auswahl wird platzart nicht angezeigt aber gesetzt wird es» — the opener shows the choice, so the script writes a new one into it.
        self::assertStringContainsString('taxmod-dialog-open ' . ChooserRenderer::SHOWS_CHOICE . '"', $html);
        // D-1163, his word 2026-11-04: «bei einfacher auswahl ein ausgegrautes feld … und recht daneben … das baum symbol das öffnet den dialog».
        self::assertStringContainsString('class="taxmod-chooser taxmod-pick"', $html);
        self::assertMatchesRegularExpression('~<label class="button taxmod-icon-button taxmod-pick-tree" for="[^"]+"[^>]*><span class="[^"]*dashicons-networking~', $html);
        self::assertStringNotContainsString('taxmod-record-quick', $html, 'no stray search field before the dialog');
    }

    #[Test]
    public function a_record_word_is_escaped(): void
    {
        $html = $this->rendering()->recordChooser(RecordTree::walk([new RecordInTree(1, null, '<b>Fach</b> & "1"')]), 'ort')->markup;

        self::assertStringNotContainsString('<b>Fach</b>', $html);
        self::assertStringContainsString('&lt;b&gt;Fach&lt;/b&gt; &amp;', $html);
    }

    private function rendering(): Rendering
    {
        $relations = new InMemoryRelations();
        $nodes     = new InMemoryNodes($relations);
        $root      = Node::create(1, 'Root', null, null, 0);
        $trash     = Node::create(2, 'Trash', $root->path, 1, 0);
        $nodes->add($root);
        $nodes->add($trash);

        return new Rendering($nodes, new FixedFramework($root, $trash), ShippedRenderers::registry(), new RememberedTypeNodes());
    }
}
