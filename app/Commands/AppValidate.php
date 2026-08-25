<?php

namespace App\Commands;

use App\Exceptions\BinaryLaneException;
use App\Support\SlackSummary;
use Hampel\ConsoleReport\RendersChecks;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Symfony\Component\Console\Helper\ProgressBar;

class AppValidate extends BaseCommand
{
    use RendersChecks;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:validate
                            {--no-api : skip the checks that call the BinaryLane API}
                            {--d|download= : download this URL to the download path, to exercise the transfer end to end}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check this machine can do what the configuration says';

    protected string $commandContext = 'validate';

    /**
     * Set once the log destination is known to be unwritable, so nothing tries
     * to write to it again.
     */
    protected bool $loggingStopped = false;

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // the package imports no Illuminate symbol, so it has to be handed
        // somewhere to write before it renders anything
        $this->setReportOutput($this->getOutput());

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

        $this->section("Run summary");
        $this->checkSummary();

        $this->section("BinaryLane API");
        $this->checkApi();
        $this->checkDownload();

        $this->newLine();

        if ($this->checksFailed())
        {
            $this->line("Validation failed - this machine cannot do what the configuration says");
            $this->newLine();

            $this->record('error', "Validation failed");
        }
        else
        {
            $this->line($this->checksWarned() ? "Checks passed, with warnings" : "All checks passed");
            $this->newLine();
        }

        // a warning is not a failure
        return $this->checkExitCode();
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

    /**
     * The same shape as wback's, so the two tools report their logging alike.
     *
     * Records are written at every level on every run, not behind a flag: a
     * destination with a threshold - Slack at critical - only proves it works
     * when something at that level is actually sent, and a webhook that has
     * been revoked says nothing about it at this end. The run posts.
     */
    protected function checkLogging() : void
    {
        $channel = config('logging.default');
        $stack = $channel === 'stack' ? implode(',', config('logging.channels.stack.channels', [])) : null;

        if ($channel === 'null' || $stack === 'null')
        {
            $this->reportWarn('log channel', 'the null channel discards everything - set LOG_CHANNEL or LOG_STACK');

            $this->loggingStopped = true;

            return;
        }

        $this->reportOk('log channel', $channel);

        if ($stack !== null)
        {
            $this->reportOk('log stack', $stack);
        }

        $channels = $channel === 'stack' ? config('logging.channels.stack.channels', []) : [$channel];

        foreach ($channels as $name)
        {
            $this->checkLogChannel(trim($name));
        }

        // worth reading back on a new installation: it is what tells one machine's
        // alerts from another's when they all report to the same place, and in a
        // container the default is a hex string that changes on every rebuild
        $hostname = config('logging.hostname');

        $hostname
            ? $this->reportOk('log hostname', $hostname)
            : $this->reportSkip('log hostname', 'records are not stamped with a hostname - set LOG_HOSTNAME');

        $this->writeTestRecords();
    }

    protected function checkLogChannel(string $name) : void
    {
        $driver = config("logging.channels.{$name}.driver");

        if (in_array($driver, ['single', 'daily']))
        {
            $path = config("logging.channels.{$name}.path");

            if (is_writable(dirname($path)) || (file_exists($path) && is_writable($path)))
            {
                $this->reportOk("log path ({$name})", $path);
            }
            else
            {
                // wback writes the records first and would die here inside Monolog;
                // checking before writing is what lets this be reported instead
                $this->reportFail("log path ({$name})", "{$path} is not writable");

                $this->stopLogging();
            }

            return;
        }

        if ($driver === 'slack')
        {
            if (empty(config("logging.channels.{$name}.url")))
            {
                $this->reportFail('slack webhook', "channel [{$name}] is in the stack but no webhook is configured");
            }
            else
            {
                // never print the webhook: this output goes into support tickets
                $this->reportOk('slack webhook', 'set, posting at ' . config("logging.channels.{$name}.level")
                    . ' as ' . config("logging.channels.{$name}.username"));
            }

            return;
        }

        $this->reportOk("log channel ({$name})", $driver ?? 'unknown driver');
    }

    /**
     * Write a record at every level, and say so - the console cannot know what
     * arrived at the other end, only that it was sent.
     */
    protected function writeTestRecords() : void
    {
        if ($this->loggingStopped)
        {
            $this->reportSkip('log records', 'not written - the destination above cannot be written');

            return;
        }

        $levels = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

        foreach ($levels as $level)
        {
            try
            {
                Log::log($level, "Validation test message [{$level}]");
            }
            catch (\Throwable $e)
            {
                $this->reportFail('log records', "writing a {$level} record failed: {$e->getMessage()}");

                return;
            }
        }

        $this->reportOk('log records', 'a message was written at every level');

        $this->line('         check that your logs - and any webhook - received them');
    }

    /**
     * Post the test message, because a webhook that has stopped working says
     * nothing about it at this end - the summary simply never arrives, which
     * looks exactly like a backup that never ran.
     */
    protected function checkSummary() : void
    {
        $reporter = $this->app->make(SlackSummary::class);

        if (!$reporter->isConfigured())
        {
            $this->reportSkip('run summary', 'nothing is sent - set BLBACKUP_SUMMARY_SLACK_WEBHOOK');

            return;
        }

        try
        {
            $reporter->sendTest();
        }
        catch (\Throwable $e)
        {
            $this->reportFail('run summary', trim(strtok($e->getMessage(), "\n") ?: ''));

            return;
        }

        $this->reportOk('run summary', 'test message delivered, sent on '
            . config('binarylane.summary.notify'));
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

        $this->loggingStopped = true;
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

    /**
     * Exercise the download path end to end against a real URL.
     *
     * Off unless asked for: it moves real bytes, and the URL has to come from
     * somewhere. A BinaryLane backup link from `blbackup backups --urls` is the
     * realistic one, but any URL exercises the same code - the Http client, the
     * sink, the configured timeout and the retry.
     */
    protected function checkDownload() : void
    {
        $url = $this->option('download');

        if (empty($url))
        {
            $this->reportSkip("Download transfer", "not exercised - pass --download=<url> to try a real transfer");

            return;
        }

        $path = '.blbackup-validate-download';

        $this->line("  Downloading [{$url}]");

        $progress = new ProgressBar($this->output, 100);
        $progress->start();

        $start = now();

        try
        {
            $this->api->download(
                $url,
                Storage::disk('downloads')->path($path),
                function ($downloadTotal, $downloadedBytes) use ($progress) {
                    if ($downloadTotal > 0)
                    {
                        $progress->setProgress(intval(round(($downloadedBytes / $downloadTotal) * 100)));
                    }
                }
            );

            $progress->finish();
            $this->newLine(2);
        }
        catch (BinaryLaneException | ConnectionException $e)
        {
            $this->newLine(2);

            Storage::disk('downloads')->delete($path);

            $this->reportFail("Download transfer", $e->getMessage());

            return;
        }

        $bytes = Storage::disk('downloads')->size($path);
        $seconds = max($start->diffInSeconds(now()), 0.001);

        Storage::disk('downloads')->delete($path);

        $this->reportOk("Download transfer", sprintf(
            '%s in %ss (%s MB/s)',
            $this->formatBytes($bytes),
            Number::format($seconds, 1),
            Number::format(($bytes / (1024 * 1024)) / $seconds, 1)
        ));
    }

    protected function reportOk(string $label, string $detail = '') : void
    {
        $this->checkOk($label, $detail);
    }

    protected function reportWarn(string $label, string $detail = '') : void
    {
        $this->checkWarn($label, $detail);

        $this->record('warning', "Validation warning", compact('label', 'detail'));
    }

    protected function reportFail(string $label, string $detail = '') : void
    {
        $this->checkFail($label, $detail);

        $this->record('error', "Validation failure", compact('label', 'detail'));
    }

    /**
     * A check that did not apply. Deliberately not [ ok ] - a skip that reads
     * as a pass is how a check that does nothing goes unnoticed.
     */
    protected function reportSkip(string $label, string $detail = '') : void
    {
        $this->checkSkip($label, $detail);
    }

    protected function formatBytes(float $bytes) : string
    {
        $gb = $bytes / (1024 * 1024 * 1024);

        return number_format($gb, 1) . ' GB';
    }
}
