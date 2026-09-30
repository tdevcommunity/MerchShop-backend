<?php

namespace Tests\Unit\Support\Api;

use App\Support\Api\CamelCase;
use Illuminate\Contracts\Support\Arrayable;
use Tests\TestCase;

class CamelCaseTest extends TestCase
{
    public function test_it_converts_nested_keys_to_camel_case(): void
    {
        $converted = CamelCase::keys([
            'product_name' => 'T-shirt',
            'stock_quantity' => 12,
            'created_at' => '2026-09-29T10:00:00+00:00',
            'product_variants' => [
                ['variant_id' => 7, 'size_label' => 'XL'],
            ],
        ]);

        $this->assertSame([
            'productName' => 'T-shirt',
            'stockQuantity' => 12,
            'createdAt' => '2026-09-29T10:00:00+00:00',
            'productVariants' => [
                ['variantId' => 7, 'sizeLabel' => 'XL'],
            ],
        ], $converted);
    }

    public function test_it_preserves_list_indexes(): void
    {
        $converted = CamelCase::keys([0 => ['unit_price' => 1500], 1 => ['unit_price' => 2500]]);

        $this->assertSame([0 => ['unitPrice' => 1500], 1 => ['unitPrice' => 2500]], $converted);
    }

    public function test_it_unwraps_arrayable_values(): void
    {
        $converted = CamelCase::keys([
            'order_items' => new class implements Arrayable
            {
                public function toArray(): array
                {
                    return ['order_item_id' => 3];
                }
            },
        ]);

        $this->assertSame(['orderItems' => ['orderItemId' => 3]], $converted);
    }

    public function test_it_leaves_scalar_values_untouched_except_keys(): void
    {
        $this->assertSame(
            ['amount' => 1500, 'label' => 'T-shirt', 'isAvailable' => true, 'tags' => null],
            CamelCase::keys(['amount' => 1500, 'label' => 'T-shirt', 'is_available' => true, 'tags' => null]),
        );
    }
}
