<?php

// config for Wm/WmPackage
return [
    'service' => [
        'geometry_computation' => [
            'neighbours_distance' => env('WM_NEIGHBOURS_DISTANCE', 500)
        ]
    ],
    'version' => '1.3.0', //x-release-please-version
];
