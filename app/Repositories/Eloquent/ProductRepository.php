<?php

namespace App\Repositories\Eloquent;

use App\Models\Product;
use App\ProductSearch;
use App\Repositories\Contracts\ProductRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/** @extends BaseRepository<Product> */
class ProductRepository extends BaseRepository implements ProductRepositoryInterface
{
    public function __construct(Product $model)
    {
        parent::__construct($model);
    }

    public function search(array $filters = [], int $perPage = 15, int $page = 1): LengthAwarePaginator
    {
        if (isset($filters['q']) && trim($filters['q']) !== '') {
            return app(ProductSearch::class)->search($filters, $perPage, $page);
        }

        return $this->paginateQuery(ProductSearch::filterQuery($this->query(), $filters), $perPage, $page);
    }
}
