<?php

namespace App\Commands;

use App\Exceptions\BinaryLaneException;
use App\Support\BackupLock;
use App\Support\SlackSummary;
use Hampel\ConsoleReport\RendersChecks;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\File;
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
                            {--unattended : do not send the messages whose only proof is a person seeing them arrive}
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

        $this->checkSection("Environment");
        $this->checkVersion();
        $this->checkPhp();
        $this->checkIntl();
        $this->checkTimezone();

        $this->checkSection("Storage");
        $this->checkStoragePath();
        $this->checkDownloadPath();
        $this->checkLock();
        $this->checkLogging();

        $this->checkSection("Server lists");
        $this->checkServerList('include');
        $this->checkServerList('exclude');

        $this->checkSection("External commands");
        $this->checkBinary('zstd', config('binarylane.zstd_binary'), '--version');
        $this->checkBinary('wget', config('binarylane.wget_binary'), '--version');
        $this->checkBinary('rclone', config('binarylane.rclone.binary'), '--version');
        $this->checkRemote();

        $this->checkSection("Run summary");
        $this->checkSummary();

        $this->checkSection("BinaryLane API");
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

    /**
     * What this install calls itself.
     *
     * The first thing worth knowing after a rebuild is whether the thing you
     * just deployed is the thing you meant to deploy, and until now this command
     * - the one a rollout is gated on - was the only place that did not say.
     *
     * A warning rather than a pass when it is unknown, because "unreleased" is
     * not cosmetic: AppServiceProvider signs every Slack run summary with
     * app()->version(), so the alerts from that install are signed with it too,
     * and nothing in the channel then says which build produced them.
     */
    protected function checkVersion() : void
    {
        $version = $this->app->version();

        if ($version === 'unreleased' || $version === '')
        {
            $this->reportWarn('Version', 'unknown - no git metadata here, so build with --build-arg VERSION=<tag>');

            return;
        }

        $this->reportOk('Version', $version);
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

    /**
     * The working directory every rclone call is given.
     *
     * Checked because Symfony's Process refuses to start when its cwd does not
     * exist, so a missing storage directory is not a degraded run - it is
     * `move` and `clean --remote` throwing after the backup has been taken.
     * A container is where this happens: /storage is excluded from the image on
     * purpose, and nothing then creates the directory it left out.
     */
    protected function checkStoragePath() : void
    {
        $path = storage_path();

        if (!is_dir($path))
        {
            $this->reportFail("Storage path", "{$path} does not exist - rclone is run from there");

            return;
        }

        if (!is_writable($path))
        {
            $this->reportWarn("Storage path", "{$path} is not writable");

            return;
        }

        $this->reportOk("Storage path", $path);
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
     * Records are written at every level on every attended run: a destination
     * with a threshold - Slack at critical - only proves it works when something
     * at that level is actually sent, and a webhook that has been revoked says
     * nothing about it at this end. The run posts. --unattended is the only way
     * to stop it, and states that nobody is watching where they would land.
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
     *
     * Skipped under --unattended, which is the one condition that makes the
     * sweep not worth its cost: the records prove the webhook and the threshold
     * by arriving, and nothing is proved by arriving where nobody is looking.
     * Never the default, though - forgetting the flag costs some channel noise
     * that can be deleted, while defaulting it on would cost every future run
     * its proof of delivery, silently, which cannot be undone by noticing.
     */
    protected function writeTestRecords() : void
    {
        $levels = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

        if ($this->loggingStopped)
        {
            $this->reportSkip('log records', 'not written - the destination above cannot be written');

            return;
        }

        if ($this->option('unattended'))
        {
            $this->reportSkip('log records', 'nothing was written - --unattended');

            $this->checkDelivery($levels);

            return;
        }

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

        $this->checkDelivery($levels);
    }

    /**
     * Say what the sweep just posted, because a count nobody was given is not a
     * count anybody can check.
     *
     * The sweep is the only thing that proves the Slack threshold: the webhook
     * url and LOG_SLACK_LEVEL are both unprovable from this end, and the records
     * arriving prove both at once. That only works if the operator is told how
     * many to expect - four is right for a level of `error`, and three means the
     * threshold is not what the configuration says. Derived from the effective
     * stack rather than written down, or the line becomes a second thing to keep
     * in step with the configuration it is describing.
     *
     * Note what the driver test here is for. It says what to go and look for; it
     * is emphatically not how to bound the sweep. `papertrail` is
     * driver => monolog and leaves the machine just as surely, so gating the loop
     * on the driver would send all eight off the box rather than none. The flag
     * bounds the sweep; this only reports on it.
     *
     * @param array<int, string> $levels every level the sweep writes at, lowest first
     */
    protected function checkDelivery(array $levels) : void
    {
        $channel = config('logging.default');

        $channels = $channel === 'stack' ? config('logging.channels.stack.channels', []) : [$channel];

        $slack = array_values(array_filter(
            array_map('trim', $channels),
            fn ($name) => config("logging.channels.{$name}.driver") === 'slack'
        ));

        if ($slack === [])
        {
            $this->reportSkip('log delivery', 'nothing in the log stack posts to slack');

            return;
        }

        foreach ($slack as $name)
        {
            // checkLogChannel() has already failed the run over a channel with no
            // webhook, which is the state that otherwise looks identical to a
            // working one from in here - so there is nothing left to say about it
            if (empty(config("logging.channels.{$name}.url")))
            {
                continue;
            }

            if ($this->option('unattended'))
            {
                $this->reportSkip("log delivery ({$name})", 'nothing was posted - --unattended');

                continue;
            }

            $threshold = config("logging.channels.{$name}.level");
            $index = array_search(strtolower((string) $threshold), $levels, true);

            if ($index === false)
            {
                // an unrecognised level is Monolog's to reject, not ours to guess at
                $this->reportWarn("log delivery ({$name})", "cannot tell what was posted - [{$threshold}] is not a log level");

                continue;
            }

            $posted = array_slice($levels, $index);

            $this->reportOk("log delivery ({$name})", sprintf(
                'posted %d %s at %s and above: %s - check they arrived',
                count($posted),
                count($posted) === 1 ? 'record' : 'records',
                $threshold,
                implode(', ', $posted)
            ));
        }
    }

    /**
     * Post the test message, because a webhook that has stopped working says
     * nothing about it at this end - the summary simply never arrives, which
     * looks exactly like a backup that never ran.
     *
     * The second of this command's two sends, and it was worth going looking for
     * rather than reasoning outward from the loud one: the eight-level sweep is
     * what anybody notices, and one message beside it does not look like a flood,
     * which is precisely how it would have survived the flag.
     */
    protected function checkSummary() : void
    {
        $reporter = $this->app->make(SlackSummary::class);

        if (!$reporter->isConfigured())
        {
            $this->reportSkip('run summary', 'nothing is sent - set BLBACKUP_SUMMARY_SLACK_WEBHOOK');

            return;
        }

        // the same reasoning as the log sweep: this webhook is proved by the
        // message arriving, so there is nothing to prove by spending one on a
        // channel nobody has been asked to read
        if ($this->option('unattended'))
        {
            $this->reportSkip('run summary', 'configured, but nothing was sent - --unattended');

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

    /**
     * Take the lock and give it straight back.
     *
     * Exercising it rather than reporting that the path looks writable, for the
     * same reason as everything else here - and it is the one check that can
     * legitimately come back held, which is what a run overrunning into this one
     * looks like.
     */
    protected function checkLock() : void
    {
        $lock = $this->app->make(BackupLock::class);

        try
        {
            if (!$lock->acquire($this->getName()))
            {
                $this->reportWarn("lock file", "held by another run [" . $lock->holder() . "]");

                return;
            }
        }
        catch (\RuntimeException $e)
        {
            $this->reportFail("lock file", $e->getMessage());

            return;
        }

        $lock->release();

        // the path matters as much as the outcome: in a container each run has
        // its own filesystem, so a lock that is not on a shared mount is one two
        // concurrent runs cannot see each other holding
        $this->reportOk("lock file", $lock->path());
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

        // the one probe here that crosses a network, and the only one that can
        // take longer than the process timeout - which threw a stack trace out
        // of the command whose whole job is to report a failure legibly
        try
        {
            $result = Process::run(config('binarylane.rclone.binary') . " lsd --quiet {$remote}");
        }
        catch (ProcessTimedOutException $e)
        {
            $this->reportFail("rclone remote", "{$remote} did not answer within the process timeout");

            return;
        }

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
     *
     * Deliberately not suppressed by --unattended, although these records can
     * reach the same Slack channel the sweep does. A warning or a failure is the
     * half of this command's output written for whoever is not at the terminal,
     * and --unattended states that nobody is - which makes it the run where the
     * record matters most, not least. The flag is about sends whose only value
     * is a person seeing them arrive; this one has value sitting in the log.
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

    /**
     * An --include / --exclude list named in the configuration.
     *
     * The list decides what gets backed up, so getting it wrong is the failure
     * that looks most like success: the run completes, the summary posts, and
     * the servers you thought were covered are not. Nothing else on this machine
     * can tell you the path is wrong until a run fails on it, and nothing at all
     * can tell you an empty list quietly stopped filtering.
     *
     * A list given only on the command line cannot be checked from here - that
     * is the argument for configuring it rather than putting it on the crontab
     * line, and the skip says so.
     */
    protected function checkServerList(string $which) : void
    {
        $label = "{$which} list";
        $path = config("binarylane.{$which}_file");

        if (empty($path))
        {
            $this->reportSkip($label, sprintf(
                'not set - %s (--%s overrides for one run)',
                $which === 'include' ? 'every server is backed up' : 'no servers are excluded',
                $which
            ));

            return;
        }

        if (!File::exists($path))
        {
            $this->reportFail($label, "{$path} - does not exist or is not readable");

            return;
        }

        $names = array_values(array_filter(array_map('trim', explode(PHP_EOL, File::get($path)))));

        if ($names === [])
        {
            $this->reportWarn($label, "{$path} - names no servers, so it does not filter anything");

            return;
        }

        $this->reportOk($label, sprintf(
            '%s (%d %s)',
            $path,
            count($names),
            count($names) === 1 ? 'server' : 'servers'
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
