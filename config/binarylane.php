<?php

return [

    /*
    |--------------------------------------------------------------------------
    | API Key
    |--------------------------------------------------------------------------
    */

    'api_token' => env('BINARYLANE_API_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Timeout
    |--------------------------------------------------------------------------
    |
    | Maximum time in seconds to wait for downloads to complete
    */

    'timeout' => env('DOWNLOAD_TIMEOUT', 3600),

    /*
    |--------------------------------------------------------------------------
    | ZSTD Path
    |--------------------------------------------------------------------------
    |
    | Path to zstd executable for testing downloads
    */

    'zstd_binary' => env('ZSTD_BINARY', '/usr/bin/zstd'),

    /*
    |--------------------------------------------------------------------------
    | Keeponly days
    |--------------------------------------------------------------------------
    |
    | Number of days to keep local backups. Backups older than this will be removed by the clean command
    */

    'keeponly_days' => env('KEEPONLY_DAYS', 7),

    /*
    |--------------------------------------------------------------------------
    | rclone settings
    |--------------------------------------------------------------------------
    |
    | Move backup files to secondary storage after downloading
    | Requires rclone to be installed
    */

    'rclone' => [
        /**
         * Path to rclone binary for transferring files to secondary storage
         */
        'binary' => env('RCLONE_BINARY', '/usr/bin/rclone'),

        /**
         * rclone remote for secondary storage ("remote:path_prefix")
         */
        'remote' => env('RCLONE_REMOTE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Wget Path
    |--------------------------------------------------------------------------
    |
    | Path to wget executable for downloading images
    */

    'wget_binary' => env('WGET_BINARY', '/usr/bin/wget'),

    /*
    |--------------------------------------------------------------------------
    | Run summary
    |--------------------------------------------------------------------------
    |
    | One Slack message per run, saying whether it worked.
    |
    | Different from the slack log channel in config/logging.php and
    | complementary to it: a log channel posts a record at a time, so it can only
    | ever report trouble - a night where everything worked produces nothing at
    | all, which is the same silence as a cron entry nobody installed or a
    | machine that was off. The same webhook can serve both.
    |
    | notify: "always", or "failure" for the bad nights only. "failure" buys
    | quiet at the cost of the property the summary exists for, since silence
    | stops meaning anything again.
    |
    */

    'summary' => [
        'slack_webhook' => env('BLBACKUP_SUMMARY_SLACK_WEBHOOK'),
        'notify' => env('BLBACKUP_SUMMARY_NOTIFY', 'always'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Timezone
    |--------------------------------------------------------------------------
    |
    | Timezone to display dates in
    */

    'timezone' => env('APP_TIMEZONE', 'UTC'),
];
