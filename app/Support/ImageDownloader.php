<?php namespace App\Support;

use App\Exceptions\DownloadFailed;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Pull a backup image down from the pre-signed URL BinaryLane hands out.
 *
 * Kept out of the API client because it is not an API call. The URL carries its
 * own authorisation, has nothing to do with the account token, and points at
 * storage rather than at the API - the client's job ends at handing the URL
 * over. What happens to the multi-gigabyte file behind it is this.
 *
 * `download --no-wget` and `app:validate --download` both come through here.
 */
class ImageDownloader
{
    /**
     * @param callable $progress Guzzle's progress callback:
     *                           (downloadTotal, downloaded, uploadTotal, uploaded)
     * @throws DownloadFailed when the transfer is refused or never connects
     */
    public function download(string $url, string $path, callable $progress) : void
    {
        Log::debug('Downloading image', ['url' => SignedUrl::redact($url), 'path' => $path]);

        try
        {
            Http::sink($path)
                ->withOptions(['progress' => $progress])
                ->timeout(config('blbackup.timeout'))
                ->retry(3, 100)
                ->get($url)
                ->throw();
        }
        catch (RequestException | ConnectionException $e)
        {
            throw DownloadFailed::for($url, $e);
        }
    }
}
