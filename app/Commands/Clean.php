<?php

namespace App\Commands;

use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Storage;

class Clean extends BaseCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'clean';

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
        $path = Storage::path('backups');
        $keeponly = config('binarylane.keeponly_days');
        $cutoff = Carbon::now()->subDays($keeponly)->timestamp;

        $this->log(
            'info',
            "Cleaning up old backups from [{$path}] older than {$keeponly} days",
            "Cleaning up old backups",
            compact('path', 'keeponly')
        );

        collect(Storage::allFiles('backups'))
            ->reject(function ($path) use ($cutoff) {
                return Storage::lastModified($path) > $cutoff;
            })
            ->each(function ($path) {
                $this->log(
                    'info',
                    "Deleting old backup file [{$path}]",
                    "Deleting old backup file",
                    compact('path')
                );

                Storage::delete($path);
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
