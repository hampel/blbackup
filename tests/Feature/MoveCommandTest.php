<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->path = backupPath();
});

/**
 * No --progress: the test output is not decorated, and neither is cron's. rclone
 * is only asked to repaint a progress block when something is there to draw it.
 */
function movedTo(string $source, string $destination): Closure
{
    return fn (PendingProcess $process) => $process->command
        === "/usr/bin/rclone moveto {$source} {$destination}";
}

function anyMove(): Closure
{
    return fn (PendingProcess $process) => str_contains($process->command, 'moveto');
}

it('moves a file to the configured remote', function () {
    putDownload($this->path, MEGABYTE);
    fakeBinaries();

    $this->artisan('move', ['file' => $this->path])
        ->expectsOutputToContain("Moving backup file from [{$this->path}]")
        ->expectsOutputToContain('Completed move to remote')
        ->assertSuccessful();

    Process::assertRan(movedTo(downloadPath($this->path), "remote:backups/{$this->path}"));
});

it('checks the remote before moving anything', function () {
    putDownload($this->path, MEGABYTE);
    fakeBinaries();

    $this->artisan('move', ['file' => $this->path])->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => $process->command === '/usr/bin/rclone lsd --quiet remote:backups');
});

it('moves every file with --all', function () {
    $second = backupPath('db1.example.com', 'db1', image: 222);
    putDownload($this->path, MEGABYTE);
    putDownload($second, MEGABYTE);
    fakeBinaries();

    $this->artisan('move', ['--all' => true])->assertSuccessful();

    Process::assertRan(movedTo(downloadPath($this->path), "remote:backups/{$this->path}"));
    Process::assertRan(movedTo(downloadPath($second), "remote:backups/{$second}"));
});

it('moves to the remote named by --remote', function () {
    putDownload($this->path, MEGABYTE);
    fakeBinaries();

    $this->artisan('move', ['file' => $this->path, '--remote' => 'other:elsewhere/'])
        ->assertSuccessful();

    Process::assertRan(movedTo(downloadPath($this->path), "other:elsewhere/{$this->path}"));
});

it('fails when no remote is configured', function () {
    config(['binarylane.rclone.remote' => null]);
    putDownload($this->path, MEGABYTE);
    fakeBinaries();

    $this->artisan('move', ['file' => $this->path])
        ->expectsOutputToContain('No remote configured')
        ->assertFailed();

    Process::assertNothingRan();
});

it('fails when the remote does not answer', function () {
    putDownload($this->path, MEGABYTE);
    fakeBinaries(['*lsd*' => Process::result(errorOutput: "didn't find section in config file", exitCode: 1)]);

    $this->artisan('move', ['file' => $this->path])
        ->expectsOutputToContain('Invalid remote')
        ->assertFailed();

    Process::assertNotRan(anyMove());
});

it('fails when the file does not exist', function () {
    fakeBinaries();

    $this->artisan('move', ['file' => 'web1.example.com/nothing.zst'])
        ->expectsOutputToContain('Could not find file web1.example.com/nothing.zst')
        ->assertFailed();

    Process::assertNotRan(anyMove());
});

it('fails when given neither a file nor --all', function () {
    fakeBinaries();

    $this->artisan('move')
        ->expectsOutputToContain('No file specified. Specify --all option')
        ->assertFailed();

    Process::assertNotRan(anyMove());
});

it('says there is nothing to move when the disk is empty', function () {
    fakeBinaries();

    $this->artisan('move', ['--all' => true])
        ->expectsOutputToContain('Nothing to be moved')
        ->assertSuccessful();

    Process::assertNotRan(anyMove());
});

it('lists what it would move with --dry-run', function () {
    putDownload($this->path, MEGABYTE);
    fakeBinaries();

    $this->artisan('move', ['file' => $this->path, '--dry-run' => true])
        ->expectsOutputToContain('Dry-run only')
        ->expectsOutputToContain("Move [{$this->path}] to remote [remote:backups/{$this->path}]")
        ->assertSuccessful();

    Process::assertNotRan(anyMove());
});

it('reports a failed transfer', function () {
    putDownload($this->path, MEGABYTE);
    fakeBinaries(['*moveto*' => Process::result(errorOutput: 'Failed to copy: quota exceeded', exitCode: 1)]);

    $this->artisan('move', ['file' => $this->path])
        ->expectsOutputToContain('Could not move file to secondary storage: Failed to copy: quota exceeded')
        ->assertFailed();
});

it('fails when one of several files cannot be moved', function () {
    $second = backupPath('db1.example.com', 'db1', image: 222);
    putDownload($this->path, MEGABYTE);
    putDownload($second, MEGABYTE);

    fakeBinaries([
        '*'.basename($this->path).'*' => Process::result(errorOutput: 'Failed to copy: quota exceeded', exitCode: 1),
    ]);

    $this->artisan('move', ['--all' => true])
        ->expectsOutputToContain('1 backup file(s) could not be moved')
        ->assertFailed();

    // the failure must not stop the run - the second file is still attempted
    Process::assertRan(movedTo(downloadPath($second), "remote:backups/{$second}"));
});

it('runs rclone from the storage path', function () {
    putDownload($this->path, MEGABYTE);
    fakeBinaries();

    $this->artisan('move', ['file' => $this->path])->assertSuccessful();

    // the working directory is deliberate: rclone resolves a directory-based
    // remote against it, so a wrong cwd breaks those remotes only
    Process::assertRan(fn (PendingProcess $process) => str_contains($process->command, 'moveto')
        && $process->path === storage_path());
});


it('asks rclone for a progress block only when something will draw it', function () {
    putDownload($this->path, MEGABYTE);
    fakeBinaries();

    // an hour of --progress repainting twice a second goes to whatever cron
    // redirects stdout to, and nothing rotates that file
    $this->artisan('move', ['file' => $this->path, '--ansi' => true])->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => str_contains($process->command, ' --progress moveto'));
});
