<?php

use Illuminate\Support\Facades\Artisan;

/*
| app:config is what someone reads first when a setting is not taking effect, and
| the environment file is the line that answers "is it even reading my file". A
| compiled binary reads the .env beside itself rather than the one in the working
| directory, so the answer is not the one people guess.
*/

function configOutput(): string
{
    Artisan::call('app:config', ['--only' => 'application']);

    return Artisan::output();
}

it('reports the environment file that was read', function () {
    app()->instance('blbackup.env.loaded', '/opt/blbackup/.env');
    app()->instance('blbackup.env.candidates', ['/opt/blbackup/.env']);

    expect(configOutput())->toMatch('/Environment File \.+ \/opt\/blbackup\/\.env/');
});

it('says where it looked when there was no environment file', function () {
    // the case the line exists for: every setting falls back to its default, and
    // without this nothing on the screen says why
    app()->instance('blbackup.env.loaded', null);
    app()->instance('blbackup.env.candidates', ['/opt/blbackup/.env']);

    expect(configOutput())->toContain('none found - looked in /opt/blbackup/.env');
});

it('records the file the framework reads from a checkout', function () {
    // bootstrap/app.php writes the answer down rather than choosing it, so what it
    // records has to be the file Laravel Zero would load - not base_path('.env') as
    // a guess inside a compiled binary, where that path is in the archive
    //
    // Read the way app:config reads it. A null bound with instance() does not count as
    // bound, so resolving it throws - which a checkout with a .env never sees and CI,
    // with none, did. Released 2.7.0 with its tests red for exactly that.
    $expected = app()->environmentFilePath();
    $loaded = app()->bound('blbackup.env.loaded') ? app('blbackup.env.loaded') : null;

    expect(app('blbackup.env.candidates'))->toBe([$expected])
        ->and($loaded)->toBe(is_file($expected) ? $expected : null);
});

it('shows the API connect timeout beside the request timeout', function () {
    // the setting that decides how long a BinaryLane host that will not answer takes to
    // fail - applied since binarylane-api-laravel 0.3.0, and absent from this dump until
    // then, which read as a setting the tool did not have
    config(['binarylane.timeout' => 12, 'binarylane.connect_timeout' => 7]);

    Artisan::call('app:config', ['--only' => 'binarylane']);

    expect(Artisan::output())
        ->toMatch('/API Request Timeout \.+ 12/')
        ->toMatch('/API Connect Timeout \.+ 7/');
});

it('shows the slack emoji', function () {
    config(['logging.channels.slack.emoji' => ':floppy_disk:']);

    Artisan::call('app:config', ['--only' => 'logging']);

    expect(Artisan::output())->toMatch('/Slack Emoji \.+ :floppy_disk:/');
});
