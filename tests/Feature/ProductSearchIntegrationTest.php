<?php

namespace Tests\Feature;

use App\Jobs\SyncProductSearch;
use App\Models\Product;
use App\Models\User;
use App\ProductSearch;
use Elastic\Elasticsearch\Client;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('elasticsearch')]
class ProductSearchIntegrationTest extends TestCase
{
    use DatabaseMigrations;

    private ?string $index = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('RUN_ELASTICSEARCH_TESTS') !== '1') {
            $this->markTestSkipped('Set RUN_ELASTICSEARCH_TESTS=1 with Elasticsearch 9.1 running.');
        }
        $this->index = 'test_products_'.Str::uuid();
        config(['elasticsearch.index' => $this->index, 'queue.default' => 'database']);
        app(ProductSearch::class)->createIndex();
    }

    protected function tearDown(): void
    {
        try {
            if ($this->index !== null) {
                app(Client::class)->indices()->delete(['index' => $this->index]);
            }
        } finally {
            parent::tearDown();
        }
    }

    private function processSearchQueue(): void
    {
        $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'search', '--stop-when-empty' => true, '--tries' => 1])->assertSuccessful();
        $this->assertDatabaseCount('failed_jobs', 0);
        app(Client::class)->indices()->refresh(['index' => $this->index]);
    }

    public function test_real_mapping_full_text_ranking_prefix_filters_and_pagination(): void
    {
        $description = Product::factory()->create(['name' => 'Acessório', 'description' => 'Teclado confortável', 'category' => 'Hardware', 'price' => '50.00', 'stock' => 2]);
        $name = Product::factory()->create(['name' => 'Teclado mecânico', 'description' => 'Iluminado', 'category' => 'Hardware', 'price' => '50.00', 'stock' => 2]);
        $exact = Product::factory()->create(['name' => 'Teclado', 'description' => null, 'category' => 'Hardware', 'price' => '50.00', 'stock' => 2]);
        Product::factory()->create(['name' => 'Livro', 'description' => 'Romance', 'category' => 'Livros', 'stock' => 0]);
        $this->processSearchQueue();
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/products?q=teclado&per_page=1')->assertOk()->assertJsonPath('meta.total', 3)->assertJsonPath('data.0.id', $exact->id);
        $this->getJson('/api/products?q=teclado&per_page=1&page=2')->assertOk()->assertJsonPath('data.0.id', $name->id);
        $this->getJson('/api/products?q=teclado&per_page=1&page=3')->assertOk()->assertJsonPath('data.0.id', $description->id);
        $this->getJson('/api/products?q=confortável')->assertOk()->assertJsonPath('data.0.id', $description->id);
        $this->getJson('/api/products?q=mecanico')->assertOk()->assertJsonPath('data.0.id', $name->id);
        $this->getJson('/api/products/suggestions?q=tec&category=Hardware&min_price=50&max_price=50&in_stock=true')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/products/suggestions?q=tec&in_stock=false')->assertOk()->assertExactJson(['data' => []]);
        $this->getJson('/api/products?q=teclado&category=Livros')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/products?q=teclado&min_price=50.01')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/products?q=teclado&max_price=49.99')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/products?q=teclado&in_stock=false')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_worker_uses_current_state_and_removes_deleted_products_with_retries(): void
    {
        $product = Product::factory()->create(['name' => 'Antigo']);
        $oldJob = new SyncProductSearch($product->id);
        $product->update(['name' => 'Novo']);
        $this->processSearchQueue();
        $oldJob->handle(app(ProductSearch::class));
        $this->assertSame('Novo', app(Client::class)->get(['index' => $this->index, 'id' => $product->id])->asArray()['_source']['name']);

        $product->delete();
        $this->processSearchQueue();
        $oldJob->handle(app(ProductSearch::class));
        $this->assertFalse(app(Client::class)->exists(['index' => $this->index, 'id' => $product->id])->asBool());
    }

    public function test_import_reconciles_bulk_changes_without_deleting_existing_index(): void
    {
        $kept = Product::factory()->create(['name' => 'Original']);
        $deleted = Product::factory()->create();
        $this->processSearchQueue();
        DB::table('products')->where('id', $kept->id)->update(['name' => 'Importado']);
        DB::table('products')->where('id', $deleted->id)->delete();

        $this->artisan('products:search-create')->assertSuccessful();
        $this->artisan('products:search-import', ['--chunk' => 1, '--reconcile' => true])->assertSuccessful();

        $this->assertSame('Importado', app(Client::class)->get(['index' => $this->index, 'id' => $kept->id])->asArray()['_source']['name']);
        $this->assertFalse(app(Client::class)->exists(['index' => $this->index, 'id' => $deleted->id])->asBool());
    }
}
