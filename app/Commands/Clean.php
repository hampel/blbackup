<?php

namespace App\Commands;

use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class Clean extends BaseCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'clean
                            {--dry-run : Don\'t delete any files, just list what would be deleted}';

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
        $keeponly = config('binarylane.keeponly_days');
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
                    $this->line("Nothing to delete");
                }
                elseif ($this->option('dry-run'))
                {
                    $this->line("The following files would be deleted:");
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
                        "Deleting old backup file [{$path}]",
                        "Deleting old backup file",
                        compact('path')
                    );

                    Storage::disk('downloads')->delete($path);
                }

            });

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
