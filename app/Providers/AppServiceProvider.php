<?php

namespace App\Providers;

use App\Support\RunSummary;
use App\Support\SlackSummary;
use GuzzleHttp\Client;
use Hampel\SlackMessage\SlackWebhook;
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

        // shared for the same reason wback shares its: the stages of a run are
        // separate command objects - create calls download, download calls move -
        // and the run is what is being summarised rather than any one of them
        $this->app->singleton(RunSummary::class);

        // bound rather than constructed where it is used, so a test can hand the
        // sender a client that answers without a network.
        //
        // http_errors off because Slack reports a refusal two different ways - a
        // status from a webhook, an "ok" field from the api - and the sender reads
        // both. The timeouts are there because this runs at the tail of a backup:
        // a webhook that has stopped answering should cost seconds, not the night.
        $this->app->singleton(SlackWebhook::class, fn () => new SlackWebhook(new Client([
            'http_errors' => false,
            'connect_timeout' => 5,
            'timeout' => 15,
        ])));

        // handed what it needs rather than reading it, so the reporter stays
        // usable somewhere config() and app() do not exist
        $this->app->singleton(SlackSummary::class, fn () => new SlackSummary(
            $this->app->make(SlackWebhook::class),
            (string) config('binarylane.summary.slack_webhook'),
            (string) config('binarylane.summary.notify'),
            config('app.name') . ' ' . $this->app->version(),
            (string) (config('logging.hostname') ?: gethostname())
        ));
    }
}
