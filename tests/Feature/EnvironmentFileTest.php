<?php

/*
| A malformed line in the .env beside a compiled binary must fail like any other bad
| setting: a sentence on stderr and exit 1.
|
| Laravel's own environment bootstrapper does that, but it only reads the project's file.
| Laravel Zero loads the one beside the binary afterwards, by calling Dotenv directly with
| no handler, so the failure escapes as an uncaught fatal - exit 255, and the stack trace on
| STDOUT wherever display_errors is on, which is the default in the PHP images this ships
| in. The entry script catches it.
|
| This is a scan rather than a run, and the reason is the reason the bug existed: the
| failure happens inside the bootstrapper, before the kernel exists, and only from a phar.
| Artisan::call() cannot reach it, and building a binary inside the suite to reach it would
| cost more than it proves. So the test guards the claim that the catch is there, and the
| behaviour itself is verified against a rebuilt binary at release time. Measured on
| 2026-09-16: without the catch, exit 255 and 3,621 bytes of trace on stdout; with it, exit
| 1, stdout empty, "The environment file is invalid!" on stderr.
*/

function entryScript() : string
{
    return file_get_contents(base_path('blbackup'));
}

it('finds the entry script it is supposed to be scanning', function () {
    // a scan that silently matches nothing passes forever
    expect(entryScript())->toContain('$kernel->handle(');
});

it('catches a malformed environment file rather than letting it escape as a fatal', function () {
    expect(entryScript())->toMatch('/catch\s*\(\s*\\\\?Dotenv\\\\Exception\\\\InvalidFileException/');
});

it('reports that failure on stderr and exits non-zero', function () {
    // stdout is what a caller reads; the trace used to land there
    expect(entryScript())->toContain("fwrite(STDERR, 'The environment file is invalid!'")
        ->toMatch('/exit\(1\);/');
});
