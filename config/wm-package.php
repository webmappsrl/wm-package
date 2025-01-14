<?php

// config for Wm/WmPackage
return [
    'version' => '1.2.6', //x-release-please-version
    'service' => [
        'geometry_computation' => [
            'neighbours_distance' => env('WM_NEIGHBOURS_DISTANCE', 500)
        ]
    ]
];
