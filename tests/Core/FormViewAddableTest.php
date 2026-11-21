<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Description\FormView;
use Taxmod\Core\Description\ShownValue;
use Taxmod\Core\Description\ValueKind;

/** D-1571: a compact form names its optional fields, so «+ Festlegung» offers them and ✕ takes them away. */
final class FormViewAddableTest extends TestCase
{
    #[Test]
    public function the_optional_fields_are_offered_and_the_others_are_not(): void
    {
        $value = new ShownValue('x', ValueKind::Text);
        $form  = new FormView([
            ['name' => 'Name', 'field' => $value, 'hint' => ''],
            ['name' => 'Etikett', 'field' => $value, 'hint' => '', 'relation' => 571, 'addable' => false],
            ['name' => 'Drucker', 'field' => $value, 'hint' => '', 'relation' => 572, 'addable' => true],
        ], ['add' => '+ Festlegung']);

        self::assertSame([571 => 'Etikett', 572 => 'Drucker'], $form->adding());
        self::assertSame(['relation' => 572, 'addable' => true], array_intersect_key($form->toArray()['form'][2], ['relation' => 0, 'addable' => 0]));
        self::assertArrayNotHasKey('addable', $form->toArray()['form'][0], 'a field not listed always shows');
    }

    #[Test]
    public function a_form_without_optional_fields_offers_nothing(): void
    {
        self::assertSame([], (new FormView([['name' => 'Name', 'field' => null, 'hint' => '']]))->adding());
    }
}
