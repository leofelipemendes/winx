<?php

namespace App;

use App\Models\Product;
use Elastic\Elasticsearch\Client;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Throwable;

class ProductSearch
{
    public function __construct(private readonly Client $client) {}

    public function createIndex(): void
    {
        $index = config('elasticsearch.index');
        if ($this->client->indices()->exists(['index' => $index])->asBool()) {
            return;
        }

        $this->client->indices()->create(['index' => $index, 'body' => [
            'settings' => [
                'number_of_shards' => 1, 'number_of_replicas' => 0,
                'analysis' => ['normalizer' => ['folded' => [
                    'type' => 'custom', 'filter' => ['lowercase', 'asciifolding'],
                ]]],
            ],
            'mappings' => ['dynamic' => 'strict', 'properties' => [
                'id' => ['type' => 'long'],
                'name' => ['type' => 'text', 'analyzer' => 'portuguese', 'fields' => [
                    'exact' => ['type' => 'keyword', 'normalizer' => 'folded'],
                    'suggest' => ['type' => 'search_as_you_type', 'analyzer' => 'standard'],
                ]],
                'description' => ['type' => 'text', 'analyzer' => 'portuguese'],
                'category' => ['type' => 'keyword'],
                'price' => ['type' => 'scaled_float', 'scaling_factor' => 100],
                'stock' => ['type' => 'integer'],
            ]],
        ]]);
    }

    /** @param array{q?: string, name?: string, category?: string, min_price?: string, max_price?: string, in_stock?: bool} $filters */
    public function search(array $filters, int $perPage = 15, int $page = 1, bool $suggest = false): LengthAwarePaginator
    {
        if ($perPage < 1 || $perPage > 100 || $page < 1 || $page * $perPage > 10000) {
            throw ValidationException::withMessages(['page' => 'A busca permite consultar até 10000 resultados.']);
        }

        $text = trim($filters['q'] ?? '');
        $clauses = [];
        foreach (['category'] as $field) {
            if (isset($filters[$field])) {
                $clauses[] = ['term' => [$field => $filters[$field]]];
            }
        }
        foreach (['min_price' => 'gte', 'max_price' => 'lte'] as $field => $operator) {
            if (isset($filters[$field])) {
                $clauses[] = ['range' => ['price' => [$operator => $filters[$field]]]];
            }
        }
        if (isset($filters['in_stock'])) {
            $clauses[] = ['range' => ['stock' => [$filters['in_stock'] ? 'gt' : 'lte' => 0]]];
        }
        if (isset($filters['name'])) {
            $literal = str_replace(['\\', '*', '?'], ['\\\\', '\\*', '\\?'], $filters['name']);
            $clauses[] = ['wildcard' => ['name.exact' => ['value' => '*'.$literal.'*', 'case_insensitive' => true]]];
        }
        $matches = $suggest ? [
            ['multi_match' => ['query' => $text, 'type' => 'bool_prefix', 'operator' => 'and',
                'fields' => ['name.suggest', 'name.suggest._2gram', 'name.suggest._3gram']]],
        ] : [
            ['multi_match' => ['query' => $text, 'fields' => ['name^5', 'description']]],
            ['match' => ['name.exact' => ['query' => $text, 'boost' => 20]]],
        ];

        try {
            $result = $this->client->search(['index' => config('elasticsearch.index'), 'body' => [
                'from' => ($page - 1) * $perPage, 'size' => $perPage,
                '_source' => false, 'track_total_hits' => true,
                'sort' => [['_score' => 'desc'], ['id' => 'asc']],
                'query' => ['bool' => ['filter' => $clauses, 'should' => $matches, 'minimum_should_match' => 1]],
            ]])->asArray();
            if (($result['timed_out'] ?? false) || ($result['_shards']['failed'] ?? 0) > 0) {
                throw new RuntimeException('Incomplete Elasticsearch search response.');
            }
        } catch (Throwable $exception) {
            Log::warning('Busca de produtos indisponível.', ['exception_type' => $exception::class]);
            throw new ServiceUnavailableHttpException(5, 'Busca indisponível.', $exception);
        }

        $ids = array_column($result['hits']['hits'], '_id');
        $products = self::filterQuery(Product::query(), $filters)->whereKey($ids)->get()->keyBy('id');
        $ordered = collect($ids)->map(fn (string $id): ?Product => $products->get($id))->filter()->values();

        return new LengthAwarePaginator($ordered, $result['hits']['total']['value'], $perPage, $page, [
            'path' => LengthAwarePaginator::resolveCurrentPath(),
        ]);
    }

    /**
     * @param  Builder<Product>  $query
     * @param  array{name?: string, category?: string, min_price?: string, max_price?: string, in_stock?: bool, q?: string}  $filters
     * @return Builder<Product>
     */
    public static function filterQuery(Builder $query, array $filters): Builder
    {
        if (isset($filters['name']) && trim($filters['name']) !== '') {
            $query->whereLike('name', '%'.trim($filters['name']).'%');
        }
        if (isset($filters['category'])) {
            $query->where('category', $filters['category']);
        }
        if (isset($filters['min_price'])) {
            $query->where('price', '>=', $filters['min_price']);
        }
        if (isset($filters['max_price'])) {
            $query->where('price', '<=', $filters['max_price']);
        }
        if (isset($filters['in_stock'])) {
            $query->where('stock', $filters['in_stock'] ? '>' : '=', 0);
        }

        return $query;
    }

    /** @param iterable<int> $ids */
    public function synchronize(iterable $ids): void
    {
        if (! $this->client->indices()->exists(['index' => config('elasticsearch.index')])->asBool()) {
            throw new RuntimeException('Product index is missing. Run products:search-create before importing or starting the search worker.');
        }

        $body = [];
        foreach ($ids as $id) {
            $product = Product::find($id);
            $metadata = ['_index' => config('elasticsearch.index'), '_id' => (string) $id];
            if ($product === null) {
                $body[] = ['delete' => $metadata];
            } else {
                $body[] = ['index' => $metadata];
                $body[] = $product->only(['id', 'name', 'description', 'category', 'price', 'stock']);
            }
        }
        if ($body === []) {
            return;
        }
        $result = $this->client->bulk(['body' => $body])->asArray();
        foreach ($result['items'] as $item) {
            $operation = array_key_first($item);
            $status = $item[$operation]['status'];
            if ($status >= 300 && ! ($operation === 'delete' && $status === 404 && ! isset($item[$operation]['error']))) {
                throw new RuntimeException('Elasticsearch bulk failure for product '.$item[$operation]['_id'].' ('.$status.').');
            }
        }
    }
}
