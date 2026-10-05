<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Service\BackupRewrite;

/**
 * Was eine Sicherung beim Umzug an einem gespeicherten Text ändert (D-908).
 *
 * @see docs/NewConcept/90-decision-log.md
 */
final class BackupRewriteTest extends TestCase
{
    private function rewrite(): BackupRewrite
    {
        return new BackupRewrite([16879 => 17001], 'http://devel.test', 'https://www.fambach.net/');
    }

    #[Test]
    public function a_media_id_gets_the_number_the_target_site_gave_the_file(): void
    {
        self::assertSame('media:17001', $this->rewrite()->text('media:16879'));
    }

    #[Test]
    public function a_media_id_that_was_not_carried_stays_as_it_was(): void
    {
        self::assertSame('media:12603', $this->rewrite()->text('media:12603'));
    }

    #[Test]
    public function a_link_to_the_old_site_points_at_the_new_one(): void
    {
        self::assertSame(
            'siehe https://www.fambach.net/?p=16387 und https://www.fambach.net/wp-content/uploads/a.zip',
            $this->rewrite()->text('siehe http://devel.test/?p=16387 und http://devel.test/wp-content/uploads/a.zip')
        );
    }

    #[Test]
    public function an_escaped_link_inside_json_is_rewritten_in_its_escaped_form(): void
    {
        self::assertSame('{"href":"https:\/\/www.fambach.net\/a"}', $this->rewrite()->text('{"href":"http:\/\/devel.test\/a"}'));
    }

    #[Test]
    public function other_text_and_empty_values_are_left_alone(): void
    {
        $rewrite = $this->rewrite();

        self::assertSame('https://example.org/datenblatt.pdf', $rewrite->text('https://example.org/datenblatt.pdf'));
        self::assertNull($rewrite->text(null));
        self::assertSame('', $rewrite->text(''));
    }

    #[Test]
    public function the_same_site_rewrites_no_link(): void
    {
        self::assertSame('http://devel.test/a', (new BackupRewrite([], 'http://devel.test/', 'http://devel.test'))->text('http://devel.test/a'));
    }
}
