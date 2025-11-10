<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Global File Upload Configuration
    |--------------------------------------------------------------------------
    |
    | Configurazione per il sistema di upload file globali
    |
    */

    // Nome del bucket AWS per i file
    'bucket' => env('WM_BUCKET', 'wmfe'),

    // Cartella base per i file JSON (all'interno dello shard)
    'json_folder' => 'json',

    // Tipi di file supportati con nomi predefiniti
    'file_types' => [
        'icons' => [
            'filename' => 'icons.json',
            'route_prefix' => 'icons-upload',
        ],
    ],

    // Middleware da applicare alle routes
    'middleware' => ['auth', 'nova'],

    // Dimensione massima file (in KB)
    'max_file_size' => 10240, // 10MB

    // Estensioni file permesse
    'allowed_extensions' => ['json'],

    // Cache duration per i file (in secondi)
    'cache_duration' => 3600, // 1 ora
];
