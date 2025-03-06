<?php

return [
    /*
    |--------------------------------------------------------------------------
    | WmPackage Logging Configuration
    |--------------------------------------------------------------------------
    |
    | This file configures the logging channels for WmPackage.
    | When integrated with a Laravel application, these channels will be merged
    | with the application's logging configuration.
    |
    */

    'channels' => [
        // Failed job specific channel
        'failed_jobs' => [
            'driver' => 'daily',
            'path' => storage_path('logs/wm-package/failed-jobs.log'),
            'level' => env('FAILED_JOBS_LOG_LEVEL', 'error'),
            'days' => 30,
        ]

        // You can add more specific channels here as needed
    ],
];
