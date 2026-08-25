<?php

use Illuminate\Http\Client\Request;
use Illuminate\Process\PendingProcess;
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
            'binarylane.api_token' => 'test-token',
            'binarylane.timeout' => 3600,
            'binarylane.timezone' => 'Australia/Sydney',
            'binarylane.zstd_binary' => '/usr/bin/zstd',
            'binarylane.wget_binary' => '/usr/bin/wget',
            'binarylane.rclone.binary' => '/usr/bin/rclone',
            'binarylane.rclone.remote' => 'remote:backups',

            // the project .env is loaded in tests too, and without this the
            // suite appends to whatever log the developer has configured
            'logging.default' => 'null',
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
 * image's created_at in binarylane.timezone, not UTC.
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
 * Answer the BinaryLane endpoints App\Api calls, routing on the request path
 * so one fake covers every command.
 *
 * $statuses is the queue of action payloads GET /actions/{id} returns as the
 * create command polls; the last one is repeated once the queue runs dry. An
 * entry may be a closure, which is how a test makes something happen between
 * one poll and the next.
 */
function fakeApi(array $servers, array $backups = [], array $links = [], array $statuses = [], array $account = []): void
{
    $statuses = collect($statuses ?: [fakeAction()]);
    $account = $account ?: fakeAccount();

    Http::fake(function (Request $request) use ($servers, $backups, $links, $statuses, $account) {
        $path = parse_url($request->url(), PHP_URL_PATH);

        return match (true) {
            // Http::fake honours the sink option, so the --no-wget path writes a
            // real file of exactly the size the API below reports
            str_ends_with($path, '.zst') => Http::response(str_repeat('x', MEGABYTE)),
            str_ends_with($path, '/backups') => Http::response(['backups' => $backups]),
            str_ends_with($path, '/account') => Http::response(['account' => $account]),
            str_ends_with($path, '/images') => Http::response(['images' => $backups]),
            str_ends_with($path, '/actions') => Http::response(['action' => fakeAction('in-progress', 0)]),
            (bool) preg_match('#/actions/\d+$#', $path) => Http::response(
                ['action' => value($statuses->count() > 1 ? $statuses->shift() : $statuses->first())]
            ),
            (bool) preg_match('#/images/(\d+)/download$#', $path, $matches) => Http::response(
                ['link' => $links[(int) $matches[1]] ?? null]
            ),
            (bool) preg_match('#/images/(\d+)$#', $path, $matches) => Http::response(
                ['image' => collect($backups)->firstWhere('id', (int) $matches[1])]
            ),
            (bool) preg_match('#/servers/(\d+)$#', $path, $matches) => Http::response(
                ['server' => collect($servers)->firstWhere('id', (int) $matches[1])]
            ),
            str_ends_with($path, '/servers') => Http::response(['servers' => $servers]),
            default => Http::response(['error' => "unexpected request to {$path}"], 404),
        };
    });
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
