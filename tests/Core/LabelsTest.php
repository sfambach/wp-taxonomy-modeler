<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Model\Label;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\SeededRole;
use Taxmod\Core\Service\Labels;
use Taxmod\Tests\Core\Fake\FixedFramework;
use Taxmod\Tests\Core\Fake\InMemoryLabels;

/**
 * The fallback chain: `<role>·<number>` → `<role>·one` → `help·one` → `node.name`.
 *
 * @see docs/NewConcept/40-i18n.md
 */
final class LabelsTest extends TestCase
{
    /** @var array<string,int> */
    private const ROLE_IDS = [
        'form'   => 8001,
        'table'  => 8002,
        'select' => 8003,
        'symbol' => 8004,
        'help'   => 8005,
    ];

    private InMemoryLabels $stored;
    private Labels $labels;
    private Node $node;

    protected function setUp(): void
    {
        $root  = Node::create(1, 'Root', null);
        $trash = Node::create(2, 'Trash', $root->path);

        $this->node   = Node::create(50, 'Widerstandswert', $root->path);
        $this->stored = new InMemoryLabels();
        $this->labels = new Labels(
            $this->stored,
            new FixedFramework($root, $trash, [], 999000, self::ROLE_IDS)
        );
    }

    private function write(string $role, string $text, string $locale = '', string $number = Label::BASE_NUMBER): void
    {
        $this->stored->put(new Label($this->node->id, '', self::ROLE_IDS[$role], $number, $locale, $text));
    }

    #[Test]
    public function the_role_that_was_asked_for_wins(): void
    {
        $this->write('form', 'Resistance value');
        $this->write('table', 'R');

        self::assertSame('Resistance value', $this->labels->of($this->node, SeededRole::Form));
        self::assertSame('R', $this->labels->of($this->node, SeededRole::Table));
    }

    #[Test]
    public function a_missing_role_falls_through_to_help(): void
    {
        // D-209: `long` is gone and the chain ends on `help`.
        $this->write('help', 'The value of the resistance in ohms');

        self::assertSame(
            'The value of the resistance in ohms',
            $this->labels->of($this->node, SeededRole::Table)
        );
    }

    #[Test]
    public function with_nothing_stored_the_nodes_own_name_is_used(): void
    {
        // ⚠️ The chain never ends on nothing. An empty cell where a name should be is worse
        // than the internal name — and the internal name always exists (D-022).
        self::assertSame('Widerstandswert', $this->labels->of($this->node, SeededRole::Form));
    }

    #[Test]
    public function the_plural_form_is_tried_before_the_role_gives_way(): void
    {
        // ⚠️ D-153: number before role. *Resistances* falling back to *Resistance* is a near
        // miss; falling back to the help text is a different word entirely.
        $this->write('form', 'Resistance');
        $this->write('help', 'The value of the resistance');

        self::assertSame('Resistance', $this->labels->of($this->node, SeededRole::Form, '', 'other'));
    }

    #[Test]
    public function a_stored_plural_form_is_used_when_there_is_one(): void
    {
        $this->write('form', 'Resistance');
        $this->write('form', 'Resistances', '', 'other');

        self::assertSame('Resistances', $this->labels->of($this->node, SeededRole::Form, '', 'other'));
        self::assertSame('Resistance', $this->labels->of($this->node, SeededRole::Form));
    }

    #[Test]
    public function the_same_thing_is_called_something_else_in_another_language(): void
    {
        // The owner's own check for this package.
        $this->write('form', 'Widerstandswert', 'de_DE');
        $this->write('form', 'Resistance value', 'en_US');

        self::assertSame('Widerstandswert', $this->labels->of($this->node, SeededRole::Form, 'de_DE'));
        self::assertSame('Resistance value', $this->labels->of($this->node, SeededRole::Form, 'en_US'));
    }

    #[Test]
    public function a_locale_with_nothing_stored_falls_back_to_the_neutral_row(): void
    {
        // ⚠️ Before the role gives way: a label written without a locale is one somebody wrote
        // for everybody, and using it beats answering a different question.
        $this->write('form', 'Resistance value');
        $this->write('help', 'a description nobody asked for');

        self::assertSame('Resistance value', $this->labels->of($this->node, SeededRole::Form, 'fr_FR'));
    }

    #[Test]
    public function an_empty_text_does_not_count_as_an_answer(): void
    {
        $this->write('form', '');
        $this->write('help', 'The description');

        self::assertSame('The description', $this->labels->of($this->node, SeededRole::Form));
    }

    #[Test]
    public function a_label_on_a_different_path_is_not_this_ones(): void
    {
        // D-158: `path` reaches one validator among several inside the same owner.
        $this->stored->put(new Label($this->node->id, '10.20', self::ROLE_IDS['form'], 'one', '', 'somewhere inside'));

        self::assertSame('Widerstandswert', $this->labels->of($this->node, SeededRole::Form));
        self::assertSame('somewhere inside', $this->labels->of($this->node, SeededRole::Form, '', 'one', '10.20'));
    }

    #[Test]
    public function symbol_is_not_translatable_by_default(): void
    {
        // ⚠️ D-261, D-262: `Ω` is `Ω` everywhere, and offering a translation field for it
        // invites somebody to fill it wrongly. A default, not a fact.
        self::assertFalse(SeededRole::Symbol->translatableByDefault());
        self::assertTrue(SeededRole::Form->translatableByDefault());
        self::assertTrue(SeededRole::Help->translatableByDefault());
    }

    #[Test]
    public function a_label_hangs_on_an_identity_so_an_edge_can_have_one_too(): void
    {
        // C8: labels hang off an identity, not off a node — which is what lets a use site be
        // called something else from the type it points at.
        $edgeId = 7777;

        $this->stored->put(new Label($edgeId, '', self::ROLE_IDS['form'], 'one', '', 'Tolerance'));

        $found = $this->labels->storedFor($edgeId);

        self::assertCount(1, $found);
        self::assertSame('Tolerance', $found[0]->text);
    }
}
