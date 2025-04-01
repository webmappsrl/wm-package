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
                            'completion' => [
                                'type' => 'completion',
                            ],
                        ],
                        'analyzer' => 'my',
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
                        'my' => [
                            'tokenizer' => 'split-into-3-chars',
                        ],
                        'phrase_analyzer' => [
                            'type' => 'custom',
                            'tokenizer' => 'keyword',
                            'filter' => ['lowercase'],
                        ],
                    ],
                    'tokenizer' => [
                        'split-into-3-chars' => [
                            'type' => 'ngram',
                            'min_gram' => 3,
                            'max_gram' => 3,
                            'token_chars' => [
                                'letter',
                                'digit',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
];
