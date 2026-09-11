<?php

/*
| Every webhook url in this suite points at hooks.slack.test, which is a
| reserved name that cannot resolve. That is a defence of its own, not a
| stylistic choice, because the Slack log channel has no seam to fake: Monolog's
| SlackWebhookHandler calls curl directly and takes no client, so neither
| Http::fake() nor a rebound container binding can reach it.
|
| The only thing keeping a test with a slack channel in its stack off the
| network is Log::spy(), which takes the logger out from under the handler. A
| test that forgets it, pointed at the real host, posts - and a 404 from a made-up
| path fails nothing, so it posts silently on every run. Pointed at a host that
| cannot resolve, the same mistake makes the handler throw and the test go red.
|
| So this scans the whole tests tree rather than asserting one value. A check on
| the helpers protects the tests already written; a scan protects the one that
| has not been written yet, which is the one that will forget the spy.
*/

it('points no webhook in the suite at the real Slack host', function () {
    // assembled rather than written out, so this file does not match itself
    $realHost = 'hooks.slack.' . 'com';

    $hits = [];

    $files = new RegexIterator(
        new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('tests'))),
        '/\.php$/'
    );

    foreach ($files as $file)
    {
        foreach (file($file->getPathname()) as $number => $line)
        {
            if (str_contains($line, $realHost))
            {
                $hits[] = '  ' . str_replace(base_path() . '/', '', $file->getPathname()) . ':' . ($number + 1);
            }
        }
    }

    expect($hits)->toBe([], 'A test names the real Slack webhook host:' . PHP_EOL
        . implode(PHP_EOL, $hits) . PHP_EOL
        . 'Use hooks.slack.test. It cannot resolve, so a test that forgets Log::spy()'
        . ' fails instead of posting.');
});

it('finds the files it is supposed to be scanning', function () {
    // a scan that silently reads nothing passes forever - the same guard the
    // log level scan carries, for the same reason
    $files = iterator_to_array(new RegexIterator(
        new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('tests'))),
        '/\.php$/'
    ));

    expect(count($files))->toBeGreaterThan(10);

    $contents = implode('', array_map(fn ($f) => file_get_contents($f->getPathname()), $files));

    expect($contents)->toContain('hooks.slack.test');
});
