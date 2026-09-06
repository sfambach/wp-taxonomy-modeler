<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Model\IdentitySpace;
use Taxmod\Core\Model\Label;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\SeededRole;
use Taxmod\Core\Service\Labels;
use Taxmod\Tests\Core\Fake\InMemoryLabels;

/**
 * Die Rückfallkette: `Rolle·Numerus` → `Rolle·one` → `name` → dieselbe Reihe in der Standardsprache.
 *
 * ⚠️ **Die sprachneutrale Zeile ist fort** ([D-387](../../../docs/NewConcept/90-decision-log.md),
 * [D-645](../../../docs/NewConcept/90-decision-log.md)); an ihre Stelle tritt die **Standardsprache**
 * von der Installationsseite. Und `name` ist seit
 * [D-646](../../../docs/NewConcept/90-decision-log.md) selbst eine Beschriftung je Sprache.
 *
 * @see docs/NewConcept/40-i18n.md
 */
final class LabelsTest extends TestCase
{
    /** Die Standardsprache dieser Prüfung — dieselbe Rolle wie `taxmod_neutral_locale`. */
    private const STANDARD = 'en_US';

    #[Test]
    public function a_batch_gives_each_node_its_own_label_and_not_the_last_ones(): void
    {
        // ⚠️ The fault this guards against is silent: a batch that flattened the stored rows the
        // way the single-node path does would hand every node the **last** node's label, which
        // shows up as one wrong name on one row and gets blamed on the data.
        $one = Node::create(60, 'Resistor', 'Root');
        $two = Node::create(61, 'Capacitor', 'Root');

        $this->stored->put(new Label($one->id, IdentitySpace::Node, SeededRole::Form, Label::BASE_NUMBER, self::STANDARD, 'Widerstand'));
        $this->stored->put(new Label($two->id, IdentitySpace::Node, SeededRole::Form, Label::BASE_NUMBER, self::STANDARD, 'Kondensator'));

        $found = $this->labels->forNodes([$one, $two]);

        self::assertSame('Widerstand', $found[$one->id]);
        self::assertSame('Kondensator', $found[$two->id]);
    }

    #[Test]
    public function a_batch_falls_back_to_the_node_name_like_the_single_walk_does(): void
    {
        // D-020, D-209: the chain ends on the node's own name and never on nothing.
        $bare = Node::create(62, 'Inductor', 'Root');

        self::assertSame('Inductor', $this->labels->forNodes([$bare])[$bare->id]);
    }

    #[Test]
    public function an_empty_batch_asks_nothing(): void
    {
        self::assertSame([], $this->labels->forNodes([]));
    }

    // ------------------------------------------------------- die Hilfe, D-662

    #[Test]
    public function only_a_written_help_answers_and_a_node_without_one_is_absent(): void
    {
        // ⚠️ **[D-662](../../../docs/NewConcept/90-decision-log.md): «überall dort, wo help label
        // ist».** *Die Bedingung ist «wo eine Hilfe steht» — wo keine steht, darf keine Antwort
        // kommen, sonst trüge jedes Feld ein Fragezeichen.*
        $mit  = Node::create(70, 'Condensator', 'Root');
        $ohne = Node::create(71, 'Resistor', 'Root');

        $this->stored->put(new Label($mit->id, IdentitySpace::Node, SeededRole::Help, Label::BASE_NUMBER, self::STANDARD, 'stores current'));

        $found = $this->labels->helpFor([$mit, $ohne]);

        self::assertSame('stores current', $found[$mit->id]);
        self::assertArrayNotHasKey($ohne->id, $found);
    }

    #[Test]
    public function the_help_never_falls_back_to_a_name(): void
    {
        // ⚠️ **Der teure Fall, und er ist gemessen worden** ([D-386](../../../docs/NewConcept/90-decision-log.md)
        // andersherum): *`forNodes()` fällt auf die Rolle `name` und zuletzt auf `$node->name`
        // zurück. **Nähme die Hilfe denselben Weg, hiesse jede Hilfe wie ihr Knoten** — ein
        // Fragezeichen, hinter dem der Name steht, den man ohnehin sieht.*
        $node = Node::create(72, 'Resistor', 'Root');

        $this->stored->put(new Label($node->id, IdentitySpace::Node, SeededRole::Form, Label::BASE_NUMBER, self::STANDARD, 'Widerstand'));

        self::assertSame([], $this->labels->helpFor([$node]));
        self::assertSame('Widerstand', $this->labels->forNodes([$node])[$node->id]);
    }

    #[Test]
    public function the_help_falls_back_to_the_default_locale_but_not_to_another_role(): void
    {
        // ⚠️ *Die **Sprache** fällt weiter zurück ([D-645](../../../docs/NewConcept/90-decision-log.md)):
        // eine Hilfe, die nur auf Englisch geschrieben ist, ist besser als keine.*
        $node = Node::create(73, 'Text', 'Root');

        $this->stored->put(new Label($node->id, IdentitySpace::Node, SeededRole::Help, Label::BASE_NUMBER, self::STANDARD, 'free text'));

        self::assertSame('free text', $this->labels->helpFor([$node], 'de_DE')[$node->id]);
    }

    #[Test]
    public function no_nodes_means_no_query_and_no_help(): void
    {
        self::assertSame([], $this->labels->helpFor([]));
    }

    private InMemoryLabels $stored;
    private Labels $labels;
    private Node $node;

    protected function setUp(): void
    {
        $root = Node::create(1, 'Root', null);

        $this->node   = Node::create(50, 'Widerstandswert', $root->path);
        $this->stored = new InMemoryLabels();
        $this->labels = new Labels($this->stored, self::STANDARD);
    }

    private function write(string $role, string $text, string $locale = self::STANDARD, string $number = Label::BASE_NUMBER): void
    {
        $this->stored->put(new Label(
            $this->node->id,
            IdentitySpace::Node,
            SeededRole::from($role),
            $number,
            $locale,
            $text
        ));
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
    public function a_missing_role_does_not_inherit_the_help_sentence(): void
    {
        // ⚠️ **This test used to assert the opposite, and the concept used to say so** — D-020 and
        // D-209 put `help` in the chain. **The owner found what that does on a real node:** a `form`
        // label nobody wrote inherited the whole help sentence as the node's name, so a column
        // heading read *condensator is an electronic part that has a capacity …*. D-386 takes `help`
        // out of the chain; the intent of D-020 survives, but the thing the others are a variation of
        // is the **name** and never the long text.
        $this->write('help', 'The value of the resistance in ohms');

        self::assertSame('Widerstandswert', $this->labels->of($this->node, SeededRole::Table));

        // ⚠️ And `help` keeps its own job: asked for directly, it answers.
        self::assertSame(
            'The value of the resistance in ohms',
            $this->labels->of($this->node, SeededRole::Help)
        );
    }

    #[Test]
    public function with_nothing_stored_the_nodes_own_name_is_used(): void
    {
        // ⚠️ The chain never ends on nothing. An empty cell where a name should be is worse
        // than the internal name — and the internal name always exists (D-022).
        self::assertSame('Widerstandswert', $this->labels->of($this->node, SeededRole::Form));
    }

    /**
     * ⚠️ **Sein Fund vom 2026-09-05, als Prüfung** ([D-646](../../../docs/NewConcept/90-decision-log.md)):
     * *«der Name muss auch sprachabhängig werden, sonst schaltet man die Sprache um und alle Knoten
     * haben noch den gleichen Namen».*
     */
    #[Test]
    public function the_name_is_language_dependent_and_ends_the_chain(): void
    {
        $this->write('name', 'Resistance value', 'en_US');
        $this->write('name', 'Widerstandswert', 'de_DE');

        // Keine `form`-Beschriftung gepflegt — die Kette fällt auf den Namen **derselben** Sprache.
        self::assertSame('Widerstandswert', $this->labels->of($this->node, SeededRole::Form, 'de_DE'));
        self::assertSame('Resistance value', $this->labels->of($this->node, SeededRole::Form, 'en_US'));
    }

    /**
     * ⚠️ *Der Name der angefragten Sprache schlägt die Rollenbeschriftung der Standardsprache —
     * genau darum wurde er sprachabhängig (D-646).*
     */
    #[Test]
    public function the_name_in_the_asked_language_beats_the_role_in_the_default_one(): void
    {
        $this->write('form', 'Resistance value', 'en_US');
        $this->write('name', 'Widerstandswert', 'de_DE');

        self::assertSame('Widerstandswert', $this->labels->of($this->node, SeededRole::Form, 'de_DE'));
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
        $this->write('form', 'Resistances', self::STANDARD, 'other');

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

    /**
     * ⚠️ **Die Gegenprobe zur Standardsprache** ([D-387](../../../docs/NewConcept/90-decision-log.md),
     * [D-645](../../../docs/NewConcept/90-decision-log.md)): *eine Sprache, für die **nichts** gepflegt
     * ist, bekommt den Text der Standardsprache. Hier stand vorher «fällt auf die neutrale Zeile
     * zurück» — die gibt es nicht mehr.*
     */
    #[Test]
    public function a_locale_with_nothing_stored_falls_back_to_the_default_language(): void
    {
        $this->write('form', 'Resistance value', self::STANDARD);
        $this->write('help', 'a description nobody asked for', self::STANDARD);

        self::assertSame('Resistance value', $this->labels->of($this->node, SeededRole::Form, 'fr_FR'));
    }

    #[Test]
    public function an_empty_text_does_not_count_as_an_answer(): void
    {
        // ⚠️ An empty text is not an answer, so the chain walks on — and since D-386 the next
        // link is the node's own name rather than the help sentence.
        $this->write('form', '');
        $this->write('help', 'The description');

        self::assertSame('Widerstandswert', $this->labels->of($this->node, SeededRole::Form));
    }

    /**
     * ⚠️ **`symbol` ist seit [D-645](../../../docs/NewConcept/90-decision-log.md) sprachabhängig wie
     * jede andere Rolle.** *Hier stand die Gegenprobe zu `translatableByDefault()`, das `symbol` als
     * einzige Rolle sprachneutral zeichnete. Sein Wort: «Symbol wird sprachabhängig.»*
     */
    #[Test]
    public function a_symbol_may_differ_per_language(): void
    {
        $this->write('symbol', 'pc', 'en_US');
        $this->write('symbol', 'St', 'de_DE');

        self::assertSame('St', $this->labels->of($this->node, SeededRole::Symbol, 'de_DE'));
        self::assertSame('pc', $this->labels->of($this->node, SeededRole::Symbol, 'en_US'));
    }

    #[Test]
    public function a_label_hangs_on_an_identity_so_an_relation_can_have_one_too(): void
    {
        // C8: labels hang off an identity, not off a node — which is what lets a use site be
        // called something else from the type it points at.
        $relationId = 7777;

        $this->stored->put(new Label($relationId, IdentitySpace::Relation, SeededRole::Form, 'one', self::STANDARD, 'Tolerance'));

        $found = $this->labels->storedFor($relationId, IdentitySpace::Relation);

        self::assertCount(1, $found);
        self::assertSame('Tolerance', $found[0]->text);
    }

    /**
     * ⚠️ **Der gemessene Fehler von `INF-035`, als Prüfung.** *Am 2026-09-05 bekam eine frisch
     * angelegte Kante **sechs** Beschriftungen statt einer: sie trug dieselbe Nummer wie ein zwei
     * Zeilen zuvor entstandener Knoten. Seit [D-581](../../../docs/NewConcept/90-decision-log.md) ein
     * Knoten keine Vererbungskante mehr anlegt, laufen die beiden Id-Zähler verschieden schnell — und
     * dann treffen sie sich.*
     *
     * ⚠️ *Die Abhilfe ist die von [D-597](../../../docs/NewConcept/90-decision-log.md): eine zweite
     * Spalte, die den Raum nennt.*
     */
    #[Test]
    public function a_relation_and_a_node_may_share_a_number_without_sharing_a_label(): void
    {
        $gleicheNummer = 8888;

        $this->stored->put(new Label($gleicheNummer, IdentitySpace::Node, SeededRole::Form, 'one', self::STANDARD, 'der Knoten'));
        $this->stored->put(new Label($gleicheNummer, IdentitySpace::Relation, SeededRole::Form, 'one', self::STANDARD, 'die Kante'));

        $amKnoten = $this->labels->storedFor($gleicheNummer, IdentitySpace::Node);
        $anDerKante = $this->labels->storedFor($gleicheNummer, IdentitySpace::Relation);

        self::assertCount(1, $amKnoten);
        self::assertCount(1, $anDerKante);
        self::assertSame('der Knoten', $amKnoten[0]->text);
        self::assertSame('die Kante', $anDerKante[0]->text);
    }

    /**
     * ⚠️ **Die Version ist die Zeilennummer** ([D-634](../../../docs/NewConcept/90-decision-log.md),
     * [D-640](../../../docs/NewConcept/90-decision-log.md)). *`labels` war bis Fassung 31 die einzige
     * Tabelle ohne sie, und {@see Labels} musste dem Journal `null` hinschreiben. **Die Ablage zählt
     * sie, nicht der Aufrufer** — sonst könnte eine Maske sie setzen und damit das Sperren aushebeln.*
     */
    #[Test]
    public function writing_a_label_a_second_time_raises_its_version(): void
    {
        $bau = fn (string $text): Label => new Label(
            $this->node->id,
            IdentitySpace::Node,
            SeededRole::Form,
            'one',
            'de_DE',
            $text
        );

        $this->labels->put($bau('erst so'));
        self::assertSame(1, $this->labels->storedFor($this->node->id, IdentitySpace::Node)[0]->version);

        $this->labels->put($bau('dann so'));
        self::assertSame(2, $this->labels->storedFor($this->node->id, IdentitySpace::Node)[0]->version);

        // ⚠️ *Ein Speichern, das nichts ändert, hebt die Version nicht (D-282, D-488).*
        $this->labels->put($bau('dann so'));
        self::assertSame(2, $this->labels->storedFor($this->node->id, IdentitySpace::Node)[0]->version);
    }
}
