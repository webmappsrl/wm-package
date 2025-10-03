<?php

return [
    'properties' => [
        'label' => [
            'it' => 'Proprietà Layer',
            'en' => 'Layer Properties',
        ],
        'fields' => [
            [
                'name' => 'description',
                'type' => 'textarea',
                'required' => false,
                'translatable' => true,
                'label' => [
                    'it' => 'Descrizione',
                    'en' => 'Description',
                ],
            ],
            [
                'name' => 'ref',
                'type' => 'text',
                'required' => false,
                'translatable' => false,
                'label' => [
                    'it' => 'Ref',
                    'en' => 'Ref',
                ],
            ],
            [
                'name' => 'from',
                'type' => 'text',
                'required' => false,
                'translatable' => false,
                'label' => [
                    'it' => 'da',
                    'en' => 'From',
                ],
            ],
            [
                'name' => 'to',
                'type' => 'text',
                'required' => false,
                'translatable' => false,
                'label' => [
                    'it' => 'a',
                    'en' => 'To',
                ],
            ],
            [
                'name' => 'ascent',
                'type' => 'number',
                'required' => false,
                'translatable' => false,
                'label' => [
                    'it' => 'Dislivello positivo',
                    'en' => 'Ascent',
                ],
            ],
            [
                'name' => 'descent',
                'type' => 'number',
                'required' => false,
                'translatable' => false,
                'label' => [
                    'it' => 'Dislivello negativo',
                    'en' => 'Descent',
                ],
            ],
            [
                'name' => 'distance',
                'type' => 'number',
                'required' => false,
                'translatable' => false,
                'label' => [
                    'it' => 'Distanza',
                    'en' => 'Distance',
                ],
            ],
            [
                'name' => 'duration_forward',
                'type' => 'number',
                'required' => false,
                'translatable' => false,
                'label' => [
                    'it' => 'Durata Andata',
                    'en' => 'Duration Forward',
                ],
            ],
            [
                'name' => 'duration_backward',
                'type' => 'number',
                'required' => false,
                'translatable' => false,
                'label' => [
                    'it' => 'Durata Ritorno',
                    'en' => 'Duration Backward',
                ],
            ],
            [
                'name' => 'geohub_id',
                'type' => 'number',
                'required' => false,
                'translatable' => false,
                'label' => [
                    'it' => 'Geohub ID',
                    'en' => 'Geohub ID',
                ],
                'help' => 'This field is automatically generated',
                'readonly' => true,
            ],
            [
                'name' => 'taxonomy_where',
                'type' => 'json',
                'required' => false,
                'translatable' => false,
                'label' => [
                    'it' => 'Tassonomia Dove',
                    'en' => 'Taxonomy Where',
                ],
            ],
        ],
    ],
];
