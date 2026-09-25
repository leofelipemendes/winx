<?php

namespace App\Repositories\Contracts;

use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/** @extends RepositoryInterface<Product> */
interface ProductRepositoryInterface extends RepositoryInterface
{
    /**
     * @param  array{q?: string, category?: string, name?: string, min_price?: string, max_price?: string, in_stock?: bool}  $filters
     * @return LengthAwarePaginator<int, Product>
     */
    public function search(array $filters = [], int $perPage = 15, int $page = 1): LengthAwarePaginator;
}
