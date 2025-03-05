<?php

return [

    /*
    |--------------------------------------------------------------------------
    | WmPackage Database Configuration
    |--------------------------------------------------------------------------
    |
    | This file configures the database connections for WmPackage.
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
];
