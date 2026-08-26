<?php

namespace App\Support;

/**
 * Taking the backup lock for the length of a command
 *
 * A command called by another that already holds the lock - the stages cron
 * runs - leaves it alone, both to take and to release.
 */
trait LocksBackups
{
    /**
     * Whether this command is the one holding the lock, and so the one that
     * gives it back.
     */
    protected bool $holdsLock = false;

    /**
     * Why the lock could not be taken, for a run that has to be reported as one
     * that never happened rather than just exit.
     */
    protected ?string $lockFailure = null;

    /**
     * @return bool false if another run holds the lock
     */
    protected function acquireLock() : bool
    {
        if (!$this->locks)
        {
            return true;
        }

        // a dry run changes nothing, so there is nothing to hold anyone off -
        // and being able to ask what clean would delete while a backup is
        // running is the point of having the flag
        if ($this->input->hasOption('dry-run') && $this->option('dry-run'))
        {
            return true;
        }

        $lock = $this->app->make(BackupLock::class);

        if ($lock->isHeld())
        {
            return true;
        }

        try
        {
            if (!$lock->acquire($this->getName()))
            {
                $holder = $lock->holder();

                $this->lockFailure = "Another backup is still running [{$holder}] - this run was skipped";

                $this->log(
                    'error',
                    $this->lockFailure,
                    "Another backup is still running - skipping this run",
                    ['holder' => $holder, 'lock' => $lock->path()]
                );

                return false;
            }
        }
        catch (\RuntimeException $e)
        {
            $this->lockFailure = $e->getMessage();

            $this->log('error', $e->getMessage(), $e->getMessage(), ['lock' => $lock->path()]);

            return false;
        }

        $this->holdsLock = true;

        return true;
    }

    protected function releaseLock() : void
    {
        if ($this->holdsLock)
        {
            $this->app->make(BackupLock::class)->release();

            $this->holdsLock = false;
        }
    }
}
