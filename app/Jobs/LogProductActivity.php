<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class LogProductActivity implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public readonly string $eventId;

    public int $backoff = 10;

    /**
     * @param  array<string, int|float|string|null>  $before
     * @param  array<string, int|float|string|null>  $after
     */
    public function __construct(
        public readonly int $productId,
        public readonly string $action,
        public readonly ?int $userId,
        public readonly string $occurredAt,
        public readonly array $before,
        public readonly array $after,
    ) {
        $this->eventId = (string) Str::uuid();
        $this->afterCommit();
    }

    public function handle(): void
    {
        DB::transaction(function (): void {
            $inserted = DB::table('product_activity_events')->insertOrIgnore([
                'event_id' => $this->eventId,
                'product_id' => $this->productId,
                'action' => $this->action,
                'user_id' => $this->userId,
                'occurred_at' => $this->occurredAt,
                'before' => json_encode($this->before, JSON_THROW_ON_ERROR),
                'after' => json_encode($this->after, JSON_THROW_ON_ERROR),
            ]);
            if (! $inserted) {
                return;
            }

            Log::info(match ($this->action) {
                'created' => 'Produto criado.',
                'updated' => 'Produto atualizado.',
                'deleted' => 'Produto excluído.',
            }, [
                'event_id' => $this->eventId,
                'product_id' => $this->productId,
                'action' => $this->action,
                'user_id' => $this->userId,
                'occurred_at' => $this->occurredAt,
                'before' => $this->before,
                'after' => $this->after,
            ]);
        });
    }
}
