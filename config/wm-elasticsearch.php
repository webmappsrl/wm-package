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
                            'exact' => [
                                'type' => 'text',
                                'analyzer' => 'standard',
                            ],
                            'phrase' => [
                                'type' => 'text',
                                'analyzer' => 'phrase_analyzer',
                            ],
                            'edge' => [
                                'type' => 'text',
                                'analyzer' => 'edge_ngram_analyzer',
                                'search_analyzer' => 'standard'
                            ],
                            'completion' => [
                                'type' => 'completion',
                            ],
                        ],
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
                'analysis' => [
                    'analyzer' => [
                        'edge_ngram_analyzer' => [
                            'type' => 'custom',
                            'tokenizer' => 'standard',
                            'filter' => ['lowercase', 'edge_ngram_filter']
                        ],
                        'phrase_analyzer' => [
                            'type' => 'custom',
                            'tokenizer' => 'keyword',
                            'filter' => ['lowercase'],
                        ],
                    ],
                    'filter' => [
                        'edge_ngram_filter' => [
                            'type' => 'edge_ngram',
                            'min_gram' => 3,
                            'max_gram' => 5
                        ]
                    ]
                ]
            ]
        ],
    ],
];
