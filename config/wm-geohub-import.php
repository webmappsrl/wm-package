<?php

use Wm\WmPackage\Jobs\Import\ImportAppJob;
use Wm\WmPackage\Jobs\Import\ImportEcMediaJob;
use Wm\WmPackage\Jobs\Import\ImportEcPoiJob;
use Wm\WmPackage\Jobs\Import\ImportEcTrackJob;
use Wm\WmPackage\Jobs\Import\ImportLayerJob;
use Wm\WmPackage\Jobs\Import\ImportTaxonomyActivityJob;
use Wm\WmPackage\Services\GeometryComputationService;
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
                    'maxProcesses' => 5,
                    'maxTime' => 0,
                    'maxJobs' => 0,
                    'memory' => 256,
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
                    'maxProcesses' => 10, // Aumentato da 3 a 10
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

    /*
    |--------------------------------------------------------------------------
    | Default Dependencies Configuration
    |--------------------------------------------------------------------------
    |
    | Configure which dependencies should be imported by default for each model.
    | This serves as a fallback when no specific dependencies are specified.
    |
    | Available dependencies for 'app' model:
    | - 'ec_poi': Import POI data
    | - 'ec_track': Import track data
    | - 'taxonomy_activity': Import taxonomy activity data
    | - 'layer': Import layer data
    | - 'ec_media': Import media data
    |
    | Examples:
    | 'default_dependencies' => [
    |     'app' => ['taxonomy_activity', 'layer'], // Import only these by default
    |     'app' => [], // Import no dependencies by default
    | ],
    |
    */
    'default_dependencies' => [
        'app' => ['ec_poi', 'ec_track', 'taxonomy_activity', 'layer', 'ec_media'], // Import all dependencies by default
    ],

    /*
    |--------------------------------------------------------------------------
    | Import Mapping Configuration
    |--------------------------------------------------------------------------
    |
    | This section defines how entities from Geohub are mapped to local models.
    | Each entity type has its own configuration including namespace, job class,
    | source table, and field mappings.
    |
    */
    'import_mapping' => [
        /*
        |----------------------------------------------------------------------
        | App Entity Mapping
        |----------------------------------------------------------------------
        */
        'app' => [
            'namespace' => 'Wm\\WmPackage\\Models\\App',
            'job' => ImportAppJob::class,
            'geohub_table' => 'apps',
            'identifier' => 'properties->geohub_id',
            'fields' => [
                'name' => 'name',
                'description' => 'description',
                'user_id' => 'user_id',
                'sku' => 'sku',
                'created_at' => 'created_at',
                'updated_at' => 'updated_at',
            ],
            'properties' => [],
            'relations' => [
                'layer' => [
                    'foreign_key' => 'app_id',
                    'model' => 'Wm\\WmPackage\\Models\\Layer',
                ],
                'ec_pois' => [
                    'foreign_key' => 'user_id',
                    'model' => 'Wm\\WmPackage\\Models\\EcPoi',
                ],
                'ec_tracks' => [
                    'foreign_key' => 'user_id',
                    'model' => 'Wm\\WmPackage\\Models\\EcTrack',
                ],
                'ec_media' => [
                    'foreign_key' => 'user_id',
                    'model' => 'Wm\\WmPackage\\Models\\EcMedia',
                ],
            ],
        ],

        /*
        |----------------------------------------------------------------------
        | Layer Entity Mapping
        |----------------------------------------------------------------------
        */
        'layer' => [
            'namespace' => 'Wm\\WmPackage\\Models\\Layer',
            'job' => ImportLayerJob::class,
            'geohub_table' => 'layers',
            'identifier' => 'properties->geohub_id',
            'fields' => [
                'name' => 'name',
                'geometry' => ['field' => 'bbox', 'transformer' => [GeometryComputationService::class, 'bboxToPolygon']],
                'app_id' => 'app_id',
                'rank' => 'rank',
                'created_at' => 'created_at',
                'updated_at' => 'updated_at',
            ],
            'properties' => [
                'column_name' => 'properties',
                'mapping' => [
                    'title' => ['field' => 'title', 'transformer' => [DataTransformer::class, 'jsonToArray']],
                    'subtitle' => ['field' => 'subtitle', 'transformer' => [DataTransformer::class, 'jsonToArray']],
                    'description' => ['field' => 'description', 'transformer' => [DataTransformer::class, 'jsonToArray']],
                    'color' => 'color',
                    'rank' => 'rank',
                    'generate_edges' => 'generate_edges',
                ],
            ],
            'relations' => [
                'taxonomy_theme' => [
                    'pivot_table' => 'taxonomy_themeables',
                    'key' => 'taxonomy_theme_id',
                    'foreign_key' => 'taxonomy_themeable_id',
                    'morphable_type' => ['key' => 'taxonomy_themeable_type', 'value' => 'App\\Models\\Layer'],
                ],
                'taxonomy_activity' => [
                    'pivot_table' => 'taxonomy_activityables',
                    'key' => 'taxonomy_activity_id',
                    'foreign_key' => 'taxonomy_activityable_id',
                    'model' => 'Wm\\WmPackage\\Models\\TaxonomyActivity',
                    'morphable_type' => ['key' => 'taxonomy_activityable_type', 'value' => 'App\\Models\\Layer'],
                    'pivot_columns' => [
                        'duration_forward',
                        'duration_backward',
                    ],
                ],
                'overlay_layers' => [
                    'pivot_table' => 'layerables',
                    'key' => 'layer_id',
                    'foreign_key' => 'layerable_id',
                    'morphable_type' => ['key' => 'layerable_type', 'value' => 'App\\Models\\OverlayLayer'],
                ],
            ],
        ],

        /*
        |----------------------------------------------------------------------
        | Media Entity Mapping
        |----------------------------------------------------------------------
        */
        'ec_media' => [
            'namespace' => 'Wm\\WmPackage\\Models\\Media',
            'job' => ImportEcMediaJob::class,
            'geohub_table' => 'ec_media',
            'identifier' => 'custom_properties->geohub_id',
            'fields' => [
                'name' => ['field' => 'name', 'transformer' => [DataTransformer::class, 'jsonToArray']],
                'geometry' => 'geometry',
                'created_at' => 'created_at',
                'updated_at' => 'updated_at',
                'url' => 'url',
            ],
            'properties' => [
                'column_name' => 'custom_properties',
                'mapping' => [
                    'description' => ['field' => 'description', 'transformer' => [DataTransformer::class, 'jsonToArray']],
                    'excerpt' => ['field' => 'excerpt', 'transformer' => [DataTransformer::class, 'jsonToArray']],
                    'source' => 'source',
                    'out_source_feature_id' => 'out_source_feature_id',
                    'rank' => 'rank',
                ],
            ],
            'relations' => [
                'ec_pois' => [
                    'pivot_table' => 'ec_media_ec_poi',
                    'foreign_key' => 'ec_media_id',
                    'key' => 'ec_poi_id',
                    'model' => 'Wm\\WmPackage\\Models\\EcPoi',
                ],
                'ec_tracks' => [
                    'pivot_table' => 'ec_media_ec_track',
                    'foreign_key' => 'ec_media_id',
                    'key' => 'ec_track_id',
                    'model' => 'Wm\\WmPackage\\Models\\EcTrack',
                ],
                'layers' => [
                    'pivot_table' => 'ec_media_layer',
                    'foreign_key' => 'ec_media_id',
                    'key' => 'layer_id',
                    'model' => 'Wm\\WmPackage\\Models\\Layer',
                ],
            ],
        ],

        /*
        |----------------------------------------------------------------------
        | Track Entity Mapping
        |----------------------------------------------------------------------
        */
        'ec_track' => [
            'namespace' => 'Wm\\WmPackage\\Models\\EcTrack',
            'job' => ImportEcTrackJob::class,
            'geohub_table' => 'ec_tracks',
            'identifier' => 'properties->geohub_id',
            'fields' => [
                'name' => ['field' => 'name', 'transformer' => [DataTransformer::class, 'jsonToArray']],
                'geometry' => 'geometry',
                'osmid' => 'osmid',
                'created_at' => 'created_at',
                'updated_at' => 'updated_at',
            ],
            'properties' => [
                'column_name' => 'properties',
                'mapping' => [
                    'description' => ['field' => 'description', 'transformer' => [DataTransformer::class, 'jsonToArray']],
                    'excerpt' => ['field' => 'excerpt', 'transformer' => [DataTransformer::class, 'jsonToArray']],
                    'audio' => ['field' => 'audio', 'transformer' => [DataTransformer::class, 'nullableJsonToArray']],
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
                    'activities' => 'activities',
                    'searchable' => 'searchable',
                    'dem_data' => 'dem_data',
                    'osm_data' => 'osm_data',
                    'manual_data' => 'manual_data',
                ],
            ],
            'relations' => [
                'ec_pois' => [
                    'pivot_table' => 'ec_poi_ec_track',
                    'key' => 'ec_poi_id',
                    'foreign_key' => 'ec_track_id',
                    'model' => 'Wm\\WmPackage\\Models\\EcPoi',
                ],
                'ec_media' => [
                    'pivot_table' => 'ec_media_ec_track',
                    'key' => 'ec_track_id',
                    'foreign_key' => 'ec_media_id',
                    'model' => 'Wm\\WmPackage\\Models\\EcMedia',
                ],
                'layer' => [
                    'pivot_table' => 'ec_track_layer',
                    'key' => 'ec_track_id',
                    'foreign_key' => 'layer_id',
                    'model' => 'Wm\\WmPackage\\Models\\Layer',
                ],
            ],
        ],

        /*
        |----------------------------------------------------------------------
        | POI Entity Mapping
        |----------------------------------------------------------------------
        */
        'ec_poi' => [
            'namespace' => 'Wm\\WmPackage\\Models\\EcPoi',
            'job' => ImportEcPoiJob::class,
            'geohub_table' => 'ec_pois',
            'identifier' => 'properties->geohub_id',
            'fields' => [
                'name' => ['field' => 'name', 'transformer' => [DataTransformer::class, 'jsonToArray']],
                'created_at' => 'created_at',
                'updated_at' => 'updated_at',
                'geometry' => 'geometry',
                'osmid' => 'osmid',
            ],
            'properties' => [
                'column_name' => 'properties',
                'mapping' => [
                    'description' => ['field' => 'description', 'transformer' => [DataTransformer::class, 'jsonToArray']],
                    'excerpt' => ['field' => 'excerpt', 'transformer' => [DataTransformer::class, 'jsonToArray']],
                    'audio' => ['field' => 'audio', 'transformer' => [DataTransformer::class, 'nullableJsonToArray']],
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
            'relations' => [
                'ec_tracks' => [
                    'pivot_table' => 'ec_poi_ec_track',
                    'key' => 'ec_track_id',
                    'foreign_key' => 'ec_poi_id',
                    'model' => 'Wm\\WmPackage\\Models\\EcTrack',
                ],
                'ec_media' => [
                    'pivot_table' => 'ec_media_ec_poi',
                    'key' => 'ec_poi_id',
                    'foreign_key' => 'ec_media_id',
                    'model' => 'Wm\\WmPackage\\Models\\EcMedia',
                ],
            ],
        ],

        /*
        |----------------------------------------------------------------------
        | Taxonomy Activity Entity Mapping
        |----------------------------------------------------------------------
        */
        'taxonomy_activity' => [
            'namespace' => 'Wm\\WmPackage\\Models\\TaxonomyActivity',
            'job' => ImportTaxonomyActivityJob::class,
            'geohub_table' => 'taxonomy_activities',
            'identifier' => 'properties->geohub_id',
            'fields' => [
                'name' => ['field' => 'name', 'transformer' => [DataTransformer::class, 'jsonToArray']],
                'description' => ['field' => 'description', 'transformer' => [DataTransformer::class, 'jsonToArray']],
                'excerpt' => ['field' => 'excerpt', 'transformer' => [DataTransformer::class, 'jsonToArray']],
                'identifier' => 'properties->geohub_id',
                'created_at' => 'created_at',
                'updated_at' => 'updated_at',
                'icon' => ['field' => 'icon', 'transformer' => [DataTransformer::class, 'svgIconToNameIcon']],
            ],
            'properties' => [
                'column_name' => 'properties',
                'mapping' => [],
            ],
            'relations' => [
                'morphable_table' => 'taxonomy_activityables',
                'foreign_key' => 'taxonomy_activity_id',
                'morphable_id' => 'taxonomy_activityable_id',
                'morphable_type' => 'taxonomy_activityable_type',
                'morphable_models' => [
                    'ec_poi' => 'Wm\\WmPackage\\Models\\EcPoi',
                    'ec_track' => 'Wm\\WmPackage\\Models\\EcTrack',
                    'media' => 'Wm\\WmPackage\\Models\\Media',
                    'layer' => 'Wm\\WmPackage\\Models\\Layer',
                ],
                'pivot_columns' => [
                    'duration_forward',
                    'duration_backward',
                ],
            ],
        ],

        /*
        |----------------------------------------------------------------------
        | Taxonomy POI Type Entity Mapping
        |----------------------------------------------------------------------
        */
        'taxonomy_poi_type' => [
            'namespace' => 'Wm\\WmPackage\\Models\\TaxonomyPoiType',
            'job' => ImportTaxonomyPoiTypeJob::class,
            'geohub_table' => 'taxonomy_poi_types',
            'identifier' => 'properties->geohub_id',
            'fields' => [
                'name' => ['field' => 'name', 'transformer' => [DataTransformer::class, 'jsonToArray']],
                'description' => ['field' => 'description', 'transformer' => [DataTransformer::class, 'jsonToArray']],
                'excerpt' => ['field' => 'excerpt', 'transformer' => [DataTransformer::class, 'jsonToArray']],
                'identifier' => 'properties->geohub_id',
                'created_at' => 'created_at',
                'updated_at' => 'updated_at',
            ],
            'properties' => [],
            'relations' => [
                'morphable_table' => 'taxonomy_poi_typeables',
                'foreign_key' => 'taxonomy_poi_type_id',
                'morphable_id' => 'taxonomy_poi_typeable_id',
                'morphable_type' => 'taxonomy_poi_typeable_type',
                'morphable_models' => [
                    'ec_poi' => 'Wm\\WmPackage\\Models\\EcPoi',
                    'ec_track' => 'Wm\\WmPackage\\Models\\EcTrack',
                    'ec_media' => 'Wm\\WmPackage\\Models\\EcMedia',
                    'layer' => 'Wm\\WmPackage\\Models\\Layer',
                ],
            ],
        ],

        /*
        |----------------------------------------------------------------------
        | Taxonomy Target Entity Mapping
        |----------------------------------------------------------------------
        */
        'taxonomy_target' => [
            'namespace' => 'Wm\\WmPackage\\Models\\TaxonomyTarget',
            'job' => '',
            'geohub_table' => 'taxonomy_targets',
            'identifier' => 'properties->geohub_id',
            'fields' => [
                'name' => ['field' => 'name', 'transformer' => [DataTransformer::class, 'jsonToArray']],
                'description' => ['field' => 'description', 'transformer' => [DataTransformer::class, 'jsonToArray']],
                'excerpt' => ['field' => 'excerpt', 'transformer' => [DataTransformer::class, 'jsonToArray']],
                'identifier' => 'properties->geohub_id',
                'created_at' => 'created_at',
                'updated_at' => 'updated_at',
            ],
            'properties' => [],
            'relations' => [
                'morphable_table' => 'taxonomy_targetables',
                'foreign_key' => 'taxonomy_target_id',
                'morphable_id' => 'taxonomy_targetable_id',
                'morphable_type' => 'taxonomy_targetable_type',
                'morphable_models' => [
                    'ec_poi' => 'Wm\\WmPackage\\Models\\EcPoi',
                    'ec_track' => 'Wm\\WmPackage\\Models\\EcTrack',
                    'ec_media' => 'Wm\\WmPackage\\Models\\EcMedia',
                    'layer' => 'Wm\\WmPackage\\Models\\Layer',
                ],
            ],
        ],

        /*
        |----------------------------------------------------------------------
        | Taxonomy When Entity Mapping
        |----------------------------------------------------------------------
        */
        'taxonomy_when' => [
            'namespace' => 'Wm\\WmPackage\\Models\\TaxonomyWhen',
            'job' => '',
            'geohub_table' => 'taxonomy_whens',
            'identifier' => 'properties->geohub_id',
            'fields' => [
                'name' => ['field' => 'name', 'transformer' => [DataTransformer::class, 'jsonToArray']],
                'description' => ['field' => 'description', 'transformer' => [DataTransformer::class, 'jsonToArray']],
                'excerpt' => ['field' => 'excerpt', 'transformer' => [DataTransformer::class, 'jsonToArray']],
                'identifier' => 'properties->geohub_id',
                'created_at' => 'created_at',
                'updated_at' => 'updated_at',
            ],
            'properties' => [],
            'relations' => [
                'morphable_table' => 'taxonomy_whenables',
                'foreign_key' => 'taxonomy_when_id',
                'morphable_id' => 'taxonomy_whenable_id',
                'morphable_type' => 'taxonomy_whenable_type',
                'morphable_models' => [
                    'ec_poi' => 'Wm\\WmPackage\\Models\\EcPoi',
                    'ec_track' => 'Wm\\WmPackage\\Models\\EcTrack',
                    'ec_media' => 'Wm\\WmPackage\\Models\\EcMedia',
                    'layer' => 'Wm\\WmPackage\\Models\\Layer',
                ],
            ],
        ],

        /*
        |----------------------------------------------------------------------
        | Taxonomy Theme Entity Mapping
        |----------------------------------------------------------------------
        */
        'taxonomy_theme' => [
            'namespace' => 'Wm\\WmPackage\\Models\\TaxonomyTheme',
            'job' => '',
            'geohub_table' => 'taxonomy_themes',
            'identifier' => 'properties->geohub_id',
            'fields' => [],
            'properties' => [],
            'relations' => [
                'morphable_table' => 'taxonomy_themeables',
                'foreign_key' => 'taxonomy_theme_id',
                'morphable_id' => 'taxonomy_themeable_id',
                'morphable_type' => 'taxonomy_themeable_type',
                'morphable_models' => [
                    'ec_poi' => 'Wm\\WmPackage\\Models\\EcPoi',
                    'ec_track' => 'Wm\\WmPackage\\Models\\EcTrack',
                    'media' => 'Wm\\WmPackage\\Models\\Media',
                    'layer' => 'Wm\\WmPackage\\Models\\Layer',
                ],
            ],
        ],
    ],
];
