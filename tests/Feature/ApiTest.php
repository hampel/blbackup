<?php

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Http;

/*
| The Http::binarylane() macro and the timezone are wired up in
| AppServiceProvider, and every command depends on both. Nothing else asserts
| them: the API fakes match on the request path, so a wrong base url or a
| missing token changes nothing a command test can see.
*/

it('sends the configured token to the binarylane v2 api', function () {
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
