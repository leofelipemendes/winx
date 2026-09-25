<?php

namespace Tests\Feature\Repositories;

use App\Models\Product;
use App\Repositories\Contracts\ProductRepositoryInterface;
use App\Repositories\Eloquent\ProductRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductRepositoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_contract_resolves_to_eloquent_implementation(): void
    {
        $this->assertInstanceOf(ProductRepository::class, $this->app->make(ProductRepositoryInterface::class));
    }

    public function test_product_crud_preserves_price_precision_and_optional_description(): void
    {
        $repository = $this->app->make(ProductRepositoryInterface::class);
        $attributes = Product::factory()->raw(['price' => '1234567890.12', 'description' => null, 'stock' => 0]);
        $product = $repository->create($attributes);

        $this->assertSame('1234567890.12', $repository->findOrFail($product->id)->price);
        $this->assertNull($product->description);
        $this->assertSame(0, $product->stock);

        $updated = $repository->update($product->id, ['price' => '19.90', 'stock' => 5]);
        $this->assertSame('19.90', $updated->price);
        $this->assertSame(5, $updated->stock);
        $this->assertSame($attributes['name'], $updated->name);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock' => 5]);
        $this->assertTrue($repository->delete($product->id));
        $this->assertNull($repository->find($product->id));
    }

    public function test_combined_filters_and_pagination(): void
    {
        $matching = Product::factory()->count(3)->create(['name' => 'Keyboard', 'price' => '50.00', 'stock' => 2]);
        Product::factory()->create(['name' => 'Mouse', 'price' => '50.00', 'stock' => 2]);
        Product::factory()->create(['name' => 'Keyboard', 'price' => '49.99', 'stock' => 2]);
        Product::factory()->create(['name' => 'Keyboard', 'price' => '50.01', 'stock' => 2]);
        Product::factory()->create(['name' => 'Keyboard', 'price' => '50.00', 'stock' => 0]);
        $repository = $this->app->make(ProductRepositoryInterface::class);

        $page = $repository->search([
            'name' => ' key ',
            'min_price' => '50.00',
            'max_price' => '50.00',
            'in_stock' => true,
        ], perPage: 2, page: 2);

        $this->assertSame(3, $page->total());
        $this->assertSame([$matching->last()->id], $page->getCollection()->pluck('id')->all());
        $this->assertSame(7, $repository->search()->total());
        $this->assertSame(7, $repository->paginate()->total());
    }

    public function test_false_and_zero_filters_are_not_discarded(): void
    {
        $free = Product::factory()->create(['price' => '0.00', 'stock' => 0]);
        Product::factory()->create(['price' => '1.00', 'stock' => 0]);
        Product::factory()->create(['price' => '0.00', 'stock' => 1]);
        $repository = $this->app->make(ProductRepositoryInterface::class);

        $page = $repository->search(['max_price' => '0.00', 'in_stock' => false]);

        $this->assertSame([$free->id], $page->getCollection()->pluck('id')->all());
        $this->assertSame(0, $repository->search(['name' => 'Nonexistent'])->total());
        $this->assertSame(3, $repository->search(['name' => '   '])->total());
    }
}
