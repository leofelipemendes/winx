<?php

namespace Tests\Feature\Products;

use App\Http\Requests\Products\IndexProductRequest;
use App\Http\Requests\Products\StoreProductRequest;
use App\Http\Requests\Products\UpdateProductRequest;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProductRequestsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::post('/api/test-products', fn (StoreProductRequest $request) => $request->validated());
        Route::patch('/api/test-products/1', fn (UpdateProductRequest $request) => $request->validated());
        Route::get('/api/test-products', fn (IndexProductRequest $request) => [
            'filters' => $request->filters(),
            'per_page' => $request->integer('per_page', 15),
            'page' => $request->integer('page', 1),
        ]);
    }

    public function test_store_requires_name_price_and_stock(): void
    {
        $this->postJson('/api/test-products')->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'price', 'stock']);
    }

    public function test_store_accepts_zero_and_excludes_unvalidated_fields(): void
    {
        $this->postJson('/api/test-products', [
            'name' => 'Product', 'price' => '0.00', 'stock' => 0, 'id' => 900,
        ])->assertOk()->assertExactJson(['name' => 'Product', 'price' => '0.00', 'stock' => 0]);
    }

    public function test_update_accepts_partial_data_and_nullable_description(): void
    {
        $this->patchJson('/api/test-products/1', ['description' => null])
            ->assertOk()->assertExactJson(['description' => null]);
        $this->patchJson('/api/test-products/1', ['stock' => 0])
            ->assertOk()->assertExactJson(['stock' => 0]);
    }

    #[DataProvider('invalidProductValues')]
    public function test_store_and_update_reject_invalid_values(string $field, mixed $value): void
    {
        $payload = array_replace(['name' => 'Product', 'price' => '10.00', 'stock' => 1], [$field => $value]);

        $this->postJson('/api/test-products', $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->patchJson('/api/test-products/1', [$field => $value])->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public static function invalidProductValues(): array
    {
        return [
            ['name', null], ['name', '   '], ['name', str_repeat('x', 256)],
            ['description', ['invalid']], ['price', null], ['price', '-0.01'],
            ['price', '1.999'], ['price', '10000000000.00'], ['price', 'invalid'],
            ['stock', null], ['stock', -1], ['stock', 1.5], ['stock', 2147483648],
        ];
    }

    public function test_filters_normalize_false_and_preserve_zero(): void
    {
        $this->getJson('/api/test-products?in_stock=false&min_price=0&max_price=0&per_page=2&page=3&ignored=x')
            ->assertOk()->assertExactJson([
                'filters' => ['in_stock' => false, 'min_price' => '0', 'max_price' => '0'],
                'per_page' => 2, 'page' => 3,
            ]);
        $this->getJson('/api/test-products?in_stock=0')->assertOk()->assertJsonPath('filters.in_stock', false);
        $this->getJson('/api/test-products?in_stock=true')->assertOk()->assertJsonPath('filters.in_stock', true);
        $this->getJson('/api/test-products')->assertOk()->assertJsonPath('per_page', 15)->assertJsonPath('page', 1);
    }

    #[DataProvider('invalidFilters')]
    public function test_invalid_filters_return_validation_errors(string $query, string $field): void
    {
        $this->getJson('/api/test-products?'.$query)->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public static function invalidFilters(): array
    {
        return [
            ['min_price=10&max_price=9', 'max_price'], ['min_price=-1', 'min_price'],
            ['max_price=1.999', 'max_price'], ['in_stock=invalid', 'in_stock'],
            ['in_stock=', 'in_stock'], ['per_page=101', 'per_page'],
            ['per_page=0', 'per_page'], ['page=0', 'page'], ['page=1.5', 'page'],
        ];
    }
}
