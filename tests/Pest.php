<?php

use App\Support\SlackSummary;
use GuzzleHttp\Client;
use Hampel\SlackMessage\SlackWebhook;
use Illuminate\Http\Client\Request;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
*/

// see the beforeEach below - the config pin is chained onto this uses()

/*
|--------------------------------------------------------------------------
| Fixed configuration
|--------------------------------------------------------------------------
|
| Pin everything the commands read out of config, so assertions on the shell
| commands they build don't depend on the developer's .env, and fake the
| downloads disk so nothing touches a real backup directory.
|
*/

uses(Tests\TestCase::class)
    ->beforeEach(function () {
        config([
            // the BinaryLane client reads its token from the package's own config,
            // which the project .env feeds as readily as anything here - so a
            // suite that did not pin it would put the developer's real token in
            // every request it fakes
            'binarylane.default' => 'main',
            'binarylane.accounts' => ['main' => ['token' => 'test-token']],
            'binarylane.per_page' => null,

            // a host that cannot resolve, for the reason the webhook fixtures use
            // hooks.slack.test. The fakes match on the request path, so this changes
            // nothing a test can see - until a request escapes them, when it fails
            // on DNS instead of reaching the real API carrying that token. Escaping
            // is not hypothetical: see fakeApi()
            'binarylane.base_uri' => 'https://api.binarylane.test',

            'blbackup.timeout' => 3600,
            'blbackup.timezone' => 'Australia/Sydney',
            'blbackup.zstd_binary' => '/usr/bin/zstd',
            'blbackup.wget_binary' => '/usr/bin/wget',
            'blbackup.rclone.binary' => '/usr/bin/rclone',
            'blbackup.rclone.remote' => 'remote:backups',
            'blbackup.lock_file' => storage_path('framework/testing/blbackup.lock'),

            // no server list unless a test asks for one - the project .env is
            // loaded here too, and a developer who has configured one would
            // otherwise find every test filtering its server list
            'blbackup.include_file' => null,
            'blbackup.exclude_file' => null,

            // the project .env is loaded in tests too, and without this the
            // suite appends to whatever log the developer has configured
            'logging.default' => 'null',

            // and without this it posted to whatever Slack channel the developer
            // has configured - 41 real messages per run, measured. SlackSummary
            // is resolved from the container with a real Guzzle client, which
            // Http::fake() cannot see, so nothing else here was going to stop it
            'blbackup.summary.slack_webhook' => null,

            // the shipped default rather than the developer's: a test that says
            // how many records a threshold posts has to be reading the threshold
            // this project ships, not the one in the .env beside the suite. Keep
            // this in step with config/logging.php - LogLevelTest asserts they
            // agree, because a pin that drifts from the default silently stops
            // testing the shipped configuration
            'logging.channels.slack.level' => 'error',

            // config/app.php resolves this by shelling out to `git describe
            // --tags`, so without a pin every test inherits whatever the ambient
            // checkout can answer. A clone with no tags - CI on a branch push,
            // or a --depth 1 clone - answers "unreleased", app:validate warns
            // about it, and tests that assert a clean run fail somewhere with
            // nothing to do with the version. The two tests that care about it
            // set it themselves
            'app.version' => '0.0.0-testing',
        ]);

        // repoints the downloads disk at storage/framework/testing and empties
        // it, so nothing is written to a real download directory
        Storage::fake('downloads');
    })
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Sizes
|--------------------------------------------------------------------------
|
| A download is accepted only when its size in GB is exactly the size the API
| reported, so test files need a size that is exact in both units. A mebibyte
| is 0.0009765625 GB, which is exact in binary floating point; most round
| decimal sizes are not.
|
*/

const MEGABYTE = 1048576;

const MEGABYTE_IN_GB = 0.0009765625;

/*
|--------------------------------------------------------------------------
| Fixtures
|--------------------------------------------------------------------------
*/

function fakeServer(array $attributes = []): array
{
    return array_merge([
        'id' => 100,
        'name' => 'web1.example.com',
        'disk' => 40,
        'memory' => 2048,
        'vcpus' => 2,
    ], $attributes);
}

function fakeImage(array $attributes = []): array
{
    return array_merge([
        'id' => 12345,
        'full_name' => 'web1.example.com backup',
        'type' => 'backup',
        'public' => false,
        'created_at' => '2026-08-20T14:30:00Z',
        'size_gigabytes' => MEGABYTE_IN_GB,
        'backup_info' => ['server_id' => 100],
    ], $attributes);
}

function fakeAccount(array $attributes = []): array
{
    return array_merge([
        'email' => 'backups@example.com',
        'status' => 'active',
    ], $attributes);
}

function fakeAction(string $status = 'completed', int $percent = 100, int $id = 900): array
{
    return [
        'id' => $id,
        'status' => $status,
        'progress' => [
            'percent_complete' => $percent,
            'current_step_detail' => 'Copying disk',
        ],
    ];
}

/**
 * The path the download command derives for an image: the datestamp is the
 * image's created_at in blbackup.timezone, not UTC.
 */
function backupPath(string $server = 'web1.example.com', string $short = 'web1', string $date = '20260821-003000', int $image = 12345): string
{
    return "{$server}/backup-{$short}-{$date}-{$image}.zst";
}

function downloadPath(string $path = ''): string
{
    return Storage::disk('downloads')->path($path);
}

function putDownload(string $path, int $bytes): void
{
    Storage::disk('downloads')->put($path, str_repeat('x', $bytes));
}

/*
|--------------------------------------------------------------------------
| Fakes
|--------------------------------------------------------------------------
*/

/**
 * Answer the BinaryLane endpoints the commands call, routing on the request path
 * so one fake covers every command.
 *
 * $statuses is the queue of action payloads GET /actions/{id} returns as the
 * create command polls; the last one is repeated once the queue runs dry. An
 * entry may be a closure, which is how a test makes something happen between
 * one poll and the next.
 */
function fakeApi(array $servers, array $backups = [], array $links = [], array $statuses = [], array $account = []): void
{
    // Http::fake() merges stubs and the first match wins, so a second call
    // would be shadowed by the first. Start from a clean factory instead.
    Http::swap(new Illuminate\Http\Client\Factory);
    Http::preventStrayRequests();

    // THE BINARYLANE CLIENT KEEPS THE HTTP FACTORY IT WAS BUILT WITH, so one built
    // before the swap above goes on sending through the old factory: it answers
    // from the old fakes, or with none there sends the request for real - and
    // preventStrayRequests() on the new factory does not stop it. Measured, not
    // assumed. Forgetting both singletons makes the next use rebuild against the
    // factory just swapped in.
    app()->forgetInstance(Psr\Http\Client\ClientInterface::class);
    app()->forgetInstance(Hampel\BinaryLane\Api\Laravel\BinaryLaneManager::class);

    $statuses = collect($statuses ?: [fakeAction()]);
    $account = $account ?: fakeAccount();

    Http::fake(function (Request $request) use ($servers, $backups, $links, $statuses, $account) {
        $path = parse_url($request->url(), PHP_URL_PATH);

        return match (true) {
            // Http::fake honours the sink option, so the --no-wget path writes a
            // real file of exactly the size the API below reports
            str_ends_with($path, '.zst') => Http::response(str_repeat('x', MEGABYTE)),
            str_ends_with($path, '/backups') => Http::response(fakeCollection('backups', $backups, $request)),
            str_ends_with($path, '/account') => Http::response(['account' => $account]),
            str_ends_with($path, '/images') => Http::response(fakeCollection('images', $backups, $request)),
            str_ends_with($path, '/actions') => Http::response(['action' => fakeAction('in-progress', 0)]),
            (bool) preg_match('#/actions/\d+$#', $path) => Http::response(
                ['action' => value($statuses->count() > 1 ? $statuses->shift() : $statuses->first())]
            ),
            // an image with nothing to download answers with no disks, which is what
            // the command reports as "no download link" - not a null, which the
            // client rightly refuses as a malformed answer
            (bool) preg_match('#/images/(\d+)/download$#', $path, $matches) => Http::response(
                ['link' => $links[(int) $matches[1]] ?? ['id' => (int) $matches[1], 'disks' => []]]
            ),
            (bool) preg_match('#/images/(\d+)$#', $path, $matches) => fakeFound(
                'image', collect($backups)->firstWhere('id', (int) $matches[1])
            ),
            (bool) preg_match('#/servers/(\d+)$#', $path, $matches) => fakeFound(
                'server', collect($servers)->firstWhere('id', (int) $matches[1])
            ),
            str_ends_with($path, '/servers') => Http::response(fakeCollection('servers', $servers, $request)),
            default => Http::response(['error' => "unexpected request to {$path}"], 404),
        };
    });
}

/**
 * A list answer the way the API gives one: the items, and meta.total across every
 * page. A per_page of 0 is the API's count-only request, so it gets the total and
 * no items - which is what makes a count that quietly fell back to counting one
 * page of a listing show up as the wrong number.
 */
function fakeCollection(string $key, array $items, Request $request): array
{
    parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

    $countOnly = isset($query['per_page']) && (int) $query['per_page'] === 0;

    return [$key => $countOnly ? [] : array_values($items), 'meta' => ['total' => count($items)]];
}

/**
 * One object, or the 404 the API answers with when there is no such thing - which
 * the client raises as NotFoundException rather than handing back an empty result.
 */
function fakeFound(string $key, ?array $object)
{
    return $object === null
        ? Http::response(['type' => 'about:blank', 'title' => 'Not Found', 'status' => 404], 404)
        : Http::response([$key => $object]);
}

function fakeLink(int $image, string $url): array
{
    return [$image => ['id' => $image, 'disks' => [['compressed_url' => $url]]]];
}

/**
 * Fake the external binaries. Handlers are matched in order and fall through
 * to success, so a test only names the commands it cares about.
 */
function fakeBinaries(array $handlers = []): void
{
    // Process::fake() merges handlers the same way Http::fake() merges stubs,
    // so the catch-all below would shadow anything a later call registered.
    Process::swap(new Illuminate\Process\Factory);

    Process::fake(array_merge($handlers, ['*' => Process::result()]));
}

/**
 * A wget fake that writes the file wget would have written, so the size check
 * that follows the download has something real to measure.
 */
function wgetWrites(int $bytes): Closure
{
    return function (PendingProcess $process) use ($bytes) {
        if (preg_match('/-O (.+)$/', $process->command, $matches))
        {
            file_put_contents(trim($matches[1]), str_repeat('x', $bytes));
        }

        return Process::result();
    };
}

/**
 * Write a server list of the kind create's --include / --exclude take, and
 * return the absolute path to it.
 */
/**
 * Put a recording transport behind every Slack post, and answer them all with
 * an "ok" nobody had to be online for.
 *
 * Worth having even though the webhook is pinned empty above, because the two
 * guard against different mistakes: the pin stops the suite posting, and a test
 * built on this proves it, against the day somebody unpins it. Http::fake() is
 * no help here - SlackWebhook is handed a real Guzzle client by
 * AppServiceProvider, and the Http facade never sees it.
 *
 * @param array $sent filled with the uri of every message posted
 */
function recordingSlack(array &$sent): void
{
    $handler = function ($request, $options) use (&$sent) {
        $sent[] = (string) $request->getUri();

        return new GuzzleHttp\Promise\FulfilledPromise(new GuzzleHttp\Psr7\Response(200, [], 'ok'));
    };

    app()->instance(SlackWebhook::class, new SlackWebhook(new Client(['handler' => $handler])));

    // the reporter is a singleton and may already hold the real transport
    app()->forgetInstance(SlackSummary::class);
}

/**
 * Hold the backup lock, as another run would.
 *
 * flock is associated with the open file description rather than the process,
 * so a second fopen() of the same path conflicts even from inside the suite.
 * The caller has to keep the handle: closing it releases the lock.
 */
function holdLock(string $holder = 'pid 999, cron, started 2026-08-26 02:00:00'): mixed
{
    $path = config('blbackup.lock_file');

    File::ensureDirectoryExists(dirname($path));

    $handle = fopen($path, 'c');

    flock($handle, LOCK_EX | LOCK_NB);
    ftruncate($handle, 0);
    fwrite($handle, $holder);
    fflush($handle);

    return $handle;
}

function writeServerList(string $name, array $servers): string
{
    Storage::disk('downloads')->put($name, implode(PHP_EOL, $servers));

    return downloadPath($name);
}

/**
 * A downloaded backup with a modification time, which is what clean expires on.
 */
function putAgedDownload(string $path, int $daysAgo, int $bytes = MEGABYTE): void
{
    putDownload($path, $bytes);

    touch(downloadPath($path), now()->subDays($daysAgo)->timestamp);
}

/**
 * One entry of `rclone lsjson -R` output. ModTime is RFC3339 with nanosecond
 * precision, which is what rclone really emits - checked against the rclone on
 * this machine, not invented.
 */
function rcloneEntry(string $path, int $daysAgo = 0, bool $isDir = false, int $bytes = MEGABYTE): array
{
    return [
        'Path' => $path,
        'Name' => basename($path),
        'Size' => $isDir ? -1 : $bytes,
        'ModTime' => now()->subDays($daysAgo)->format('Y-m-d\TH:i:s.u000P'),
        'IsDir' => $isDir,
    ];
}

function rcloneListing(array $entries): string
{
    return json_encode($entries);
}

/**
 * The table a command printed, as rows of cells - headers first.
 *
 * expectsTable() renders the rows it is given and asserts each resulting line
 * appears in the output, which means it cannot see rows the command printed
 * and the test did not expect. Comparing this against the whole expected table
 * catches extra rows, missing rows and wrong order alike.
 *
 * Cells keep any padding the command applied (Str::padLeft) and lose only the
 * single space the renderer puts either side, so alignment is still asserted.
 */
function renderedTable(string $output): array
{
    return collect(explode(PHP_EOL, $output))
        ->map(fn ($line) => rtrim($line))
        ->filter(fn ($line) => str_starts_with($line, '|'))
        ->map(fn ($line) => collect(explode('|', trim($line, '|')))
            ->map(fn ($cell) => rtrim(substr($cell, 1)))
            ->all())
        ->values()
        ->all();
}
