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
    | Lock file
    |--------------------------------------------------------------------------
    |
    | One lock covers every command that writes, so a run that overruns holds
    | the next one off rather than running over the top of it. Defaults to the
    | storage path.
    |
    | In a container this MUST name a path on a shared mount. Each
    | `docker compose run` gets its own filesystem, so a lock inside the image
    | is a lock two concurrent runs cannot see each other holding.
    |
    | The default is resolved here rather than in BackupLock, so that this file
    | and .env.example agree about what it is - they did not, and an operator
    | reading .env.example took its container example for the default.
    */

    'lock_file' => env('LOCK_FILE') ?: storage_path('blbackup.lock'),

    /*
    |--------------------------------------------------------------------------
    | Server lists
    |--------------------------------------------------------------------------
    |
    | Paths to plain-text files naming servers to back up, one hostname per
    | line, matched against the server's name. An include list backs up only
    | the servers it names; an exclude list backs up everything but those.
    |
    | Neither is set by default, which means no filtering - every server on the
    | account is backed up. That is not an error and app:validate does not treat
    | it as one.
    |
    | These exist so that an unattended install can be read out of its
    | configuration. --include and --exclude override them for one run, but a
    | list that only ever appeared on a crontab line is invisible to app:config
    | and unverifiable by app:validate: nothing can tell you the path is wrong
    | until a run fails on it, and nothing can tell you the run is quietly
    | backing up more than you think.
    |
    */

    'include_file' => env('INCLUDE_FILE'),

    'exclude_file' => env('EXCLUDE_FILE'),

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
