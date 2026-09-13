<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->server = fakeServer();
    $this->image = fakeImage();
    $this->url = 'https://images.binarylane.com.au/backup-12345.zst';
    $this->path = backupPath();
});

/**
 * The API as every test but one sees it: one server with one backup.
 * Http::fake() merges stubs and the first match wins, so each test registers
 * its own rather than inheriting one from beforeEach.
 */
function fakeOneBackup(array $server, array $image, string $url): void
{
    fakeApi([$server], [$image], fakeLink($image['id'], $url));
}

function wgetCommand(string $url, string $path): Closure
{
    return fn (PendingProcess $process) => $process->command === "/usr/bin/wget {$url} -O {$path}";
}

function zstdCommand(string $path): Closure
{
    return fn (PendingProcess $process) => $process->command === "/usr/bin/zstd --test --no-progress --quiet {$path}";
}

it('downloads the latest backup for a hostname, then verifies it with zstd', function () {
    fakeOneBackup($this->server, $this->image, $this->url);
    fakeBinaries(['*wget*' => wgetWrites(MEGABYTE)]);

    $this->artisan('download', ['server' => 'web1.example.com'])
        ->assertSuccessful();

    Process::assertRan(wgetCommand($this->url, downloadPath($this->path)));
    Process::assertRan(zstdCommand(downloadPath($this->path)));

    expect(Storage::disk('downloads')->exists($this->path))->toBeTrue()
        ->and(Storage::disk('downloads')->size($this->path))->toBe(MEGABYTE);
});

it('downloads the newest backup when a server has several', function () {
    $older = fakeImage(['id' => 111, 'created_at' => '2026-08-18T14:30:00Z']);
    $newer = fakeImage(['id' => 222, 'created_at' => '2026-08-20T14:30:00Z']);

    fakeApi([$this->server], [$older, $newer], fakeLink(222, $this->url));
    fakeBinaries(['*wget*' => wgetWrites(MEGABYTE)]);

    $this->artisan('download', ['server' => 'web1.example.com'])
        ->assertSuccessful();

    Process::assertRan(wgetCommand($this->url, downloadPath(backupPath(image: 222))));
});

it('downloads a specific image id', function () {
    fakeOneBackup($this->server, $this->image, $this->url);
    fakeBinaries(['*wget*' => wgetWrites(MEGABYTE)]);

    $this->artisan('download', ['--image' => 12345])
        ->assertSuccessful();

    Process::assertRan(wgetCommand($this->url, downloadPath($this->path)));
});

it('leaves a backup alone that has already been downloaded', function () {
    fakeOneBackup($this->server, $this->image, $this->url);
    putDownload($this->path, MEGABYTE);
    fakeBinaries();

    $this->artisan('download', ['server' => 'web1.example.com'])
        ->expectsOutputToContain('already exists')
        ->assertSuccessful();

    Process::assertNothingRan();
});

it('does not overwrite a short file, which may be an interrupted download', function () {
    fakeOneBackup($this->server, $this->image, $this->url);
    putDownload($this->path, MEGABYTE / 2);
    fakeBinaries();

    $this->artisan('download', ['server' => 'web1.example.com'])
        ->expectsOutputToContain('does not match expected')
        ->expectsOutputToContain('1 backup(s) could not be downloaded')
        ->assertFailed();

    Process::assertNothingRan();

    expect(Storage::disk('downloads')->size($this->path))->toBe((int) (MEGABYTE / 2));
});

it('re-downloads an existing file when forced', function () {
    fakeOneBackup($this->server, $this->image, $this->url);
    putDownload($this->path, MEGABYTE / 2);
    fakeBinaries(['*wget*' => wgetWrites(MEGABYTE)]);

    $this->artisan('download', ['server' => 'web1.example.com', '--force' => true])
        ->assertSuccessful();

    Process::assertRan(wgetCommand($this->url, downloadPath($this->path)));

    expect(Storage::disk('downloads')->size($this->path))->toBe(MEGABYTE);
});

it('deletes a download that fails the zstd check', function () {
    fakeOneBackup($this->server, $this->image, $this->url);
    fakeBinaries([
        '*wget*' => wgetWrites(MEGABYTE),
        '*zstd*' => Process::result(errorOutput: 'zstd: corrupted block detected', exitCode: 1),
    ]);

    $this->artisan('download', ['server' => 'web1.example.com'])
        ->expectsOutputToContain('failed zstd test')
        ->expectsOutputToContain('Deleting invalid backup file')
        ->assertFailed();

    expect(Storage::disk('downloads')->exists($this->path))->toBeFalse();
});

it('reports a download whose size does not match the API', function () {
    fakeOneBackup($this->server, $this->image, $this->url);
    fakeBinaries(['*wget*' => wgetWrites(MEGABYTE / 2)]);

    $this->artisan('download', ['server' => 'web1.example.com'])
        ->expectsOutputToContain('does not match expected')
        ->assertFailed();

    Process::assertRan(zstdCommand(downloadPath($this->path)));
});

it('skips the download when wget fails', function () {
    fakeOneBackup($this->server, $this->image, $this->url);
    fakeBinaries(['*wget*' => Process::result(errorOutput: 'wget: unable to resolve host', exitCode: 4)]);

    $this->artisan('download', ['server' => 'web1.example.com'])
        ->expectsOutputToContain('Could not download file')
        ->assertFailed();

    Process::assertNotRan(zstdCommand(downloadPath($this->path)));
});

it('moves the download to the configured remote', function () {
    fakeOneBackup($this->server, $this->image, $this->url);
    fakeBinaries([
        '*wget*' => wgetWrites(MEGABYTE),
        '*lsjson*' => Process::result(errorOutput: 'directory not found', exitCode: 3),
    ]);

    $this->artisan('download', ['server' => 'web1.example.com', '--move' => true])
        ->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => $process->command
        === "/usr/bin/rclone moveto ".downloadPath($this->path)." remote:backups/{$this->path}");
});

it('reports success when a specific image is downloaded and moved', function () {
    fakeOneBackup($this->server, $this->image, $this->url);
    fakeBinaries([
        '*wget*' => wgetWrites(MEGABYTE),
        '*lsjson*' => Process::result(errorOutput: 'directory not found', exitCode: 3),
    ]);

    // the --image path is the one that propagates downloadImage()'s return
    // value to the exit code, so a move that worked has to read as success
    $this->artisan('download', ['--image' => 12345, '--move' => true])
        ->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => str_contains($process->command, 'moveto'));
});

it('reports failure when the move after a download fails', function () {
    fakeOneBackup($this->server, $this->image, $this->url);
    fakeBinaries([
        '*wget*' => wgetWrites(MEGABYTE),
        '*lsjson*' => Process::result(errorOutput: 'directory not found', exitCode: 3),
        '*moveto*' => Process::result(errorOutput: 'Failed to copy: quota exceeded', exitCode: 1),
    ]);

    $this->artisan('download', ['--image' => 12345, '--move' => true])
        ->expectsOutputToContain('Could not move file to secondary storage')
        ->assertFailed();
});

it('does not download an image already shipped to the remote', function () {
    fakeOneBackup($this->server, $this->image, $this->url);
    fakeBinaries([
        '*lsjson*' => Process::result(output: json_encode(['Path' => $this->path, 'Size' => MEGABYTE])),
    ]);

    $this->artisan('download', ['server' => 'web1.example.com', '--move' => true])
        ->expectsOutputToContain('already exists on remote')
        ->assertSuccessful();

    Process::assertNotRan(wgetCommand($this->url, downloadPath($this->path)));
});

it('downloads with the http client when --no-wget is given', function () {
    fakeOneBackup($this->server, $this->image, $this->url);
    putDownload($this->path, MEGABYTE);
    fakeBinaries();

    $this->artisan('download', ['server' => 'web1.example.com', '--no-wget' => true, '--force' => true])
        ->assertSuccessful();

    Http::assertSent(fn ($request) => $request->url() === $this->url);

    Process::assertNotRan(wgetCommand($this->url, downloadPath($this->path)));
    Process::assertRan(zstdCommand(downloadPath($this->path)));
});

it('lists the available backups when given nothing to download', function () {
    fakeOneBackup($this->server, $this->image, $this->url);
    $this->artisan('download')
        ->expectsOutputToContain('Specify a hostname, server_id or backup_id')
        ->assertFailed();
});

it('reports success when the backup is already downloaded', function () {
    fakeOneBackup($this->server, $this->image, $this->url);
    putDownload($this->path, MEGABYTE);
    fakeBinaries();

    // nothing to do is not a failure - otherwise re-running a completed
    // download --all would report trouble every time
    $this->artisan('download', ['--image' => 12345])
        ->expectsOutputToContain('already exists')
        ->assertSuccessful();

    Process::assertNothingRan();
});

it('downloads the other servers after one fails, then reports failure', function () {
    $other = fakeServer(['id' => 200, 'name' => 'db1.example.com']);
    fakeApi([$this->server, $other], [$this->image], fakeLink(12345, $this->url));

    fakeBinaries([
        '*db1.example.com*' => Process::result(errorOutput: 'wget: unable to resolve host', exitCode: 4),
        '*wget*' => wgetWrites(MEGABYTE),
    ]);

    $this->artisan('download', ['--all' => true])
        ->expectsOutputToContain('Could not download file')
        ->expectsOutputToContain('1 backup(s) could not be downloaded')
        ->assertFailed();

    // the failure must not stop the run - the other server is still downloaded
    expect(Storage::disk('downloads')->exists($this->path))->toBeTrue();
});

it('downloads only the servers named in an include file', function () {
    $other = fakeServer(['id' => 200, 'name' => 'db1.example.com']);
    fakeApi([$this->server, $other], [$this->image], fakeLink(12345, $this->url));
    fakeBinaries(['*wget*' => wgetWrites(MEGABYTE)]);

    $this->artisan('download', [
        '--all' => true,
        '--include' => writeServerList('include.txt', ['db1.example.com']),
    ])->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => str_contains($process->command, 'db1.example.com'));
    Process::assertNotRan(fn (PendingProcess $process) => str_contains($process->command, 'web1.example.com'));
});

it('skips the servers named in an exclude file', function () {
    $other = fakeServer(['id' => 200, 'name' => 'db1.example.com']);
    fakeApi([$this->server, $other], [$this->image], fakeLink(12345, $this->url));
    fakeBinaries(['*wget*' => wgetWrites(MEGABYTE)]);

    $this->artisan('download', [
        '--all' => true,
        '--exclude' => writeServerList('exclude.txt', ['db1.example.com']),
    ])->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => str_contains($process->command, 'web1.example.com'));
    Process::assertNotRan(fn (PendingProcess $process) => str_contains($process->command, 'db1.example.com'));
});

it('takes the exclude list from configuration when no option is given', function () {
    $other = fakeServer(['id' => 200, 'name' => 'db1.example.com']);
    fakeApi([$this->server, $other], [$this->image], fakeLink(12345, $this->url));
    fakeBinaries(['*wget*' => wgetWrites(MEGABYTE)]);

    config(['blbackup.exclude_file' => writeServerList('exclude.txt', ['db1.example.com'])]);

    $this->artisan('download', ['--all' => true])->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => str_contains($process->command, 'web1.example.com'));
    Process::assertNotRan(fn (PendingProcess $process) => str_contains($process->command, 'db1.example.com'));
});

it('fails when the include file cannot be read', function () {
    fakeOneBackup($this->server, $this->image, $this->url);
    fakeBinaries();

    $this->artisan('download', ['--all' => true, '--include' => '/no/such/list.txt'])
        ->expectsOutputToContain('Include file [/no/such/list.txt] does not exists or is not readable')
        ->assertFailed();

    Process::assertNothingRan();
});

it('fails when the exclude file cannot be read', function () {
    fakeOneBackup($this->server, $this->image, $this->url);
    fakeBinaries();

    $this->artisan('download', ['--all' => true, '--exclude' => '/no/such/list.txt'])
        ->expectsOutputToContain('Exclude file [/no/such/list.txt] does not exists or is not readable')
        ->assertFailed();

    Process::assertNothingRan();
});

it('runs wget from the download root', function () {
    fakeOneBackup($this->server, $this->image, $this->url);
    fakeBinaries(['*wget*' => wgetWrites(MEGABYTE)]);

    $this->artisan('download', ['server' => 'web1.example.com'])->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => str_contains($process->command, 'wget')
        && $process->path === downloadPath());
});
