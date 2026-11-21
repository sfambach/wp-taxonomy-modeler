<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Privacy\Confidentiality;

/** «Vertraulich» at the edge, switched on or off per node; nearer wins (D-1578). */
final class ConfidentialityTest extends TestCase
{
    private const KONTAKT = 3083;
    private const PERSON  = 14;
    private const ORG     = 13;
    private const NAME    = 666;

    #[Test]
    public function a_person_is_sealed_and_an_organisation_stays_plain(): void
    {
        $plan = new Confidentiality([], [self::NAME => [self::PERSON => true]]);

        self::assertTrue($plan->at(self::NAME, [1, self::KONTAKT, self::PERSON]));
        self::assertFalse($plan->at(self::NAME, [1, self::KONTAKT, self::ORG]));
        self::assertTrue($plan->mayHold(self::NAME));
        self::assertTrue($plan->dependsOnNode(self::NAME));
        self::assertSame([self::NAME => true], $plan->edges());
    }

    #[Test]
    public function the_edge_holds_for_all_and_a_node_may_switch_it_off_for_its_subtree(): void
    {
        $plan = new Confidentiality([self::NAME => true], [self::NAME => [self::ORG => false]]);

        self::assertTrue($plan->at(self::NAME, [1, self::KONTAKT, self::PERSON]));
        self::assertFalse($plan->at(self::NAME, [1, self::KONTAKT, self::ORG]));
        self::assertFalse($plan->at(self::NAME, [1, self::KONTAKT, self::ORG, 77]));
    }

    #[Test]
    public function nearer_wins(): void
    {
        $plan = new Confidentiality([], [self::NAME => [self::KONTAKT => true, self::ORG => false, 77 => true]]);

        self::assertTrue($plan->at(self::NAME, [1, self::KONTAKT, self::PERSON]));
        self::assertFalse($plan->at(self::NAME, [1, self::KONTAKT, self::ORG]));
        self::assertTrue($plan->at(self::NAME, [1, self::KONTAKT, self::ORG, 77]));
    }

    #[Test]
    public function an_edge_nothing_says_anything_about_is_plain(): void
    {
        $plan = new Confidentiality([], [self::NAME => [self::ORG => false]]);

        self::assertFalse($plan->mayHold(self::NAME));
        self::assertFalse($plan->at(5, [1, self::PERSON]));
        self::assertSame([], $plan->edges());
        self::assertFalse(Confidentiality::none()->mayHold(self::NAME));
    }
}
