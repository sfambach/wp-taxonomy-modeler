<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Listing\ChoiceFilter;

/**
 * D-1477: a filter for X keeps X, what lies below X, and each ancestor of X up to the field's target.
 *
 * @see docs/NewConcept/90-decision-log.md D-1477
 */
final class ChoiceFilterTest extends TestCase
{
    private const ZIEL  = '1.2.16';
    private const PC    = '1.2.16.20';
    private const XT    = '1.2.16.20.21';
    private const AT    = '1.2.16.20.22';
    private const AMIGA = '1.2.16.30';

    #[Test]
    public function the_wanted_node_and_what_lies_below_it_are_kept(): void
    {
        self::assertTrue(ChoiceFilter::fits(self::XT, self::XT, self::ZIEL));
        self::assertTrue(ChoiceFilter::fits(self::XT, self::PC, self::ZIEL), 'D-791');
    }

    #[Test]
    public function each_ancestor_up_to_the_target_is_kept(): void
    {
        self::assertTrue(ChoiceFilter::fits(self::PC, self::XT, self::ZIEL));
        self::assertTrue(ChoiceFilter::fits(self::ZIEL, self::XT, self::ZIEL), 'the target means all');
    }

    #[Test]
    public function above_the_target_siblings_and_empty_are_not(): void
    {
        self::assertFalse(ChoiceFilter::fits('1.2', self::XT, self::ZIEL));
        self::assertFalse(ChoiceFilter::fits(self::AT, self::XT, self::ZIEL));
        self::assertFalse(ChoiceFilter::fits(self::AMIGA, self::XT, self::ZIEL));
        self::assertFalse(ChoiceFilter::fits('', self::XT, self::ZIEL), 'empty is «not yet decided»');
        self::assertFalse(ChoiceFilter::fits(self::PC, self::XT, ''), 'without a target only X and below');
    }

    #[Test]
    public function a_prefix_of_digits_is_not_an_ancestor(): void
    {
        self::assertFalse(ChoiceFilter::fits('1.2.16.2', self::XT, self::ZIEL));
        self::assertFalse(ChoiceFilter::fits('1.2.16.200', self::PC, self::ZIEL));
    }
}
