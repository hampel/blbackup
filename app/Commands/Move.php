<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Process\ProcessResult;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

class Move extends BaseCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'move
                            {file? : file to move, relative to download path}
                            {--all : move all files}
                            {--dry-run : don\'t move any files, test only}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Move downloaded backup files to secondary storage';

    protected string $commandContext = 'move';

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
            $this->fail("No file specified. Specify --all option to move all backup files");
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
                        $this->line("Nothing to be moved");
                    }
                    elseif ($this->option('dry-run'))
                    {
                        $this->line("The following files would be moved from downloads to remote:");
                    }
                })
                ->each(function ($path) {
                    $this->moveFile($path);
                });
        }
        else
        {
            $this->moveFile($file);
        }

        return self::SUCCESS;
    }

    protected function moveFile(string $path) : bool
    {
        if (!Storage::disk('downloads')->exists($path))
        {
            $this->log('warning', "Backup file [{$path}] does not exist");
            return false;
        }

        if ($this->option('dry-run'))
        {
            $this->line("Move [{$path}] to remote");
            return true;
        }

        $rclone = config('binarylane.rclone.binary');
        $sourcePath = Storage::disk('downloads')->path($path);
        $remotePath = rtrim(config('binarylane.rclone.remote'), '/') . '/' . $path;
        $verbosity = $this->getVerbosity();
        $dryrun = $this->option('dry-run') ? ' --dry-run' : '';
        $cmd = "{$rclone}{$verbosity}{$dryrun} --progress moveto {$sourcePath} {$remotePath}";

        $this->log(
            'notice',
            "Moving backup file from [{$path}] to [{$remotePath}]",
            "Moving backup file to secondary storage",
            compact('cmd')
        );

        $result = $this->processRclone($cmd, Storage::disk('downloads')->path(''), true);

        if ($result->failed())
        {
            $output = $result->errorOutput();

            $this->log(
                'error',
                "Could not move file to secondary storage: " . $output,
                "Could not move file to secondary storage",
                compact('output', 'cmd')
            );
        }

        return $result->successful();
    }

    protected function processRclone(string $cmd, string $path = '', bool $deleteLines = false, bool $treatStdErrAsOut = false) : ProcessResult
    {
        $count = 0;

        return Process::forever()
            ->path($path)
            ->run($cmd, function (string $type, string $output) use (&$count, $deleteLines, $treatStdErrAsOut) {

                if ($type === 'out' || $treatStdErrAsOut)
                {
                    if ($deleteLines)
                    {
                        $linecount = count(explode("\n", $output));

                        if ($count > 0)
                        {
                            $this->deleteLines($linecount);
                        }

                        $this->output->write($output);
                    }
                    else
                    {
                        $this->line($output);
                    }

                    $count++;
                }
                else
                {
                    $this->error($output);
                }
            });
    }

    /**
     * Define the command's schedule.
     */
    public function schedule(Schedule $schedule): void
    {
        // $schedule->command(static::class)->everyMinute();
    }
}
