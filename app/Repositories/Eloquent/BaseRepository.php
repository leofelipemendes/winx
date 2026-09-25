<?php

namespace App\Repositories\Eloquent;

use App\Repositories\Contracts\RepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * @template TModel of Model
 *
 * @implements RepositoryInterface<TModel>
 */
abstract class BaseRepository implements RepositoryInterface
{
    /** @param TModel $model */
    public function __construct(protected readonly Model $model) {}

    /** @return Builder<TModel> */
    protected function query(): Builder
    {
        return $this->model->newQuery();
    }

    public function paginate(int $perPage = 15, int $page = 1): LengthAwarePaginator
    {
        return $this->paginateQuery($this->query(), $perPage, $page);
    }

    /**
     * @param  Builder<TModel>  $query
     * @return LengthAwarePaginator<int, TModel>
     */
    protected function paginateQuery(Builder $query, int $perPage, int $page): LengthAwarePaginator
    {
        if ($perPage < 1 || $perPage > 100 || $page < 1) {
            throw new InvalidArgumentException('Per page must be between 1 and 100, and page must be positive.');
        }

        return $query->orderBy($this->model->getQualifiedKeyName())
            ->paginate(perPage: $perPage, page: $page);
    }

    public function find(int $id): ?Model
    {
        return $this->query()->find($id);
    }

    public function findOrFail(int $id): Model
    {
        return $this->query()->findOrFail($id);
    }

    public function create(array $attributes): Model
    {
        return $this->query()->create($attributes);
    }

    public function update(int $id, array $attributes): Model
    {
        $model = $this->findOrFail($id);
        $model->fill($attributes);
        $model->saveOrFail();

        return $model;
    }

    public function delete(int $id): bool
    {
        return (bool) $this->findOrFail($id)->deleteOrFail();
    }
}
