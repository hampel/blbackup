<?php

namespace App\Providers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Request;
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
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }
}
