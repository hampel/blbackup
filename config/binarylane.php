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

    'timeout' => env('TIMEOUT', 3600),

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
];
