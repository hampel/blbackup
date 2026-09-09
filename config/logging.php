<?php

use App\Logging\StampHostname;
use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogUdpHandler;
use Monolog\Processor\PsrLogMessageProcessor;

// named once, because the slack channel labels its posts with it as well
$hostname = env('LOG_HOSTNAME', gethostname());

return [

    /*
    |--------------------------------------------------------------------------
    | Log Hostname
    |--------------------------------------------------------------------------
    |
    | What this machine calls itself in the logs, stamped onto every record by the
    | channels tapped with StampHostname below. A Slack channel that says which
    | host it is reporting on can serve every installation from one webhook,
    | instead of one webhook per machine to tell them apart.
    |
    | This runs in a container, which calls itself a hex string that changes with
    | every image rebuild - so setting LOG_HOSTNAME is worth doing even with one
    | installation. Set it to an empty value to leave records unstamped.
    |
    */

    'hostname' => $hostname,

    /*
    |--------------------------------------------------------------------------
    | Default Log Channel
    |--------------------------------------------------------------------------
    |
    | This option defines the default log channel that is utilized to write
    | messages to your logs. The value provided here should match one of
    | the channels present in the list of "channels" configured below.
    |
    */

    'default' => env('LOG_CHANNEL', 'stack'),

    /*
    |--------------------------------------------------------------------------
    | Deprecations Log Channel
    |--------------------------------------------------------------------------
    |
    | This option controls the log channel that should be used to log warnings
    | regarding deprecated PHP and library features. This allows you to get
    | your application ready for upcoming major versions of dependencies.
    |
    */

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace' => env('LOG_DEPRECATIONS_TRACE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Log Channels
    |--------------------------------------------------------------------------
    |
    | Here you may configure the log channels for your application. Laravel
    | utilizes the Monolog PHP logging library, which includes a variety
    | of powerful log handlers and formatters that you're free to use.
    |
    | Available Drivers: "single", "daily", "slack", "syslog",
    |                    "errorlog", "monolog", "custom", "stack"
    |
    */

    'channels' => [

        'stack' => [
            'driver' => 'stack',
            'channels' => explode(',', env('LOG_STACK', 'null')),
            'ignore_exceptions' => false,
        ],

        'single' => [
            'driver' => 'single',
            'path' => env('LOG_STORAGE_PATH', storage_path('blbackup.log')),
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
            'tap' => [StampHostname::class],
        ],

        'daily' => [
            'driver' => 'daily',
            'path' => env('LOG_STORAGE_PATH', storage_path('blbackup.log')),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => env('LOG_DAILY_DAYS', 14),
            'replace_placeholders' => true,
            'tap' => [StampHostname::class],
        ],

        // the username also lands in the footer of the Slack attachment, which is
        // shown whether or not the webhook is allowed to override the posting name
        'slack' => [
            'driver' => 'slack',
            'url' => env('LOG_SLACK_WEBHOOK_URL'),
            'username' => env('LOG_SLACK_USERNAME', $hostname ?: 'blbackup'),
            'emoji' => env('LOG_SLACK_EMOJI', ':boom:'),
            // `error`, not Laravel's stock `critical`, because nothing in this
            // application logs above `error` - the highest level any call site
            // uses. Left at `critical` the channel passes every check and then
            // stays silent on the night it was installed for, which is worse
            // than a noisy one: it cannot be told from a quiet night.
            // app:validate warns if this is set above what anything logs at,
            // and AppValidate::HIGHEST_LOGGED_LEVEL is what it compares against.
            'level' => env('LOG_SLACK_LEVEL', 'error'),
            'replace_placeholders' => true,
            'tap' => [StampHostname::class],
        ],

        'papertrail' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => env('LOG_PAPERTRAIL_HANDLER', SyslogUdpHandler::class),
            'handler_with' => [
                'host' => env('PAPERTRAIL_URL'),
                'port' => env('PAPERTRAIL_PORT'),
                'connectionString' => 'tls://'.env('PAPERTRAIL_URL').':'.env('PAPERTRAIL_PORT'),
            ],
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'stderr' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => StreamHandler::class,
            'formatter' => env('LOG_STDERR_FORMATTER'),
            'with' => [
                'stream' => 'php://stderr',
            ],
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'syslog' => [
            'driver' => 'syslog',
            'level' => env('LOG_LEVEL', 'debug'),
            'facility' => env('LOG_SYSLOG_FACILITY', LOG_USER),
            'replace_placeholders' => true,
        ],

        'errorlog' => [
            'driver' => 'errorlog',
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],

        'emergency' => [
            'path' => storage_path('logs/laravel.log'),
        ],

    ],

];
