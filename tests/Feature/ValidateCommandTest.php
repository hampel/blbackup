<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

/*
| Assertions go through Artisan::call() and expect()->toContain() rather than
| expectsOutputToContain(), because a check prints its marker and its detail on
| one line and two expectsOutputToContain() calls cannot both match one line.
| The API fake is registered per test for the same reason as elsewhere:
| Http::fake() merges, and the first registered stub wins.
*/

beforeEach(function () {
    fakeBinaries(['*--version*' => Process::result(output: "some tool v1.2.3\nmore detail")]);
});

function validate(array $parameters = []): array
{
    $exit = Artisan::call('app:validate', $parameters);

    return [$exit, Artisan::output()];
}

it('passes when the machine can do what the configuration says', function () {
    fakeApi([fakeServer()]);

    [$exit, $output] = validate();

    expect($exit)->toBe(0)
        ->and($output)->toContain('[ ok ] PHP')
        ->toContain('[ ok ] ext-intl')
        ->toContain('[ ok ] Timezone')
        ->toContain('All checks passed');
});

it('reports the version of each external binary', function () {
    fakeApi([fakeServer()]);

    validate();

    foreach (['/usr/bin/zstd --version', '/usr/bin/wget --version', '/usr/bin/rclone --version'] as $command)
    {
        Process::assertRan(fn ($process) => $process->command === $command);
    }
});

it('fails when a configured binary cannot be run', function () {
    fakeApi([fakeServer()]);
    fakeBinaries([
        '*zstd*' => Process::result(errorOutput: 'sh: 1: zstd: not found', exitCode: 127),
        '*--version*' => Process::result(output: 'some tool v1.2.3'),
    ]);

    [$exit, $output] = validate();

    expect($exit)->toBe(1)
        ->and($output)->toContain('[fail] zstd')
        ->toContain('could not be run')
        ->toContain('sh: 1: zstd: not found');
});

it('fails when a binary path is not configured', function () {
    fakeApi([fakeServer()]);
    config(['binarylane.wget_binary' => null]);

    [$exit, $output] = validate();

    expect($exit)->toBe(1)
        ->and($output)->toContain('[fail] wget')
        ->toContain('no path configured');
});

it('actually runs rclone against the remote', function () {
    fakeApi([fakeServer()]);

    validate();

    Process::assertRan(fn ($process) => $process->command === '/usr/bin/rclone lsd --quiet remote:backups');
});

it('fails when the remote does not answer', function () {
    fakeApi([fakeServer()]);
    fakeBinaries([
        '*lsd*' => Process::result(errorOutput: "didn't find section in config file", exitCode: 1),
        '*--version*' => Process::result(output: 'some tool v1.2.3'),
    ]);

    [$exit, $output] = validate();

    expect($exit)->toBe(1)
        ->and($output)->toContain('[fail] rclone remote')
        ->toContain('did not answer');
});

it('skips the remote when none is configured, without failing', function () {
    fakeApi([fakeServer()]);
    config(['binarylane.rclone.remote' => null]);

    [$exit, $output] = validate();

    // a skip must not read as a pass, and must not fail the run either
    expect($exit)->toBe(0)
        ->and($output)->toContain('[    ] rclone remote')
        ->toContain('not configured')
        ->not->toContain('[ ok ] rclone remote');

    Process::assertNotRan(fn ($process) => str_contains($process->command, 'lsd'));
});

it('warns but does not fail when nothing is logged anywhere', function () {
    fakeApi([fakeServer()]);
    config(['logging.default' => 'null']);

    [$exit, $output] = validate();

    expect($exit)->toBe(0)
        ->and($output)->toContain('[warn] Log channel')
        ->toContain('nothing is recorded anywhere');
});

it('checks the log file is writable', function () {
    fakeApi([fakeServer()]);
    config(['logging.default' => 'single', 'logging.channels.single.path' => storage_path('blbackup-test.log')]);

    [$exit, $output] = validate();

    expect($exit)->toBe(0)->and($output)->toContain('[ ok ] Log file (single)');
});

it('fails when the log file cannot be written', function () {
    fakeApi([fakeServer()]);
    config(['logging.default' => 'single', 'logging.channels.single.path' => '/no/such/directory/blbackup.log']);

    [$exit, $output] = validate();

    expect($exit)->toBe(1)
        ->and($output)->toContain('[fail] Log file (single)')
        ->toContain('is not writable');
});

it('calls the api to prove the token works', function () {
    fakeApi([fakeServer()]);

    [$exit, $output] = validate();

    expect($exit)->toBe(0)
        ->and($output)->toContain('[ ok ] BinaryLane account')
        ->toContain('backups@example.com (active)')
        ->toContain('[ ok ] Servers visible');

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/account'));
});

it('fails when the api rejects the token', function () {
    Http::fake(['*' => Http::response(['error' => 'unauthorized'], 401)]);

    [$exit, $output] = validate();

    expect($exit)->toBe(1)
        ->and($output)->toContain('[fail] BinaryLane API')
        ->toContain('[401]');
});

it('fails when no api token is configured', function () {
    fakeApi([fakeServer()]);
    config(['binarylane.api_token' => null]);

    [$exit, $output] = validate();

    expect($exit)->toBe(1)->and($output)->toContain('[fail] API token');

    Http::assertNothingSent();
});

it('skips the api calls with --no-api', function () {
    fakeApi([fakeServer()]);

    [$exit, $output] = validate(['--no-api' => true]);

    expect($exit)->toBe(0)->and($output)->toContain('[    ] BinaryLane account');

    Http::assertNothingSent();
});

it('never prints the api token or the slack webhook', function () {
    fakeApi([fakeServer()]);
    config([
        'logging.default' => 'stack',
        'logging.channels.stack.channels' => ['slack'],
        'logging.channels.slack.url' => 'https://hooks.slack.com/services/SECRET/WEBHOOK/VALUE',
    ]);

    // this output is what gets pasted into a support ticket
    [$exit, $output] = validate();

    expect($exit)->toBe(0)
        ->and($output)->toContain('[ ok ] Slack webhook')
        ->not->toContain('SECRET/WEBHOOK/VALUE')
        ->not->toContain('test-token');
});
