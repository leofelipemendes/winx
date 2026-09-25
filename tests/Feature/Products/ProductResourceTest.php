<?php

namespace Tests\Feature\Products;

use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ProductResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_resource_exposes_only_public_fields_and_decimal_price(): void
    {
        $product = Product::factory()->create(['price' => '19.90', 'description' => null, 'stock' => 0])->fresh();
        $product->setAttribute('internal_field', 'private');
        Route::get('/api/test-product-resource', fn () => new ProductResource($product));

        $this->getJson('/api/test-product-resource')->assertOk()->assertExactJson([
            'data' => [
                'id' => $product->id,
                'name' => $product->name,
                'description' => null,
                'category' => $product->category,
                'price' => '19.90',
                'stock' => 0,
                'created_at' => $product->created_at->toISOString(),
                'updated_at' => $product->updated_at->toISOString(),
            ],
        ]);
    }

    public function test_paginated_collection_includes_links_and_metadata(): void
    {
        Product::factory()->count(3)->create();
        Route::get('/api/test-product-resources', fn () => ProductResource::collection(
            Product::query()->orderBy('id')->paginate(2),
        ));

        $this->getJson('/api/test-product-resources')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonStructure(['data', 'links' => ['first', 'last', 'prev', 'next'], 'meta']);
    }
}
