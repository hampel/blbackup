<?php

return [
    'default' => 'local',
    'disks' => [
        'local' => [
            'driver' => 'local',
            'root' => env('BACKUP_DIR'),
        ],
    ],
];
