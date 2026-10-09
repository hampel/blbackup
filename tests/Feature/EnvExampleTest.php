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
| settings. Whether a documented value IS the default is mostly left to the EXAMPLE
| marker the file explains at the top, because encoding that here would need a
| hand-kept map of env key to config key - another thing to drift.
|
| One class needs no map, though, and is the one that cannot be true: a default
| computed from storage_path() has no literal to show, since a compiled binary
| resolves the storage path against the working directory. Those keys are found by
| scanning config/ rather than listed here, and their documented value must be
| blank. Three lines showed a relative literal until 2026-10-09, each off by a
| `storage/` segment from what the default actually resolves to in a phar.
*/

/**
 * Settings Laravel's stock config files carry that this tool does not use and
 * has no business documenting.
 */
const UNDOCUMENTED = [
    // the BinaryLane client package's, and meaningless for a tool that uses one
    // account, walks every page regardless of page size, and talks to BinaryLane's
    // own API rather than to a recorded fixture or a terminating proxy
    'BINARYLANE_ACCOUNT',
    'BINARYLANE_API_URL',
    'BINARYLANE_PER_PAGE',

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

    // the client package's config too: the application reads it as surely as its
    // own, merged in by the package's provider rather than living in config/, and
    // the API token is one of its settings
    $files = [...glob(base_path('config/*.php')), base_path('vendor/hampel/binarylane-api-laravel/config/binarylane.php')];

    foreach ($files as $file)
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
    // is documented because a compiled binary is useless without knowing it.
    // The HOST_ paths are read by docker compose, which interpolates the same
    // .env the app is given - the app never sees them, and a container install
    // cannot start without them
    $imaginary = array_diff(
        documentedKeys(),
        configuredKeys(),
        ['LARAVEL_STORAGE_PATH', 'HOST_DOWNLOAD_PATH', 'HOST_LOG_PATH']
    );

    expect($imaginary)->toBeEmpty(
        'Documented but never read: ' . implode(', ', $imaginary)
    );
});

/**
 * Settings whose default is computed from the storage path, found by scanning
 * config/ so that a new one cannot be added without this noticing. Both shapes
 * the tree uses: env('KEY', storage_path(...)) and env('KEY') ?: storage_path(...).
 */
function computedDefaultKeys(): array
{
    $keys = [];

    foreach (glob(base_path('config/*.php')) as $file)
    {
        preg_match_all(
            "/env\('([A-Z_]+)'(?:\s*,\s*|\)\s*\?:\s*)storage_path\(/",
            file_get_contents($file),
            $matches
        );

        $keys = array_merge($keys, $matches[1]);
    }

    return array_values(array_unique($keys));
}

it('finds the settings whose default is computed', function () {
    // a scan that silently matches nothing passes forever
    expect(computedDefaultKeys())->toContain('DOWNLOAD_PATH', 'LOCK_FILE', 'LOG_STORAGE_PATH');
});

it('documents a computed default as blank rather than inventing a literal', function () {
    $example = file_get_contents(base_path('.env.example'));

    foreach (computedDefaultKeys() as $key)
    {
        preg_match_all('/^#?' . preg_quote($key, '/') . '=(.*)$/m', $example, $matches);

        expect($matches[1])->not->toBeEmpty("{$key} is not documented at all");

        foreach ($matches[1] as $value)
        {
            // a literal here reads exactly like a real default and is never true for
            // long: the storage path follows the working directory in a phar
            expect(trim($value))->toBe('', "{$key} shows a literal for a computed default");
        }
    }
});
