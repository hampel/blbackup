<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Process\ProcessResult;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

class Check extends BaseCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'check
                            {file? : file to check, relative to download path}
                            {--all : check all files}
                            {--dry-run : don\'t check any files, test only}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check downloaded backup files for zstd validity';

    protected string $commandContext = 'check';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $file = $this->argument('file');

        if ($this->option('all'))
        {
            $file = '';
        }
        elseif (empty($file))
        {
            $this->fail("No file specified. Specify --all option to check all backup files");
        }
        elseif (!Storage::disk('downloads')->exists($file))
        {
            $this->fail("Could not find file {$file}");
        }

        if ($this->option('dry-run'))
        {
            $this->line("Dry-run only");
        }

        if ($this->option('all'))
        {
            collect(Storage::disk('downloads')->allFiles(''))
                ->tap(function (Collection $collection) {
                    if ($collection->count() === 0)
                    {
                        $this->line("Nothing to be checked");
                    }
                    elseif ($this->option('dry-run'))
                    {
                        $this->line("The following files would be checked in downloads:");
                    }
                })
                ->each(function ($path) {
                    $this->checkFile($path);
                });
        }
        else
        {
            return $this->checkFile($file) ? self::SUCCESS : self::FAILURE;
        }

        return self::SUCCESS;
    }

    protected function checkFile(string $path) : bool
    {
        if (!Storage::disk('downloads')->exists($path))
        {
            $this->log('warning', "Backup file [{$path}] does not exist");
            return false;
        }

        if ($this->option('dry-run'))
        {
            $this->line("Check [{$path}]");
            return true;
        }

        return $this->testDownload(Storage::disk('downloads')->path($path));
    }

    protected function testDownload(string $path) : bool
    {
        $binary = config('binarylane.zstd_binary');

        $cmd = "{$binary} --test --no-progress --quiet {$path}";

        $this->logCmd('zstd test', $cmd);

        $this->log(
            'notice',
            "Testing download [{$path}]",
            "Testing download",
            compact('path')
        );

        $result = Process::path(Storage::disk('downloads')->path(''))->run($cmd);

        if ($result->failed())
        {
            $output = trim($result->errorOutput());

            $this->log(
                'error',
                "Downloaded file failed zstd test: " . $output,
                "Downloaded file failed zstd test",
                compact('path', 'output', 'cmd')
            );
        }
        else
        {
            $this->log(
                'notice',
                "zstd test successful",
                "zstd test successful");
        }

        return $result->successful();
    }

    /**
     * Define the command's schedule.
     */
    public function schedule(Schedule $schedule): void
    {
        // $schedule->command(static::class)->everyMinute();
    }
}
