<?php

namespace App\Providers;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Client::class, function (): Client {
            $builder = ClientBuilder::create()
                ->setHosts(config('elasticsearch.hosts'))
                ->setRetries(0)
                ->setHttpClientOptions(['timeout' => 5, 'connect_timeout' => 2]);
            if (config('elasticsearch.api_key')) {
                $builder->setApiKey(config('elasticsearch.api_key'));
            }

            return $builder->build();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
