<?php

namespace App\Providers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Http::macro('binarylane', function () : PendingRequest {
            return Http::withToken(config('binarylane.api_token'))->baseUrl('https://api.binarylane.com.au/v2');
        });

        date_default_timezone_set(config('binarylane.timezone', 'UTC'));
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            \Illuminate\Contracts\Log\ContextLogProcessor::class,
            \Illuminate\Log\Context\ContextLogProcessor::class
        );
    }
}
