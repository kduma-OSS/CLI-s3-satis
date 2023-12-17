<?php

return [
    'default' => 'local',
    'disks' => [
        'local' => [
            'driver' => 'local',
            'root' => getcwd(),
        ],

        'temp' => [
            'driver' => 'local',
            'root' => str(sys_get_temp_dir())->finish(DIRECTORY_SEPARATOR)->append('s3-satis-generator')->finish(DIRECTORY_SEPARATOR),
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('S3_ACCESS_KEY_ID'),
            'secret' => env('S3_SECRET_ACCESS_KEY'),
            'region' => env('S3_REGION', 'us-east-1'),
            'bucket' => env('S3_BUCKET'),
            'endpoint' => env('S3_ENDPOINT'),
            'use_path_style_endpoint' => env('S3_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
        ],
    ],
];
