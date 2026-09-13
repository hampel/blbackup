<?php namespace App\Exceptions;

use Illuminate\Http\Client\RequestException;

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

        return new self("Could not download image [{$url}]{$detail}", 0, $previous);
    }
}
