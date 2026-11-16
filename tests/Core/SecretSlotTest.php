<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Import\SecretBox;
use Taxmod\Core\Import\SecretSlot;

/** One secret of a module: the constant first, the sealed one second, a stored one that no longer opens is broken. */
final class SecretSlotTest extends TestCase
{
    #[Test]
    public function the_constant_goes_first(): void
    {
        $box  = new SecretBox('salt', 'dhl');
        $slot = new SecretSlot(' from-config ', $box->seal('stored'), $box);

        $this->assertTrue($slot->fromConstant());
        $this->assertSame('from-config', $slot->value());
    }

    #[Test]
    public function the_stored_secret_opens_with_its_box(): void
    {
        $box = new SecretBox('salt', 'dhl');

        $this->assertSame('stored', (new SecretSlot('', $box->seal('stored'), $box))->value());
    }

    #[Test]
    public function a_stored_secret_under_other_salts_is_broken(): void
    {
        $slot = new SecretSlot('', (new SecretBox('old', 'dhl'))->seal('stored'), new SecretBox('new', 'dhl'));

        $this->assertTrue($slot->isStored());
        $this->assertNull($slot->value());
        $this->assertTrue($slot->broken());
    }

    #[Test]
    public function nothing_stored_is_neither_a_value_nor_broken(): void
    {
        $slot = new SecretSlot('', '', new SecretBox('salt', 'dhl'));

        $this->assertNull($slot->value());
        $this->assertFalse($slot->broken());
    }
}
