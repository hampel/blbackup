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
        else
        {
            if (!$this->option('no-interaction') && !$this->confirm("This operation cannot be undone. Continue ?")) {
                $this->line("Operation aborted by user");
                return self::SUCCESS;
            }
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
                }

            });

        if ($this->option('remote'))
        {
            $rclone = config('binarylane.rclone.binary');
            $remotePath = rtrim(config('binarylane.rclone.remote'), '/');

            $cmd = "{$rclone} lsjson -R  {$remotePath}";

            $result = Process::run($cmd);

            if ($result->failed())
            {
                $output = $result->errorOutput();

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

            collect($files)
                ->reject(function ($file) use ($cutoff) {
                    $modTime = Carbon::createFromFormat("Y-m-d\TH:i:s.vP", $file['ModTime'])->timestamp;

                    return $file['IsDir'] || $modTime > $cutoff;
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
                ->each(function ($file) use ($rclone, $remotePath, $verbosity) {

                    $path = $file['Path'];

                    if ($this->option('dry-run'))
                    {
                        $this->line($path);
                    }
                    else
                    {
                        $this->log(
                            'notice',
                            "Deleting old backup file from remote filesystem [{$path}]",
                            "Deleting old backup file from remote filesystem",
                            compact('path')
                        );

                        $cmd = "{$rclone}{$verbosity} deletefile {$remotePath}/{$path}";

                        $result = Process::run($cmd);

                        if ($result->failed())
                        {
                            $output = $result->errorOutput();

                            $this->log(
                                'error',
                                "Could not delete old backup file from remote filesystem: " . $output,
                                "Could not delete old backup file from remote filesystem",
                                compact('output')
                            );

                            return self::FAILURE;
                        }
                    }

                });
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
