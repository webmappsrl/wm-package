<?php

return [
    'indices' => [
        'mappings' => [
            'default' => [
                'properties' => [
                    'id' => [
                        'type' => 'keyword',
                    ],
                    'name' => [
                        'type' => 'text',
                        'fields' => [
                            'keyword' => [
                                'type' => 'keyword',
                            ],
                        ],
                        'analyzer' => 'standard',
                    ],
                    'start' => [
                        'type' => 'geo_point',
                    ],
                    'end' => [
                        'type' => 'geo_point',
                    ],
                    'taxonomyActivities' => [
                        'type' => 'keyword',
                    ],
                    'taxonomyWheres' => [
                        'type' => 'keyword',
                    ],
                    'layers' => [
                        'type' => 'keyword',
                    ],
                ],
            ],
        ],
        'settings' => [
            'default' => [
                'number_of_shards' => 1,
                'number_of_replicas' => 0,
            ],
        ],
    ],
];
