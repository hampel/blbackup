<?php

namespace App\Support;

/**
 * What a run did, collected as it happens
 *
 * State only: no rendering, no configuration, no framework. It is a container
 * singleton because the stages of a run are separate command objects - create
 * calls download, download calls check and move - and the thing being summarised
 * is the run, not any one of them.
 *
 * The distinction that makes this worth having: a log channel can only report
 * trouble, so a night where everything worked produces nothing at all, which
 * looks exactly like a cron entry nobody installed. One message per run saying
 * what it did is the only thing that tells those apart.
 */
class RunSummary
{
    protected ?float $startedAt = null;

    protected bool $dryRun = false;

    protected ?string $blockedBy = null;

    /**
     * The command that owns this run and will report it. The stages it calls
     * see that it is taken and leave it alone.
     */
    protected ?string $owner = null;

    /**
     * @var array<int, array{server: string, seconds: float}>
     */
    protected array $backups = [];

    /**
     * @var array<int, array{server: string, path: string, bytes: int}>
     */
    protected array $downloads = [];

    /**
     * @var array<int, array{path: string, bytes: int}>
     */
    protected array $moves = [];

    /**
     * @var array<int, string>
     */
    protected array $deletions = [];

    /**
     * @var array<int, array{item: string, stage: string, message: string}>
     */
    protected array $failures = [];

    /**
     * Claim the run for a command.
     *
     * @return bool true for the first command to ask, which is the one that
     *              reports; false for every stage it calls
     */
    public function claim(string $command, bool $dryRun = false) : bool
    {
        if ($this->owner !== null)
        {
            return false;
        }

        $this->owner = $command;
        $this->dryRun = $dryRun;
        $this->startedAt = microtime(true);

        return true;
    }

    public function ownedBy() : ?string
    {
        return $this->owner;
    }

    /**
     * The run could not start at all - configuration missing, nothing to work
     * with. Recorded without counts, because a row of zeroes reads as a run that
     * started and found nothing.
     */
    public function block(string $reason) : void
    {
        $this->blockedBy = $reason;
    }

    /**
     * @return bool whether the run got as far as doing anything at all, which is
     *              what separates a run that failed from one that never started
     */
    public function hasWork() : bool
    {
        return $this->backups !== []
            || $this->downloads !== []
            || $this->moves !== []
            || $this->deletions !== []
            || $this->failures !== [];
    }

    public function blockedBy() : ?string
    {
        return $this->blockedBy;
    }

    public function isDryRun() : bool
    {
        return $this->dryRun;
    }

    public function recordBackup(string $server, float $seconds) : void
    {
        $this->backups[] = ['server' => $server, 'seconds' => $seconds];
    }

    public function recordDownload(string $server, string $path, int $bytes) : void
    {
        $this->downloads[] = ['server' => $server, 'path' => $path, 'bytes' => $bytes];
    }

    public function recordMove(string $path, int $bytes) : void
    {
        $this->moves[] = ['path' => $path, 'bytes' => $bytes];
    }

    public function recordDeletion(string $path) : void
    {
        $this->deletions[] = $path;
    }

    public function recordFailure(string $item, string $stage, string $message) : void
    {
        $this->failures[] = ['item' => $item, 'stage' => $stage, 'message' => $message];
    }

    public function failed() : bool
    {
        return !empty($this->failures) || $this->blockedBy !== null;
    }

    /**
     * @return array<int, array{item: string, stage: string, message: string}>
     */
    public function failures() : array
    {
        return $this->failures;
    }

    public function backupCount() : int
    {
        return count($this->backups);
    }

    public function downloadCount() : int
    {
        return count($this->downloads);
    }

    public function moveCount() : int
    {
        return count($this->moves);
    }

    public function deletionCount() : int
    {
        return count($this->deletions);
    }

    /**
     * @return int bytes downloaded, which is the figure worth reporting - it is
     *             what filled the disk and what took the time
     */
    public function bytes() : int
    {
        return array_sum(array_column($this->downloads, 'bytes'));
    }

    public function movedBytes() : int
    {
        return array_sum(array_column($this->moves, 'bytes'));
    }

    /**
     * @return array<int, string> the servers this run touched, each once
     */
    public function servers() : array
    {
        return array_values(array_unique(array_merge(
            array_column($this->backups, 'server'),
            array_column($this->downloads, 'server')
        )));
    }

    public function serverCount() : int
    {
        return count($this->servers());
    }

    public function seconds() : float
    {
        return $this->startedAt === null ? 0.0 : microtime(true) - $this->startedAt;
    }
}
