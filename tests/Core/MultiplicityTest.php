<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Model\Multiplicity;

/**
 * What an attribute means when nobody has said, and why that is not a cosmetic default.
 *
 * ⚠️ **This file exists because nothing guarded the default and a change to it went unnoticed.** On
 * 2026-08-26 the standard moved from `0..1` to `1` ([D-434](../../../docs/NewConcept/90-decision-log.md))
 * — **flipping 23 of 32 attribute edges from optional to required** — and all 285 tests stayed green.
 * *A default nobody asserts is a decision nobody can defend.*
 *
 * @see docs/NewConcept/10-domain-core.md
 */
final class MultiplicityTest extends TestCase
{
    #[Test]
    public function the_standard_is_exactly_one(): void
    {
        // D-434, on the owner's word: *the standard multiplicity should be 1.*
        self::assertSame(Multiplicity::ExactlyOne, Multiplicity::standard());
    }

    #[Test]
    public function and_the_standard_therefore_requires_a_value(): void
    {
        // ⚠️ **This is the assertion that carries the meaning.** D-405, in the owner's words: *a floor
        // of one **is** mandatoriness.* So the default is not a shape on a screen — it decides whether
        // a new attribute must be answered, and it should fail loudly if somebody widens it back
        // without meaning to.
        self::assertTrue(Multiplicity::standard()->requiresOne());
    }

    #[Test]
    public function an_absent_setting_reads_as_the_standard(): void
    {
        // D-379's surviving half: a multiplicity is never nothing, and the fallback lives in one place.
        self::assertSame(Multiplicity::standard(), Multiplicity::fromSetting(null));
        self::assertSame(Multiplicity::standard(), Multiplicity::fromSetting(''));
    }

    #[Test]
    public function a_stored_value_beats_the_standard(): void
    {
        // ⚠️ *The counter-check: without it, a `fromSetting()` that ignored its argument and always
        // answered the standard would pass every assertion above.*
        self::assertSame(Multiplicity::ZeroToMany, Multiplicity::fromSetting('0..*'));
        self::assertSame(Multiplicity::ZeroToOne, Multiplicity::fromSetting('0..1'));
    }

    #[Test]
    public function one_is_written_as_one_and_stored_as_one_to_one(): void
    {
        // The owner renamed the notation and the stored token stayed: *`1..1` we renamed to `1`.*
        self::assertSame('1', Multiplicity::ExactlyOne->notation());
        self::assertSame('1..1', Multiplicity::ExactlyOne->value);
    }
}
