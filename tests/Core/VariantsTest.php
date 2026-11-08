<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Model\VariantComplaint;
use Taxmod\Core\Model\Variants;

/** D-1208: a variant shows its own «Je Variante» fields and its parent's other fields; one parent, no variant of a variant. */
final class VariantsTest extends TestCase
{
    private const OF = 900;
    private const LABEL = 1;
    private const SIZE = 2;
    private const COLOUR = 3;
    private const PICTURES = 4;

    /** @return array<int, array<int, list<string>>> */
    private function parent(): array
    {
        return [48476 => [self::LABEL => ['OLED 0,91″'], self::SIZE => ['128×32'], self::PICTURES => ['media:7']]];
    }

    #[Test]
    public function a_variant_reads_fixed_fields_from_its_parent_and_keeps_its_own_flagged_ones(): void
    {
        $variant = [50001 => [self::OF => ['48476'], self::COLOUR => ['weiß'], self::SIZE => ['stale']]];
        $shown   = Variants::shown($variant, $this->parent(), self::OF, [self::COLOUR, self::PICTURES])[50001];

        self::assertSame(['OLED 0,91″'], $shown[self::LABEL]);
        self::assertSame(['128×32'], $shown[self::SIZE], 'a field without the flag is the parent\'s, whatever the variant holds');
        self::assertSame(['weiß'], $shown[self::COLOUR]);
        self::assertSame(['media:7'], $shown[self::PICTURES], 'a flagged field left empty shows the parent\'s (his word: «eigenes bild wäre möglich»)');
        self::assertSame(['48476'], $shown[self::OF]);
    }

    #[Test]
    public function a_record_that_is_no_variant_keeps_its_own_values(): void
    {
        self::assertSame($this->parent(), Variants::shown($this->parent(), [], self::OF, [self::COLOUR]));
    }

    #[Test]
    public function the_parent_links_are_read_from_the_parent_field(): void
    {
        self::assertSame([50001 => 48476], Variants::parents([50001 => [self::OF => ['48476']], 48476 => []], self::OF));
    }

    #[Test]
    public function one_parent_and_no_variant_of_a_variant(): void
    {
        $known = [50001 => 48476];

        self::assertSame([], Variants::complaints(50002, 48476, $known));
        self::assertSame([VariantComplaint::OfItself], Variants::complaints(48476, 48476, []));
        self::assertSame([VariantComplaint::ParentIsVariant], Variants::complaints(50002, 50001, $known));
        self::assertSame([VariantComplaint::HasVariants], Variants::complaints(48476, 12345, $known));
        self::assertSame([], Variants::complaints(50002, 0, $known), 'no parent is always allowed');
    }
}
