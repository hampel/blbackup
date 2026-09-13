<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

/*
| The create command waits on a backup with the client's actions()->await(),
| and hands it a wait that goes through Sleep - so Sleep::fake() stops a test
| sleeping. It is faked here for every test: await() checks before its first
| sleep, so a backup that is already finished never waits, but one that is still
| running would otherwise cost ten real seconds a poll.
|
| The timeout counts the seconds await() has waited, not the wall clock, which
| is why the timeout test below drives it with faked sleeps rather than by moving
| the clock.
*/

beforeEach(function () {
    Sleep::fake();
});

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
        ->expectsOutputToContain('1 server backup(s) did not complete')
        ->assertFailed();
});

it('gives up on a backup that exceeds the timeout', function () {
    config(['blbackup.timeout' => 15]);

    fakeApi([$this->server], statuses: [fakeAction('in-progress', 10)]);

    $this->artisan('create', ['server' => 'web1.example.com'])
        ->expectsOutputToContain('exceeded timeout of 15 seconds')
        ->expectsOutputToContain('Error backing up web1.example.com - status: in-progress')
        ->assertFailed();

    // ten seconds between polls, and never past the deadline: the second wait is
    // cut to the five that are left, so the timeout reported is the one asked for
    Sleep::assertSequence([
        Sleep::for(10)->seconds(),
        Sleep::for(5)->seconds(),
    ]);
});

it('does not wait on a backup that is already finished', function () {
    fakeApi([$this->server]);

    $this->artisan('create', ['server' => 'web1.example.com'])->assertSuccessful();

    Sleep::assertNeverSlept();
});

it('reports a backup that is waiting on something and will not finish by itself', function () {
    // blocked on an unpaid invoice: still in progress on paper, and it would stay
    // that way for the whole timeout if it were only polled
    fakeApi([$this->server], statuses: [array_merge(fakeAction('in-progress', 10), ['blocking_invoice_id' => 4242])]);

    $this->artisan('create', ['server' => 'web1.example.com'])
        ->expectsOutputToContain('Error backing up web1.example.com - status: blocked')
        ->assertFailed();

    Sleep::assertNeverSlept();
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

it('takes the exclude list from configuration when no option is given', function () {
    fakeApi([$this->server, $this->other]);

    config(['blbackup.exclude_file' => writeServerList('exclude.txt', ['db1.example.com'])]);

    $this->artisan('create', ['--all' => true])->assertSuccessful();

    Http::assertSent(backupWasRequestedFor(100));
    Http::assertNotSent(backupWasRequestedFor(200));
});

it('takes the include list from configuration when no option is given', function () {
    fakeApi([$this->server, $this->other]);

    config(['blbackup.include_file' => writeServerList('include.txt', ['db1.example.com'])]);

    $this->artisan('create', ['--all' => true])->assertSuccessful();

    Http::assertSent(backupWasRequestedFor(200));
    Http::assertNotSent(backupWasRequestedFor(100));
});

it('lets the option override the configured list', function () {
    fakeApi([$this->server, $this->other]);

    config(['blbackup.exclude_file' => writeServerList('configured.txt', ['db1.example.com'])]);

    $this->artisan('create', [
        '--all' => true,
        '--exclude' => writeServerList('given.txt', ['web1.example.com']),
    ])->assertSuccessful();

    // the configured list would have excluded 200, the given one excludes 100
    Http::assertSent(backupWasRequestedFor(200));
    Http::assertNotSent(backupWasRequestedFor(100));
});

it('trims the lines of a server list, so a file written on windows still matches', function () {
    fakeApi([$this->server, $this->other]);

    Storage::disk('downloads')->put('crlf.txt', "db1.example.com\r\n");

    config(['blbackup.exclude_file' => downloadPath('crlf.txt')]);

    $this->artisan('create', ['--all' => true])->assertSuccessful();

    Http::assertSent(backupWasRequestedFor(100));
    Http::assertNotSent(backupWasRequestedFor(200));
});

it('backs up everything when the configured list names no servers', function () {
    fakeApi([$this->server, $this->other]);

    // the safe way round: a truncated list must not silently stop the backups.
    // app:validate is what warns that it has stopped filtering
    config(['blbackup.include_file' => writeServerList('empty.txt', [])]);

    $this->artisan('create', ['--all' => true])->assertSuccessful();

    Http::assertSent(backupWasRequestedFor(100));
    Http::assertSent(backupWasRequestedFor(200));
});

it('fails when the configured exclude file cannot be read', function () {
    fakeApi([$this->server]);

    config(['blbackup.exclude_file' => '/no/such/list.txt']);

    $this->artisan('create', ['--all' => true])
        ->expectsOutputToContain('Exclude file [/no/such/list.txt] does not exists or is not readable')
        ->assertFailed();

    Http::assertNotSent(backupWasRequestedFor(100));
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
        === '/usr/bin/rclone moveto '.downloadPath(backupPath())." remote:backups/".backupPath());
});

it('does not download a backup that failed', function () {
    fakeApi([$this->server], [fakeImage()], statuses: [fakeAction('errored', 40)]);
    fakeBinaries();

    $this->artisan('create', ['server' => 'web1.example.com', '--download' => true])
        ->expectsOutputToContain('Error backing up')
        ->assertFailed();

    Process::assertNothingRan();
});

it('backs up the rest of the servers after one fails, then reports failure', function () {
    // the status queue is consumed in order, so the first server errors and
    // the second completes
    fakeApi([$this->server, $this->other], statuses: [
        fakeAction('errored', 40),
        fakeAction('completed'),
    ]);

    $this->artisan('create', ['--all' => true])
        ->expectsOutputToContain('Error backing up web1.example.com')
        ->expectsOutputToContain('Completed server backup db1.example.com')
        ->expectsOutputToContain('1 server backup(s) did not complete')
        ->assertFailed();

    // the failure must not stop the run - the second server is still backed up
    Http::assertSent(backupWasRequestedFor(200));
});

it('reports failure when a backup is taken but the download fails', function () {
    fakeApi([$this->server], [fakeImage()], fakeLink(12345, 'https://images.binarylane.com.au/backup-12345.zst'));
    fakeBinaries(['*wget*' => Process::result(errorOutput: 'wget: unable to resolve host', exitCode: 4)]);

    $this->artisan('create', ['server' => 'web1.example.com', '--download' => true])
        ->expectsOutputToContain('Completed server backup web1.example.com')
        ->expectsOutputToContain('Could not download file')
        ->expectsOutputToContain('1 server backup(s) did not complete')
        ->assertFailed();
});

it('reports a backup that was accepted with no action to follow', function () {
    // a bodiless 202 is a documented answer to every server action. There is then
    // nothing to wait on, and nothing here can tell whether the backup happened
    Http::fake(function ($request) {
        $path = parse_url($request->url(), PHP_URL_PATH);

        return match (true) {
            str_ends_with($path, '/actions') => Http::response('', 202),
            default => Http::response(['servers' => [fakeServer()], 'meta' => ['total' => 1]]),
        };
    });

    $this->artisan('create', ['server' => 'web1.example.com'])
        ->expectsOutputToContain('accepted the backup of web1.example.com but returned no action to follow')
        ->assertFailed();
});

it('records why a backup failed, since the client no longer does', function () {
    // binarylane-api 0.3.0 logs nothing above debug, so the reason BinaryLane gave
    // is lost unless this command's own record carries it
    fakeApi([$this->server], statuses: [array_merge(fakeAction('errored', 40), ['result_data' => 'disk quota exceeded'])]);

    Illuminate\Support\Facades\Log::spy();

    $this->artisan('create', ['server' => 'web1.example.com'])->assertFailed();

    Illuminate\Support\Facades\Log::shouldHaveReceived('log')->withArgs(
        fn ($level, $message, $context) => $level === 'error'
            && $message === 'Error backing up server'
            && is_string($context['reason'] ?? null)
            && $context['reason'] !== ''
    );
});
