<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Exception\NotAFrozenState;
use Taxmod\Core\Exception\NotAValueOfThatType;
use Taxmod\Core\Model\FrozenState;
use Taxmod\Core\Model\Label;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\SettingKey;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Service\Labels;
use Taxmod\Tests\Core\Fake\CountingIdentities;
use Taxmod\Tests\Core\Fake\FixedFramework;
use Taxmod\Tests\Core\Fake\InMemoryLabels;
use Taxmod\Tests\Core\Fake\InMemoryNodes;
use Taxmod\Tests\Core\Fake\InMemoryRelations;
use Taxmod\Tests\Core\Fake\RecordedChanges;

/**
 * A journal entry carries an address, and what it wrote can be read back.
 *
 * ⚠️ **The measured fault this stands against** ([D-427](../../docs/NewConcept/90-decision-log.md)):
 * a setting row read `what = "setting min set"`, `before = NULL`, `after = "10"`. *Something set
 * `min` to 10 — and not **for which place**, which is what
 * [D-413](../../docs/NewConcept/90-decision-log.md)'s `path` answers. All 5773 setting rows in the
 * real table looked like that; **0** of them named a path.*
 *
 * ⚠️ *Half of these tests are the format itself and the other half is the pair of services that
 * writes it, because the two fail differently: a format that cannot round-trip is broken for
 * everybody, while a service that forgets to hand it the path is broken for exactly one column.*
 *
 * @see docs/NewConcept/10-domain-core.md
 */
final class FrozenStateTest extends TestCase
{
    #[Test]
    public function what_was_written_comes_back_field_by_field(): void
    {
        $state = FrozenState::of(['key' => 'min', 'path' => '4654', 'type' => 'int', 'value' => '10']);

        $read = FrozenState::parse($state->write());

        self::assertNotNull($read);
        self::assertSame('min', $read->field('key'));
        self::assertSame('4654', $read->field('path'));
        self::assertSame('int', $read->field('type'));
        self::assertSame('10', $read->field('value'));
        self::assertSame($state->write(), $read->write());
    }

    #[Test]
    public function an_empty_field_is_an_answer_and_survives(): void
    {
        // ⚠️ *An empty `path` means «the owner itself» (D-413) and a **missing** `path` means «written
        // before this format existed». Losing the difference would make the second look like the first.
        $read = FrozenState::parse(FrozenState::of(['key' => 'min', 'path' => '', 'type' => 'int', 'value' => '10'])->write());

        self::assertNotNull($read);
        self::assertTrue($read->has('path'));
        self::assertSame('', $read->field('path'));
    }

    #[Test]
    public function the_last_field_may_hold_anything_at_all(): void
    {
        // The nastiest value a text setting could carry: spaces, an `=`, and something that looks
        // like another field.
        $nasty = 'two words = one path=9 thing';

        $read = FrozenState::parse(
            FrozenState::of(['key' => 'note', 'path' => '', 'type' => 'text', 'value' => $nasty])->write()
        );

        self::assertNotNull($read);
        self::assertSame($nasty, $read->field('value'));
        self::assertSame('', $read->field('path'), 'the real address must not be taken from inside the text');
    }

    #[Test]
    public function whitespace_anywhere_but_last_is_refused_instead_of_silently_glued(): void
    {
        $this->expectException(NotAFrozenState::class);

        FrozenState::of(['name' => 'Part List Item', 'path' => '1.2']);
    }

    #[Test]
    public function a_field_name_that_could_not_be_read_back_is_refused(): void
    {
        $this->expectException(NotAFrozenState::class);

        FrozenState::of(['Path Of' => '1.2']);
    }

    #[Test]
    public function a_state_with_no_fields_is_refused(): void
    {
        $this->expectException(NotAFrozenState::class);

        FrozenState::of([]);
    }

    #[Test]
    public function a_row_written_before_this_format_still_gives_up_its_path(): void
    {
        // ⚠️ *The dialect of 2085 existing rows — `name` before `path`, unescaped, with spaces in the
        // name (844 rows have them). It has to keep parsing: no migration rewrites these.
        $old = 'id=30387 version=1 name=__path Text of a Thing path=1.406.408.30387';

        $read = FrozenState::parse($old);

        self::assertNotNull($read);
        self::assertSame('1.406.408.30387', $read->field('path'));
        self::assertSame('__path Text of a Thing', $read->field('name'));
        self::assertSame('30387', $read->field('id'));
    }

    #[Test]
    public function the_new_order_and_the_old_one_answer_the_same_address(): void
    {
        $old = 'id=7 version=2 name=Board Two path=1.402.7';
        $new = FrozenState::of(['id' => 7, 'version' => 2, 'path' => '1.402.7', 'name' => 'Board Two'])->write();

        self::assertSame(
            FrozenState::parse($old)?->field('path'),
            FrozenState::parse($new)?->field('path')
        );
    }

    /**
     * ⚠️ *Every one of these is a real stored value, taken off the table: a setting's bare number, a
     * promotion's bare path, a trash sweep's sentence, a seeded node's name for itself.*
     */
    #[Test]
    public function a_row_that_is_not_a_field_list_stays_exactly_what_it_was(): void
    {
        foreach (['10', '0', '1.189.191', '3 parked', '3 nodes, 7 relations', 'framework: Model', 'the root'] as $stored) {
            $read = FrozenState::parse($stored);

            self::assertNotNull($read, $stored);
            self::assertSame($stored, $read->plainValue(), $stored);
            self::assertSame($stored, $read->write(), $stored);
            self::assertNull($read->field('path'), $stored);
        }
    }

    #[Test]
    public function nothing_stored_is_nothing_read(): void
    {
        self::assertNull(FrozenState::parse(null));
        self::assertNull(FrozenState::parse(''));
    }

    /**
     * ⚠️ **This is the pair `describe()` could not give.** *`describe()` is prose (D-400): it answers
     * `10` for an integer, for a decimal and for the characters «10» alike, and it drops a reference's
     * id on purpose (D-363). A replay cannot re-point a reference at «(a reference)».*
     */
    #[Test]
    public function every_kind_of_value_survives_the_round_trip(): void
    {
        $values = [
            TypedValue::ofInt(10),
            TypedValue::ofInt(0),
            TypedValue::ofDecimal('2.50'),
            TypedValue::ofText('two words = one'),
            TypedValue::ofText(''),
            TypedValue::ofDate('2026-08-28'),
            TypedValue::ofReference(4654),
            TypedValue::ofBool(true),
            TypedValue::ofBool(false),
            TypedValue::nothing(),
        ];

        foreach ($values as $value) {
            $again = TypedValue::ofTypeName($value->typeName(), $value->rawValue());

            self::assertTrue($value->equals($again), $value->typeName() . ' ' . $value->rawValue());
        }
    }

    #[Test]
    public function a_reference_keeps_its_id_in_a_state_and_loses_it_in_prose(): void
    {
        $reference = TypedValue::ofReference(4654);

        self::assertSame('4654', $reference->rawValue());
        self::assertSame('(a reference)', $reference->describe(), 'prose must stay prose');
    }

    #[Test]
    public function a_type_nobody_wrote_is_refused_rather_than_read_as_text(): void
    {
        $this->expectException(NotAValueOfThatType::class);

        TypedValue::ofTypeName('colour', '#ff0000');
    }

    /*
     * Hier standen fuenf Zusicherungen ueber die Journalzeilen, die {@see Settings} beim Setzen und
     * Zuruecknehmen einer Einstellung schrieb — Schluessel, Pfad, Typ, Wert, und die Gegenprobe,
     * dass ein Zuruecknehmen ohne Wert nichts verzeichnet. **Der Schreiber ist mit der
     * `settings`-Tabelle gestrichen (D-579)**, und eine Zusicherung ueber eine Zeile, die niemand
     * mehr schreibt, prueft nichts. *Der Rest dieser Datei — die Form `FrozenState` selbst und der
     * Label-Eintrag — bleibt unberuehrt.*
     */

    #[Test]
    public function a_label_entry_says_the_verb_in_what_and_the_address_in_the_state(): void
    {
        $relations  = new InMemoryRelations();
        $nodes  = new InMemoryNodes($relations);
        $ids    = new CountingIdentities();
        $root   = Node::create($ids->next(), 'Root', null);
        $trash  = Node::create($ids->next(), 'Trash', $root->path);

        $nodes->add($root);
        $nodes->add($trash);

        $changes = new RecordedChanges();
        $labels  = new Labels(
            new InMemoryLabels(),
            new FixedFramework($root, $trash),
            $changes,
            $nodes
        );

        $labels->put(new Label($root->id, '4654', 733, 'one', 'de_DE', 'Ein Name mit Leerzeichen'));

        $entry = $changes->entries[0];

        self::assertSame('label set', $entry[2]);

        $after = FrozenState::parse((string) $entry[4]);

        self::assertNotNull($after);
        self::assertSame('733', $after->field('role'));
        self::assertSame('4654', $after->field('path'));
        self::assertSame('one', $after->field('number'));
        self::assertSame('de_DE', $after->field('locale'));
        self::assertSame('Ein Name mit Leerzeichen', $after->field('text'));
    }
}
