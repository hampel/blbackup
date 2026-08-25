<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->path = backupPath();
});

function zstdTested(string $path): Closure
{
    return fn (PendingProcess $process) => $process->command
        === "/usr/bin/zstd --test --no-progress --quiet {$path}";
}

it('tests a downloaded file with zstd', function () {
    putDownload($this->path, MEGABYTE);
    fakeBinaries();

    $this->artisan('check', ['file' => $this->path])
        ->expectsOutputToContain('zstd test successful')
        ->assertSuccessful();

    Process::assertRan(zstdTested(downloadPath($this->path)));
});

it('fails when the file does not decompress', function () {
    putDownload($this->path, MEGABYTE);
    fakeBinaries(['*zstd*' => Process::result(errorOutput: 'zstd: corrupted block detected', exitCode: 1)]);

    $this->artisan('check', ['file' => $this->path])
        ->expectsOutputToContain('failed zstd test: zstd: corrupted block detected')
        ->assertFailed();
});

it('fails when the file does not exist', function () {
    fakeBinaries();

    $this->artisan('check', ['file' => 'web1.example.com/nothing.zst'])
        ->expectsOutputToContain('Could not find file web1.example.com/nothing.zst')
        ->assertFailed();

    Process::assertNothingRan();
});

it('fails when given neither a file nor --all', function () {
    fakeBinaries();

    $this->artisan('check')
        ->expectsOutputToContain('No file specified. Specify --all option')
        ->assertFailed();

    Process::assertNothingRan();
});

it('checks every downloaded file with --all', function () {
    putDownload($this->path, MEGABYTE);
    putDownload(backupPath('db1.example.com', 'db1', image: 222), MEGABYTE);
    fakeBinaries();

    $this->artisan('check', ['--all' => true])->assertSuccessful();

    Process::assertRan(zstdTested(downloadPath($this->path)));
    Process::assertRan(zstdTested(downloadPath(backupPath('db1.example.com', 'db1', image: 222))));
});

it('carries on to the next file after one fails --all', function () {
    putDownload($this->path, MEGABYTE);
    $second = backupPath('db1.example.com', 'db1', image: 222);
    putDownload($second, MEGABYTE);

    fakeBinaries([
        '*'.basename($this->path) => Process::result(errorOutput: 'zstd: corrupted block detected', exitCode: 1),
    ]);

    $this->artisan('check', ['--all' => true])
        ->expectsOutputToContain('failed zstd test')
        ->assertSuccessful();

    // the failure must not stop the run - the second file is still tested
    Process::assertRan(zstdTested(downloadPath($second)));

    // both files are left in place; check never deletes
    expect(Storage::disk('downloads')->exists($this->path))->toBeTrue()
        ->and(Storage::disk('downloads')->exists($second))->toBeTrue();
});

it('says there is nothing to check when the disk is empty', function () {
    fakeBinaries();

    $this->artisan('check', ['--all' => true])
        ->expectsOutputToContain('Nothing to be checked')
        ->assertSuccessful();

    Process::assertNothingRan();
});

it('lists a file without testing it with --dry-run', function () {
    putDownload($this->path, MEGABYTE);
    fakeBinaries();

    $this->artisan('check', ['file' => $this->path, '--dry-run' => true])
        ->expectsOutputToContain('Dry-run only')
        ->expectsOutputToContain("Check [{$this->path}]")
        ->assertSuccessful();

    Process::assertNothingRan();
});

it('lists every file without testing them with --all --dry-run', function () {
    putDownload($this->path, MEGABYTE);
    fakeBinaries();

    $this->artisan('check', ['--all' => true, '--dry-run' => true])
        ->expectsOutputToContain('The following files would be checked in downloads:')
        ->expectsOutputToContain("Check [{$this->path}]")
        ->assertSuccessful();

    Process::assertNothingRan();
});
