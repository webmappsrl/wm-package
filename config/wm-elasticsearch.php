<?php

return [
    'host' => env('ELASTICSEARCH_PORT') && env('ELASTICSEARCH_SCHEME')
        ? env('ELASTICSEARCH_SCHEME').'://'.env('ELASTICSEARCH_HOST').':'.env('ELASTICSEARCH_PORT')
        : env('ELASTICSEARCH_HOST', 'elasticsearch:9200'),
    'user' => env('ELASTICSEARCH_USER', 'elastic'),
    'password' => env('ELASTICSEARCH_PASSWORD', env('ELASTICSEARCH_PASS', 'changeme')),
    'cloud_id' => env('ELASTICSEARCH_CLOUD_ID', env('ELASTICSEARCH_API_ID')),
    'api_key' => env('ELASTICSEARCH_API_KEY'),
    'ssl_verification' => env('ELASTICSEARCH_SSL_VERIFICATION', false),
    'queue' => [
        'timeout' => env('SCOUT_QUEUE_TIMEOUT'),
    ],
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
                                'search_analyzer' => 'standard',
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
                    'layers' => [
                        'type' => 'keyword',
                    ],
                    'taxonomyWheres' => [
                        'type' => 'keyword',
                    ],
                    'taxonomyIcons' => [
                        'type' => 'object',
                        'enabled' => false,
                    ],
                ],
            ],
        ],
        'settings' => [
            'default' => [
                'number_of_shards' => 1,
                'number_of_replicas' => 0,
                'mapping' => [
                    'total_fields' => [
                        'limit' => 2000,
                    ],
                ],
                'analysis' => [
                    'analyzer' => [
                        'edge_ngram_analyzer' => [
                            'type' => 'custom',
                            'tokenizer' => 'standard',
                            'filter' => ['lowercase', 'edge_ngram_filter'],
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
                            'max_gram' => 5,
                        ],
                    ],
                ],
            ],
        ],
    ],
];
