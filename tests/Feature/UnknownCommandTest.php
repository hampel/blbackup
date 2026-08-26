<?php

use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;

/*
| These drive the console kernel directly. $this->artisan() resolves and calls
| the command itself, so it never passes through the proxying this is about -
| a test written with it would pass whether or not the override is in place.
*/

function runKernel(string $arguments): array
{
    $output = new BufferedOutput;

    $input = new StringInput($arguments);

    // Symfony asks "Did you mean this?" when a command name nearly matches, and
    // waits on stdin for the answer - which hangs the suite. Cron is not a tty
    // either, so non-interactive is also the real behaviour being tested.
    $input->setInteractive(false);

    $exit = app(Kernel::class)->handle($input, $output);

    return [$exit, $output->fetch()];
}

it('binds the application kernel over the framework one', function () {
    // without this the override compiles, loads, and does nothing at all
    expect(app(Kernel::class))->toBeInstanceOf(App\Kernel::class);
});

it('fails on a command name that does not exist', function () {
    [$exit, $output] = runKernel('app:validte');

    expect($exit)->toBe(1)
        ->and($output)->toContain('app:validte');
});

it('suggests the command that was meant', function () {
    [, $output] = runKernel('app:validte');

    expect($output)->toContain('app:validate');
});

it('does not print the command list instead of failing', function () {
    [, $output] = runKernel('backpus');

    // the summary is what it used to print, and exit 0 while doing it
    expect($output)->not->toContain('USAGE:');
});

it('still proxies a bare invocation to the default command', function () {
    [$exit, $output] = runKernel('');

    expect($exit)->toBe(0)->and($output)->toContain('USAGE:');
});

it('still proxies an invocation carrying only options', function () {
    [$exit] = runKernel('--version');

    expect($exit)->toBe(0);
});

it('still runs a command that does exist', function () {
    fakeApi([fakeServer()]);

    [$exit, $output] = runKernel('servers --names');

    expect($exit)->toBe(0)->and($output)->toContain('web1.example.com');
});

it('refuses the scheduler commands, which this application does not use', function () {
    // hidden is not removed: a hidden schedule:run stays runnable, prints "No
    // scheduled commands are ready to run" and exits 0 - a clean success from a
    // command that does nothing, in a tool whose exit code is all cron reads
    foreach (['schedule:run', 'schedule:list', 'schedule:finish'] as $command)
    {
        [$exit, $output] = runKernel($command);

        // Symfony names the namespace rather than the command, which is itself
        // the evidence: there is no "schedule" namespace left to define it in
        expect($exit)->not->toBe(0)
            ->and($output)->toContain('There are no commands defined in the "schedule" namespace');
    }
});
