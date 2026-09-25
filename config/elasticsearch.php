<?php

return [
    'hosts' => explode(',', env('ELASTICSEARCH_HOSTS', 'http://elasticsearch:9200')),
    'api_key' => env('ELASTICSEARCH_API_KEY'),
    'index' => env('ELASTICSEARCH_INDEX', 'products_v1'),
    'queue' => env('ELASTICSEARCH_QUEUE', 'search'),
    'connection' => env('ELASTICSEARCH_QUEUE_CONNECTION', 'database'),
];
