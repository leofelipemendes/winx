<?php

namespace App\Observers;

use App\Jobs\LogProductActivity;
use App\Jobs\SyncProductSearch;
use App\Models\Product;
use Illuminate\Support\Facades\Auth;

class ProductObserver
{
    public function created(Product $product): void
    {
        LogProductActivity::dispatch(
            (int) $product->getKey(), 'created', Auth::id(), now()->toIso8601String(),
            [], $this->attributes($product),
        );
        SyncProductSearch::dispatch((int) $product->getKey());
    }

    public function updated(Product $product): void
    {
        $changed = array_intersect_key($product->getChanges(), $this->attributes($product));
        if ($changed !== []) {
            LogProductActivity::dispatch(
                (int) $product->getKey(), 'updated', Auth::id(), now()->toIso8601String(),
                array_intersect_key($product->getRawOriginal(), $changed), $changed,
            );
            SyncProductSearch::dispatch((int) $product->getKey());
        }
    }

    public function deleted(Product $product): void
    {
        LogProductActivity::dispatch(
            (int) $product->getKey(), 'deleted', Auth::id(), now()->toIso8601String(),
            $this->attributes($product), [],
        );
        SyncProductSearch::dispatch((int) $product->getKey());
    }

    /** @return array<string, int|float|string|null> */
    private function attributes(Product $product): array
    {
        return array_intersect_key($product->getAttributes(), array_flip(['name', 'description', 'category', 'price', 'stock']));
    }
}
