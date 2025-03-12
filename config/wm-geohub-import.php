<?php

use Wm\WmPackage\Jobs\Import\ImportAppJob;
use Wm\WmPackage\Jobs\Import\ImportEcMediaJob;
use Wm\WmPackage\Jobs\Import\ImportEcPoiJob;
use Wm\WmPackage\Jobs\Import\ImportEcTrackJob;
use Wm\WmPackage\Jobs\Import\ImportLayerJob;
use Wm\WmPackage\Services\Import\DataTransformer;

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
            'connection' => 'redis',
            'queue' => 'geohub-import',
            'retry_after' => 90,
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
                    'tries' => 1,
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
                    'tries' => 1,
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
        'layer' => ['namespace' => 'Wm\\WmPackage\\Models\\Layer', 'job' => ImportLayerJob::class],
    ],

    // Media import configuration
    'import_media' => [
        'source_disk' => env('geohub_media'),
        'target_disk' => env('public'),
        'target_path' => env('media'),
        'collection' => env('default'),
    ],

    // Import mapping configuration
    'import_mapping' => [
        // App mapping
        'app' => [
            'identifiers' => ['properties->geohub_id'],
            'fields' => [
                'name' => 'name',
                'description' => 'description',
                'user_id' => 'user_id',
                'sku' => 'sku',
                'created_at' => 'created_at',
                'updated_at' => 'updated_at',
            ],
        ],
        'layer' => [
            'identifiers' => ['properties->geohub_id'],
            'fields' => [
                'name' => 'name',
                'geometry' => 'geometry',
                'app_id' => 'app_id',
                'rank' => 'rank',
                'created_at' => 'created_at',
                'updated_at' => 'updated_at',
            ],
            'properties' => [
                'title' => ['field' => 'title', 'transformer' => [DataTransformer::class, 'jsonToArray']],
                'subtitle' => ['field' => 'subtitle', 'transformer' => [DataTransformer::class, 'jsonToArray']],
                'description' => ['field' => 'description', 'transformer' => [DataTransformer::class, 'jsonToArray']],
                'color' => 'color',
                'rank' => 'rank',
                'bbox' => 'bbox',
                'generate_edges' => 'generate_edges',
            ],
        ],

        // EcMedia mapping
        'ec_media' => [
            'identifiers' => ['properties->geohub_id'],
            'fields' => [
                'name' => ['field' => 'name', 'transformer' => [DataTransformer::class, 'jsonToArray']],
                'geometry' => 'geometry',
                'created_at' => 'created_at',
                'updated_at' => 'updated_at',
            ],
            'properties' => [
                'description' => ['field' => 'description', 'transformer' => [DataTransformer::class, 'jsonToArray']],
                'excerpt' => ['field' => 'excerpt', 'transformer' => [DataTransformer::class, 'jsonToArray']],
                'source' => 'source',
                'out_source_feature_id' => 'out_source_feature_id',
                'rank' => 'rank',
            ],
        ],

        // EcTrack mapping
        'ec_track' => [
            'identifiers' => ['properties->geohub_id'],
            'fields' => [
                'name' => ['field' => 'name', 'transformer' => [DataTransformer::class, 'jsonToArray']],
                'app_id' => 'app_id',
                'geometry' => 'geometry',
                'osmid' => 'osmid',
                'created_at' => 'created_at',
                'updated_at' => 'updated_at',
            ],
            'properties' => [
                'description' => ['field' => 'description', 'transformer' => [DataTransformer::class, 'jsonToArray']],
                'excerpt' => ['field' => 'excerpt', 'transformer' => [DataTransformer::class, 'jsonToArray']],
                'audio' => ['field' => 'audio', 'transformer' => [DataTransformer::class, 'jsonToArray']],
                'source_id' => 'source_id',
                'import_method' => 'import_method',
                'source' => 'source',
                'distance_comp' => 'distance_comp',
                'ele_from' => 'ele_from',
                'ele_to' => 'ele_to',
                'ele_min' => 'ele_min',
                'ele_max' => 'ele_max',
                'distance' => 'distance',
                'duration_forward' => 'duration_forward',
                'duration_backward' => 'duration_backward',
                'ascent' => 'ascent',
                'descent' => 'descent',
                'difficulty' => ['field' => 'difficulty', 'transformer' => [DataTransformer::class, 'jsonToArray']],
                'slope' => 'slope',
                'mbtiles' => 'mbtiles',
                'elevation_chart_image' => 'elevation_chart_image',
                'out_source_feature_id' => 'out_source_feature_id',
                'from' => 'from',
                'ref' => 'ref',
                'to' => 'to',
                'cai_scale' => 'cai_scale',
                'related_url' => 'related_url',
                'not_accessible' => 'not_accessible',
                'not_accessible_message' => 'not_accessible_message',
                'skip_geomixer_tech' => 'skip_geomixer_tech',
                'taxonomy_wheres_show_first' => 'taxonomy_wheres_show_first',
                'allow_print_pdf' => 'allow_print_pdf',
                'color' => 'color',
                'difficulty_i18n' => 'difficulty_i18n',
                'layers' => 'layers',
                'themes' => 'themes',
                'activities' => 'activities',
                'searchable' => 'searchable',
                'dem_data' => 'dem_data',
                'osm_data' => 'osm_data',
                'manual_data' => 'manual_data',
            ],
        ],

        // EcPoi mapping
        'ec_poi' => [
            'identifiers' => ['properties->geohub_id'],
            'fields' => [
                'name' => ['field' => 'name', 'transformer' => [DataTransformer::class, 'jsonToArray']],
                'app_id' => 'app_id',
                'created_at' => 'created_at',
                'updated_at' => 'updated_at',
                'geometry' => 'geometry',
                'osmid' => 'osmid',
            ],
            'properties' => [
                'description' => ['field' => 'description', 'transformer' => [DataTransformer::class, 'jsonToArray']],
                'excerpt' => ['field' => 'excerpt', 'transformer' => [DataTransformer::class, 'jsonToArray']],
                'audio' => ['field' => 'audio', 'transformer' => [DataTransformer::class, 'jsonToArray']],
                'related_url' => 'related_url',
                'ele' => 'ele',
                'addr_complete' => 'addr_complete',
                'addr_street' => 'addr_street',
                'addr_housenumber' => 'addr_housenumber',
                'addr_postcode' => 'addr_postcode',
                'addr_locality' => 'addr_locality',
                'out_source_feature_id' => 'out_source_feature_id',
                'contact_phone' => 'contact_phone',
                'contact_email' => 'contact_email',
                'opening_hours' => 'opening_hours',
                'capacity' => 'capacity',
                'stars' => 'stars',
                'type' => 'type',
                'reachability_by_public_transportation_description' => 'reachability_by_public_transportation_description',
                'reachability_by_public_transportation_check' => 'reachability_by_public_transportation_check',
                'reachability_by_car_description' => 'reachability_by_car_description',
                'reachability_by_car_check' => 'reachability_by_car_check',
                'reachability_on_foot_description' => 'reachability_on_foot_description',
                'reachability_on_foot_check' => 'reachability_on_foot_check',
                'reachability_by_bike_description' => 'reachability_by_bicycle_description',
                'reachability_by_bike_check' => 'reachability_by_bicycle_check',
                'access_food_description' => 'access_food_description',
                'access_food_check' => 'access_food_check',
                'access_cognitive_description' => 'access_cognitive_description',
                'access_cognitive_check' => 'access_cognitive_check',
                'access_cognitive_level' => 'access_cognitive_level',
                'access_vision_description' => 'access_vision_description',
                'access_vision_check' => 'access_vision_check',
                'access_vision_level' => 'access_vision_level',
                'access_hearing_description' => 'access_hearing_description',
                'access_hearing_check' => 'access_hearing_check',
                'access_hearing_level' => 'access_hearing_level',
                'access_mobility_description' => 'access_mobility_description',
                'access_mobility_check' => 'access_mobility_check',
                'access_mobility_level' => 'access_mobility_level',
                'accessibility_pdf' => 'accessibility_pdf',
                'accessibility_validity_date' => 'accessibility_validity_date',
                'noInteraction' => 'noInteraction',
                'noDetails' => 'noDetails',
                'code' => 'code',
                'icon' => 'icon',
                'color' => 'color',
            ],
        ],
    ],
];
