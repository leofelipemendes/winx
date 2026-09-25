<?php

namespace Tests\Feature\Products;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create());
    }

    public function test_product_crud(): void
    {
        config(['app.debug' => true]);

        $response = $this->postJson('/api/products', [
            'name' => 'Keyboard', 'price' => '19.90', 'stock' => 5,
        ])->assertCreated()->assertJsonPath('data.price', '19.90');
        $id = $response->json('data.id');

        $this->getJson('/api/products/'.$id)->assertOk()->assertJsonPath('data.name', 'Keyboard');
        $this->patchJson('/api/products/'.$id, ['stock' => 0])->assertOk()->assertJsonPath('data.stock', 0);
        $this->putJson('/api/products/'.$id, ['name' => 'Updated'])->assertOk()->assertJsonPath('data.name', 'Updated');
        $this->deleteJson('/api/products/'.$id)->assertNoContent();
        $this->assertDatabaseMissing('products', ['id' => $id]);
        $this->getJson('/api/products/'.$id)->assertNotFound()->assertExactJson(['message' => 'Produto não encontrado.']);
        $this->patchJson('/api/products/'.$id, ['stock' => 1])->assertNotFound()->assertExactJson(['message' => 'Produto não encontrado.']);
        $this->deleteJson('/api/products/'.$id)->assertNotFound()->assertExactJson(['message' => 'Produto não encontrado.']);
    }

    public function test_unknown_api_route_returns_friendly_404_without_accept_header(): void
    {
        config(['app.debug' => true]);

        $this->get('/api/unknown-route')->assertNotFound()
            ->assertExactJson(['message' => 'Recurso não encontrado.']);
    }

    public function test_unknown_web_route_keeps_html_404(): void
    {
        $this->get('/unknown-route')->assertNotFound()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8');
    }

    public function test_unknown_web_route_returns_friendly_404_when_json_is_requested(): void
    {
        $this->getJson('/unknown-route')->assertNotFound()
            ->assertExactJson(['message' => 'Recurso não encontrado.']);
    }

    public function test_listing_uses_filters_and_pagination(): void
    {
        Product::factory()->count(3)->create(['name' => 'Keyboard', 'stock' => 0]);
        Product::factory()->create(['name' => 'Mouse', 'stock' => 5]);

        $response = $this->getJson('/api/products?name=Keyboard&in_stock=false&per_page=2')
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 3);
        $this->assertStringContainsString('name=Keyboard', $response->json('links.next'));
        $this->getJson($response->json('links.next'))->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_invalid_data_does_not_create_products(): void
    {
        $this->postJson('/api/products', ['price' => -1])->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'price', 'stock']);
        $this->assertDatabaseCount('products', 0);
        $this->getJson('/api/products?per_page=101')->assertUnprocessable();
        $this->getJson('/api/products/invalid')->assertNotFound();
    }

    public function test_combined_filters_preserve_category_in_pagination_links(): void
    {
        $products = Product::factory()->count(3)->create([
            'name' => 'Keyboard', 'category' => 'Electronics', 'price' => '50.00', 'stock' => 2,
        ]);
        Product::factory()->create(['name' => 'Keyboard', 'category' => 'Books', 'price' => '50.00', 'stock' => 2]);
        Product::factory()->create(['name' => 'Keyboard', 'category' => 'Electronics', 'price' => '50.01', 'stock' => 2]);
        Product::factory()->create(['name' => 'Keyboard', 'category' => 'Electronics', 'price' => '50.00', 'stock' => 0]);
        $response = $this->getJson('/api/products?name=key&category=Electronics&min_price=50&max_price=50&in_stock=true&per_page=2')
            ->assertOk()->assertJsonPath('meta.total', 3)->assertJsonCount(2, 'data');
        $this->assertStringContainsString('category=Electronics', $response->json('links.next'));
        $this->getJson($response->json('links.next'))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $products->last()->id);
        $this->getJson('/api/products?category=Nonexistent')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_detail_returns_complete_product_information(): void
    {
        $product = Product::factory()->create(['category' => 'Books'])->fresh();
        $this->getJson('/api/products/'.$product->id)->assertOk()->assertExactJson([
            'data' => [
                'id' => $product->id, 'name' => $product->name,
                'description' => $product->description, 'category' => 'Books',
                'price' => $product->price, 'stock' => $product->stock,
                'created_at' => $product->created_at->toISOString(),
                'updated_at' => $product->updated_at->toISOString(),
            ],
        ]);
    }

    public function test_category_can_be_saved_updated_and_cleared(): void
    {
        $id = $this->postJson('/api/products', [
            'name' => 'Book', 'category' => 'Books', 'price' => '9.90', 'stock' => 1,
        ])->assertCreated()->assertJsonPath('data.category', 'Books')->json('data.id');
        $this->patchJson('/api/products/'.$id, ['category' => 'Education'])->assertOk()->assertJsonPath('data.category', 'Education');
        $this->patchJson('/api/products/'.$id, ['category' => null])->assertOk()->assertJsonPath('data.category', null);
        $this->getJson('/api/products?category[]=invalid')->assertUnprocessable()->assertJsonValidationErrors('category');
    }
}
