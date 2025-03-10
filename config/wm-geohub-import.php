<?php

use Wm\WmPackage\Jobs\Import\ImportAppJob;
use Wm\WmPackage\Jobs\Import\ImportEcPoiJob;
use Wm\WmPackage\Jobs\Import\ImportEcMediaJob;
use Wm\WmPackage\Jobs\Import\ImportEcTrackJob;

return [
    /*
    |--------------------------------------------------------------------------
    | Geohub Import Configuration
    |--------------------------------------------------------------------------
    |
    | This file contains all configurations related to Geohub import functionality,
    | including database connections, queue settings, logging, and import mappings.
    |
    */

    // Database connection for geohub
    'db_connection' => env('GEOHUB_DB_CONNECTION', 'geohub'),

    // Queue for import jobs
    'import_queue' => env('GEOHUB_IMPORT_QUEUE', 'geohub-import'),

    // Log channel for import
    'import_log_channel' => env('GEOHUB_IMPORT_LOG_CHANNEL', 'wm-package-failed-jobs'),

    /*
    |--------------------------------------------------------------------------
    | Database Configuration
    |--------------------------------------------------------------------------
    |
    | Database connections for WmPackage Geohub import
    | When integrated with a Laravel application, these connections will be merged
    | with the application's database configuration.
    |
    */
    'connections' => [
        'geohub' => [
            'driver' => 'pgsql',
            'host' => env('GEOHUB_DB_HOST'),
            'port' => env('GEOHUB_DB_PORT', '5432'),
            'database' => env('GEOHUB_DB_DATABASE'),
            'username' => env('GEOHUB_DB_USERNAME'),
            'password' => env('GEOHUB_DB_PASSWORD'),
            'charset' => 'utf8',
            'prefix' => '',
            'schema' => 'public',
            'sslmode' => 'prefer',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging Configuration
    |--------------------------------------------------------------------------
    |
    | Logging channels for WmPackage.
    | When integrated with a Laravel application, these channels will be merged
    | with the application's logging configuration.
    |
    */
    'logging' => [
        'channels' => [
            // Failed job specific channel
            'wm-package-failed-jobs' => [
                'driver' => 'daily',
                'path' => storage_path('logs/wm-package/failed-jobs.log'),
                'days' => 30,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure the connection options for the Geohub import queue.
    | This queue is used for all jobs related to importing data from Geohub.
    |
    */
    'queue' => [
        'geohub-import' => [
            'connection' => env('GEOHUB_IMPORT_CONNECTION', 'redis'),
            'queue' => env('GEOHUB_IMPORT_QUEUE', 'geohub-import'),
            'retry_after' => (int) env('GEOHUB_IMPORT_RETRY_AFTER', 90),
            'block_for' => null,
            'after_commit' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Horizon Queue Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Horizon queue workers specifically for Geohub imports.
    | These settings will be merged with the application's Horizon configuration.
    |
    */
    'horizon' => [
        'environments' => [
            'production' => [
                'geohub-import-supervisor' => [
                    'connection' => 'redis',
                    'queue' => ['geohub-import'],
                    'balance' => 'auto',
                    'autoScalingStrategy' => 'time',
                    'maxProcesses' => 10,
                    'maxTime' => 0,
                    'maxJobs' => 0,
                    'memory' => 128,
                    'tries' => 3,
                    'timeout' => 600,
                    'nice' => 0,
                ],
            ],
            'local' => [
                'geohub-import-supervisor' => [
                    'connection' => 'redis',
                    'queue' => ['geohub-import'],
                    'balance' => 'auto',
                    'autoScalingStrategy' => 'time',
                    'maxProcesses' => 3,
                    'maxTime' => 0,
                    'maxJobs' => 0,
                    'memory' => 128,
                    'tries' => 3,
                    'timeout' => 600,
                    'nice' => 0,
                ],
            ],
        ],
    ],

    // Models to use for import
    'import_models' => [
        'app' => ['namespace' => 'Wm\\WmPackage\\Models\\App', 'job' => ImportAppJob::class],
        'ec_media' => ['namespace' => 'Wm\\WmPackage\\Models\\EcMedia', 'job' => ImportEcMediaJob::class],
        'ec_track' => ['namespace' => 'Wm\\WmPackage\\Models\\EcTrack', 'job' => ImportEcTrackJob::class],
        'ec_poi' => ['namespace' => 'Wm\\WmPackage\\Models\\EcPoi', 'job' => ImportEcPoiJob::class],
    ],

    // Media import configuration
    'import_media' => [
        'source_disk' => env('GEOHUB_IMPORT_MEDIA_SOURCE_DISK', 'geohub_media'),
        'target_disk' => env('GEOHUB_IMPORT_MEDIA_TARGET_DISK', 'public'),
        'target_path' => env('GEOHUB_IMPORT_MEDIA_TARGET_PATH', 'media'),
        'collection' => env('GEOHUB_IMPORT_MEDIA_COLLECTION', 'default'),
    ],

    // Import mapping configuration
    'import_mapping' => [
        // App mapping
        'app' => [
            'identifiers' => ['name', 'customer_name', 'sku'],
            'fields' => [
                'name' => 'name',
                'description' => 'description',
                'user_id' => 'user_id',
                'sku' => [
                    'field' => 'sku',
                    'transformer' => ['Wm\\WmPackage\\Services\\Import\\DataTransformer', 'jsonToArray'],
                ],
                'created_at' => 'created_at',
                'updated_at' => 'updated_at',
            ],
            'properties' => [
                'api_url' => 'api_url',
                'fb_pixel_id' => 'fb_pixel_id',
                'ga_tracker_id' => 'ga_tracker_id',
            ],
        ],

        // EcMedia mapping
        'ec_media' => [
            'identifiers' => ['properties->geohub_id'],
            'fields' => [
                'name' => 'name',
                'description' => 'description',
                'app_id' => 'app_id',
                'user_id' => 'user_id',
                'file_path' => 'file_path',
                'created_at' => 'created_at',
                'updated_at' => 'updated_at',
            ],
            'properties' => [
                'caption' => 'caption',
                'type' => 'type',
            ],
            'geometry' => true,
        ],

        // EcTrack mapping
        'ec_track' => [
            'identifiers' => ['properties->geohub_id'],
            'fields' => [
                'name' => 'name',
                'description' => 'description',
                'app_id' => 'app_id',
                'user_id' => 'user_id',
                'created_at' => 'created_at',
                'updated_at' => 'updated_at',
            ],
            'properties' => [
                'ele_from' => 'ele_from',
                'ele_to' => 'ele_to',
                'ele_min' => 'ele_min',
                'ele_max' => 'ele_max',
                'distance' => 'distance',
                'duration_forward' => 'duration_forward',
                'duration_backward' => 'duration_backward',
                'ascent' => 'ascent',
                'descent' => 'descent',
                'difficulty' => 'difficulty',
                'mbtiles' => 'mbtiles',
            ],
            'geometry' => true,
        ],

        // EcPoi mapping
        'ec_poi' => [
            'identifiers' => ['properties->geohub_id'],
            'fields' => [
                'name' => 'name',
                'description' => 'description',
                'app_id' => 'app_id',
                'user_id' => 'user_id',
                'created_at' => 'created_at',
                'updated_at' => 'updated_at',
            ],
            'properties' => [
                'ele' => 'ele',
                'addr_complete' => 'addr_complete',
                'addr_street' => 'addr_street',
                'addr_housenumber' => 'addr_housenumber',
                'addr_postcode' => 'addr_postcode',
                'addr_locality' => 'addr_locality',
                'contact_phone' => 'contact_phone',
                'contact_email' => 'contact_email',
                'contact_website' => 'contact_website',
                'opening_hours' => 'opening_hours',
                'capacity' => 'capacity',
                'stars' => 'stars',
                'type' => 'type',
            ],
            'geometry' => true,
        ],
    ],
];
