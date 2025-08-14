<?php

namespace App\Commands;

use Carbon\CarbonInterval;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Process\ProcessResult;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;

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
                            {--remote= : rclone remote to move file to}
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
        $remote = $this->option('remote') ?? config('binarylane.rclone.remote');
        if (empty($remote))
        {
            $this->fail("No remote configured - specify RCLONE_REMOTE in .env file or use --remote option");
        }
        elseif (!$this->isValidRemote($remote))
        {
            $this->fail("Invalid remote - please refer to rclone documentation for usage");
        }

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
                ->each(function ($path) use ($remote) {
                    $this->moveFile($remote, $path);
                });
        }
        else
        {
            $this->moveFile($remote, $file);
        }

        return self::SUCCESS;
    }

    protected function moveFile(string $remote, string $path) : bool
    {
        if (!Storage::disk('downloads')->exists($path))
        {
            $this->log('warning', "Backup file [{$path}] does not exist");
            return false;
        }

        $remotePath = rtrim($remote, '/') . '/' . $path;

        if ($this->option('dry-run'))
        {
            $this->line("Move [{$path}] to remote [{$remotePath}]");
            return true;
        }

        $size = Storage::disk('downloads')->size($path);

        $rclone = config('binarylane.rclone.binary');
        $sourcePath = Storage::disk('downloads')->path($path);

        $verbosity = $this->getVerbosity();
        $dryrun = $this->option('dry-run') ? ' --dry-run' : '';
        $cmd = "{$rclone}{$verbosity}{$dryrun} --progress moveto {$sourcePath} {$remotePath}";

        $this->logCmd('rclone moveto', $cmd);

        $this->log(
            'notice',
            "Moving backup file from [{$path}] to [{$remotePath}]",
            "Moving backup file to secondary storage",
            ['source' => $path, 'remote' => $remotePath]
        );

        $start = now();

        $result = $this->processRclone($cmd, storage_path(), true);

        if ($result->successful())
        {
            $seconds = $start->diffInSeconds(now());
            $mb = $size / (1024 * 1024);
            $speed = Number::format($mb / $seconds, 1);

            $elapsed = CarbonInterval::seconds($seconds)->cascade()->forHumans();
            $secondsFormatted = Number::format($seconds, 1);

            $this->newLine();
            $this->log(
                'notice',
                "Completed move to remote in {$elapsed} ({$speed} MB/s)",
                "Completed move to remote",
                ['remote' => $remotePath, 'elapsed' => $elapsed, 'seconds' => $secondsFormatted, 'megabytes_per_second' => $speed]
            );
            $this->newLine();

            return true;
        }
        else
        {
            $output = trim($result->errorOutput());

            $this->log(
                'error',
                "Could not move file to secondary storage: " . $output,
                "Could not move file to secondary storage",
                compact('output', 'cmd')
            );

            return false;
        }
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

    protected function isValidRemote(string $remote) : bool
    {
        $rclone = config('binarylane.rclone.binary');
        $cmd = "{$rclone} lsd --quiet {$remote}";

        $this->logCmd('rclone lsd', $cmd);

        $result = Process::path(storage_path())->run($cmd);

        if ($result->failed())
        {
            $output = trim($result->errorOutput());

            $this->log(
                'error',
                "Could not get remote directory list: " . $output,
                "Could not get remote directory list",
                compact('output')
            );
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
