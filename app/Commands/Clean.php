<?php

namespace App\Commands;

use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

class Clean extends BaseCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'clean
                            {--days= : Over-ride configuration and delete backups older than this number of days}
                            {--dry-run : Don\'t delete any files, just list what would be deleted}
                            {--remote : Also check remote files}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up old backup files';

    protected string $commandContext = 'clean';

    protected bool $locks = true;

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $keeponly = intval($this->option('days') ?? config('blbackup.keeponly_days'));
        $keepleast = max(0, intval(config('blbackup.keepleast_days')));
        $path = Storage::disk('downloads')->path('');

        $this->log(
            'notice',
            "Cleaning up old backups from [{$path}] older than {$keeponly} days,"
                . " keeping the most recent {$keepleast} days of each server",
            "Cleaning up old backups",
            compact('path', 'keeponly', 'keepleast')
        );

        if ($this->option('dry-run'))
        {
            $this->line("Dry-run only");
        }
        elseif ($this->input->isInteractive() && !$this->confirm("This operation cannot be undone. Continue ?"))
        {
            // isInteractive() rather than the --no-interaction flag, because that is
            // the state confirm() itself obeys - --quiet clears it too, and Symfony
            // throws rather than assuming a default when stdin cannot be read

            $this->log(
                'notice',
                "Cancelled at the confirmation prompt - nothing was deleted",
                "Clean cancelled at the confirmation prompt",
                compact('path', 'keeponly')
            );

            $this->line("Run <comment>clean --dry-run</comment> to see which files would be deleted.");

            // a run called off by hand is not one to tell Slack about - reaching the
            // prompt at all means somebody was watching, and they know what they did
            $this->summary->cancel();

            return self::SUCCESS;
        }

        $cutoff = Carbon::now()->subDays($keeponly)->timestamp;

        $local = collect(Storage::disk('downloads')->allFiles(''))
            ->mapWithKeys(function ($path) {
                return [$path => Storage::disk('downloads')->lastModified($path)];
            });

        $this->expired($local, $cutoff, $keepleast, 'downloads')
            ->tap(function (Collection $collection) {
                if ($collection->count() === 0)
                {
                    $this->line("Nothing to delete from downloads");
                }
                elseif ($this->option('dry-run'))
                {
                    $this->line("The following files would be deleted from downloads:");
                }
            })
            ->each(function ($path) {

                if ($this->option('dry-run'))
                {
                    $this->line($path);
                }
                else
                {
                    $this->log(
                        'notice',
                        "Deleting old backup file from downloads [{$path}]",
                        "Deleting old backup file from downloads",
                        compact('path')
                    );

                    Storage::disk('downloads')->delete($path);

                    $this->summary->recordDeletion($path);
                }

            });

        if ($this->option('remote'))
        {
            $rclone = config('blbackup.rclone.binary');
            $remotePath = rtrim(config('blbackup.rclone.remote'), '/');

            $cmd = "{$rclone} lsjson -R  {$remotePath}";

            $this->logCmd('rclone lsjson', $cmd);

            $result = Process::path(storage_path())->run($cmd);

            if ($result->failed())
            {
                $output = trim($result->errorOutput());

                $this->log(
                    'error',
                    "Could not get remote file list: " . $output,
                    "Could not get remote file list",
                    compact('output')
                );

                return self::FAILURE;
            }

            try
            {
                $files = json_decode($result->output(), true, 512, JSON_THROW_ON_ERROR);
            }
            catch (\JsonException $e)
            {
                $this->log(
                    'error',
                    "Could not decode json data for remote file list: " . $e->getMessage(),
                    "Could not decode json data",
                    ['error' => $e->getMessage()]
                );

                return self::FAILURE;
            }

            $verbosity = $this->getVerbosity();

            $remote = collect($files)
                // directories are dropped before their times are parsed at all
                ->reject(fn ($file) => $file['IsDir'])
                ->mapWithKeys(function ($file) {
                    // rclone emits RFC3339 with nanosecond precision, and some
                    // backends use Z rather than an offset - too variable for a
                    // fixed format string, which threw rather than failing the
                    // command
                    return [$file['Path'] => Carbon::parse($file['ModTime'])->timestamp];
                });

            $failed = $this->expired($remote, $cutoff, $keepleast, 'remote filesystem')
                ->tap(function (Collection $collection) {
                    if ($collection->count() === 0)
                    {
                        $this->line("Nothing to delete from remote filesystem");
                    }
                    elseif ($this->option('dry-run'))
                    {
                        $this->line("The following files would be deleted from remote filesystem:");
                    }
                })
                // reject rather than each, so one failed deletion doesn't stop
                // the run and what is left is the files still on the remote.
                // A return from inside each() only returns from the closure.
                ->reject(function ($path) use ($rclone, $remotePath, $verbosity) {

                    if ($this->option('dry-run'))
                    {
                        $this->line($path);

                        return true;
                    }

                    $this->log(
                        'notice',
                        "Deleting old backup file from remote filesystem [{$path}]",
                        "Deleting old backup file from remote filesystem",
                        compact('path')
                    );

                    $this->summary->recordDeletion($path);

                    $cmd = "{$rclone}{$verbosity} deletefile {$remotePath}/{$path}";

                    $this->logCmd('rclone deletefile', $cmd);

                    $result = Process::path(storage_path())->run($cmd);

                    if ($result->failed())
                    {
                        $output = trim($result->errorOutput());

                        $this->log(
                            'error',
                            "Could not delete old backup file from remote filesystem: " . $output,
                            "Could not delete old backup file from remote filesystem",
                            compact('path', 'output')
                        );

                        $this->summary->recordFailure($path, 'clean', $output);

                        return false;
                    }

                    return true;
                });

            if ($failed->isNotEmpty())
            {
                $this->log(
                    'error',
                    "{$failed->count()} old backup file(s) could not be deleted from the remote filesystem",
                    "Old backup files could not be deleted from the remote filesystem",
                    ['count' => $failed->count(), 'files' => $failed->all()]
                );

                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

    /**
     * The backups old enough to delete, less the ones the floor holds back
     *
     * Age on its own eventually leaves nothing at all. A server that stops being backed
     * up - deleted, renamed, excluded, or failing every night - has its last good
     * backups expired along with the rest once they pass the retention period, and that
     * is found out when one is needed. So the most recent days of each server's backups
     * are kept whatever their age. Counted in days rather than files, so a second backup
     * taken by hand is not a second day of cover, and per server - the first directory
     * of the path - so one server that stopped keeps its own last backups while the
     * others expire normally. It only ever prevents a deletion.
     *
     * @param Collection $modified backup file path => modification timestamp
     * @return Collection the paths to delete
     */
    protected function expired(Collection $modified, int $cutoff, int $keepleast, string $where) : Collection
    {
        $held = $modified
            ->groupBy(fn ($timestamp, $path) => $this->serverOf($path), true)
            ->flatMap(function (Collection $server) use ($keepleast) {
                $days = $server->map(fn ($timestamp) => $this->backupDate($timestamp))
                    ->unique()
                    ->sortDesc()
                    ->take($keepleast);

                return $server->filter(fn ($timestamp) => $days->contains($this->backupDate($timestamp)))
                    ->keys();
            });

        return $modified
            ->filter(fn ($timestamp) => $timestamp <= $cutoff)
            ->keys()
            ->reject(function ($path) use ($held, $keepleast, $where) {
                if (!$held->contains($path))
                {
                    return false;
                }

                $this->log(
                    'info',
                    "Keeping old backup file in {$where} [{$path}] - it is among the most recent"
                        . " {$keepleast} days of backups for its server",
                    "Keeping old backup file as one of the most recent for its server",
                    compact('path', 'keepleast')
                );

                return true;
            })
            ->values();
    }

    protected function serverOf(string $path) : string
    {
        return str_contains($path, '/') ? strstr($path, '/', true) : '';
    }

    protected function backupDate(int $timestamp) : string
    {
        return Carbon::createFromTimestamp($timestamp, config('blbackup.timezone'))->toDateString();
    }

    /**
     * Define the command's schedule.
     */
    public function schedule(Schedule $schedule): void
    {
        // $schedule->command(static::class)->everyMinute();
    }
}
