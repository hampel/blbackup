<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\File;

/**
 * The lock that keeps two backup runs off each other
 *
 * A backup here is a multi-gigabyte download of a disk image, and a night that
 * overruns into the next one is not an exotic case - it is what a slow link or a
 * grown server does. Two runs on top of each other fight over the same download
 * path and the same remote.
 *
 * Held for as long as the process lives: flock is released by the kernel when
 * the process ends, however it ends, so a run that is killed leaves nothing to
 * clean up and no stale lock to break by hand. The file is only somewhere to
 * hang the lock and to record who holds it.
 *
 * Bound as a singleton, because cron takes the lock for a whole run and the
 * stages it calls have to see that it is already held - flock conflicts with
 * itself when the same process opens the same file twice.
 */
class BackupLock
{
    /**
     * @var resource|null
     */
    protected $handle = null;

    /**
     * Take the lock, recording who has it.
     *
     * @param string $command the command taking it
     * @return bool false if another run holds it
     * @throws \RuntimeException if the lock file cannot be opened
     */
    public function acquire(string $command) : bool
    {
        $path = $this->path();

        File::ensureDirectoryExists(dirname($path));

        $handle = @fopen($path, 'c');

        if ($handle === false)
        {
            throw new \RuntimeException("Could not open lock file [{$path}]");
        }

        if (!flock($handle, LOCK_EX | LOCK_NB))
        {
            fclose($handle);

            return false;
        }

        // leave enough behind to say what holds it, for whoever gets skipped
        ftruncate($handle, 0);
        fwrite($handle, sprintf(
            "pid %d, %s, started %s",
            getmypid(),
            $command,
            Carbon::now()->toDateTimeString()
        ));
        fflush($handle);

        $this->handle = $handle;

        return true;
    }

    public function release() : void
    {
        if (is_resource($this->handle))
        {
            fclose($this->handle);

            $this->handle = null;
        }
    }

    /**
     * @return bool whether this process already holds it
     */
    public function isHeld() : bool
    {
        return is_resource($this->handle);
    }

    /**
     * @return string what the lock file says about whoever holds it
     */
    public function holder() : string
    {
        return trim((string) @file_get_contents($this->path()));
    }

    public function path() : string
    {
        return config('blbackup.lock_file');
    }
}
