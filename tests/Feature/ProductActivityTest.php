<?php

namespace Tests\Feature;

use App\Jobs\LogProductActivity;
use App\Models\Product;
use App\Models\User;
use App\ProductSearch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class ProductActivityTest extends TestCase
{
    use DatabaseMigrations;

    public function test_product_crud_logs_are_written_only_when_the_queue_is_processed(): void
    {
        config(['queue.default' => 'database']);
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $this->freezeTime();
        Log::spy();

        $productId = $this->postJson('/api/products', [
            'name' => 'Teclado', 'price' => '20.00', 'stock' => 5,
        ])->assertCreated()->json('data.id');
        $this->patchJson('/api/products/'.$productId, ['stock' => 3])->assertOk();
        $this->deleteJson('/api/products/'.$productId)->assertNoContent();

        $this->assertDatabaseMissing('products', ['id' => $productId]);
        $this->assertSame(3, DB::table('jobs')->where('queue', 'default')->count());
        Log::shouldNotHaveReceived('info');

        $this->artisan('queue:work', ['--stop-when-empty' => true, '--tries' => 1])->assertSuccessful();

        $this->assertSame(0, DB::table('jobs')->where('queue', 'default')->count());
        $this->assertDatabaseCount('failed_jobs', 0);
        Log::shouldHaveReceived('info')->with('Produto criado.', Mockery::on(
            fn (array $context): bool => $context['product_id'] === $productId
                && $context['user_id'] === $user->id
                && $context['action'] === 'created'
                && $context['occurred_at'] === now()->toIso8601String()
                && $context['before'] === []
                && $context['after']['name'] === 'Teclado'
                && $context['after']['stock'] === 5,
        ))->once();
        Log::shouldHaveReceived('info')->with('Produto atualizado.', Mockery::on(
            fn (array $context): bool => $context['product_id'] === $productId
                && $context['user_id'] === $user->id
                && $context['action'] === 'updated'
                && $context['before']['stock'] === 5
                && $context['after']['stock'] === 3,
        ))->once();
        Log::shouldHaveReceived('info')->with('Produto excluído.', Mockery::on(
            fn (array $context): bool => $context['product_id'] === $productId
                && $context['user_id'] === $user->id
                && $context['action'] === 'deleted'
                && $context['before']['name'] === 'Teclado'
                && $context['before']['stock'] === 3
                && $context['after'] === [],
        ))->once();
    }

    public function test_product_job_is_dispatched_after_commit_without_authenticated_user(): void
    {
        config(['queue.default' => 'database']);
        Log::spy();
        DB::beginTransaction();
        $product = Product::factory()->create();
        $this->assertDatabaseCount('jobs', 0);

        DB::commit();

        $this->assertDatabaseCount('jobs', 2);
        $this->artisan('queue:work', ['--once' => true, '--tries' => 1])->assertSuccessful();
        Log::shouldHaveReceived('info')->with('Produto criado.', Mockery::on(
            fn (array $context): bool => $context['product_id'] === $product->id && $context['user_id'] === null,
        ))->once();
    }

    public function test_rolled_back_product_creation_does_not_queue_a_log(): void
    {
        config(['queue.default' => 'database']);
        DB::beginTransaction();
        Product::factory()->create();

        DB::rollBack();

        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_rejected_product_requests_do_not_queue_logs(): void
    {
        config(['queue.default' => 'database']);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/products', [])->assertUnprocessable();
        $this->patchJson('/api/products/1', ['stock' => 3])->assertNotFound();
        $this->deleteJson('/api/products/1')->assertNotFound();

        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('products', 0);
    }

    public function test_reprocessing_same_audit_job_does_not_duplicate_event_or_log(): void
    {
        Log::spy();
        $job = new LogProductActivity(123, 'deleted', null, now()->toIso8601String(), ['name' => 'Excluído'], []);

        $job->handle();
        unserialize(serialize($job))->handle();

        $this->assertDatabaseCount('product_activity_events', 1);
        $this->assertDatabaseHas('product_activity_events', ['event_id' => $job->eventId, 'product_id' => 123]);
        Log::shouldHaveReceived('info')->once();
    }

    public function test_update_audits_only_changed_fields_and_rollback_dispatches_neither_job(): void
    {
        config(['queue.default' => 'database']);
        $product = Product::factory()->create(['stock' => 5]);
        DB::table('jobs')->delete();
        DB::beginTransaction();
        $product->update(['stock' => 2]);
        $this->assertDatabaseCount('jobs', 0);
        DB::rollBack();
        $this->assertDatabaseCount('jobs', 0);
        $product->refresh()->update(['stock' => 3]);
        $payload = json_decode(DB::table('jobs')->where('queue', 'default')->value('payload'), true);
        $job = unserialize($payload['data']['command']);

        $this->assertSame(['stock' => 5], $job->before);
        $this->assertSame(['stock' => 3], $job->after);
        $this->assertInstanceOf(ShouldQueue::class, $job);
    }

    public function test_rolled_back_deletion_does_not_dispatch_jobs(): void
    {
        config(['queue.default' => 'database']);
        $product = Product::factory()->create();
        DB::table('jobs')->delete();
        DB::beginTransaction();
        $product->delete();
        $this->assertDatabaseCount('jobs', 0);
        DB::rollBack();

        $this->assertDatabaseHas('products', ['id' => $product->id]);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_search_failure_does_not_prevent_audit_processing(): void
    {
        config(['queue.default' => 'database']);
        Product::factory()->create();
        $this->mock(ProductSearch::class)->shouldReceive('synchronize')->times(5)->andThrow(new \RuntimeException('Offline'));
        Log::spy();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            DB::table('jobs')->where('queue', 'search')->update(['available_at' => 0]);
            $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'search', '--once' => true])->assertSuccessful();
        }
        $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'default', '--stop-when-empty' => true, '--tries' => 1])->assertSuccessful();

        $this->assertDatabaseCount('product_activity_events', 1);
        $this->assertDatabaseCount('failed_jobs', 1);
        Log::shouldHaveReceived('info')->once();
    }
}
