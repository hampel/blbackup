<?php

use App\Support\RunSummary;
use App\Support\SignedUrl;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/*
| A backup image's download URL needs no authentication and is good for twenty-four
| hours - whoever holds it can download the whole disk. Its secret is in the path.
| It used to reach the log on every download and, on a failure, an error record that
| a Slack log channel posts.
|
| So the tests here put a token in the path and look for it everywhere a URL could
| surface - every log record's message and context, what the command printed, and
| what the run summary recorded. A test that only checked the message it expected
| would miss the context, and a test that only checked the URL it passed would miss
| the one wget reports for a redirect.
*/

const SIGNED_URL = 'https://images.binarylane.com.au/downloads/SIGNED-SECRET-TOKEN/vd/backup-12345.zst';

/**
 * Every log record written from here on, flattened to text.
 */
function captureLogs(array &$records): void
{
    Log::listen(function ($event) use (&$records) {
        $records[] = $event->level . ' ' . $event->message . ' ' . json_encode($event->context);
    });
}

/**
 * Run a command and return everything a reader could see: the log, the console, the summary.
 */
function everythingWrittenBy(string $command, array $arguments): string
{
    $records = [];
    captureLogs($records);

    Artisan::call($command, $arguments);

    return implode("\n", $records)
        . "\n" . Artisan::output()
        . "\n" . json_encode(app(RunSummary::class)->failures());
}

it('reduces a url to its host', function () {
    expect(SignedUrl::redact(SIGNED_URL))->toBe('https://images.binarylane.com.au/[path withheld]')
        ->and(SignedUrl::redact('not a url'))->toBe('[url withheld]');
});

it('redacts every url in a piece of text, not only a known one', function () {
    $text = "--2026-09-14--  " . SIGNED_URL . "\nLocation: https://storage.example.test/SECOND-TOKEN/disk [following]";

    expect(SignedUrl::redactAll($text))
        ->not->toContain('SIGNED-SECRET-TOKEN')
        ->not->toContain('SECOND-TOKEN')
        ->toContain('https://storage.example.test/[path withheld]')
        ->toContain('[following]');
});

it('keeps the url out of everything when a wget download fails', function () {
    fakeApi([fakeServer()], [fakeImage()], fakeLink(12345, SIGNED_URL));
    // wget's real error output begins with the URL, and names any redirect it followed
    fakeBinaries(['*wget*' => Process::result(
        errorOutput: "--2026-09-14 23:08:21--  " . SIGNED_URL . "\nLocation: https://storage.example.test/SECOND-TOKEN/disk [following]\nERROR 403: Forbidden.",
        exitCode: 8
    )]);

    $written = everythingWrittenBy('download', ['server' => 'web1.example.com']);

    expect($written)->toContain('Could not download file')
        ->toContain('images.binarylane.com.au')
        ->not->toContain('SIGNED-SECRET-TOKEN')
        ->not->toContain('SECOND-TOKEN');

    // and the command that ran still had the real URL - redacting it there would break it
    Process::assertRan(fn ($process) => str_contains($process->command, SIGNED_URL));
});

it('keeps the url out of everything when an http download is refused', function () {
    fakeBinaries();
    fakeDownloadAnswering(fn () => Http::response('denied', 403));

    $written = everythingWrittenBy('download', ['server' => 'web1.example.com', '--no-wget' => true]);

    expect($written)->toContain('Could not download image')
        ->toContain('[403]')
        ->not->toContain('SIGNED-SECRET-TOKEN');
});

it('keeps the url out of everything when an http download cannot connect', function () {
    // a connection failure's own message names the URL it could not reach
    fakeBinaries();
    fakeDownloadAnswering(fn () => throw new ConnectionException('cURL error 7: Failed to connect for ' . SIGNED_URL));

    $written = everythingWrittenBy('download', ['server' => 'web1.example.com', '--no-wget' => true]);

    expect($written)->toContain('Could not download image')
        ->not->toContain('SIGNED-SECRET-TOKEN');
});

it('keeps the url out of the log on a download that works', function () {
    fakeApi([fakeServer()], [fakeImage()], fakeLink(12345, SIGNED_URL));
    fakeBinaries(['*wget*' => wgetWrites(MEGABYTE)]);

    $written = everythingWrittenBy('download', ['server' => 'web1.example.com']);

    expect($written)->toContain('Downloading web1.example.com image from [https://images.binarylane.com.au/[path withheld]]')
        ->not->toContain('SIGNED-SECRET-TOKEN');
});

it('keeps the url out of what app:validate prints and records', function () {
    fakeApi([fakeServer()]);
    fakeBinaries(['*--version*' => Process::result(output: 'some tool v1.2.3')]);
    Http::swap(new Illuminate\Http\Client\Factory);
    Http::fake(['*' => Http::response('denied', 403)]);

    $written = everythingWrittenBy('app:validate', ['--download' => SIGNED_URL, '--no-api' => true]);

    expect($written)->toContain('Download transfer')
        ->not->toContain('SIGNED-SECRET-TOKEN');
});

/**
 * One server with one backup whose download link is SIGNED_URL, and the image itself
 * answered by $download. A single stub set, so the answer for the image is not shadowed
 * by fakeApi()'s own - Http::fake() merges, and the first match wins.
 */
function fakeDownloadAnswering(Closure $download): void
{
    Http::swap(new Illuminate\Http\Client\Factory);

    Http::fake(function ($request) use ($download) {
        $path = parse_url($request->url(), PHP_URL_PATH);

        return match (true) {
            str_ends_with($path, '.zst') => $download(),
            str_ends_with($path, '/backups') => Http::response(['backups' => [fakeImage()], 'meta' => ['total' => 1]]),
            (bool) preg_match('#/images/12345/download$#', $path) => Http::response(['link' => fakeLink(12345, SIGNED_URL)[12345]]),
            default => Http::response(['servers' => [fakeServer()], 'meta' => ['total' => 1]]),
        };
    });
}
