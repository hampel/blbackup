<?php

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/*
| cron is the unattended entry point: it runs the stages in order, decides
| whether the backups leave this machine, and never asks anybody anything.
|
| Whether they leave is gated on RCLONE_REMOTE alone. An installation that can
| run the container on the file server holding the backups has nowhere to move
| them to, and unsetting one variable is the whole of that change.
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

it('backs up, downloads, moves and expires when a remote is configured', function () {
    $this->artisan('cron')
        ->expectsOutputToContain('downloading and moving to [remote:backups]')
        ->assertSuccessful();

    Process::assertRan(ranRclone('moveto'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'lsjson -R'));
});

it('downloads and keeps the file when no remote is configured', function () {
    config(['binarylane.rclone.remote' => null]);

    $this->artisan('cron')
        ->expectsOutputToContain('downloading only - no rclone remote is configured')
        ->assertSuccessful();

    // the whole of the change when the tool runs on the box that keeps the
    // backups: nothing is moved, and there is no remote side to expire
    Process::assertDidntRun(fn ($process) => str_contains($process->command, 'rclone'));

    expect(Storage::disk('downloads')->exists(backupPath()))->toBeTrue();
});

it('leaves the files here but still expires the remote with --no-move', function () {
    $this->artisan('cron', ['--no-move' => true])
        ->expectsOutputToContain('downloading only - moving is switched off for this run')
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

it('passes a server list through to the create stage', function () {
    $list = writeServerList('exclude.txt', ['web1.example.com']);

    $this->artisan('cron', ['--exclude' => $list, '--no-clean' => true])->assertSuccessful();

    Process::assertDidntRun(fn ($process) => str_contains($process->command, 'wget'));
});

it('still expires when the backups failed, and reports failure', function () {
    fakeApi([fakeServer()], statuses: [fakeAction('errored', 40)]);

    // a server that would not back up tonight is no reason to leave last
    // month's downloads filling the disk
    $this->artisan('cron')
        ->expectsOutputToContain('Backup stage [create] failed')
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
