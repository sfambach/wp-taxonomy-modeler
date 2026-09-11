<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Model\SettingShape;

/**
 * ⚠️ **Hier standen neun Zusagen über die Aufzählung `SettingKey`, und sie ist mit Schritt 7 des
 * Bauplans (2026-09-11) gefallen** ([D-712](../../docs/NewConcept/90-decision-log.md)): *was eine
 * Einstellung ist und welchen Typ ihr Wert hat, sagt der Vertrag der Klasse ({@see ContractAttributesTest}).
 * Geblieben ist die Gestalt selbst.*
 */
final class SettingShapeTest extends TestCase
{
    #[Test]
    public function only_a_switch_forbids_an_empty_value(): void
    {
        $forbidding = array_values(array_filter(
            SettingShape::cases(),
            static fn (SettingShape $shape): bool => ! $shape->allowsNothing()
        ));

        self::assertSame([SettingShape::Switch], $forbidding);
    }
}
