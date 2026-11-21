<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Service\SimilarFields;

final class SimilarFieldsTest extends TestCase
{
    /** @return list<array{relation: int, name: string, type: string|null, target: int, settings: array<string, string>, uses: int}> */
    private static function fields(): array
    {
        return [
            ['relation' => 1, 'name' => 'Druckauflösung', 'type' => 'integer', 'target' => 10, 'settings' => ['min' => '1', 'max' => '1200'], 'uses' => 1],
            ['relation' => 2, 'name' => 'Displayauflösung', 'type' => 'integer', 'target' => 11, 'settings' => ['min' => '1'], 'uses' => 3],
            ['relation' => 3, 'name' => 'Druckaufloesung', 'type' => 'text', 'target' => 12, 'settings' => [], 'uses' => 1],
            ['relation' => 4, 'name' => 'Gewicht', 'type' => 'integer', 'target' => 13, 'settings' => ['min' => '1', 'max' => '1200'], 'uses' => 2],
            ['relation' => 5, 'name' => 'Farbe', 'type' => 'integer', 'target' => 14, 'settings' => [], 'uses' => 9],
        ];
    }

    #[Test]
    public function names_are_folded_before_they_are_compared(): void
    {
        self::assertSame('maxgroesse', SimilarFields::folded('Max. Größe'));
        self::assertSame(1.0, SimilarFields::nameSimilarity(SimilarFields::folded('Druckauflösung'), SimilarFields::folded('druckaufloesung')));
    }

    #[Test]
    public function another_type_is_never_similar(): void
    {
        $found = (new SimilarFields())->among(['type' => 'integer', 'name' => 'Druckauflösung'], self::fields(), 10);

        self::assertNotContains(3, array_column($found, 'relation'));
        self::assertSame(1, $found[0]['relation']);
    }

    #[Test]
    public function equal_settings_find_a_field_whatever_its_name(): void
    {
        $found = (new SimilarFields())->among(['type' => 'integer', 'name' => 'Druckauflösung', 'settings' => ['min' => '1', 'max' => '1200']], self::fields(), 10);

        self::assertSame([1, 4], array_column($found, 'relation'));
        self::assertSame(['min', 'max'], $found[1]['matching_settings']);
        self::assertSame([], $found[1]['differing_settings']);
    }

    #[Test]
    public function an_unlike_name_without_matching_settings_is_left_out(): void
    {
        $found = (new SimilarFields())->among(['type' => 'integer', 'name' => 'Farbtiefe'], self::fields(), 10);

        self::assertNotContains(4, array_column($found, 'relation'));
    }

    #[Test]
    public function on_equal_score_the_more_used_field_comes_first(): void
    {
        $found = (new SimilarFields())->among(['type' => 'integer', 'settings' => ['min' => '1']], self::fields(), 10);

        self::assertSame([2, 4, 1], array_column($found, 'relation'));
    }
}
