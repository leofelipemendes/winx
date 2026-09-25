<?php

namespace App\Repositories\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;

/** @template TModel of Model */
interface RepositoryInterface
{
    /** @return LengthAwarePaginator<int, TModel> */
    public function paginate(int $perPage = 15, int $page = 1): LengthAwarePaginator;

    /** @return TModel|null */
    public function find(int $id): ?Model;

    /** @return TModel */
    public function findOrFail(int $id): Model;

    /**
     * @param  array<string, mixed>  $attributes
     * @return TModel
     */
    public function create(array $attributes): Model;

    /**
     * @param  array<string, mixed>  $attributes
     * @return TModel
     */
    public function update(int $id, array $attributes): Model;

    public function delete(int $id): bool;
}
