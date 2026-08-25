<?php

namespace App\Logging;

/**
 * Add the hostname stamp to a log channel
 *
 * A tap rather than the channel's own "processors" key, because that key is only read
 * by the monolog driver - slack, single and daily ignore it.
 *
 * Set LOG_HOSTNAME to an empty value to turn the stamp off wherever it is configured.
 */
class StampHostname
{
    /**
     * @param \Illuminate\Log\Logger $logger the channel being built
     * @return void
     */
    public function __invoke($logger) : void
    {
        $hostname = config('logging.hostname');

        if (empty($hostname))
        {
            return;
        }

        $logger->pushProcessor(new HostnameProcessor($hostname));
    }
}
