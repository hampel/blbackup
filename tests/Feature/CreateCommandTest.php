<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

/*
| The create command polls BinaryLane with Sleep::for(10)->seconds()->while(),
| and Sleep::fake() cannot help here - a faked sleep returns before the while
| loop, so the poll callback never runs at all. Sleep is therefore real in these
| tests, which is only affordable because a poll that ends the loop never
| sleeps: the callback runs before the first sleep. Every test below has to
| reach a stopping condition on its first poll, or it will cost ten seconds.
*/

beforeEach(function () {
    $this->server = fakeServer();
    $this->other = fakeServer(['id' => 200, 'name' => 'db1.example.com', 'disk' => 80]);
});

function backupWasRequestedFor(int $serverId): Closure
{
    return fn ($request) => $request->method() === 'POST'
        && str_ends_with($request->url(), "/servers/{$serverId}/actions")
        && $request['type'] === 'take_backup'
        && $request['backup_type'] === 'temporary'
        && $request['replacement_strategy'] === 'oldest';
}

it('takes a backup for a hostname', function () {
    fakeApi([$this->server]);

    $this->artisan('create', ['server' => 'web1.example.com'])
        ->expectsOutputToContain('Backing up 40GB from web1.example.com')
        ->expectsOutputToContain('Completed server backup web1.example.com')
        ->assertSuccessful();

    Http::assertSent(backupWasRequestedFor(100));
});

it('takes a backup for a numeric server id', function () {
    fakeApi([$this->server]);

    $this->artisan('create', ['server' => '100'])
        ->expectsOutputToContain('Completed server backup web1.example.com')
        ->assertSuccessful();

    Http::assertSent(fn ($request) => $request->method() === 'GET'
        && str_ends_with($request->url(), '/servers/100'));
    Http::assertSent(backupWasRequestedFor(100));
});

it('reports a backup that errors', function () {
    fakeApi([$this->server], statuses: [fakeAction('errored', 40)]);

    $this->artisan('create', ['server' => 'web1.example.com'])
        ->expectsOutputToContain('Error backing up web1.example.com - status: errored')
        ->assertSuccessful();
});

it('gives up on a backup that exceeds the timeout', function () {
    config(['binarylane.timeout' => 15]);

    // the clock moves while the backup is being polled, which is the only way
    // the elapsed-time check in the poll loop can ever trip
    fakeApi([$this->server], statuses: [function () {
        $this->travel(20)->seconds();

        return fakeAction('in-progress', 10);
    }]);

    $this->artisan('create', ['server' => 'web1.example.com'])
        ->expectsOutputToContain('exceeded timeout of 15 seconds')
        ->expectsOutputToContain('Error backing up web1.example.com - status: in-progress')
        ->assertSuccessful();
});

it('backs up every server with --all', function () {
    fakeApi([$this->server, $this->other]);

    $this->artisan('create', ['--all' => true])
        ->expectsOutputToContain('Backing up all servers')
        ->assertSuccessful();

    Http::assertSent(backupWasRequestedFor(100));
    Http::assertSent(backupWasRequestedFor(200));
});

it('backs up only the servers named in an include file', function () {
    fakeApi([$this->server, $this->other]);

    $this->artisan('create', [
        '--all' => true,
        '--include' => writeServerList('include.txt', ['db1.example.com']),
    ])->assertSuccessful();

    Http::assertSent(backupWasRequestedFor(200));
    Http::assertNotSent(backupWasRequestedFor(100));
});

it('skips the servers named in an exclude file', function () {
    fakeApi([$this->server, $this->other]);

    $this->artisan('create', [
        '--all' => true,
        '--exclude' => writeServerList('exclude.txt', ['db1.example.com']),
    ])->assertSuccessful();

    Http::assertSent(backupWasRequestedFor(100));
    Http::assertNotSent(backupWasRequestedFor(200));
});

it('fails when the include file cannot be read', function () {
    fakeApi([$this->server]);

    $this->artisan('create', ['--all' => true, '--include' => '/no/such/list.txt'])
        ->expectsOutputToContain('Include file [/no/such/list.txt] does not exists or is not readable')
        ->assertFailed();

    Http::assertNotSent(backupWasRequestedFor(100));
});

it('fails when the exclude file cannot be read', function () {
    fakeApi([$this->server]);

    $this->artisan('create', ['--all' => true, '--exclude' => '/no/such/list.txt'])
        ->expectsOutputToContain('Exclude file [/no/such/list.txt] does not exists or is not readable')
        ->assertFailed();

    Http::assertNotSent(backupWasRequestedFor(100));
});

it('fails when given neither a server nor --all', function () {
    fakeApi([$this->server]);

    $this->artisan('create')
        ->expectsOutputToContain('No hostname or server_id specified')
        ->assertFailed();

    Http::assertNothingSent();
});

it('fails when the hostname matches no server', function () {
    fakeApi([]);

    $this->artisan('create', ['server' => 'nothing.example.com'])
        ->expectsOutputToContain('No server data returned for nothing.example.com')
        ->assertFailed();
});

it('downloads each backup it takes with --download', function () {
    fakeApi([$this->server], [fakeImage()], fakeLink(12345, 'https://images.binarylane.com.au/backup-12345.zst'));
    fakeBinaries(['*wget*' => wgetWrites(MEGABYTE)]);

    $this->artisan('create', ['server' => 'web1.example.com', '--download' => true])
        ->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => $process->command
        === '/usr/bin/wget https://images.binarylane.com.au/backup-12345.zst -O '.downloadPath(backupPath()));
});

it('passes --move through to the download', function () {
    fakeApi([$this->server], [fakeImage()], fakeLink(12345, 'https://images.binarylane.com.au/backup-12345.zst'));
    fakeBinaries([
        '*wget*' => wgetWrites(MEGABYTE),
        '*lsjson*' => Process::result(errorOutput: 'directory not found', exitCode: 3),
    ]);

    $this->artisan('create', ['server' => 'web1.example.com', '--download' => true, '--move' => true])
        ->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => $process->command
        === '/usr/bin/rclone --progress moveto '.downloadPath(backupPath())." remote:backups/".backupPath());
});

it('does not download a backup that failed', function () {
    fakeApi([$this->server], [fakeImage()], statuses: [fakeAction('errored', 40)]);
    fakeBinaries();

    $this->artisan('create', ['server' => 'web1.example.com', '--download' => true])
        ->expectsOutputToContain('Error backing up')
        ->assertSuccessful();

    Process::assertNothingRan();
});
