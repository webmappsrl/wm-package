<?php

// config for Wm/WmPackage
return [
    'services' => [
        'geometry_computation' => [
            'neighbours_distance' => env('WM_NEIGHBOURS_DISTANCE', 500),
        ],
        'dem' => [
            'host' => env('WM_DEM_HOST', 'https://dem.maphub.it'),
            'ele_api' => env('WM_DEM_ELE_API', '/api/v1/elevation'),
            'tech_data_api' => env('WM_DEM_TECH_DATA_API', '/api/v1/track'),
            '3d_data_api' => env('WM_DEM_3D_DATA_API', '/api/v1/track3d'),
        ],
        'nodejs' => [
            'executable' => env('WM_NODE_EXECUTABLE', '/usr/bin/node'),
        ],
    ],
    'version' => '1.3.0', // x-release-please-version
];
