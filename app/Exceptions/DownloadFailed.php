<?php namespace App\Exceptions;

use Illuminate\Http\Client\RequestException;
use App\Support\SignedUrl;

/**
 * A backup image could not be pulled down from the URL BinaryLane handed out.
 *
 * Its own type rather than one of the API client's, because this transfer is not
 * an API call - see ImageDownloader.
 */
class DownloadFailed extends \RuntimeException
{
    public static function for(string $url, \Throwable $previous) : self
    {
        $detail = $previous instanceof RequestException
            ? " [{$previous->response->status()}]: {$previous->response->reason()}"
            : ": {$previous->getMessage()}";

        // the whole message, not only the URL passed in: a connection failure's own message
        // names the URL it could not reach. This message is logged at error and, from a
        // command, recorded in the run summary
        return new self(SignedUrl::redactAll("Could not download image [{$url}]{$detail}"), 0, $previous);
    }
}
