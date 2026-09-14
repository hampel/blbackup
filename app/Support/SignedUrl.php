<?php namespace App\Support;

/**
 * Keep a backup image's download URL out of anything written down.
 *
 * The URL BinaryLane hands out for an image needs no authentication: whoever holds
 * it can download the server's entire disk until it expires, twenty-four hours
 * after it is issued. Its secret is in the path, not a query string, so trimming
 * parameters would withhold nothing - only the host is safe to show.
 *
 * It used to be written in full into the log on every download, and on a failed one
 * into an error record, which a Slack log channel at its default threshold posts.
 * Use this wherever a download URL, or text that may contain one, is logged, printed
 * or put in an exception message. `backups --urls` is the one deliberate exception:
 * printing the URL is what it is for.
 */
final class SignedUrl
{
    /**
     * The URL reduced to its scheme and host.
     */
    public static function redact(string $url) : string
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (!is_string($host) || $host === '')
        {
            return '[url withheld]';
        }

        $scheme = parse_url($url, PHP_URL_SCHEME) ?: 'https';

        return "{$scheme}://{$host}/[path withheld]";
    }

    /**
     * Every URL in a piece of text, redacted.
     *
     * Every URL rather than the one known in advance, because the text is often not
     * ours: wget writes the URL it was given into its error output, and the URL of
     * every redirect it followed - and a signed URL may redirect to another.
     */
    public static function redactAll(string $text) : string
    {
        return (string) preg_replace_callback(
            '#https?://[^\s\'"<>\]]+#i',
            fn (array $match) => self::redact($match[0]),
            $text
        );
    }
}
