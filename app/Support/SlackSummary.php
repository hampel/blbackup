<?php

namespace App\Support;

use Hampel\SlackMessage\SlackAttachment;
use Hampel\SlackMessage\SlackMessage;
use Hampel\SlackMessage\SlackWebhook;

/**
 * Render a run summary and post it
 *
 * Reads no configuration and resolves nothing: everything it needs arrives
 * through the constructor. That is what would let the same class work somewhere
 * config() and app() do not exist, and it costs nothing to do from the start.
 * Reading configuration is the application's job; reporting is this one's.
 */
class SlackSummary
{
    /**
     * Failures quoted in the message before it says "and N more". The rest are
     * in the log; this only has to be enough to act on.
     */
    protected const MAX_FAILURES = 10;

    /**
     * How much of one failure to quote.
     */
    protected const MAX_MESSAGE = 300;

    /**
     * @param SlackWebhook $slack transport
     * @param string $webhook incoming webhook url, empty to send nothing
     * @param string $notify 'failure' for the bad nights only, anything else for all of them
     * @param string $application what to sign the message with, name and version
     * @param string $hostname what this machine calls itself
     */
    public function __construct(
        protected SlackWebhook $slack,
        protected string $webhook,
        protected string $notify,
        protected string $application,
        protected string $hostname
    )
    {
    }

    /**
     * @return bool whether this run is one the operator asked to hear about
     */
    public function shouldSend(RunSummary $summary) : bool
    {
        if (empty($this->webhook))
        {
            return false;
        }

        // nothing was done and somebody was there to decide that, so the channel
        // would be reporting a night's backup to the person who just cancelled it
        if ($summary->wasCancelled())
        {
            return false;
        }

        // "failure" is for an installation that would rather have silence than a
        // nightly all-clear - at the cost of not being able to tell a working
        // backup from an uninstalled one, which is the whole point of a summary
        return $this->notify === 'failure' ? $summary->failed() : true;
    }

    /**
     * @return bool whether there is anywhere to send a summary
     */
    public function isConfigured() : bool
    {
        return !empty($this->webhook);
    }

    /**
     * @throws \RuntimeException if Slack would not take it
     */
    public function send(RunSummary $summary) : void
    {
        $this->post($this->build($summary));
    }

    /**
     * Post a message that proves the webhook works
     *
     * A mistyped or revoked webhook is invisible until the night it matters,
     * because nothing about a summary that was never delivered reaches the
     * machine that sent it. Actually posting is the only check worth having.
     *
     * @throws \RuntimeException if Slack would not take it
     */
    public function sendTest() : void
    {
        $this->post($this->slack->message(function (SlackMessage $message) {
            $message
                ->success()
                ->content('Test message from app:validate on ' . $this->hostname)
                ->attachment(function (SlackAttachment $attachment) {
                    $attachment
                        ->fallback('Test message from app:validate on ' . $this->hostname)
                        ->content('The run summary is configured and this webhook works.')
                        ->footer($this->application . ' on ' . $this->hostname)
                        ->timestamp(new \DateTimeImmutable);
                });
        }));
    }

    /**
     * @throws \RuntimeException if Slack would not take it
     */
    protected function post(SlackMessage $message) : void
    {
        $response = $this->slack->send($this->webhook, $message);

        if (!$this->slack->accepted($response))
        {
            throw new \RuntimeException('Slack refused the message: ' . $this->slack->error($response));
        }
    }

    public function build(RunSummary $summary) : SlackMessage
    {
        return $this->slack->message(function (SlackMessage $message) use ($summary) {
            $summary->failed() ? $message->error() : $message->success();

            $message->content($this->headline($summary));

            $message->attachment(function (SlackAttachment $attachment) use ($summary) {
                $attachment
                    ->fallback($this->headline($summary))
                    ->fields($this->fields($summary))
                    ->footer($this->application . ' on ' . $this->hostname)
                    ->timestamp(new \DateTimeImmutable);

                // rendered above the fields in Slack, which is why the failures
                // go here: they are what wants reading first
                $body = $this->body($summary);

                if ($body !== '')
                {
                    $attachment->content($body);
                }
            });
        });
    }

    /**
     * @return string the line that has to be readable from a phone notification
     */
    protected function headline(RunSummary $summary) : string
    {
        $prefix = $summary->isDryRun() ? '[Dry run] ' : '';

        $outcome = match (true) {
            $summary->blockedBy() !== null => 'did not run',
            $summary->failed() => 'failed',
            default => 'completed',
        };

        return "{$prefix}Backup {$outcome} on " . $this->hostname;
    }

    /**
     * @return array<string, string> the run in numbers, as Slack's two column fields
     */
    protected function fields(RunSummary $summary) : array
    {
        // a run that never started has nothing to count, and zeroes would read as
        // a run that did start and found nothing to do
        if ($summary->blockedBy() !== null)
        {
            return [];
        }

        $fields = ['Servers' => (string) $summary->serverCount()];

        if ($summary->backupCount() > 0)
        {
            $fields['Backups taken'] = (string) $summary->backupCount();
        }

        if ($summary->downloadCount() > 0)
        {
            $fields['Downloaded'] = $summary->downloadCount() . ' (' . $this->filesize($summary->bytes()) . ')';
        }

        if ($summary->moveCount() > 0)
        {
            $fields['Moved to remote'] = (string) $summary->moveCount();
        }

        if ($summary->deletionCount() > 0)
        {
            $fields['Expired'] = (string) $summary->deletionCount();
        }

        $fields['Duration'] = $this->duration($summary->seconds());

        if ($summary->failures() !== [])
        {
            $fields['Failures'] = (string) count($summary->failures());
        }

        return $fields;
    }

    /**
     * @return string what went wrong, named by server and stage so it can be
     *                acted on without opening the log first
     */
    protected function body(RunSummary $summary) : string
    {
        if ($summary->blockedBy() !== null)
        {
            return $summary->blockedBy();
        }

        $failures = $summary->failures();

        if ($failures === [])
        {
            return '';
        }

        $lines = [];

        foreach (array_slice($failures, 0, self::MAX_FAILURES) as $failure)
        {
            $lines[] = sprintf(
                '%s (%s): %s',
                $failure['item'],
                $failure['stage'],
                $this->reason($failure['message'])
            );
        }

        $remaining = count($failures) - self::MAX_FAILURES;

        if ($remaining > 0)
        {
            $lines[] = "... and {$remaining} more - see the log";
        }

        return implode("\n", $lines);
    }

    /**
     * A failure is often several lines of a command's own output, and all of it
     * is in the log already - here it only has to be enough to recognise
     */
    protected function reason(string $message) : string
    {
        $message = trim(strtok($message, "\n") ?: '');

        return mb_strlen($message) > self::MAX_MESSAGE
            ? mb_substr($message, 0, self::MAX_MESSAGE) . '...'
            : $message;
    }

    protected function filesize(int $bytes) : string
    {
        if ($bytes >= 1024 * 1024 * 1024)
        {
            return number_format($bytes / (1024 * 1024 * 1024), 2) . ' GB';
        }

        if ($bytes >= 1024 * 1024)
        {
            return number_format($bytes / (1024 * 1024), 1) . ' MB';
        }

        return number_format($bytes / 1024, 1) . ' KB';
    }

    protected function duration(float $seconds) : string
    {
        // a run that took a fraction of a second is a real answer, and "0s" reads
        // as a duration nobody measured
        if ($seconds < 1)
        {
            return '<1s';
        }

        $seconds = (int) round($seconds);

        if ($seconds < 60)
        {
            return "{$seconds}s";
        }

        $minutes = intdiv($seconds, 60);

        if ($minutes < 60)
        {
            return sprintf('%dm %ds', $minutes, $seconds % 60);
        }

        return sprintf('%dh %dm', intdiv($minutes, 60), $minutes % 60);
    }
}
