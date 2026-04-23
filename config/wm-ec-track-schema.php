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
                'type' => 'tiptap',
                'required' => false,
                'translatable' => true,
                'label' => [
                    'it' => 'Descrizione',
                    'en' => 'Description',
                ],
            ],
            [
                'name' => 'excerpt',
                'type' => 'textarea',
                'required' => false,
                'translatable' => true,
                'label' => [
                    'it' => 'Excerpt',
                    'en' => 'Excerpt',
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
