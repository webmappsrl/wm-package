<?php

// config for Wm/WmPackage
return [
    'version' => '1.3.0', // x-release-please-version
    'shard_name' => env('SHARD_NAME', env('APP_NAME')),
    'services' => [
        'geometry_computation' => [
            'neighbours_distance' => env('WM_NEIGHBOURS_DISTANCE', 500),
        ],
        'nodejs' => [
            'executable' => env('WM_NODE_EXECUTABLE', '/usr/bin/node'),
        ],
        'image' => [
            'thumbnail_sizes' => [
                ['width' => 108, 'height' => 148],
                ['width' => 108, 'height' => 137],
                ['width' => 150, 'height' => 150],
                ['width' => 225, 'height' => 100],
                ['width' => 118, 'height' => 138],
                ['width' => 108, 'height' => 139],
                ['width' => 118, 'height' => 117],
                ['width' => 335, 'height' => 250],
                ['width' => 400, 'height' => 200],
                ['width' => 1440, 'height' => 500],
                ['width' => 1920, 'height' => 1080],
                ['width' => 250, 'height' => 150],
            ],
        ],
        'pbf' => [
            'min_zoom' => env('PBF_MIN_ZOOM', 5),
            'max_zoom' => env('PBF_MAX_ZOOM', 13),
            'zoom_treshold' => env('PBF_ZOOM_TRESHOLD', 6),
        ],
    ],
    'clients' => [
        'dem' => [
            'host' => env('DEM_HOST', 'https://dem.maphub.it'),
            'ele_api' => env('DEM_ELE_API', 'api/v1/elevation'),
            'tech_data_api' => env('DEM_TECH_DATA_API', 'api/v1/track'),
            '3d_data_api' => env('DEM_3D_DATA_API', 'api/v1/track3d'),
        ],
        'cai' => [
            'basic_auth_user' => env('CAI_BASIC_AUTH_USER'),
            'basic_auth_password' => env('CAI_BASIC_AUTH_PASSWORD'),
        ],
        'osmfeatures' => [
            'host' => env('OSMFEATURES_HOST', 'https://osmfeatures.maphub.it'),
        ],
        'geohub' => [
            'host' => env('GEOHUB_HOST', 'https://geohub.webmapp.it'),
        ],
    ],
    'ec_track_table' => env('EC_TRACK_TABLE', 'ec_tracks'),
    'ec_track_model' => env('EC_TRACK_MODEL', 'App\Models\EcTrack'),
];
