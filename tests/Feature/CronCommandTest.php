<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/*
| cron is the unattended entry point: it runs the stages in order, decides
| whether the backups leave this machine, and never asks anybody anything.
|
| Whether they leave is gated on RCLONE_REMOTE alone. An installation that can
| run the container on the file server holding the backups has nowhere to move
| them to, and unsetting one variable is the whole of that change.
|
| It downloads the backups BinaryLane takes on each server's schedule and takes
| none of its own: BinaryLane allows at most two temporary backups per server in
| any seven days, and a nightly run needs seven.
*/

beforeEach(function () {
    fakeApi([fakeServer()], [fakeImage()], fakeLink(12345, 'https://images.binarylane.com.au/backup-12345.zst'));

    fakeBinaries([
        '*wget*' => wgetWrites(MEGABYTE),
        '*lsjson*' => Process::result(rcloneListing([])),
    ]);
});

/**
 * download --move also runs lsjson, to see whether the image is on the remote
 * already - so "clean reached the remote" has to key on its -R, not on lsjson.
 */
function ranRclone(string $subcommand): Closure
{
    return fn ($process) => str_contains($process->command, ' ' . $subcommand . ' ');
}

/**
 * Any take_backup action at all, whatever its slot or strategy.
 */
function anyBackupTaken(): Closure
{
    return fn ($request) => $request->method() === 'POST'
        && str_contains($request->url(), '/actions')
        && ($request['type'] ?? null) === 'take_backup';
}

it('downloads, moves and expires when a remote is configured', function () {
    $this->artisan('cron')
        ->expectsOutputToContain('Downloading the scheduled backups of all servers and moving them to [remote:backups]')
        ->assertSuccessful();

    Process::assertRan(ranRclone('moveto'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'lsjson -R'));
});

it('takes no backups of its own', function () {
    $this->artisan('cron', ['--no-clean' => true])->assertSuccessful();

    // the scheduled backup is downloaded; nothing is asked of BinaryLane that would
    // count against its two temporary backups per server in seven days
    Http::assertNotSent(anyBackupTaken());
    Process::assertRan(fn ($process) => str_contains($process->command, 'wget'));
});

it('skips a server with no scheduled backups, and still succeeds', function () {
    fakeApi([fakeServer(['selected_size_options' => ['daily_backups' => 0, 'weekly_backups' => 0, 'monthly_backups' => 0]])]);

    $this->artisan('cron', ['--no-clean' => true, '-v' => true])
        ->expectsOutputToContain('1 server(s) have no scheduled backups and are skipped: web1.example.com')
        ->assertSuccessful();

    Http::assertNotSent(anyBackupTaken());
    Process::assertDidntRun(fn ($process) => str_contains($process->command, 'wget'));
});

it('downloads and keeps the file when no remote is configured', function () {
    config(['blbackup.rclone.remote' => null]);

    $this->artisan('cron')
        ->expectsOutputToContain('without moving them - no rclone remote is configured')
        ->assertSuccessful();

    // the whole of the change when the tool runs on the box that keeps the
    // backups: nothing is moved, and there is no remote side to expire
    Process::assertDidntRun(fn ($process) => str_contains($process->command, 'rclone'));

    expect(Storage::disk('downloads')->exists(backupPath()))->toBeTrue();
});

it('leaves the files here but still expires the remote with --no-move', function () {
    $this->artisan('cron', ['--no-move' => true])
        ->expectsOutputToContain('without moving them - moving is switched off for this run')
        ->assertSuccessful();

    Process::assertDidntRun(ranRclone('moveto'));

    // what previous nights shipped there still ages, whatever tonight did
    Process::assertRan(fn ($process) => str_contains($process->command, 'lsjson -R'));

    expect(Storage::disk('downloads')->exists(backupPath()))->toBeTrue();
});

it('skips the expiry with --no-clean', function () {
    $this->artisan('cron', ['--no-clean' => true])->assertSuccessful();

    Process::assertDidntRun(fn ($process) => str_contains($process->command, 'lsjson -R'));
});

it('expires old backups without asking', function () {
    putAgedDownload('web1.example.com/backup-web1-20240101-120000-99999.zst', daysAgo: 3650);

    // clean prompts when run by hand, and a confirm() with no stdin to read is
    // an exception rather than a default - so cron has to say --no-interaction
    $this->artisan('cron', ['--no-move' => true])->assertSuccessful();

    expect(Storage::disk('downloads')->exists('web1.example.com/backup-web1-20240101-120000-99999.zst'))->toBeFalse();
});

it('passes a server list through to the download stage', function () {
    $list = writeServerList('exclude.txt', ['web1.example.com']);

    $this->artisan('cron', ['--exclude' => $list, '--no-clean' => true])->assertSuccessful();

    Process::assertDidntRun(fn ($process) => str_contains($process->command, 'wget'));
});

it('still expires when the downloads failed, and reports failure', function () {
    // a scheduled server with no backup at all: its schedule has never run
    fakeApi([fakeServer()]);

    // a server that could not be downloaded tonight is no reason to leave last
    // month's downloads filling the disk
    $this->artisan('cron')
        ->expectsOutputToContain('Backup stage [download] failed')
        ->assertFailed();

    Process::assertRan(fn ($process) => str_contains($process->command, 'lsjson -R'));
});

it('posts nothing to slack when the suite runs it', function () {
    // the other half of the guard in ValidateCommandTest: cron posts a summary
    // of its own at the end of every run, through the same container singleton
    $sent = [];
    recordingSlack($sent);

    $this->artisan('cron')->assertSuccessful();

    expect($sent)->toBe([]);
});
