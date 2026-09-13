<?php

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Artisan;

/*
| app.version is app('git.version'), which shells out to `git describe`. That
| works in a checkout, and app:build compiles the result into a phar as a
| literal - but a container has neither a .git directory nor a git binary, so it
| falls back to "unreleased". BLBACKUP_VERSION is how the image is told, and
| AppServiceProvider is what applies it.
|
| These drive the provider directly rather than through the environment, because
| the application is already booted by the time a test body runs and the setting
| is read during register().
*/

it('reports the version through app:config', function () {
    config(['app.version' => '9.9.9']);

    Artisan::call('app:config', ['--only' => 'application']);

    expect(Artisan::output())->toContain('9.9.9');
});

it('applies a configured version over what git could not work out', function () {
    config(['app.version' => 'unreleased', 'blbackup.version' => '2.1.1']);

    (new AppServiceProvider(app()))->register();

    expect(config('app.version'))->toBe('2.1.1');
});

it('leaves the version alone when none is configured', function () {
    // a checkout and a compiled binary both know what they are, and must keep
    // reporting the tag they really came from
    config(['app.version' => '2.1.1', 'blbackup.version' => null]);

    (new AppServiceProvider(app()))->register();

    expect(config('app.version'))->toBe('2.1.1');
});
