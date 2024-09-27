<?php

/**
 * This configuration file defines the models that will be exported in CSV along with the fields they contain. Feel free to add more models here.
 */
return [
    'models' => [
        'UgcPoi' => [
            'label' => 'Poles',
            'fields' => ['id', 'name', 'geom', 'updated_at'],
        ],
        'UgcTrack' => [
            'label' => 'UGC Track',
            'fields' => ['id', 'name', 'geometry', 'created_at', 'updated_at'],
        ],
        // Add other models as needed
    ],
];
