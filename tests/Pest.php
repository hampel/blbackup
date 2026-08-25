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
 */
function fakeApi(array $server, array $backups = [], array $links = []): void
{
    Http::fake(function (Request $request) use ($server, $backups, $links) {
        $path = parse_url($request->url(), PHP_URL_PATH);

        return match (true) {
            // Http::fake honours the sink option, so the --no-wget path writes a
            // real file of exactly the size the API below reports
            str_ends_with($path, '.zst') => Http::response(str_repeat('x', MEGABYTE)),
            str_ends_with($path, '/backups') => Http::response(['backups' => $backups]),
            (bool) preg_match('#/images/(\d+)/download$#', $path, $matches) => Http::response(
                ['link' => $links[(int) $matches[1]] ?? null]
            ),
            (bool) preg_match('#/images/(\d+)$#', $path, $matches) => Http::response(
                ['image' => collect($backups)->firstWhere('id', (int) $matches[1])]
            ),
            (bool) preg_match('#/servers/\d+$#', $path) => Http::response(['server' => $server]),
            str_ends_with($path, '/servers') => Http::response(['servers' => [$server]]),
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
