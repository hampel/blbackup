<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/*
| One lock covers every command that writes, so a run that overruns holds the
| next one off rather than running over the top of it.
|
| flock is associated with the open file description rather than the process, so
| a second fopen() of the same path conflicts even from here - which is what
| makes these tests possible and, less conveniently, why BackupLock has to be a
| singleton: cron opening it and then create opening it again would deadlock the
| run against itself.
*/


it('skips a run while another one holds the lock', function () {
    $handle = holdLock();

    fakeApi([fakeServer()]);
    fakeBinaries();

    $this->artisan('cron', ['--no-clean' => true])
        ->expectsOutputToContain('Another backup is still running [pid 999, cron, started 2026-08-26 02:00:00]')
        ->assertFailed();

    // nothing was started, rather than started and abandoned
    Process::assertDidntRun(fn ($process) => str_contains($process->command, 'wget'));

    fclose($handle);
});

it('skips a stage run by hand while a backup is running', function () {
    $handle = holdLock();

    putDownload(backupPath(), MEGABYTE);
    fakeBinaries();

    $this->artisan('move', ['file' => backupPath()])->assertFailed();

    Process::assertDidntRun(fn ($process) => str_contains($process->command, 'moveto'));

    expect(Storage::disk('downloads')->exists(backupPath()))->toBeTrue();

    fclose($handle);
});

it('gives the lock back when the command finishes', function () {
    putDownload(backupPath(), MEGABYTE);
    fakeBinaries();

    $this->artisan('move', ['file' => backupPath()])->assertSuccessful();

    // asserted on the lock rather than by running a second command: the
    // singleton reports a lock this process never gave back as one it holds, so
    // every later command short-circuits past it and the run looks fine
    expect(app(App\Support\BackupLock::class)->isHeld())->toBeFalse();
});

it('lets a dry run through, because it changes nothing', function () {
    $handle = holdLock();

    putAgedDownload(backupPath(), daysAgo: 30);
    fakeBinaries();

    // asking what clean would delete while a backup is running is the point of
    // having the flag
    $this->artisan('clean', ['--dry-run' => true])
        ->expectsOutputToContain(backupPath())
        ->assertSuccessful();

    fclose($handle);
});

it('does not lock the commands that only read', function () {
    $handle = holdLock();

    fakeApi([fakeServer()], [fakeImage()]);

    $this->artisan('servers')->assertSuccessful();
    $this->artisan('backups')->assertSuccessful();
    $this->artisan('account')->assertSuccessful();

    fclose($handle);
});

it('fails rather than running unlocked when the lock file cannot be opened', function () {
    $path = storage_path('framework/testing/unopenable.lock');

    File::ensureDirectoryExists($path);

    config(['blbackup.lock_file' => $path]);

    fakeApi([fakeServer()]);
    fakeBinaries();

    // a lock that cannot be taken is not a lock that does not matter
    $this->artisan('cron', ['--no-clean' => true])
        ->expectsOutputToContain('Could not open lock file')
        ->assertFailed();

    Process::assertDidntRun(fn ($process) => str_contains($process->command, 'wget'));
});
