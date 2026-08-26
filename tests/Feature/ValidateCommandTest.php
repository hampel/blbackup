<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

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
        // the default test config logs nowhere, which warns - and a warning is
        // not a failure
        ->toContain('Checks passed, with warnings');
});

it('says so plainly when nothing warned either', function () {
    fakeApi([fakeServer()]);
    config(['logging.default' => 'single', 'logging.channels.single.path' => storage_path('probe.log')]);

    [$exit, $output] = validate();

    expect($exit)->toBe(0)->and($output)->toContain('All checks passed');
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

it('fails rather than crashing when the remote does not answer in time', function () {
    fakeApi([fakeServer()]);
    fakeBinaries([
        '*lsd*' => fn () => throw new Illuminate\Process\Exceptions\ProcessTimedOutException(
            new Symfony\Component\Process\Exception\ProcessTimedOutException(
                new Symfony\Component\Process\Process(['rclone']), 1
            ),
            Process::result()
        ),
        '*--version*' => Process::result(output: 'some tool v1.2.3'),
    ]);

    // a stack trace out of the command whose whole job is to report failures
    // legibly is the one outcome it must never produce
    [$exit, $output] = validate();

    expect($exit)->toBe(1)
        ->and($output)->toContain('[fail] rclone remote')
        ->toContain('did not answer within the process timeout');
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
        ->and($output)->toContain('[warn] log channel')
        ->toContain('discards everything');
});

it('warns when the stack contains only the null channel', function () {
    fakeApi([fakeServer()]);
    config(['logging.default' => 'stack', 'logging.channels.stack.channels' => ['null']]);

    [$exit, $output] = validate();

    expect($exit)->toBe(0)->and($output)->toContain('[warn] log channel')->toContain('LOG_STACK');
});

it('fails when the storage path does not exist, because rclone runs from there', function () {
    fakeApi([fakeServer()]);

    // Symfony's Process refuses to start when its cwd does not exist, so this
    // is move and clean --remote throwing after the backup has been taken -
    // not a degraded run. A container is where it happens: /storage is left
    // out of the image on purpose and nothing recreates the empty directory.
    config(['app.storage_path' => '/no/such/directory']);
    app()->useStoragePath('/no/such/directory');

    [$exit, $output] = validate();

    expect($exit)->toBe(1)
        ->and($output)->toContain('[fail] Storage path')
        ->toContain('does not exist');
});

it('checks the log file is writable', function () {
    fakeApi([fakeServer()]);
    config(['logging.default' => 'single', 'logging.channels.single.path' => storage_path('blbackup-test.log')]);

    [$exit, $output] = validate();

    expect($exit)->toBe(0)->and($output)->toContain('[ ok ] log path (single)');
});

it('fails when the log file cannot be written', function () {
    fakeApi([fakeServer()]);
    config(['logging.default' => 'single', 'logging.channels.single.path' => '/no/such/directory/blbackup.log']);

    [$exit, $output] = validate();

    expect($exit)->toBe(1)
        ->and($output)->toContain('[fail] log path (single)')
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
        ->and($output)->toContain('[ ok ] slack webhook')
        ->not->toContain('SECRET/WEBHOOK/VALUE')
        ->not->toContain('test-token');
});

it('writes a record at every level on every run', function () {
    fakeApi([fakeServer()]);
    config(['logging.default' => 'single', 'logging.channels.single.path' => storage_path('probe.log')]);

    Log::spy();

    [$exit, $output] = validate();

    expect($exit)->toBe(0)->and($output)->toContain('a message was written at every level');

    // not behind a flag: a Slack channel at critical only proves it works when
    // something at that level is really sent
    foreach (['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'] as $level)
    {
        Log::shouldHaveReceived('log')->with($level, "Validation test message [{$level}]");
    }
});

it('reports the hostname records are stamped with', function () {
    fakeApi([fakeServer()]);
    config(['logging.default' => 'single', 'logging.hostname' => 'unraid']);

    [$exit, $output] = validate();

    expect($exit)->toBe(0)->and($output)->toContain('[ ok ] log hostname')->toContain('unraid');
});

it('reports unstamped records as a skip rather than a pass', function () {
    fakeApi([fakeServer()]);
    config(['logging.default' => 'single', 'logging.hostname' => null]);

    [$exit, $output] = validate();

    expect($exit)->toBe(0)
        ->and($output)->toContain('[    ] log hostname')
        ->toContain('set LOG_HOSTNAME')
        ->not->toContain('[ ok ] log hostname');
});

it('stamps records with the hostname', function () {
    $logger = new Monolog\Logger('test');
    config(['logging.hostname' => 'unraid']);

    (new App\Logging\StampHostname)(new Illuminate\Log\Logger($logger));

    $record = new Monolog\LogRecord(
        new DateTimeImmutable, 'test', Monolog\Level::Error, 'a message'
    );

    foreach ($logger->getProcessors() as $processor)
    {
        $record = $processor($record);
    }

    expect($record->extra)->toBe(['hostname' => 'unraid']);
});

it('leaves records unstamped when no hostname is configured', function () {
    $logger = new Monolog\Logger('test');
    config(['logging.hostname' => '']);

    (new App\Logging\StampHostname)(new Illuminate\Log\Logger($logger));

    expect($logger->getProcessors())->toBe([]);
});

it('does not try to write records when the destination is unwritable', function () {
    fakeApi([fakeServer()]);
    config(['logging.default' => 'single', 'logging.channels.single.path' => '/no/such/directory/blbackup.log']);

    [$exit, $output] = validate();

    expect($exit)->toBe(1)
        ->and($output)->toContain('[fail] log path (single)')
        ->toContain('[    ] log records')
        ->toContain('cannot be written');
});

it('does not exercise a transfer unless one is asked for', function () {
    fakeApi([fakeServer()]);

    [$exit, $output] = validate();

    expect($exit)->toBe(0)->and($output)->toContain('[    ] Download transfer');

    Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '.zst'));
});

it('downloads a url end to end with --download', function () {
    fakeApi([fakeServer()]);

    [$exit, $output] = validate(['--download' => 'https://images.binarylane.com.au/probe.zst']);

    expect($exit)->toBe(0)->and($output)->toContain('[ ok ] Download transfer');

    Http::assertSent(fn ($request) => str_ends_with($request->url(), 'probe.zst'));

    // the probe file must not be left behind on the download disk
    expect(Storage::disk('downloads')->allFiles(''))->toBe([]);
});

it('fails when the transfer cannot be made', function () {
    // the only fake registered, because fakeApi() answers .zst with a
    // successful body and the first registered stub wins
    Http::fake(['*' => Http::response('nope', 404)]);

    [$exit, $output] = validate([
        '--download' => 'https://images.binarylane.com.au/probe.zst',
        '--no-api' => true,
    ]);

    expect($exit)->toBe(1)->and($output)->toContain('[fail] Download transfer');

    expect(Storage::disk('downloads')->allFiles(''))->toBe([]);
});
