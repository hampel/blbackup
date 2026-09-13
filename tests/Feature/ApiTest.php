<?php

use App\Providers\AppServiceProvider;
use Hampel\BinaryLane\Api\Laravel\BinaryLaneManager;
use Illuminate\Support\Facades\Http;

/*
| The BinaryLane client and the timezone are wired up outside any command, and
| every command depends on both. Nothing else asserts them: the API fakes match
| on the request path, so a wrong base url or a missing token changes nothing a
| command test can see.
*/

it('sends the configured token to the binarylane v2 api', function () {
    // BinaryLane's own host, which is the client's default - the suite pins an
    // unresolvable one everywhere else, so this is the one test that the default
    // is really the API
    config(['binarylane.base_uri' => null]);
    fakeApi([]);

    $this->artisan('account')->assertSuccessful();

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer test-token')
        && str_starts_with($request->url(), 'https://api.binarylane.com.au/v2/'));
});

it('applies the configured timezone as the default', function () {
    config(['blbackup.timezone' => 'America/New_York']);

    // the provider booted with the .env value when the test application was
    // created, so it has to be re-run to see the config set above
    (new AppServiceProvider($this->app))->boot();

    expect(date_default_timezone_get())->toBe('America/New_York')
        ->and(now()->timezone->getName())->toBe('America/New_York');
});

it('fakes a client that was built before the fakes were swapped', function () {
    // the client keeps the HTTP factory it was built with, and fakeApi() swaps in a
    // new one. Without fakeApi() forgetting the client, the second answer below is
    // still the first - and with no fake left on the old factory the request goes
    // out for real, past preventStrayRequests()
    fakeApi([fakeServer(['name' => 'first.example.com'])]);

    expect(app(BinaryLaneManager::class)->servers()->list()->items[0]->name)->toBe('first.example.com');

    fakeApi([fakeServer(['name' => 'second.example.com'])]);

    expect(app(BinaryLaneManager::class)->servers()->list()->items[0]->name)->toBe('second.example.com');
});
