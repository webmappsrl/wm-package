<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Here you may configure as many filesystem "disks" as you wish, and you
    | may even configure multiple disks of the same driver. Defaults have
    | been setup for each driver as an example of the required options.
    |
    | Supported Drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'backups' => [
            'driver' => 'local',
            'root' => storage_path('backups'),
        ],

        'importer' => [
            'driver' => 'local',
            'root' => storage_path('importer'),
        ],

        'mapping' => [
            'driver' => 'local',
            'root' => storage_path('importer/mapping'),
        ],

        'pois' => [
            'driver' => 'local',
            'root' => storage_path('json/pois'),
        ],

        'conf' => [
            'driver' => 'local',
            'root' => storage_path('json/conf'),
        ],

        'osm2cai' => [
            'driver' => 'local',
            'root' => storage_path('importer/osm2cai'),
        ],

        'importer-osfmedia' => [
            'driver' => 'local',
            'root' => storage_path('app'),
        ],
        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'visibility' => 'public',
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
        ],
        //the dumps disk could have a dedicated aws configuration
        'wmdumps' => [
            'driver' => 's3',
            'key' => env('AWS_DUMPS_ACCESS_KEY_ID', env('AWS_ACCESS_KEY_ID')),
            'secret' => env('AWS_DUMPS_SECRET_ACCESS_KEY', env('AWS_SECRET_ACCESS_KEY')),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => 'wmdumps',
            'url' => env('AWS_DUMPS_URL', env('AWS_URL')),
            'endpoint' => env('AWS_DUMPS_ENDPOINT', env('AWS_ENDPOINT')),
            'use_path_style_endpoint' => env('AWS_DUMPS_USE_PATH_STYLE_ENDPOINT', env('AWS_USE_PATH_STYLE_ENDPOINT', false)),
        ],
        'wmfeconf' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => 'wmfeconf',
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'root' => 'geohub/conf',
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
        ],
        'wmfepois' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => 'wmfepois',
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'root' => 'geohub/pois',
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
        ],
        'wmfetracks' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => 'wmfetracks',
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'root' => 'geohub/tracks',
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
        ],
        's3-osfmedia' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => 'osfmedia',
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
        ],
        's3-wmpbf' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => 'wmpbf',
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
        ],
    ],

];
