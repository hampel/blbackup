<?php namespace App\Commands;

use App\Api;
use App\Exceptions\BinaryLaneException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Process\ProcessResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use function PHPUnit\Framework\isInstanceOf;

abstract class BaseCommand extends Command
{
    protected string $commandContext;

    protected Api $api;

    protected function execute(InputInterface $input, OutputInterface $output) : int
    {
        if (isset($this->commandContext)) {
            $this->setCommandContext($this->commandContext);
        }

        $this->api = $this->app->make(Api::class);

        try
        {
            return parent::execute($input, $output);
        }
        catch (BinaryLaneException | ConnectionException $e)
        {
            Log::error($e->getMessage());
            $this->components->error($e->getMessage());

            return static::FAILURE;
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
        elseif (isInstanceOf(\Throwable::class, $exception))
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

    protected function process(string $cmd, string $path = '', bool $deleteLines = false, bool $treatStdErrAsOut = false) : ProcessResult
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

    protected function processWget(string $cmd, string $path = '') : ProcessResult
    {
        $count = 0;
        $lineCount = 0;
        $last = '';

        $progress = new ProgressBar($this->output, 100);

        $result = Process::forever()
            ->path($path)
            ->run($cmd, function (string $type, string $output) use (&$count, &$lineCount, &$last, $progress) {

                collect(explode(PHP_EOL, $output))
                    ->reject(function (string $line) {
                        return empty($line);
                    })
                    ->each(function (string $line) use (&$count, &$lineCount, &$last, $progress) {
                        if ($lineCount < 8)
                        {
                            if (preg_match("/\.\.\.\./", $line))
                            {
                                // skip
                            }
                            else
                            {
                                $this->line($line);
                            }
                        }
                        elseif ($lineCount == 8)
                        {
                            $progress->start();
                        }
                        elseif (preg_match("/^\s*\d*\w\s*[\.\s]+(\d+)\%\s+[\d\.]+\w\s+[\d\w]+$/", $line, $matches))
                        {
                            $progress->setProgress(intval($matches[1]));
                        }
                        elseif (preg_match("/^\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}:\d{2}\s\(/", $line))
                        {
                            $last = $line;
                        }
                        elseif (preg_match("/\.\.\./", $line))
                        {
                            // skip these partial lines
                        }
                        elseif (strlen(trim($line, " .")) <= 15)
                        {
                            // skip short lines
                        }
                        else
                        {
                            $this->line("[{$line}]");
                        }

                        $lineCount++;
                    });

                $count++;
            });

        $progress->finish();
        $this->newLine(2);

        $this->line($last);

        return $result;
    }

    protected function processZstd(string $cmd, string $path = '') : ProcessResult
    {
        $last = '';

        $result = Process::forever()
            ->path($path)
            ->run($cmd, function (string $type, string $output) use (&$last) {

                $last = collect(explode("\r", $output))
                    ->reject(function (string $line) {
                        return empty(trim($line));
                    })
                    ->last();
            });

        $this->newLine();
        $this->line($last);

        return $result;
    }

    protected function testDownload(string $path) : bool
    {
        $binary = config('binarylane.zstd_binary');

        $cmd = "{$binary} --test --progress {$path}";

        $this->log(
            'notice',
            "Testing download [{$path}]",
            "Testing download",
            compact('cmd')
        );

        $result = $this->processZstd($cmd, storage_path());

        if ($result->failed())
        {
            $output = $result->errorOutput();

            $this->log(
                'error',
                "Downloaded file failed zstd test: " . $output,
                "Downloaded file failed zstd test",
                compact('path', 'output')
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
}
