<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/*
| --quiet is how a crontab asks to hear only about trouble, and cron mails output,
| not an exit code. So a failure that stops a whole command has to print under it:
| before, a revoked token or a missing server list exited 1 and printed nothing,
| while the failure of one server - which goes through log() - did print.
*/

function runAt(int $verbosity, string $command, array $arguments = []): array
{
    $output = new BufferedOutput($verbosity);

    $exit = Artisan::call($command, $arguments, $output);

    return [$exit, $output->fetch()];
}

it('prints an API failure under --quiet', function () {
    Http::fake(['*' => Http::response('', 401)]);

    [$exit, $output] = runAt(OutputInterface::VERBOSITY_QUIET, 'account');

    expect($exit)->toBe(1)->and($output)->toContain('HTTP 401');
});

it('prints a failure raised with fail() under --quiet', function () {
    [$exit, $output] = runAt(OutputInterface::VERBOSITY_QUIET, 'create');

    expect($exit)->toBe(1)->and($output)->toContain('No hostname or server_id specified');
});

it('prints a fail() message once, not twice, at normal verbosity', function () {
    // BaseCommand::fail() prints under --quiet only, because at normal verbosity Laravel's
    // own catch prints it - and doing both would say it twice
    [$exit, $output] = runAt(OutputInterface::VERBOSITY_NORMAL, 'create');

    expect($exit)->toBe(1)
        ->and(substr_count($output, 'No hostname or server_id specified'))->toBe(1);
});
