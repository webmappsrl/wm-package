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
        // General package exceptions channel
        'package_exceptions' => [
            'driver' => 'daily',
            'path' => storage_path('logs/wm-package/exceptions.log'),
            'level' => env('WM_PACKAGE_LOG_LEVEL', 'error'),
            'days' => 14,
        ],

        // Geohub import specific channel
        'geohub-import' => [
            'driver' => 'daily',
            'path' => storage_path('logs/wm-package/geohub-import.log'),
            'level' => env('GEOHUB_IMPORT_LOG_LEVEL', 'debug'),
            'days' => 30,
        ],

        // You can add more specific channels here as needed
    ],
];
