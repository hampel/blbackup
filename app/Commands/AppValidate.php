<?php

namespace App\Commands;

use App\Exceptions\BinaryLaneException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

class AppValidate extends BaseCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:validate
                            {--no-api : skip the checks that call the BinaryLane API}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check this machine can do what the configuration says';

    protected string $commandContext = 'validate';

    /**
     * Whether any check has failed, which decides the exit code.
     */
    protected bool $failed = false;

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->section("Environment");
        $this->checkPhp();
        $this->checkIntl();
        $this->checkTimezone();

        $this->section("Storage");
        $this->checkDownloadPath();
        $this->checkLogging();

        $this->section("External commands");
        $this->checkBinary('zstd', config('binarylane.zstd_binary'), '--version');
        $this->checkBinary('wget', config('binarylane.wget_binary'), '--version');
        $this->checkBinary('rclone', config('binarylane.rclone.binary'), '--version');
        $this->checkRemote();

        $this->section("BinaryLane API");
        $this->checkApi();

        $this->newLine();

        if ($this->failed)
        {
            $this->line("Validation failed - this machine cannot do what the configuration says");
            $this->newLine();

            $this->record('error', "Validation failed");

            return self::FAILURE;
        }

        $this->line("All checks passed");
        $this->newLine();

        return self::SUCCESS;
    }

    protected function checkPhp() : void
    {
        $this->reportOk("PHP", phpversion());
    }

    protected function checkIntl() : void
    {
        // Illuminate\Support\Number, used to format sizes and speeds in create,
        // download and move, needs ext-intl and fatals without it
        if (extension_loaded('intl'))
        {
            $this->reportOk("ext-intl", "loaded");
        }
        else
        {
            $this->reportFail("ext-intl", "not loaded - reporting sizes and speeds will fatal");
        }
    }

    protected function checkTimezone() : void
    {
        $timezone = config('binarylane.timezone');

        if (in_array($timezone, timezone_identifiers_list()))
        {
            $this->reportOk("Timezone", $timezone);
        }
        else
        {
            $this->reportFail("Timezone", "[{$timezone}] is not a known timezone");
        }
    }

    protected function checkDownloadPath() : void
    {
        $path = Storage::disk('downloads')->path('');
        $probe = '.blbackup-validate';

        try
        {
            Storage::disk('downloads')->put($probe, 'probe');
            Storage::disk('downloads')->delete($probe);
        }
        catch (\Throwable $e)
        {
            $this->reportFail("Download path", "{$path} is not writable: {$e->getMessage()}");

            return;
        }

        $this->reportOk("Download path", "{$path} (writable)");

        $free = disk_free_space($path);

        if ($free !== false)
        {
            $this->reportOk("Free space", $this->formatBytes($free));
        }
    }

    protected function checkLogging() : void
    {
        $channel = config('logging.default');

        if ($channel === 'null')
        {
            $this->reportWarn("Log channel", "null - nothing is recorded anywhere");

            return;
        }

        $this->reportOk("Log channel", $channel);

        $channels = $channel === 'stack'
            ? config('logging.channels.stack.channels', [])
            : [$channel];

        foreach ($channels as $name)
        {
            $this->checkLogChannel(trim($name));
        }
    }

    protected function checkLogChannel(string $name) : void
    {
        $driver = config("logging.channels.{$name}.driver");

        if (in_array($driver, ['single', 'daily']))
        {
            $path = config("logging.channels.{$name}.path");
            $directory = dirname($path);

            if (is_writable($directory) || (file_exists($path) && is_writable($path)))
            {
                $this->reportOk("Log file ({$name})", $path);
            }
            else
            {
                $this->reportFail("Log file ({$name})", "{$path} is not writable");

                $this->stopLogging();
            }

            return;
        }

        if ($driver === 'slack')
        {
            if (empty(config("logging.channels.{$name}.url")))
            {
                $this->reportFail("Slack webhook", "channel [{$name}] is in the stack but no webhook is configured");
            }
            else
            {
                // never print the webhook: this output goes into support tickets
                $this->reportOk("Slack webhook", "set, posting at " . config("logging.channels.{$name}.level"));
            }

            return;
        }

        $this->reportOk("Log channel ({$name})", $driver ?? 'unknown driver');
    }

    protected function checkBinary(string $label, ?string $binary, string $versionFlag) : void
    {
        if (empty($binary))
        {
            $this->reportFail($label, "no path configured");

            return;
        }

        $result = Process::run("{$binary} {$versionFlag}");

        if ($result->failed())
        {
            $this->reportFail($label, "{$binary} could not be run: " . trim($result->errorOutput()));

            return;
        }

        $version = trim(strtok($result->output(), PHP_EOL) ?: '');

        $this->reportOk($label, "{$binary} ({$version})");
    }

    protected function checkRemote() : void
    {
        $remote = config('binarylane.rclone.remote');

        if (empty($remote))
        {
            // move and clean --remote are optional, so this is not a failure
            $this->reportSkip("rclone remote", "not configured - move and clean --remote are unavailable");

            return;
        }

        $result = Process::run(config('binarylane.rclone.binary') . " lsd --quiet {$remote}");

        if ($result->failed())
        {
            $this->reportFail("rclone remote", "{$remote} did not answer: " . trim($result->errorOutput()));

            return;
        }

        $this->reportOk("rclone remote", "{$remote} answered");
    }

    protected function checkApi() : void
    {
        if (empty(config('binarylane.api_token')))
        {
            $this->reportFail("API token", "not configured");

            return;
        }

        $this->reportOk("API token", "set");

        if ($this->option('no-api'))
        {
            $this->reportSkip("BinaryLane account", "skipped with --no-api");

            return;
        }

        try
        {
            $account = $this->api->account();

            $this->reportOk("BinaryLane account", "{$account['email']} ({$account['status']})");

            $servers = $this->api->servers();

            $this->reportOk("Servers visible", (string) count($servers));
        }
        catch (BinaryLaneException | ConnectionException $e)
        {
            $this->reportFail("BinaryLane API", $e->getMessage());
        }
    }

    /**
     * Take the log out of the run once it is known to be unwritable.
     *
     * Every command logs - App\Api logs each call - and Monolog throws when it
     * cannot open its file, so without this the checks after this point die
     * with a stack trace instead of reporting. Worth knowing that this is not
     * validate's problem alone: a log path that cannot be written takes any
     * command down on its first API call.
     */
    protected function stopLogging() : void
    {
        // not forgetChannels(): closing a stream handler opens the stream it
        // could not open in the first place, and throws. Log resolves the
        // default driver from config on each call, so this is enough.
        config(['logging.default' => 'null']);
    }

    /**
     * Write to the log, but never let the log break the validation: one of the
     * things being checked is whether the log destination can be written at
     * all, and Monolog throws when it cannot.
     */
    protected function record(string $level, string $message, array $context = []) : void
    {
        try
        {
            Log::log($level, $message, $context);
        }
        catch (\Throwable $e)
        {
            // reported by checkLogging() as a failed check in its own right
        }
    }

    protected function reportOk(string $label, string $detail = '') : void
    {
        $this->render('<fg=green>[ ok ]</>', $label, $detail);
    }

    protected function reportWarn(string $label, string $detail = '') : void
    {
        $this->render('<fg=yellow>[warn]</>', $label, $detail);

        $this->record('warning', "Validation warning", compact('label', 'detail'));
    }

    protected function reportFail(string $label, string $detail = '') : void
    {
        $this->failed = true;

        $this->render('<fg=red>[fail]</>', $label, $detail);

        $this->record('error', "Validation failure", compact('label', 'detail'));
    }

    /**
     * A check that did not apply. Deliberately not [ ok ] - a skip that reads
     * as a pass is how a check that does nothing goes unnoticed.
     */
    protected function reportSkip(string $label, string $detail = '') : void
    {
        $this->render('<fg=gray>[    ]</>', $label, $detail);
    }

    /**
     * Written out by hand rather than with twoColumnDetail(), whose
     * EnsureRelativePaths mutator strips base_path() out of every value and
     * cannot be opted out of - which would print absolute paths as convincing
     * relative ones, and paths are most of what this command reports.
     */
    protected function render(string $marker, string $label, string $detail) : void
    {
        $this->line("  {$marker} " . str_pad($label, 22) . " {$detail}");
    }

    protected function formatBytes(float $bytes) : string
    {
        $gb = $bytes / (1024 * 1024 * 1024);

        return number_format($gb, 1) . ' GB';
    }
}
