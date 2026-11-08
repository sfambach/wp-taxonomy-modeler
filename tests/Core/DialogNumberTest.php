<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Renderer\DialogNumber;

/** A dialog's number is drawn by the model's integer field, not by hand (his word 2026-11-08: «kein Standard-Int-Feld»). */
final class DialogNumberTest extends TestCase
{
    #[Test]
    public function itIsTheSpinnerWithItsBoundsAndValue(): void
    {
        self::assertSame('<input type="number" name="copies" value="2" min="1" max="50" step="1">', DialogNumber::input('copies', 2, 1, 50));
    }

    #[Test]
    public function emptyStaysEmptyAndTheFormIsNamed(): void
    {
        $markup = DialogNumber::input('copies', null, 1, 50, 'taxmod-bulk');

        self::assertStringContainsString('form="taxmod-bulk"', $markup);
        self::assertStringNotContainsString('value=', $markup);
        self::assertStringNotContainsString('style=', $markup);
    }
}
