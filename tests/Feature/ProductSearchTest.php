<?php

namespace Tests\Feature;

use App\Jobs\SyncProductSearch;
use App\Models\Product;
use App\Models\User;
use App\ProductSearch;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class ProductSearchTest extends TestCase
{
    use RefreshDatabase;

    private function respond(array $body, int $status = 200): void
    {
        $response = new Response($status, ['X-Elastic-Product' => 'Elasticsearch', 'Content-Type' => 'application/json'], json_encode($body));
        $handler = new MockHandler([$response, $response]);
        $client = ClientBuilder::create()->setHttpClient(new HttpClient(['handler' => HandlerStack::create($handler)]))->setRetries(0)->build();
        $this->app->instance(Client::class, $client);
    }

    public function test_search_preserves_relevance_and_rechecks_current_database_filters(): void
    {
        $first = Product::factory()->create(['stock' => 2]);
        $second = Product::factory()->create(['stock' => 3]);
        $hidden = Product::factory()->create(['stock' => 0]);
        $this->respond(['hits' => ['total' => ['value' => 4], 'hits' => [
            ['_id' => (string) $second->id], ['_id' => (string) $hidden->id], ['_id' => (string) $first->id], ['_id' => '9999'],
        ]]]);
        Sanctum::actingAs(User::factory()->create());

        $response = $this->getJson('/api/products?q=teclado&in_stock=true&per_page=4')->assertOk();

        $this->assertSame([$second->id, $first->id], array_column($response->json('data'), 'id'));
        $response->assertJsonPath('meta.total', 4);
    }

    public function test_search_and_suggestions_require_authentication(): void
    {
        $this->getJson('/api/products?q=teclado')->assertUnauthorized();
        $this->getJson('/api/products/suggestions?q=tec')->assertUnauthorized();
    }

    public function test_invalid_queries_and_deep_pages_are_rejected(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/products?q[]=match_all')->assertUnprocessable()->assertJsonValidationErrors('q');
        $this->getJson('/api/products?q=tec&page=101&per_page=100')->assertUnprocessable()->assertJsonValidationErrors('page');
        $this->getJson('/api/products/suggestions')->assertUnprocessable()->assertJsonValidationErrors('q');
        $this->getJson('/api/products/suggestions?q=tec&per_page=101')->assertUnprocessable();
    }

    public function test_elasticsearch_failure_returns_503_instead_of_empty_results(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->respond(['error' => 'unavailable'], 503);
        $this->getJson('/api/products?q=teclado')->assertServiceUnavailable()->assertHeader('Retry-After', '5');
        $this->respond(['error' => 'unavailable'], 503);
        $this->getJson('/api/products/suggestions?q=tec')->assertServiceUnavailable();
    }

    public function test_partial_search_response_returns_503(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->respond(['timed_out' => true, 'hits' => ['total' => ['value' => 0], 'hits' => []]]);
        $this->getJson('/api/products?q=teclado')->assertServiceUnavailable();
    }

    public function test_bulk_individual_failure_is_not_silently_accepted(): void
    {
        $product = Product::factory()->create();
        $this->respond(['errors' => true, 'items' => [['index' => ['_id' => (string) $product->id, 'status' => 400, 'error' => ['type' => 'mapper_parsing_exception']]]]]);

        $this->expectException(RuntimeException::class);
        (new SyncProductSearch($product->id))->handle(app(ProductSearch::class));
    }

    public function test_already_deleted_document_is_idempotent(): void
    {
        $this->respond(['errors' => false, 'items' => [['delete' => ['_id' => '999', 'status' => 404, 'result' => 'not_found']]]]);
        (new SyncProductSearch(999))->handle(app(ProductSearch::class));
        $this->assertDatabaseCount('products', 0);
    }

    public function test_missing_index_does_not_create_an_index_with_implicit_mapping(): void
    {
        $this->respond([], 404);

        $this->expectExceptionMessage('Product index is missing.');
        app(ProductSearch::class)->synchronize([123]);
    }
}
