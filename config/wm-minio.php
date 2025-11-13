<?php

return [
    /*
    |--------------------------------------------------------------------------
    | MinIO Configuration
    |--------------------------------------------------------------------------
    |
    | Configurazione per MinIO, storage S3-compatible per sviluppo locale
    | e produzione. MinIO può essere configurato tramite variabili d'ambiente.
    |
    */

    // Endpoint interno (per comunicazione tra container Docker)
    // Formato: http://minio-{APP_NAME}:9000
    'internal_endpoint' => env('MINIO_INTERNAL_ENDPOINT')
        ?: (env('APP_NAME')
            ? 'http://minio-'.env('APP_NAME').':9000'
            : 'http://minio-osm2cai2:9000'),

    // Endpoint esterno (per accesso dall'host)
    'external_endpoint' => env('MINIO_EXTERNAL_ENDPOINT', env('AWS_ENDPOINT', 'http://localhost:9002')),

    // Endpoint da usare (default: interno se in Docker, altrimenti esterno)
    // Se AWS_ENDPOINT è definito, usalo, altrimenti usa l'endpoint interno
    'endpoint' => env('MINIO_ENDPOINT', env('AWS_ENDPOINT'))
        ?: (env('APP_NAME')
            ? 'http://minio-'.env('APP_NAME').':9000'
            : 'http://minio-osm2cai2:9000'),

    // Credenziali
    'root_user' => env('MINIO_ROOT_USER', env('AWS_ACCESS_KEY_ID', 'minioadmin')),
    'root_password' => env('MINIO_ROOT_PASSWORD', env('AWS_SECRET_ACCESS_KEY', 'minioadmin')),

    // Porte
    'api_port' => env('MINIO_API_PORT', env('FORWARD_MINIO_PORT', 9000)),
    'console_port' => env('MINIO_CONSOLE_PORT', env('FORWARD_MINIO_CONSOLE_PORT', 8900)),

    // Console URL (per accesso dall'host)
    'console_url' => env('MINIO_CONSOLE_URL')
        ?: 'http://localhost:'.(env('FORWARD_MINIO_CONSOLE_PORT', 9003)),

    // Console base path (per reverse proxy)
    'console_base_href' => env('MINIO_CONSOLE_BASE_HREF', '/minio'),

    // Bucket predefinito
    'default_bucket' => env('MINIO_DEFAULT_BUCKET', env('AWS_BUCKET', 'osm2cai2-bucket')),

    // Configurazione S3-compatible
    's3' => [
        'key' => env('AWS_ACCESS_KEY_ID', env('MINIO_ROOT_USER', 'minioadmin')),
        'secret' => env('AWS_SECRET_ACCESS_KEY', env('MINIO_ROOT_PASSWORD', 'minioadmin')),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
        'bucket' => env('AWS_BUCKET', env('MINIO_DEFAULT_BUCKET', 'osm2cai2-bucket')),
        'url' => env('AWS_URL', env('MINIO_EXTERNAL_ENDPOINT', 'http://localhost:9002')),
        'endpoint' => env('AWS_ENDPOINT')
            ?: (env('APP_NAME')
                ? 'http://minio-'.env('APP_NAME').':9000'
                : 'http://minio-osm2cai2:9000'),
        'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', true), // IMPORTANTE per MinIO
        'visibility' => env('MINIO_VISIBILITY', 'public'),
    ],

    // Health check configuration
    'healthcheck' => [
        'enabled' => env('MINIO_HEALTHCHECK_ENABLED', true),
        'timeout' => env('MINIO_HEALTHCHECK_TIMEOUT', 5),
        'retries' => env('MINIO_HEALTHCHECK_RETRIES', 3),
    ],
];
