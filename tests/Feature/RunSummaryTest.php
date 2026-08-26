<?php

use App\Support\RunSummary;
use App\Support\SlackSummary;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Hampel\SlackMessage\SlackWebhook;
use Illuminate\Support\Facades\Process;

/*
| Faked at the HTTP client, not at the sender: the payload is what this code
| produces, so mocking SlackSummary would test nothing. The assertions read the
| decoded request body.
*/

function slackTransport(array &$history, array $responses = null): SlackWebhook
{
    $stack = HandlerStack::create(new MockHandler($responses ?: [new Response(200, [], 'ok')]));
    $stack->push(Middleware::history($history));

    return new SlackWebhook(new Client(['handler' => $stack, 'http_errors' => false]));
}

function reporter(array &$history, string $notify = 'always', string $webhook = 'https://hooks.slack.test/abc'): SlackSummary
{
    return new SlackSummary(
        slackTransport($history),
        $webhook,
        $notify,
        'BinaryLane Backup 1.9.2',
        'unraid'
    );
}

function sentPayload(array $history): array
{
    return json_decode((string) $history[0]['request']->getBody(), true);
}

it('reports a good run with what it produced', function () {
    $history = [];
    $summary = new RunSummary;
    $summary->claim('create');
    $summary->recordBackup('web1.example.com', 65.0);
    $summary->recordDownload('web1.example.com', 'web1.example.com/backup.zst', 2 * 1024 * 1024 * 1024);
    $summary->recordMove('web1.example.com/backup.zst', 2 * 1024 * 1024 * 1024);

    reporter($history)->send($summary);

    $payload = sentPayload($history);
    $attachment = $payload['attachments'][0];
    $fields = collect($attachment['fields'])->pluck('value', 'title');

    expect($payload['text'])->toBe('Backup completed on unraid')
        ->and($attachment['color'])->toBe('good')
        ->and($fields['Servers'])->toBe('1')
        ->and($fields['Backups taken'])->toBe('1')
        ->and($fields['Downloaded'])->toBe('1 (2.00 GB)')
        ->and($fields['Moved to remote'])->toBe('1')
        ->and($attachment['footer'])->toBe('BinaryLane Backup 1.9.2 on unraid');
});

it('names the server and the stage that failed', function () {
    $history = [];
    $summary = new RunSummary;
    $summary->claim('create');
    $summary->recordBackup('web1.example.com', 10.0);
    $summary->recordFailure('db1.example.com', 'download', "wget: unable to resolve host\nsecond line");

    reporter($history)->send($summary);

    $payload = sentPayload($history);
    $attachment = $payload['attachments'][0];

    expect($payload['text'])->toBe('Backup failed on unraid')
        ->and($attachment['color'])->toBe('danger')
        // the body renders above the fields in Slack, so failures are read first
        ->and($attachment['text'])->toBe('db1.example.com (download): wget: unable to resolve host')
        ->and(collect($attachment['fields'])->pluck('value', 'title')['Failures'])->toBe('1');
});

it('reports a run that never started, without counts', function () {
    $history = [];
    $summary = new RunSummary;
    $summary->claim('create');
    $summary->block('No server data returned - the API token may be wrong');

    reporter($history)->send($summary);

    $payload = sentPayload($history);

    expect($payload['text'])->toBe('Backup did not run on unraid')
        // a row of zeroes would read as a run that started and found nothing
        ->and($payload['attachments'][0]['fields'] ?? [])->toBe([])
        ->and($payload['attachments'][0]['text'])->toContain('the API token may be wrong');
});

it('marks a dry run', function () {
    $history = [];
    $summary = new RunSummary;
    $summary->claim('clean', dryRun: true);

    reporter($history)->send($summary);

    expect(sentPayload($history)['text'])->toBe('[Dry run] Backup completed on unraid');
});

it('caps the failures it quotes', function () {
    $history = [];
    $summary = new RunSummary;
    $summary->claim('create');

    for ($i = 1; $i <= 12; $i++)
    {
        $summary->recordFailure("server{$i}.example.com", 'create', 'backup errored');
    }

    reporter($history)->send($summary);

    expect(sentPayload($history)['attachments'][0]['text'])->toContain('... and 2 more - see the log');
});

it('sends nothing when no webhook is configured', function () {
    $history = [];
    $summary = new RunSummary;
    $summary->claim('create');

    expect(reporter($history, webhook: '')->shouldSend($summary))->toBeFalse();
});

it('sends nothing for a run the operator cancelled', function () {
    $history = [];
    $summary = new RunSummary;
    $summary->claim('clean');
    $summary->cancel();

    // clean is the only command that asks before it acts, and "Backup completed"
    // with no counts, delivered to the channel of the person who just answered
    // no, is worse than saying nothing at all
    expect(reporter($history)->shouldSend($summary))->toBeFalse();
});

it('sends a good run only when notify is always', function () {
    $history = [];
    $summary = new RunSummary;
    $summary->claim('create');

    expect(reporter($history, 'always')->shouldSend($summary))->toBeTrue()
        ->and(reporter($history, 'failure')->shouldSend($summary))->toBeFalse();
});

it('sends a failed run whatever notify says', function () {
    $history = [];
    $summary = new RunSummary;
    $summary->claim('create');
    $summary->recordFailure('web1.example.com', 'create', 'backup errored');

    expect(reporter($history, 'failure')->shouldSend($summary))->toBeTrue();
});

it('raises when slack refuses the message', function () {
    $history = [];
    $slack = slackTransport($history, [new Response(403, [], 'invalid_token')]);
    $reporter = new SlackSummary($slack, 'https://hooks.slack.test/abc', 'always', 'app', 'unraid');

    $summary = new RunSummary;
    $summary->claim('create');

    expect(fn () => $reporter->send($summary))->toThrow(RuntimeException::class, 'Slack refused the message');
});

it('posts a test message that says where it came from', function () {
    $history = [];

    reporter($history)->sendTest();

    $payload = sentPayload($history);

    expect($payload['text'])->toContain('Test message from app:validate on unraid')
        ->and($payload['attachments'][0]['color'])->toBe('good');
});

it('formats durations at the boundaries', function () {
    $summary = new class extends RunSummary {
        public float $fake = 0.0;
        public function seconds() : float { return $this->fake; }
    };

    $reporter = new SlackSummary(new SlackWebhook(new Client), 'https://hooks.slack.test/abc', 'always', 'app', 'unraid');
    $duration = (new ReflectionMethod($reporter, 'duration'))->getClosure($reporter);

    expect($duration(0.4))->toBe('<1s')      // not "0s", which reads as unmeasured
        ->and($duration(1.0))->toBe('1s')
        ->and($duration(59.6))->toBe('1m 0s')
        ->and($duration(90.0))->toBe('1m 30s')
        ->and($duration(3600.0))->toBe('1h 0m')
        ->and($duration(7830.0))->toBe('2h 10m');
});

/*
| The wiring: cron is the only command that posts, a whole run produces one
| message however many stages it ran, and Slack cannot change the outcome of
| the work. Stages run by hand say nothing - somebody is already watching them.
*/

function interceptSummary(array &$history, string $notify = 'always', string $webhook = 'https://hooks.slack.test/abc', array $responses = null): void
{
    $transport = slackTransport($history, $responses);

    app()->singleton(SlackSummary::class, fn () => new SlackSummary(
        $transport, $webhook, $notify, 'BinaryLane Backup 1.9.2', 'unraid'
    ));
}

it('posts one summary for a run, not one per stage', function () {
    $history = [];
    interceptSummary($history);

    fakeApi([fakeServer()], [fakeImage()], fakeLink(12345, 'https://images.binarylane.com.au/backup-12345.zst'));
    fakeBinaries([
        '*wget*' => wgetWrites(MEGABYTE),
        '*lsjson*' => Process::result(rcloneListing([])),
    ]);

    $this->artisan('cron')->assertSuccessful();

    // cron claims the run; every stage it calls sees it taken and stays quiet
    expect($history)->toHaveCount(1);

    $fields = collect(sentPayload($history)['attachments'][0]['fields'])->pluck('value', 'title');

    expect(sentPayload($history)['text'])->toBe('Backup completed on unraid')
        ->and($fields['Backups taken'])->toBe('1')
        ->and($fields['Downloaded'])->toBe('1 (1.0 MB)')
        ->and($fields['Moved to remote'])->toBe('1');
});

it('says nothing when a stage is run by hand', function () {
    $history = [];
    interceptSummary($history);

    putDownload(backupPath(), MEGABYTE);
    fakeBinaries();

    $this->artisan('move', ['file' => backupPath()])->assertSuccessful();

    // somebody is sitting watching this one, and the summary would be delivered
    // to their own channel to tell them what they just watched happen
    expect($history)->toBeEmpty();
});

it('reports the failure a run recorded', function () {
    $history = [];
    interceptSummary($history);

    fakeApi([fakeServer()], statuses: [fakeAction('errored', 40)]);

    $this->artisan('cron', ['--no-clean' => true])->assertFailed();

    expect(sentPayload($history)['text'])->toBe('Backup failed on unraid')
        ->and(sentPayload($history)['attachments'][0]['text'])->toContain('web1.example.com (create): backup errored');
});

it('does not fail the run when slack refuses the summary', function () {
    $history = [];
    interceptSummary($history, responses: [new Response(500, [], 'server error')]);

    fakeApi([fakeServer()], [fakeImage()], fakeLink(12345, 'https://images.binarylane.com.au/backup-12345.zst'));
    fakeBinaries(['*wget*' => wgetWrites(MEGABYTE)]);

    // the work succeeded; whether Slack heard about it does not change that
    $this->artisan('cron', ['--no-clean' => true])
        ->expectsOutputToContain('Could not send the run summary')
        ->assertSuccessful();
});

it('does not post a summary for the listing commands', function () {
    $history = [];
    interceptSummary($history);

    fakeApi([fakeServer()]);

    $this->artisan('servers')->assertSuccessful();
    $this->artisan('account')->assertSuccessful();

    expect($history)->toBeEmpty();
});

it('reports a run that could not start at all', function () {
    $history = [];
    interceptSummary($history);

    fakeApi([]);

    // nothing ran, so nothing logged anything worth summarising - this is the
    // failure that otherwise leaves one log line and no alert
    $this->artisan('cron', ['--no-clean' => true])->assertFailed();

    expect($history)->toHaveCount(1);

    $payload = sentPayload($history);

    expect($payload['text'])->toBe('Backup did not run on unraid')
        ->and($payload['attachments'][0]['text'])->toContain('No server data returned')
        ->and($payload['attachments'][0]['fields'] ?? [])->toBe([]);
});

it('reports an unreadable server list as a run that did not start', function () {
    $history = [];
    interceptSummary($history);

    fakeApi([fakeServer()]);

    // the stage that could not start is the one with something to say, and cron
    // is the one that owns the run - so the block has to cross that boundary
    $this->artisan('cron', ['--include' => '/no/such/list.txt', '--no-clean' => true])->assertFailed();

    expect(sentPayload($history)['text'])->toBe('Backup did not run on unraid')
        ->and(sentPayload($history)['attachments'][0]['text'])->toContain('/no/such/list.txt');
});

it('keeps what a run did when it fails part way through', function () {
    $history = [];
    interceptSummary($history);

    putDownload(backupPath(), MEGABYTE);
    fakeBinaries();

    // move succeeds, then the second file is missing: a failure within a run,
    // not a run that never started - the move it did manage must survive
    $this->artisan('move', ['file' => backupPath()])->assertSuccessful();
    $summary = app(App\Support\RunSummary::class);
    $summary->recordFailure('other.zst', 'move', 'gone');

    expect($summary->hasWork())->toBeTrue()
        ->and($summary->blockedBy())->toBeNull();
});
