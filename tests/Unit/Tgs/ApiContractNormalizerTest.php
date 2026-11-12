<?php

declare(strict_types=1);

namespace ApiMedia\Tgs\Tests\Unit\Tgs;

use ApiMedia\Tgs\Tests\Fixtures\ApiContractNormalizer;
use ApiMedia\Tgs\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class ApiContractNormalizerTest extends TestCase
{
    #[Test]
    public function scalars_become_their_type_name(): void
    {
        $this->assertSame('string', ApiContractNormalizer::normalize('hello'));
        $this->assertSame('int', ApiContractNormalizer::normalize(42));
        $this->assertSame('float', ApiContractNormalizer::normalize(4.2));
        $this->assertSame('true', ApiContractNormalizer::normalize(true));
        $this->assertSame('false', ApiContractNormalizer::normalize(false));
        $this->assertSame('null', ApiContractNormalizer::normalize(null));
    }

    #[Test]
    public function true_and_false_normalize_differently_so_a_boolean_flip_is_visible(): void
    {
        $this->assertNotSame(
            ApiContractNormalizer::normalize(true),
            ApiContractNormalizer::normalize(false),
        );
    }

    #[Test]
    public function associative_array_keeps_keys_and_order_while_normalizing_values(): void
    {
        $input = ['id' => 'abc-123', 'title' => 'Hello', 'is_open' => true, 'count' => 3, 'extra' => null];

        $this->assertSame(
            ['id' => 'string', 'title' => 'string', 'is_open' => 'true', 'count' => 'int', 'extra' => 'null'],
            ApiContractNormalizer::normalize($input),
        );
    }

    #[Test]
    public function nested_structures_are_normalized_recursively(): void
    {
        $input = [
            'type' => 'column',
            'data' => [
                'id' => 'xyz',
                'content' => [
                    ['type' => 'content', 'data' => ['content' => '<p>a</p>']],
                ],
            ],
        ];

        $this->assertSame(
            [
                'type' => 'string',
                'data' => [
                    'id' => 'string',
                    'content' => [
                        ['type' => 'string', 'data' => ['content' => 'string']],
                    ],
                ],
            ],
            ApiContractNormalizer::normalize($input),
        );
    }

    #[Test]
    public function a_list_of_identically_shaped_items_collapses_to_one_representative(): void
    {
        $input = [
            ['url' => 'https://a', 'width' => 100],
            ['url' => 'https://b', 'width' => 200],
            ['url' => 'https://c', 'width' => 300],
        ];

        $this->assertSame(
            [['url' => 'string', 'width' => 'int']],
            ApiContractNormalizer::normalize($input),
        );
    }

    #[Test]
    public function a_list_of_differently_shaped_items_is_left_untouched(): void
    {
        $input = [
            ['type' => 'content', 'data' => ['content' => 'x']],
            ['type' => 'picture', 'data' => ['alt' => 'y', 'is_lightbox' => true]],
        ];

        $this->assertSame(
            [
                ['type' => 'string', 'data' => ['content' => 'string']],
                ['type' => 'string', 'data' => ['alt' => 'string', 'is_lightbox' => 'true']],
            ],
            ApiContractNormalizer::normalize($input),
        );
    }

    #[Test]
    public function empty_list_stays_an_empty_list(): void
    {
        $this->assertSame([], ApiContractNormalizer::normalize([]));
    }
}
