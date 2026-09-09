<?php

use App\Commands\AppValidate;

/*
| AppValidate::HIGHEST_LOGGED_LEVEL is a claim about every other file in app/,
| and the slack threshold warning is worth exactly as much as that claim is
| true. Nothing else in the suite would notice it going stale - a `critical`
| call added in a year's time breaks the warning silently, and the warning is
| the thing that was supposed to catch a channel that cannot fire.
*/

const LOG_LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

/**
 * Every logging call in app/, as [file, line, level].
 *
 * Token-based rather than a regex, because the level usually sits on the line
 * after `$this->log(` - there are 40 such call sites here, and a per-line
 * pattern finds almost none of them.
 *
 * Two shapes are recognised: the first literal argument to log()/record(), and
 * a static Log::<level>() call. Deliberately not `$this-><level>()`, which is
 * Laravel's console output - `$this->alert('...')` prints a banner and logs
 * nothing, and counting it would be a false alarm forever.
 */
function loggingCalls(): array
{
    $calls = [];

    $files = new RegexIterator(
        new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app'))),
        '/\.php$/'
    );

    foreach ($files as $file)
    {
        $path = $file->getPathname();
        $tokens = token_get_all(file_get_contents($path));
        $count = count($tokens);

        $next = function (int $i) use ($tokens, $count) {
            $i++;
            while ($i < $count && is_array($tokens[$i]) && $tokens[$i][0] === T_WHITESPACE) $i++;

            return $i;
        };

        for ($i = 0; $i < $count; $i++)
        {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_STRING) continue;

            $name = $tokens[$i][1];
            $line = $tokens[$i][2];

            // Log::critical(...) - the receiver has to be the facade, so an
            // ordinary method that happens to share a level's name is ignored
            if (in_array(strtolower($name), LOG_LEVELS, true))
            {
                $before = $i - 1;
                while ($before >= 0 && is_array($tokens[$before]) && $tokens[$before][0] === T_WHITESPACE) $before--;

                $isStatic = $before >= 1
                    && is_array($tokens[$before]) && $tokens[$before][0] === T_DOUBLE_COLON;

                if ($isStatic && $tokens[$next($i)] === '(')
                {
                    $calls[] = [$path, $line, strtolower($name)];
                }

                continue;
            }

            if (!in_array($name, ['log', 'record'], true)) continue;

            $open = $next($i);
            if (($tokens[$open] ?? null) !== '(') continue;

            $arg = $next($open);
            if (!is_array($tokens[$arg]) || $tokens[$arg][0] !== T_CONSTANT_ENCAPSED_STRING) continue;

            $level = strtolower(trim($tokens[$arg][1], "'\""));

            if (in_array($level, LOG_LEVELS, true))
            {
                $calls[] = [$path, $line, $level];
            }
        }
    }

    return $calls;
}

it('finds the logging calls it is supposed to be scanning', function () {
    // the guard on the guard: a scanner that silently matched nothing would
    // pass the test below forever, which is the failure mode of every such scan
    $calls = loggingCalls();

    expect(count($calls))->toBeGreaterThan(50);

    $levels = array_unique(array_column($calls, 2));

    expect($levels)->toContain('error')->toContain('debug')->toContain('notice');
});

it('logs nothing above the level app:validate claims is the highest', function () {
    $ceiling = array_search(
        (new ReflectionClass(AppValidate::class))->getConstant('HIGHEST_LOGGED_LEVEL'),
        LOG_LEVELS,
        true
    );

    expect($ceiling)->not->toBeFalse('HIGHEST_LOGGED_LEVEL is not a log level');

    $above = array_filter(
        loggingCalls(),
        fn ($call) => array_search($call[2], LOG_LEVELS, true) > $ceiling
    );

    $report = implode(PHP_EOL, array_map(
        fn ($c) => '  ' . str_replace(base_path() . '/', '', $c[0]) . ':' . $c[1] . ' logs at ' . $c[2],
        $above
    ));

    expect($above)->toBe([], "Something now logs above HIGHEST_LOGGED_LEVEL:"
        . PHP_EOL . $report . PHP_EOL
        . "Raise the constant and lower LOG_SLACK_LEVEL's default to match, or the"
        . " slack threshold warning is telling installs to configure a level that"
        . " would now miss records.");
});

it('pins the slack level the suite runs at to the level that ships', function () {
    // the pin in tests/Pest.php exists to keep the suite off the developer's
    // .env; it is only useful while it agrees with what an install would get
    $shipped = require base_path('config/logging.php');

    expect(config('logging.channels.slack.level'))->toBe($shipped['channels']['slack']['level']);
});
