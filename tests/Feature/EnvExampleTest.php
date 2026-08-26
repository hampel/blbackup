<?php

/*
| .env.example is the only description of what this tool can be configured to
| do, and it is read by someone setting up a machine they cannot easily test on.
| A setting missing from it is a setting the tool does not have, as far as its
| reader is concerned - and a value in it that is not the default reads as one
| that is, which is how LOCK_FILE came to look like it was already on a shared
| mount when it was not.
|
| This checks the half a test can check: that the file and config/ name the same
| settings. Whether a documented value IS the default is left to the EXAMPLE
| marker the file explains at the top, because encoding that here would need a
| hand-kept map of env key to config key - another thing to drift.
*/

/**
 * Settings Laravel's stock config files carry that this tool does not use and
 * has no business documenting.
 */
const UNDOCUMENTED = [
    'LOG_DEPRECATIONS_CHANNEL',
    'LOG_DEPRECATIONS_TRACE',
    'LOG_PAPERTRAIL_HANDLER',
    'LOG_STDERR_FORMATTER',
    'LOG_SYSLOG_FACILITY',
    'PAPERTRAIL_PORT',
    'PAPERTRAIL_URL',
];

function configuredKeys(): array
{
    $keys = [];

    foreach (glob(base_path('config/*.php')) as $file)
    {
        preg_match_all("/env\('([A-Z_]+)'/", file_get_contents($file), $matches);

        $keys = array_merge($keys, $matches[1]);
    }

    return array_values(array_diff(array_unique($keys), UNDOCUMENTED));
}

function documentedKeys(): array
{
    preg_match_all(
        '/^#?([A-Z_]+)=/m',
        file_get_contents(base_path('.env.example')),
        $matches
    );

    return array_values(array_unique($matches[1]));
}

it('documents every setting the configuration reads', function () {
    $missing = array_diff(configuredKeys(), documentedKeys());

    expect($missing)->toBeEmpty(
        'Not in .env.example: ' . implode(', ', $missing)
    );
});

it('documents no setting the configuration does not read', function () {
    // LARAVEL_STORAGE_PATH is read by the framework rather than by config/, and
    // is documented because a compiled binary is useless without knowing it
    $imaginary = array_diff(documentedKeys(), configuredKeys(), ['LARAVEL_STORAGE_PATH']);

    expect($imaginary)->toBeEmpty(
        'Documented but never read: ' . implode(', ', $imaginary)
    );
});
