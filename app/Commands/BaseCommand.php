<?php namespace App\Commands;

use App\Api;
use App\Exceptions\BinaryLaneException;
use App\Support\RunSummary;
use App\Support\SlackSummary;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

abstract class BaseCommand extends Command
{
    protected string $commandContext;

    protected Api $api;

    protected RunSummary $summary;

    /**
     * Whether this command reports a run summary when it is the one invoked.
     * The listing commands do not: nothing was done to report.
     */
    protected bool $summarises = false;

    /**
     * Whether this command claimed the run, and so is the one that reports it.
     * The stages it calls see the run is taken and leave it alone.
     */
    protected bool $ownsRun = false;

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
        if (is_string($exception))
        {
            Log::error($exception);
        }
        elseif ($exception instanceof \Throwable)
        {
            Log::error($exception->getMessage());
        }

        parent::fail($exception);
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
