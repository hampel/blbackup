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
        $keeponly = intval($this->option('days') ?? config('binarylane.keeponly_days'));
        $path = Storage::disk('downloads')->path('');

        $this->log(
            'notice',
            "Cleaning up old backups from [{$path}] older than {$keeponly} days",
            "Cleaning up old backups",
            compact('path', 'keeponly')
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

        collect(Storage::disk('downloads')->allFiles(''))
            ->reject(function ($path) use ($cutoff) {
                return Storage::disk('downloads')->lastModified($path) > $cutoff;
            })
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
            $rclone = config('binarylane.rclone.binary');
            $remotePath = rtrim(config('binarylane.rclone.remote'), '/');

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

            $failed = collect($files)
                ->reject(function ($file) use ($cutoff) {
                    // rclone emits RFC3339 with nanosecond precision, and some
                    // backends use Z rather than an offset - too variable for a
                    // fixed format string, which threw rather than failing the
                    // command. Directories are rejected before parsing at all.
                    return $file['IsDir'] || Carbon::parse($file['ModTime'])->timestamp > $cutoff;
                })
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
                ->reject(function ($file) use ($rclone, $remotePath, $verbosity) {

                    $path = $file['Path'];

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
                    ['count' => $failed->count(), 'files' => $failed->pluck('Path')->all()]
                );

                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

    /**
     * Define the command's schedule.
     */
    public function schedule(Schedule $schedule): void
    {
        // $schedule->command(static::class)->everyMinute();
    }
}
