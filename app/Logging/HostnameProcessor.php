<?php

namespace App\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Stamp every record with the machine it came from
 *
 * One Slack webhook can then serve a whole fleet, because the message itself says
 * which host raised it - rather than the webhook it arrived through having to say so,
 * which means creating one per installation.
 *
 * It earns its place here even with one installation: this runs inside a container,
 * and a container calls itself a random hex string that changes every time the image
 * is rebuilt. LOG_HOSTNAME is what makes the records say "unraid" instead.
 */
class HostnameProcessor implements ProcessorInterface
{
    /**
     * @var string what this machine is called in the logs
     */
    protected $hostname;

    public function __construct(string $hostname)
    {
        $this->hostname = $hostname;
    }

    public function __invoke(LogRecord $record) : LogRecord
    {
        // extra rather than context, which belongs to the caller: a record about a
        // server of its own keeps the hostname it set
        return $record->with(extra: $record->extra + ['hostname' => $this->hostname]);
    }
}
