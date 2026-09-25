<?php

use App\Models\Product;
use App\ProductSearch;
use Elastic\Elasticsearch\Client;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('products:search-create', function (ProductSearch $search): void {
    $search->createIndex();
    $this->info('Índice disponível; índices existentes foram preservados.');
})->purpose('Create the product search index if absent');

Artisan::command('products:search-import {--chunk=500} {--reconcile : Remove documents whose products no longer exist}', function (ProductSearch $search, Client $client): int {
    $chunk = filter_var($this->option('chunk'), FILTER_VALIDATE_INT);
    if ($chunk === false || $chunk < 1 || $chunk > 1000) {
        $this->error('Chunk deve estar entre 1 e 1000.');

        return 1;
    }
    Product::query()->select('id')->chunkById($chunk, function (Collection $products) use ($search): void {
        $search->synchronize($products->pluck('id')->all());
    });
    if ($this->option('reconcile')) {
        $scrollId = null;
        try {
            $result = $client->search(['index' => config('elasticsearch.index'), 'scroll' => '1m', 'size' => $chunk,
                'body' => ['sort' => ['_doc'], '_source' => false, 'query' => ['match_all' => new stdClass]],
            ])->asArray();
            do {
                $scrollId = $result['_scroll_id'];
                $ids = array_column($result['hits']['hits'], '_id');
                $search->synchronize(array_map('intval', $ids));
                $result = $client->scroll(['scroll_id' => $scrollId, 'scroll' => '1m'])->asArray();
            } while ($result['hits']['hits'] !== []);
        } finally {
            if ($scrollId !== null) {
                $client->clearScroll(['scroll_id' => $scrollId]);
            }
        }
    }
    $client->indices()->refresh(['index' => config('elasticsearch.index')]);
    $this->info('Produtos sincronizados.');

    return 0;
})->purpose('Import products in batches and optionally reconcile deleted products');
