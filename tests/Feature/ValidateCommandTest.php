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
    config(['blbackup.wget_binary' => null]);

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
    config(['blbackup.rclone.remote' => null]);

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
    Http::fake(['*' => Http::response('', 401)]);

    [$exit, $output] = validate();

    expect($exit)->toBe(1)
        ->and($output)->toContain('[fail] BinaryLane API')
        ->toContain('HTTP 401');
});

it('fails when no api token is configured', function () {
    fakeApi([fakeServer()]);
    // the token the client sends, which lives in its own config
    config(['binarylane.accounts.main.token' => null]);

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

it('reports the version this install calls itself', function () {
    fakeApi([fakeServer()]);
    config(['app.version' => '9.9.9']);

    [$exit, $output] = validate(['--no-api' => true]);

    expect($exit)->toBe(0)->and($output)->toContain('[ ok ] Version')->toContain('9.9.9');
});

it('warns when it cannot tell what version it is', function () {
    fakeApi([fakeServer()]);

    // what a container reports: no .git and no git binary, so `git describe`
    // finds nothing. Not cosmetic - every Slack run summary is signed with this
    config(['app.version' => 'unreleased']);

    [$exit, $output] = validate(['--no-api' => true]);

    expect($exit)->toBe(0)->and($output)->toContain('[warn] Version')->toContain('--build-arg');
});

it('reports the configured server lists and how many servers they name', function () {
    fakeApi([fakeServer()]);
    config([
        'blbackup.include_file' => writeServerList('include.txt', ['web1.example.com']),
        'blbackup.exclude_file' => writeServerList('exclude.txt', ['db1.example.com', 'db2.example.com']),
    ]);

    [$exit, $output] = validate(['--no-api' => true]);

    expect($exit)->toBe(0)
        ->and($output)->toContain('[ ok ] include list')
        ->and($output)->toContain('(1 server)')
        ->and($output)->toContain('[ ok ] exclude list')
        ->and($output)->toContain('(2 servers)');
});

it('reports an unset server list as a skip rather than a pass', function () {
    fakeApi([fakeServer()]);

    [$exit, $output] = validate(['--no-api' => true]);

    expect($exit)->toBe(0)
        ->and($output)->toContain('[    ] include list')
        ->and($output)->toContain('[    ] exclude list');
});

it('fails when a configured server list cannot be read', function () {
    fakeApi([fakeServer()]);
    config(['blbackup.exclude_file' => '/no/such/list.txt']);

    [$exit, $output] = validate(['--no-api' => true]);

    // the list decides what gets backed up, so a path that is wrong has to stop
    // the image being rolled out rather than being noticed on the night
    expect($exit)->toBe(1)->and($output)->toContain('[fail] exclude list');
});

it('warns when a configured server list names no servers', function () {
    fakeApi([fakeServer()]);
    config(['blbackup.exclude_file' => writeServerList('empty.txt', [])]);

    [$exit, $output] = validate(['--no-api' => true]);

    // a warning, not a failure: it still backs everything up, which is safe -
    // but nothing else on the machine would ever mention that it stopped filtering
    expect($exit)->toBe(0)->and($output)->toContain('[warn] exclude list');
});

it('never prints the api token or the slack webhook', function () {
    fakeApi([fakeServer()]);
    config([
        'logging.default' => 'stack',
        'logging.channels.stack.channels' => ['slack'],
        'logging.channels.slack.url' => 'https://hooks.slack.test/services/SECRET/WEBHOOK/VALUE',
    ]);

    // without this the level sweep is routed through Monolog's slack handler to
    // the url above, and really posts - four requests to Slack's webhook host, which
    // 404 and so never fail the test. Http::fake() does not reach the handler;
    // taking the logger out from under it is what does
    Log::spy();

    // this output is what gets pasted into a support ticket
    [$exit, $output] = validate();

    expect($exit)->toBe(0)
        ->and($output)->toContain('[ ok ] slack webhook')
        ->not->toContain('SECRET/WEBHOOK/VALUE')
        ->not->toContain('test-token');
});

it('writes a record at every level on every attended run', function () {
    fakeApi([fakeServer()]);
    config(['logging.default' => 'single', 'logging.channels.single.path' => storage_path('probe.log')]);

    Log::spy();

    [$exit, $output] = validate();

    expect($exit)->toBe(0)->and($output)->toContain('a message was written at every level');

    // not behind a flag by default: a Slack channel at critical only proves it
    // works when something at that level is really sent. --unattended is the one
    // thing that stops it, and is covered below
    foreach (['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'] as $level)
    {
        Log::shouldHaveReceived('log')->with($level, "Validation test message [{$level}]");
    }
});

it('reports the hostname records are stamped with', function () {
    fakeApi([fakeServer()]);
    config(['logging.default' => 'single', 'logging.hostname' => 'nas.example.test']);

    [$exit, $output] = validate();

    expect($exit)->toBe(0)->and($output)->toContain('[ ok ] log hostname')->toContain('nas.example.test');
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
    config(['logging.hostname' => 'nas.example.test']);

    (new App\Logging\StampHostname)(new Illuminate\Log\Logger($logger));

    $record = new Monolog\LogRecord(
        new DateTimeImmutable, 'test', Monolog\Level::Error, 'a message'
    );

    foreach ($logger->getProcessors() as $processor)
    {
        $record = $processor($record);
    }

    expect($record->extra)->toBe(['hostname' => 'nas.example.test']);
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

it('posts nothing to slack when the suite runs it', function () {
    // this is a guard on tests/Pest.php, not on the command. Before the webhook
    // was pinned there, the project .env supplied a real one and a full suite run
    // posted 41 messages to a live channel - measured, not estimated
    fakeApi([fakeServer()]);

    $sent = [];
    recordingSlack($sent);

    [$exit, $output] = validate();

    expect($sent)->toBe([])
        ->and($exit)->toBe(0)
        ->and($output)->toContain('[    ] run summary');
});

it('sends nothing whose only proof is somebody seeing it with --unattended', function () {
    fakeApi([fakeServer()]);
    config(['logging.default' => 'single', 'logging.channels.single.path' => storage_path('probe.log')]);

    $sent = [];
    recordingSlack($sent);
    config(['blbackup.summary.slack_webhook' => 'https://hooks.slack.test/abc']);
    app()->forgetInstance(App\Support\SlackSummary::class);

    Log::spy();

    [$exit, $output] = validate(['--unattended' => true]);

    expect($exit)->toBe(0)
        ->and($sent)->toBe([])
        ->and($output)->toContain('[    ] log records')
        ->toContain('[    ] run summary')
        ->toContain('--unattended');

    foreach (['debug', 'error', 'emergency'] as $level)
    {
        Log::shouldNotHaveReceived('log', [$level, "Validation test message [{$level}]"]);
    }
});

it('still records its own warnings and failures with --unattended', function () {
    // the half of the output written for whoever is not at the terminal, which
    // is precisely who --unattended says is running it
    fakeApi([fakeServer()]);
    config([
        'logging.default' => 'single',
        'logging.channels.single.path' => storage_path('probe.log'),
        'blbackup.timezone' => 'Mars/Olympus_Mons',
    ]);

    Log::spy();

    [$exit] = validate(['--unattended' => true]);

    expect($exit)->toBe(1);

    Log::shouldHaveReceived('log')->with('error', 'Validation failure', [
        'label' => 'Timezone',
        'detail' => '[Mars/Olympus_Mons] is not a known timezone',
    ]);
});

it('says how many records the sweep posted, and at what', function () {
    // a count nobody was given is not a count anybody can check: four records is
    // right for a threshold of error, and three means it is not what config says
    fakeApi([fakeServer()]);
    config([
        'logging.default' => 'stack',
        'logging.channels.stack.channels' => ['slack'],
        'logging.channels.slack.url' => 'https://hooks.slack.test/services/A/B/C',
        'logging.channels.slack.level' => 'error',
    ]);

    Log::spy();

    [$exit, $output] = validate();

    expect($exit)->toBe(0)
        ->and($output)->toContain('[ ok ] log delivery (slack)')
        ->toContain('posted 4 records at error and above: error, critical, alert, emergency');
});

it('derives that count rather than reciting one', function () {
    fakeApi([fakeServer()]);
    config([
        'logging.default' => 'stack',
        'logging.channels.stack.channels' => ['slack'],
        'logging.channels.slack.url' => 'https://hooks.slack.test/services/A/B/C',
        'logging.channels.slack.level' => 'emergency',
    ]);

    Log::spy();

    [$exit, $output] = validate();

    expect($exit)->toBe(0)
        ->and($output)->toContain('posted 1 record at emergency and above: emergency');
});

it('has nothing to say about delivery when nothing in the stack posts to slack', function () {
    fakeApi([fakeServer()]);
    config(['logging.default' => 'single', 'logging.channels.single.path' => storage_path('probe.log')]);

    [$exit, $output] = validate();

    expect($exit)->toBe(0)
        ->and($output)->toContain('[    ] log delivery')
        ->toContain('nothing in the log stack posts to slack');
});

it('does not guess when the threshold is not a log level', function () {
    fakeApi([fakeServer()]);
    config([
        'logging.default' => 'stack',
        'logging.channels.stack.channels' => ['slack'],
        'logging.channels.slack.url' => 'https://hooks.slack.test/services/A/B/C',
        'logging.channels.slack.level' => 'loud',
    ]);

    Log::spy();

    [$exit, $output] = validate();

    expect($exit)->toBe(0)
        ->and($output)->toContain('[warn] log delivery (slack)')
        ->toContain('[loud] is not a log level');
});

it('makes no call that leaves the machine with --offline', function () {
    fakeApi([fakeServer()]);
    config(['logging.default' => 'single', 'logging.channels.single.path' => storage_path('probe.log')]);

    $sent = [];
    recordingSlack($sent);
    config(['blbackup.summary.slack_webhook' => 'https://hooks.slack.test/abc']);
    app()->forgetInstance(App\Support\SlackSummary::class);

    Log::spy();

    [$exit, $output] = validate(['--offline' => true, '--download' => 'https://images.binarylane.com.au/probe.zst']);

    expect($exit)->toBe(0)
        ->and($sent)->toBe([])
        ->and($output)->toContain('[    ] rclone remote')
        ->toContain('[    ] BinaryLane account')
        ->toContain('skipped with --offline')
        ->toContain('[    ] Download transfer')
        ->toContain('--download cannot override')
        ->toContain('[    ] log records')
        ->toContain('[    ] run summary');

    // the token is a fact about the installation and still worth establishing
    expect($output)->toContain('[ ok ] API token');

    Process::assertDidntRun(fn ($process) => str_contains($process->command, ' lsd '));
    Http::assertNothingSent();
});

it('names the flag the operator actually passed', function () {
    fakeApi([fakeServer()]);
    config(['logging.default' => 'single', 'logging.channels.single.path' => storage_path('probe.log')]);

    Log::spy();

    [, $offline] = validate(['--offline' => true]);
    [, $unattended] = validate(['--unattended' => true]);

    expect($offline)->toContain('nothing was written - --offline')
        ->and($unattended)->toContain('nothing was written - --unattended');
});

it('still probes the remote when only nobody is watching', function () {
    // --unattended says the network is fine, so a remote that has stopped
    // answering is exactly what it should still surface. This asymmetry is the
    // whole reason there are two flags rather than one
    fakeApi([fakeServer()]);

    [$exit, $output] = validate(['--unattended' => true]);

    expect($exit)->toBe(0)->and($output)->toContain('[ ok ] rclone remote');

    Process::assertRan(fn ($process) => str_contains($process->command, ' lsd '));
});

it('leaves everything but the api alone with --no-api', function () {
    // what CI runs inside the image: no credentials, but the level sweep really
    // written and the binaries really run. --no-api must not creep into meaning
    // --offline, or that check quietly stops happening
    fakeApi([fakeServer()]);
    config(['logging.default' => 'single', 'logging.channels.single.path' => storage_path('probe.log')]);

    Log::spy();

    [$exit, $output] = validate(['--no-api' => true]);

    expect($exit)->toBe(0)
        ->and($output)->toContain('skipped with --no-api')
        ->toContain('[ ok ] log records')
        ->toContain('[ ok ] rclone remote');

    foreach (['debug', 'error', 'emergency'] as $level)
    {
        Log::shouldHaveReceived('log')->with($level, "Validation test message [{$level}]");
    }
});

/**
 * A stack with one slack channel in it, at the given threshold.
 */
function slackStack(string $level, string $url = 'https://hooks.slack.test/services/A/B/C'): void
{
    config([
        'logging.default' => 'stack',
        'logging.channels.stack.channels' => ['slack'],
        'logging.channels.slack.url' => $url,
        'logging.channels.slack.level' => $level,
    ]);

    Log::spy();
}

it('warns when the slack threshold is above anything this app logs at', function () {
    // the quiet failure: the webhook is valid, every check passes, and then
    // nothing arrives on the night it was installed for
    fakeApi([fakeServer()]);
    slackStack('critical');

    [$exit, $output] = validate();

    expect($exit)->toBe(0)
        ->and($output)->toContain('[warn] log threshold (slack)')
        ->toContain('critical is above error')
        ->toContain('set LOG_SLACK_LEVEL=error')
        // a warning, not a failure - this exit code gates a container rebuild
        ->toContain('Checks passed, with warnings');
});

it('says nothing about the threshold at the level that ships', function () {
    fakeApi([fakeServer()]);
    slackStack('error');

    [$exit, $output] = validate();

    expect($exit)->toBe(0)->and($output)->not->toContain('log threshold');
});

it('says nothing about the threshold below that level either', function () {
    fakeApi([fakeServer()]);
    slackStack('warning');

    [$exit, $output] = validate();

    expect($exit)->toBe(0)->and($output)->not->toContain('log threshold');
});

it('warns about the threshold even when it is sending nothing', function () {
    // the asymmetry with the two sends: a threshold is a static fact about the
    // configuration rather than something the sweep discovers, so the run that
    // posts nothing is exactly the run that should still surface it
    fakeApi([fakeServer()]);
    slackStack('emergency');

    foreach ([['--unattended' => true], ['--offline' => true]] as $flags)
    {
        [, $output] = validate($flags);

        expect($output)->toContain('[warn] log threshold (slack)')
            ->toContain('emergency is above error')
            ->toContain('nothing was posted -');
    }
});

it('counts the run summary in when it shares the log webhook', function () {
    // they are separate settings and usually separate channels, but pointing
    // both at one is the obvious thing to do - and then one more message
    // arrives than the sweep sent, which makes a correct count look wrong
    fakeApi([fakeServer()]);
    slackStack('error', 'https://hooks.slack.test/services/SHARED');

    $sent = [];
    recordingSlack($sent);
    config(['blbackup.summary.slack_webhook' => 'https://hooks.slack.test/services/SHARED']);
    app()->forgetInstance(App\Support\SlackSummary::class);

    [$exit, $output] = validate();

    expect($exit)->toBe(0)
        ->and($output)->toContain('posted 4 records at error and above')
        ->toContain('and the run summary on this webhook, so expect 5')
        ->not->toContain('check they arrived');

    expect($sent)->toHaveCount(1);
});

it('does not count it in when the two point somewhere different', function () {
    fakeApi([fakeServer()]);
    slackStack('error', 'https://hooks.slack.test/services/LOGS');

    $sent = [];
    recordingSlack($sent);
    config(['blbackup.summary.slack_webhook' => 'https://hooks.slack.test/services/SUMMARY']);
    app()->forgetInstance(App\Support\SlackSummary::class);

    [$exit, $output] = validate();

    expect($exit)->toBe(0)
        ->and($output)->toContain('check they arrived')
        ->not->toContain('so expect');
});

it('reports how many servers the account has, not how many fit on a page', function () {
    // one server on the page and twenty-five on the account: the line used to
    // count the page, so it could never say more than twenty
    fakeApi([fakeServer()]);
    Http::swap(new Illuminate\Http\Client\Factory);
    app()->forgetInstance(Psr\Http\Client\ClientInterface::class);
    app()->forgetInstance(Hampel\BinaryLane\Api\Laravel\BinaryLaneManager::class);

    Http::fake(function ($request) {
        $path = parse_url($request->url(), PHP_URL_PATH);

        return match (true) {
            str_ends_with($path, '/account') => Http::response(['account' => fakeAccount()]),
            str_ends_with($path, '/servers') => Http::response(['servers' => [fakeServer()], 'meta' => ['total' => 25]]),
            default => Http::response(['error' => "unexpected request to {$path}"], 404),
        };
    });

    [$exit, $output] = validate();

    expect($exit)->toBe(0)->and($output)->toMatch('/\[ ok \] Servers visible\s+25/');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'per_page=0'));
});
