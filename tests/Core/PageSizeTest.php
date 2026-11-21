<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Listing\PageSize;

/**
 * The page's choice beats the user's setting, which beats the site's setting; an unknown size lets the next level speak.
 */
final class PageSizeTest extends TestCase
{
    #[Test]
    public function the_choice_on_the_page_wins(): void
    {
        self::assertSame(20, PageSize::resolve(20, 100, 10));
    }

    #[Test]
    public function the_user_setting_beats_the_site_setting(): void
    {
        self::assertSame(100, PageSize::resolve(null, 100, 10));
    }

    #[Test]
    public function the_site_setting_speaks_when_nothing_else_is_set(): void
    {
        self::assertSame(10, PageSize::resolve(null, null, 10));
    }

    #[Test]
    public function without_any_setting_the_default_holds(): void
    {
        self::assertSame(PageSize::DEFAULT, PageSize::resolve(null, null, null));
    }

    #[Test]
    public function a_size_not_offered_counts_as_not_set(): void
    {
        self::assertSame(100, PageSize::resolve(7, 100, 10));
        self::assertSame(10, PageSize::resolve(0, -5, 10));
    }
}
