<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->old = backupPath(date: '20260801-003000', image: 111);
    $this->recent = backupPath(date: '20260824-003000', image: 222);
});

const CONFIRMATION = 'This operation cannot be undone. Continue ?';

function deletedFromRemote(string $path): Closure
{
    return fn (PendingProcess $process) => $process->command
        === "/usr/bin/rclone deletefile remote:backups/{$path}";
}

it('deletes local backups older than the retention period', function () {
    putAgedDownload($this->old, daysAgo: 30);
    putAgedDownload($this->recent, daysAgo: 1);
    fakeBinaries();

    $this->artisan('clean')
        ->expectsConfirmation(CONFIRMATION, 'yes')
        ->expectsOutputToContain("Deleting old backup file from downloads [{$this->old}]")
        ->assertSuccessful();

    expect(Storage::disk('downloads')->exists($this->old))->toBeFalse()
        ->and(Storage::disk('downloads')->exists($this->recent))->toBeTrue();
});

it('takes the retention period from --days', function () {
    putAgedDownload($this->old, daysAgo: 30);
    putAgedDownload($this->recent, daysAgo: 5);
    fakeBinaries();

    // both are older than 3 days, though only one is older than the configured 7
    $this->artisan('clean', ['--days' => 3])
        ->expectsConfirmation(CONFIRMATION, 'yes')
        ->expectsOutputToContain('older than 3 days')
        ->assertSuccessful();

    expect(Storage::disk('downloads')->exists($this->old))->toBeFalse()
        ->and(Storage::disk('downloads')->exists($this->recent))->toBeFalse();
});

it('deletes nothing when the confirmation is declined', function () {
    putAgedDownload($this->old, daysAgo: 30);
    fakeBinaries();

    $this->artisan('clean')
        ->expectsConfirmation(CONFIRMATION, 'no')
        ->expectsOutputToContain('Cancelled at the confirmation prompt - nothing was deleted')
        ->expectsOutputToContain('clean --dry-run')
        ->assertSuccessful();

    expect(Storage::disk('downloads')->exists($this->old))->toBeTrue();
});

it('lists what it would delete with --dry-run, without asking', function () {
    putAgedDownload($this->old, daysAgo: 30);
    putAgedDownload($this->recent, daysAgo: 1);
    fakeBinaries();

    $this->artisan('clean', ['--dry-run' => true])
        ->expectsOutputToContain('Dry-run only')
        ->expectsOutputToContain('The following files would be deleted from downloads:')
        ->expectsOutputToContain($this->old)
        ->assertSuccessful();

    expect(Storage::disk('downloads')->exists($this->old))->toBeTrue();
});

it('says there is nothing to delete when every backup is recent', function () {
    putAgedDownload($this->recent, daysAgo: 1);
    fakeBinaries();

    $this->artisan('clean')
        ->expectsConfirmation(CONFIRMATION, 'yes')
        ->expectsOutputToContain('Nothing to delete from downloads')
        ->assertSuccessful();

    expect(Storage::disk('downloads')->exists($this->recent))->toBeTrue();
});

it('deletes old files from the remote with --remote', function () {
    fakeBinaries(['*lsjson*' => Process::result(output: rcloneListing([
        rcloneEntry('web1.example.com', daysAgo: 30, isDir: true),
        rcloneEntry($this->old, daysAgo: 30),
        rcloneEntry($this->recent, daysAgo: 1),
    ]))]);

    $this->artisan('clean', ['--remote' => true])
        ->expectsConfirmation(CONFIRMATION, 'yes')
        ->expectsOutputToContain("Deleting old backup file from remote filesystem [{$this->old}]")
        ->assertSuccessful();

    Process::assertRan(deletedFromRemote($this->old));

    // a recent file stays, and a directory is never passed to deletefile even
    // though its own modification time is old
    Process::assertNotRan(deletedFromRemote($this->recent));
    Process::assertNotRan(deletedFromRemote('web1.example.com'));
});

it('lists what it would delete from the remote with --dry-run', function () {
    fakeBinaries(['*lsjson*' => Process::result(output: rcloneListing([
        rcloneEntry($this->old, daysAgo: 30),
    ]))]);

    $this->artisan('clean', ['--remote' => true, '--dry-run' => true])
        ->expectsOutputToContain('The following files would be deleted from remote filesystem:')
        ->expectsOutputToContain($this->old)
        ->assertSuccessful();

    Process::assertNotRan(deletedFromRemote($this->old));
});

it('says there is nothing to delete from the remote', function () {
    fakeBinaries(['*lsjson*' => Process::result(output: rcloneListing([
        rcloneEntry($this->recent, daysAgo: 1),
    ]))]);

    $this->artisan('clean', ['--remote' => true])
        ->expectsConfirmation(CONFIRMATION, 'yes')
        ->expectsOutputToContain('Nothing to delete from remote filesystem')
        ->assertSuccessful();
});

it('fails when a remote file cannot be deleted, having tried the rest', function () {
    $second = backupPath(date: '20260802-003000', image: 333);

    fakeBinaries([
        '*lsjson*' => Process::result(output: rcloneListing([
            rcloneEntry($this->old, daysAgo: 30),
            rcloneEntry($second, daysAgo: 30),
        ])),
        '*'.basename($this->old) => Process::result(errorOutput: 'permission denied', exitCode: 1),
    ]);

    $this->artisan('clean', ['--remote' => true])
        ->expectsConfirmation(CONFIRMATION, 'yes')
        ->expectsOutputToContain('Could not delete old backup file from remote filesystem: permission denied')
        ->expectsOutputToContain('1 old backup file(s) could not be deleted')
        ->assertFailed();

    // the failure must not stop the run - the second file is still deleted
    Process::assertRan(deletedFromRemote($second));
});

it('fails when the remote listing cannot be read', function () {
    fakeBinaries(['*lsjson*' => Process::result(errorOutput: 'directory not found', exitCode: 3)]);

    $this->artisan('clean', ['--remote' => true])
        ->expectsConfirmation(CONFIRMATION, 'yes')
        ->expectsOutputToContain('Could not get remote file list: directory not found')
        ->assertFailed();
});

it('fails when the remote listing is not json', function () {
    fakeBinaries(['*lsjson*' => Process::result(output: 'not json at all')]);

    $this->artisan('clean', ['--remote' => true])
        ->expectsConfirmation(CONFIRMATION, 'yes')
        ->expectsOutputToContain('Could not decode json data for remote file list')
        ->assertFailed();
});

/*
| The floor under the retention period. A server that stops being backed up must not
| have its last good backups expired along with the rest, so the most recent
| KEEPLEAST_DAYS days of each server's backups survive whatever their age.
*/

it('keeps the most recent days of a server that stopped being backed up', function () {
    config()->set('blbackup.keepleast_days', 2);

    $oldest = backupPath(date: '20260725-003000', image: 100);
    putAgedDownload($oldest, daysAgo: 40);
    putAgedDownload($this->old, daysAgo: 30);
    putAgedDownload(backupPath(date: '20260731-003000', image: 110), daysAgo: 31);
    fakeBinaries();

    $this->artisan('clean', ['-v' => true])
        ->expectsConfirmation(CONFIRMATION, 'yes')
        ->expectsOutputToContain("Keeping old backup file in downloads [{$this->old}]")
        ->assertSuccessful();

    // every one is past the retention period; only the oldest day is past the floor
    expect(Storage::disk('downloads')->exists($this->old))->toBeTrue()
        ->and(Storage::disk('downloads')->exists(backupPath(date: '20260731-003000', image: 110)))->toBeTrue()
        ->and(Storage::disk('downloads')->exists($oldest))->toBeFalse();
});

it('holds each server to its own floor', function () {
    config()->set('blbackup.keepleast_days', 1);

    $stopped = backupPath(server: 'web2.example.com', short: 'web2', date: '20260801-003000', image: 200);
    putAgedDownload($stopped, daysAgo: 30);
    putAgedDownload($this->old, daysAgo: 30);
    putAgedDownload($this->recent, daysAgo: 1);
    fakeBinaries();

    $this->artisan('clean')
        ->expectsConfirmation(CONFIRMATION, 'yes')
        ->assertSuccessful();

    // web1 still backs up, so its old file goes; web2's last backup is all it has
    expect(Storage::disk('downloads')->exists($this->old))->toBeFalse()
        ->and(Storage::disk('downloads')->exists($this->recent))->toBeTrue()
        ->and(Storage::disk('downloads')->exists($stopped))->toBeTrue();
});

it('counts days rather than files, so backups taken together are one day of cover', function () {
    config()->set('blbackup.keepleast_days', 2);

    // two backups on the newest day must not use up both days of the floor
    $again = backupPath(date: '20260801-093000', image: 112);
    $dayBefore = backupPath(date: '20260731-003000', image: 110);
    $oldest = backupPath(date: '20260725-003000', image: 100);
    putAgedDownload($this->old, daysAgo: 30);
    putAgedDownload($again, daysAgo: 30);
    putAgedDownload($dayBefore, daysAgo: 31);
    putAgedDownload($oldest, daysAgo: 37);
    fakeBinaries();

    $this->artisan('clean')
        ->expectsConfirmation(CONFIRMATION, 'yes')
        ->assertSuccessful();

    expect(Storage::disk('downloads')->exists($this->old))->toBeTrue()
        ->and(Storage::disk('downloads')->exists($again))->toBeTrue()
        ->and(Storage::disk('downloads')->exists($dayBefore))->toBeTrue()
        ->and(Storage::disk('downloads')->exists($oldest))->toBeFalse();
});

it('keeps the floor with --days, which moves only the retention period', function () {
    config()->set('blbackup.keepleast_days', 1);

    putAgedDownload($this->old, daysAgo: 30);
    putAgedDownload($this->recent, daysAgo: 5);
    fakeBinaries();

    $this->artisan('clean', ['--days' => 3])
        ->expectsConfirmation(CONFIRMATION, 'yes')
        ->assertSuccessful();

    expect(Storage::disk('downloads')->exists($this->old))->toBeFalse()
        ->and(Storage::disk('downloads')->exists($this->recent))->toBeTrue();
});

it('expires strictly by age when the floor is turned off', function () {
    config()->set('blbackup.keepleast_days', 0);

    putAgedDownload($this->old, daysAgo: 30);
    fakeBinaries();

    $this->artisan('clean')
        ->expectsConfirmation(CONFIRMATION, 'yes')
        ->assertSuccessful();

    expect(Storage::disk('downloads')->exists($this->old))->toBeFalse();
});

it('keeps the most recent days of a server on the remote too', function () {
    config()->set('blbackup.keepleast_days', 1);

    $oldest = backupPath(date: '20260725-003000', image: 100);
    $stopped = backupPath(server: 'web2.example.com', short: 'web2', date: '20260801-003000', image: 200);

    fakeBinaries(['*lsjson*' => Process::result(output: rcloneListing([
        rcloneEntry('web1.example.com', daysAgo: 40, isDir: true),
        rcloneEntry($oldest, daysAgo: 40),
        rcloneEntry($this->old, daysAgo: 30),
        rcloneEntry('web2.example.com', daysAgo: 30, isDir: true),
        rcloneEntry($stopped, daysAgo: 30),
    ]))]);

    $this->artisan('clean', ['--remote' => true])
        ->expectsConfirmation(CONFIRMATION, 'yes')
        ->assertSuccessful();

    Process::assertRan(deletedFromRemote($oldest));
    Process::assertNotRan(deletedFromRemote($this->old));
    Process::assertNotRan(deletedFromRemote($stopped));
});

it('keeps three days of each server unless told otherwise', function () {
    // the default, read from the config file rather than the suite's pin of 0
    $config = require base_path('config/blbackup.php');

    expect($config['keepleast_days'])->toBe(3);
})->skip(fn () => env('KEEPLEAST_DAYS') !== null, 'KEEPLEAST_DAYS is set in this environment');
