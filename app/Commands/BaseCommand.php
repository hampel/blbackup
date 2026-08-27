<?php namespace App\Commands;

use App\Api;
use App\Exceptions\BinaryLaneException;
use App\Support\BackupLock;
use App\Support\LocksBackups;
use App\Support\RunSummary;
use App\Support\SlackSummary;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

abstract class BaseCommand extends Command
{
    use LocksBackups;

    protected string $commandContext;

    protected Api $api;

    protected RunSummary $summary;

    /**
     * Whether this command reports a run summary when it is the one invoked.
     *
     * Only cron does. Every stage can be run by hand, and a summary posted for a
     * command somebody is sitting and watching is noise delivered to the channel
     * of the person watching it - so posting belongs to the unattended entry
     * point rather than to a guess about whether anyone is there.
     */
    protected bool $summarises = false;

    /**
     * Whether this command claimed the run, and so is the one that reports it.
     * The stages it calls see the run is taken and leave it alone.
     */
    protected bool $ownsRun = false;

    /**
     * Whether this command takes the backup lock.
     *
     * The ones that write do; the ones that only read do not, because being
     * unable to list servers while a backup runs would be an odd sort of safety.
     */
    protected bool $locks = false;

    protected function execute(InputInterface $input, OutputInterface $output) : int
    {
        if (isset($this->commandContext)) {
            $this->setCommandContext($this->commandContext);
        }

        $this->api = $this->app->make(Api::class);
        $this->summary = $this->app->make(RunSummary::class);

        if ($this->summarises)
        {
            $this->ownsRun = $this->summary->claim(
                $this->getName(),
                $input->hasOption('dry-run') && $input->getOption('dry-run')
            );
        }

        try
        {
            // one lock for the whole run, so tonight's backups cannot start over
            // the top of last night's - a multi-gigabyte image that overruns is
            // what a slow link does, not an exotic case. The stages cron calls
            // see it is already held and leave it alone.
            if (!$this->acquireLock())
            {
                $this->recordFailedStart((string) $this->lockFailure);

                return static::FAILURE;
            }

            try
            {
                $status = parent::execute($input, $output);
            }
            catch (BinaryLaneException | ConnectionException $e)
            {
                Log::error($e->getMessage());
                $this->components->error($e->getMessage());

                $this->summary->recordFailure($this->getName(), $this->getName(), $e->getMessage());

                $status = static::FAILURE;
            }
            finally
            {
                // the kernel would do this when the process ended, but a command
                // called by another has to give it back before the caller goes on
                $this->releaseLock();
            }
        }
        finally
        {
            // after the work, and never able to change its outcome
            if ($this->ownsRun)
            {
                $this->reportRun();
            }
        }

        return $status;
    }

    /**
     * Post the run summary.
     *
     * Whether Slack heard about the work does not change whether the work
     * succeeded, so everything here is caught and the command's own exit code
     * is left alone. A webhook that has stopped answering must cost the run a
     * warning, not the night.
     */
    protected function reportRun() : void
    {
        try
        {
            $reporter = $this->app->make(SlackSummary::class);

            if (!$reporter->shouldSend($this->summary))
            {
                return;
            }

            $reporter->send($this->summary);
        }
        catch (\Throwable $e)
        {
            Log::warning("Could not send the run summary", ['error' => $e->getMessage()]);

            $this->line("Could not send the run summary: " . $e->getMessage());
        }
    }

    protected function setCommandContext(string $context): void
    {
        Log::withContext([
            'command' => $context,
        ]);
    }

    public function fail(\Throwable|string|null $exception = null)
    {
        $message = match (true) {
            is_string($exception) => $exception,
            $exception instanceof \Throwable => $exception->getMessage(),
            default => null,
        };

        if ($message !== null)
        {
            Log::error($message);

            $this->recordFailedStart($message);
        }

        parent::fail($exception);
    }

    /**
     * A run that could not start still has to report.
     *
     * That is the failure which otherwise leaves one log line and no alert:
     * an unreadable server list, a missing remote, an API token that no longer
     * works. Nothing ran, so nothing logged anything worth summarising, and the
     * summary that never arrives looks exactly like a night when there was
     * nothing to do.
     *
     * Recorded as a block only when the run had not done anything yet - a fail()
     * after real work is a failure within a run, and reporting it as "did not
     * run" would throw away everything the run did manage.
     *
     * Recorded by whichever command failed, not only the one that owns the run:
     * cron owns it, and the stage that could not start is the one with something
     * to say. Nothing is recorded when no command has claimed a run at all, which
     * is a stage somebody is running by hand.
     */
    protected function recordFailedStart(string $message) : void
    {
        if (!isset($this->summary) || $this->summary->ownedBy() === null)
        {
            return;
        }

        $this->summary->hasWork()
            ? $this->summary->recordFailure($this->getName(), $this->getName(), $message)
            : $this->summary->block($message);
    }

    /**
     * The server list named by --include or --exclude, or by configuration.
     *
     * The option wins for one run; configuration is what an unattended install
     * is read out of.
     *
     * A file that yields no entries is treated as no list at all, so an empty
     * include file backs up every server rather than none. That is the safe way
     * round - a truncated list should not silently stop the backups - but it is
     * surprising enough that app:validate warns about it, since nothing else
     * would say a word.
     *
     * A missing file fails the command rather than being ignored. A list that
     * silently did not apply is the failure worth being loud about: the run
     * looks like every other night and quietly backs up more than was asked.
     *
     * Lines are trimmed before they are matched. These files get edited on
     * Windows through \\wsl$, and a CRLF list matches nothing at all against
     * hostnames that have no carriage return in them - which excludes nobody,
     * without a word.
     *
     * @param string $which 'include' or 'exclude'
     *
     * @return array<int, string>|null the hostnames, or null when no list applies
     */
    protected function serverList(string $which) : ?array
    {
        $path = $this->option($which) ?: config("binarylane.{$which}_file");

        if (empty($path))
        {
            return null;
        }

        if (!File::exists($path))
        {
            $this->fail(ucfirst($which) . " file [{$path}] does not exists or is not readable");
        }

        $names = array_values(array_filter(array_map('trim', explode(PHP_EOL, File::get($path)))));

        return $names === [] ? null : $names;
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

        // an integer, not a string: line() hands this to parseVerbosity(), which
        // knows v/vv/vvv/quiet/normal and silently falls back to the caller's own
        // verbosity for anything else - so a level the map does not carry would
        // print at whatever -v the run happened to be given
        $verbosity = $verbosityMap[$level] ?? OutputInterface::VERBOSITY_NORMAL;
        $style = $styleMap[$level] ?? null;

        Log::log($level, $logMessage, $context);
        $this->line($message, $style, $verbosity);
    }

    protected function logCmd($description, $cmd)
    {
        Log::debug($description, compact('cmd'));
    }

    protected function section($string, $verbosity = null)
    {
        if (! $this->output->getFormatter()->hasStyle('section')) {
            $style = new OutputFormatterStyle('cyan');

            $this->output->getFormatter()->setStyle('section', $style);
        }

        $this->output->newLine();
        $this->line($string, 'section', $verbosity);
        $this->line(str_repeat('-', strlen($string)), 'section', $verbosity);
        $this->output->newLine();
    }

    protected function getVerbosity() : string
    {
        return match (true) {
            $this->output->isVerbose() => ' --verbose',
            $this->output->isQuiet() => ' --quiet',
            default => '',
        };
    }

    protected function deleteLines(int $count = 1)
    {
        if ($count > 0)
        {
            $this->output->write("\x0D");
            $this->output->write("\x1B[2K");

            // delete the remaining $i - 1 lines
            for ($i = 1; $i < $count; $i++)
            {
                $this->output->write("\x1B[1A");
                $this->output->write("\x1B[2K");
            }
        }
    }

}
