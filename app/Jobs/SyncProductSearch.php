<?php

namespace App\Jobs;

use App\ProductSearch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncProductSearch implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 30;

    public function __construct(public readonly int $productId)
    {
        $this->afterCommit();
        $this->onConnection(config('elasticsearch.connection'));
        $this->onQueue(config('elasticsearch.queue'));
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [5, 15, 60, 120];
    }

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('product-search:'.$this->productId))->releaseAfter(5)->expireAfter(60)];
    }

    public function handle(ProductSearch $search): void
    {
        $search->synchronize([$this->productId]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Falha na indexação de produto.', ['product_id' => $this->productId, 'exception_type' => $exception ? $exception::class : null]);
    }
}
