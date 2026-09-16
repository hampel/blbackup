<?php

/*
| LOG_STACK falls back to the null channel however it is left empty.
|
| env() turns the literal value `null` into PHP null, so `LOG_STACK=null` - the value
| .env.example documents - used to reach explode() as '' and build a stack of one channel
| called ''. Laravel cannot create that channel: it falls back to its emergency logger at
| storage_path('logs/laravel.log'), so every record a run was supposed to write goes to a
| file nothing mentions, and the run still exits 0. This tool's log is the only trace an
| overnight backup leaves.
|
| The config file is evaluated with the variable set, rather than read through config(),
| because the value is computed at load time and config() only holds the one this process
| started with.
*/

function stackChannelsWith(?string $value) : array
{
    $saved = [
        'server' => $_SERVER['LOG_STACK'] ?? null,
        'env' => $_ENV['LOG_STACK'] ?? null,
        'putenv' => getenv('LOG_STACK'),
    ];

    try
    {
        if ($value === null)
        {
            unset($_SERVER['LOG_STACK'], $_ENV['LOG_STACK']);
            putenv('LOG_STACK');
        }
        else
        {
            $_SERVER['LOG_STACK'] = $_ENV['LOG_STACK'] = $value;
            putenv("LOG_STACK={$value}");
        }

        $config = require base_path('config/logging.php');

        return $config['channels']['stack']['channels'];
    }
    finally
    {
        foreach (['server' => '_SERVER', 'env' => '_ENV'] as $key => $global)
        {
            if ($saved[$key] === null)
            {
                unset($GLOBALS[$global]['LOG_STACK']);
            }
            else
            {
                $GLOBALS[$global]['LOG_STACK'] = $saved[$key];
            }
        }

        putenv($saved['putenv'] === false ? 'LOG_STACK' : "LOG_STACK={$saved['putenv']}");
    }
}

it('logs to the null channel when LOG_STACK is unset', function () {
    expect(stackChannelsWith(null))->toBe(['null']);
});

it('treats LOG_STACK=null as the default, not as a channel with no name', function () {
    expect(stackChannelsWith('null'))->toBe(['null']);
});

it('treats an empty LOG_STACK as the default', function () {
    expect(stackChannelsWith(''))->toBe(['null']);
});

it('drops an empty entry from a list of channels', function () {
    expect(stackChannelsWith('single,,slack'))->toBe(['single', 'slack']);
});

it('still honours a stack of real channels', function () {
    expect(stackChannelsWith('single,slack'))->toBe(['single', 'slack']);
});
