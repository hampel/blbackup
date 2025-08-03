<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;

class Test extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:test
                            {--l|logs : generate test logs of varying log levels}
                            {--d|download= : test download of the specified URL}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test application configuration';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        if ($this->option('logs'))
        {
            $levels = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];
            foreach ($levels as $level)
            {
                $this->log($level, "This is a test log message");
            }

            $this->line("Log messages written - please check your logs");
            $this->info("Logging configuration");
            $this->info("---------------------");

            $logging = collect(config('logging'))->dot();
            foreach ($logging as $key => $value)
            {
                $this->line("$key: $value");
            }
        }
        elseif ($url = $this->option('download'))
        {
            $storage = Storage::disk('downloads')->path('');
            $path = tempnam($storage, 'download-test-');

            $this->line("Downloading [{$url}] to [{$path}]");

            $start = now();

            $progress = new ProgressBar($this->output, 100);
            $progress->start();

            $result = Http::sink($path)
                ->withOptions(['progress' => function ($downloadTotal, $downloadedBytes, $uploadTotal, $uploadedBytes) use ($progress) {
                    if ($downloadTotal > 0)
                    {
                        $progress->setProgress(intval(round(($downloadedBytes / $downloadTotal) * 100)));
                    }
                }])
                ->timeout(3600)
                ->get($url);

            $progress->finish();

            if ($result->successful())
            {
                $timeFormatted = Number::format($start->diffInSeconds(now()), 1);
                $this->newLine();
                $this->line("Completed download in {$timeFormatted} seconds");
                $this->newLine();
            }
            else
            {
                $this->fail($result->reason());
            }
        }
        else
        {
            $this->line("Please specify a test type: --logs | --download");
        }

        return Command::SUCCESS;
    }

    protected function log($level, $message, $logMessage = null, $context = [])
    {
        $verbosityMap = [
            'debug' => OutputInterface::VERBOSITY_DEBUG,
            'info' => OutputInterface::VERBOSITY_VERBOSE,
            'notice' => OutputInterface::VERBOSITY_NORMAL,
            'warning' => OutputInterface::VERBOSITY_NORMAL,
            'error' => OutputInterface::VERBOSITY_QUIET,
            'critical' => OutputInterface::VERBOSITY_QUIET,
            'alert' => OutputInterface::VERBOSITY_QUIET,
            'emergency' => OutputInterface::VERBOSITY_QUIET,
        ];

        $styleMap = [
            'debug' => null,
            'info' => 'info',
            'notice' => 'comment',
            'warning' => 'comment',
            'error' => 'error',
            'critical' => 'error',
            'alert' => 'error',
            'emergency' => 'error',
        ];

        $logMessage = $logMessage ?? $message;
        $verbosity = $verbosityMap[$level] ?? 'warning';
        $style = $styleMap[$level] ?? null;

        Log::log($level, $logMessage, $context);
        $this->line($message, $style, $verbosity);
    }

    /**
     * Define the command's schedule.
     */
    public function schedule(Schedule $schedule): void
    {
         //
    }
}
