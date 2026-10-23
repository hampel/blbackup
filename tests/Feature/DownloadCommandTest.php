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

it('refuses a disk that only offers the raw image', function () {
    // saved as .zst, a raw disk fails zstd --test and is deleted - after the whole
    // disk has been transferred. The client's own url() would fall back to it
    fakeApi([$this->server], [$this->image], [12345 => ['id' => 12345, 'disks' => [['raw_url' => 'https://images.binarylane.com.au/raw-12345.img']]]]);
    fakeBinaries();

    $this->artisan('download', ['server' => 'web1.example.com'])
        ->expectsOutputToContain('No download link found for web1.example.com image 12345')
        ->assertFailed();

    Process::assertNothingRan();
});

/*
| Only --all is filtered by the server lists - see the same tests for create.
*/

it('downloads a server named on the command line even though the exclude list names it', function () {
    $other = fakeServer(['id' => 200, 'name' => 'db1.example.com']);
    fakeApi([$this->server, $other], [$this->image], fakeLink(12345, $this->url));
    fakeBinaries(['*wget*' => wgetWrites(MEGABYTE)]);

    config(['blbackup.exclude_file' => writeServerList('exclude.txt', ['web1.example.com'])]);

    $this->artisan('download', ['server' => 'web1.example.com'])->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => str_contains($process->command, 'web1.example.com'));
});

it('downloads a server named by id even though the include list leaves it out', function () {
    $other = fakeServer(['id' => 200, 'name' => 'db1.example.com']);
    fakeApi([$this->server, $other], [$this->image], fakeLink(12345, $this->url));
    fakeBinaries(['*wget*' => wgetWrites(MEGABYTE)]);

    config(['blbackup.include_file' => writeServerList('include.txt', ['db1.example.com'])]);

    $this->artisan('download', ['server' => (string) $this->server['id']])->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => str_contains($process->command, 'web1.example.com'));
});

it('does not read the lists for a named server, so a missing one cannot block the download', function () {
    fakeOneBackup($this->server, $this->image, $this->url);
    fakeBinaries(['*wget*' => wgetWrites(MEGABYTE)]);

    config(['blbackup.include_file' => '/no/such/list.txt']);

    $this->artisan('download', ['server' => 'web1.example.com'])->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => str_contains($process->command, 'web1.example.com'));
});

/*
| download --all fetches the backups BinaryLane takes on each server's schedule,
| because nothing takes them nightly any more - BinaryLane allows at most two
| temporary backups per server in any seven days. So it must pick a backup that
| has finished, skip a server with no schedule, and notice a schedule that stops,
| which would otherwise look like a good night: the same backup skipped as
| already downloaded, forever.
*/

function unscheduled(array $attributes = []): array
{
    return fakeServer(array_merge(
        ['selected_size_options' => ['daily_backups' => 0, 'weekly_backups' => 0, 'monthly_backups' => 0]],
        $attributes
    ));
}

function hoursAgo(int $hours): string
{
    return now()->subHours($hours)->utc()->format('Y-m-d\TH:i:s\Z');
}

it('downloads the newest completed backup, not one still being taken', function () {
    $finished = fakeImage(['id' => 111, 'created_at' => '2026-08-19T14:30:00Z']);
    $inProgress = fakeImage(['id' => 222, 'created_at' => '2026-08-20T14:30:00Z', 'status' => 'pending']);

    fakeApi([$this->server], [$finished, $inProgress], fakeLink(111, $this->url));
    fakeBinaries(['*wget*' => wgetWrites(MEGABYTE)]);

    $this->artisan('download', ['--all' => true])->assertSuccessful();

    Process::assertRan(wgetCommand($this->url, downloadPath(backupPath(date: '20260820-003000', image: 111))));
});

it('fails a server whose every backup is still being taken', function () {
    fakeApi([$this->server], [fakeImage(['status' => 'pending'])], fakeLink(12345, $this->url));
    fakeBinaries();

    $this->artisan('download', ['--all' => true])
        ->expectsOutputToContain('No completed backup for web1.example.com yet')
        ->assertFailed();

    Process::assertDidntRun(fn (PendingProcess $process) => str_contains($process->command, 'wget'));
});

it('skips a server with no scheduled backups under --all', function () {
    $other = unscheduled(['id' => 200, 'name' => 'db1.example.com']);

    fakeApi([$this->server, $other], [$this->image], fakeLink(12345, $this->url));
    fakeBinaries(['*wget*' => wgetWrites(MEGABYTE)]);

    $this->artisan('download', ['--all' => true, '-v' => true])
        ->expectsOutputToContain('1 server(s) have no scheduled backups and are skipped: db1.example.com')
        ->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => str_contains($process->command, 'web1.example.com'));
    Process::assertNotRan(fn (PendingProcess $process) => str_contains($process->command, 'db1.example.com'));
});

it('still downloads a server with no schedule when it is named', function () {
    // a backup taken by hand is still worth fetching
    fakeOneBackup(unscheduled(), $this->image, $this->url);
    fakeBinaries(['*wget*' => wgetWrites(MEGABYTE)]);

    $this->artisan('download', ['server' => 'web1.example.com'])->assertSuccessful();

    Process::assertRan(wgetCommand($this->url, downloadPath($this->path)));
});

it('fails a daily backup older than its schedule allows, having still downloaded it', function () {
    config(['blbackup.backup_grace_hours' => 12]);

    fakeOneBackup($this->server, fakeImage(['created_at' => hoursAgo(40)]), $this->url);
    fakeBinaries(['*wget*' => wgetWrites(MEGABYTE)]);

    $this->artisan('download', ['--all' => true])
        ->expectsOutputToContain('The newest backup of web1.example.com is 40 hours old, against a schedule that should have taken one within 36')
        ->assertFailed();

    // the stale backup is still the newest there is, and worth having
    Process::assertRan(fn (PendingProcess $process) => str_contains($process->command, 'wget'));
});

it('accepts a daily backup inside its schedule and the grace', function () {
    config(['blbackup.backup_grace_hours' => 12]);

    fakeOneBackup($this->server, fakeImage(['created_at' => hoursAgo(30)]), $this->url);
    fakeBinaries(['*wget*' => wgetWrites(MEGABYTE)]);

    $this->artisan('download', ['--all' => true])->assertSuccessful();
});

it('gives a weekly schedule a week', function () {
    config(['blbackup.backup_grace_hours' => 12]);

    $weekly = fakeServer(['selected_size_options' => ['daily_backups' => 0, 'weekly_backups' => 1, 'monthly_backups' => 0]]);

    fakeOneBackup($weekly, fakeImage(['created_at' => hoursAgo(100)]), $this->url);
    fakeBinaries(['*wget*' => wgetWrites(MEGABYTE)]);
    $this->artisan('download', ['--all' => true])->assertSuccessful();

    fakeOneBackup($weekly, fakeImage(['id' => 777, 'created_at' => hoursAgo(190)]), $this->url);
    fakeBinaries(['*wget*' => wgetWrites(MEGABYTE)]);
    $this->artisan('download', ['--all' => true])->assertFailed();
});

it('does not judge the age of a backup on a server with no schedule', function () {
    config(['blbackup.backup_grace_hours' => 12]);

    // taken by hand, so how old it is was the operator's choice
    fakeOneBackup(unscheduled(), fakeImage(['created_at' => hoursAgo(400)]), $this->url);
    fakeBinaries(['*wget*' => wgetWrites(MEGABYTE)]);

    $this->artisan('download', ['server' => 'web1.example.com'])->assertSuccessful();
});
