<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Service\AbilityBackupRule;

final class AbilityBackupRuleTest extends TestCase
{
    private const NOW = 1_800_000_000;

    public function testTheFirstCopyIsMade(): void
    {
        self::assertTrue(AbilityBackupRule::needsNewCopy(['set_values'], null, false, self::NOW, 30));
    }

    public function testAYoungCopyIsKeptForAnEdit(): void
    {
        self::assertFalse(AbilityBackupRule::needsNewCopy(['set_values', 'move_part'], self::NOW - 29 * 60, true, self::NOW, 30));
    }

    public function testAnOldCopyIsReplaced(): void
    {
        self::assertTrue(AbilityBackupRule::needsNewCopy(['set_values'], self::NOW - 30 * 60, true, self::NOW, 30));
    }

    public function testAMissingFileIsReplaced(): void
    {
        self::assertTrue(AbilityBackupRule::needsNewCopy(['set_values'], self::NOW - 60, false, self::NOW, 30));
    }

    public function testZeroMinutesCopiesEveryTime(): void
    {
        self::assertTrue(AbilityBackupRule::needsNewCopy(['set_values'], self::NOW - 60, true, self::NOW, 0));
    }

    /** @return iterable<string, array{string}> */
    public static function throwingAway(): iterable
    {
        foreach (AbilityBackupRule::ALWAYS as $op) {
            yield $op => [$op];
        }
    }

    #[DataProvider('throwingAway')]
    public function testWhatThrowsAwayIsAlwaysCopied(string $op): void
    {
        self::assertTrue(AbilityBackupRule::needsNewCopy(['set_values', $op], self::NOW - 60, true, self::NOW, 30));
    }
}
