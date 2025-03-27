<?php

return [
    'layer' => [
        'title' => [
            'type' => 'text',
            'label' => 'Title',
            'translatable' => true,
            'rules' => ['nullable']
        ],
        'subtitle' => [
            'type' => 'text',
            'label' => 'Subtitle',
            'translatable' => true,
            'rules' => ['nullable']
        ],
        'description' => [
            'type' => 'textarea',
            'label' => 'Description',
            'translatable' => true,
            'rules' => ['nullable']
        ],
        'rank' => [
            'type' => 'number',
            'label' => 'Rank',
            'rules' => ['nullable', 'integer']
        ],
        'color' => [
            'type' => 'text',
            'label' => 'Color',
            'rules' => ['nullable', 'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'],
            'help' => 'Enter a valid hex color code (e.g., #000000, #000, #00000000)'
        ],
        'generate_edges' => [
            'type' => 'boolean',
            'label' => 'Generate Edges',
            'rules' => ['boolean']
        ],
        'geohub_id' => [
            'type' => 'number',
            'label' => 'Geohub ID',
            'rules' => ['nullable', 'integer'],
            'help' => 'This field is automatically generated',
            'readonly' => true
        ],
        'geohub_synced_at' => [
            'type' => 'text',
            'label' => 'Geohub Synced At',
            'rules' => ['nullable', 'date'],
            'help' => 'This field is automatically generated',
            'readonly' => true
        ],
    ],
    'ec_poi' => [
        'description' => [
            'type' => 'textarea',
            'label' => 'Description',
            'translatable' => true,
            'rules' => ['nullable']
        ],
        'excerpt' => [
            'type' => 'textarea',
            'label' => 'Excerpt',
            'translatable' => true,
            'rules' => ['nullable']
        ],
        'color' => [
            'type' => 'text',
            'label' => 'Color',
            'rules' => ['nullable', 'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'],
            'help' => 'Enter a valid hex color code (e.g., #000000)'
        ],
        'ele' => [
            'type' => 'number',
            'label' => 'Elevation',
            'rules' => ['nullable', 'numeric']
        ],
        'icon' => [
            'type' => 'text',
            'label' => 'Icon',
            'rules' => ['nullable']
        ],
        'type' => [
            'type' => 'text',
            'label' => 'Type',
            'rules' => ['nullable']
        ],
        'addr_street' => [
            'type' => 'text',
            'label' => 'Street Address',
            'rules' => ['nullable']
        ],
        'addr_housenumber' => [
            'type' => 'text',
            'label' => 'House Number',
            'rules' => ['nullable']
        ],
        'addr_postcode' => [
            'type' => 'text',
            'label' => 'Postal Code',
            'rules' => ['nullable']
        ],
        'addr_locality' => [
            'type' => 'text',
            'label' => 'Locality',
            'rules' => ['nullable']
        ],
        'contact_phone' => [
            'type' => 'text',
            'label' => 'Phone',
            'rules' => ['nullable']
        ],
        'contact_email' => [
            'type' => 'text',
            'label' => 'Email',
            'rules' => ['nullable', 'email']
        ],
        'opening_hours' => [
            'type' => 'textarea',
            'label' => 'Opening Hours',
            'rules' => ['nullable']
        ],
        'geohub_id' => [
            'type' => 'number',
            'label' => 'Geohub ID',
            'rules' => ['nullable', 'integer'],
            'help' => 'This field is automatically generated',
            'readonly' => true
        ],
        'geohub_synced_at' => [
            'type' => 'text',
            'label' => 'Geohub Synced At',
            'rules' => ['nullable', 'date'],
            'help' => 'This field is automatically generated',
            'readonly' => true
        ]
    ],
    'ec_track' => [
        'from' => [
            'type' => 'text',
            'label' => 'Starting Point',
            'rules' => ['nullable', 'string'],
            'help' => 'Starting point of the track'
        ],
        'to' => [
            'type' => 'text',
            'label' => 'Destination',
            'rules' => ['nullable', 'string'],
            'help' => 'End point of the track'
        ],
        'ref' => [
            'type' => 'text',
            'label' => 'Reference',
            'rules' => ['nullable', 'string'],
            'help' => 'Track reference or stage number'
        ],
        'distance' => [
            'type' => 'number',
            'label' => 'Distance (km)',
            'rules' => ['nullable', 'numeric'],
            'help' => 'Total track distance in kilometers'
        ],
        'ascent' => [
            'type' => 'number',
            'label' => 'Total Ascent (m)',
            'rules' => ['nullable', 'numeric'],
            'help' => 'Total ascent in meters'
        ],
        'descent' => [
            'type' => 'number',
            'label' => 'Total Descent (m)',
            'rules' => ['nullable', 'numeric'],
            'help' => 'Total descent in meters'
        ],
        'ele_min' => [
            'type' => 'number',
            'label' => 'Minimum Elevation (m)',
            'rules' => ['nullable', 'numeric'],
            'help' => 'Minimum elevation along the track'
        ],
        'ele_max' => [
            'type' => 'number',
            'label' => 'Maximum Elevation (m)',
            'rules' => ['nullable', 'numeric'],
            'help' => 'Maximum elevation along the track'
        ],
        'duration_forward' => [
            'type' => 'number',
            'label' => 'Forward Duration (min)',
            'rules' => ['nullable', 'numeric'],
            'help' => 'Estimated duration in minutes for forward direction'
        ],
        'duration_backward' => [
            'type' => 'number',
            'label' => 'Backward Duration (min)',
            'rules' => ['nullable', 'numeric'],
            'help' => 'Estimated duration in minutes for backward direction'
        ],
        'description' => [
            'type' => 'textarea',
            'label' => 'Description',
            'translatable' => true,
            'rules' => ['nullable'],
            'help' => 'Detailed description of the track'
        ],
        'excerpt' => [
            'type' => 'textarea',
            'label' => 'Excerpt',
            'translatable' => true,
            'rules' => ['nullable'],
            'help' => 'Brief description or summary of the track'
        ],
        'difficulty' => [
            'type' => 'textarea',
            'label' => 'Difficulty',
            'translatable' => true,
            'rules' => ['nullable'],
            'help' => 'Track difficulty description'
        ],
        'cai_scale' => [
            'type' => 'text',
            'label' => 'CAI Scale',
            'rules' => ['nullable', 'string'],
            'help' => 'CAI difficulty scale classification'
        ],
        'not_accessible_message' => [
            'type' => 'textarea',
            'label' => 'Not Accessible Message',
            'rules' => ['nullable'],
            'help' => 'Message to display when the track is not accessible'
        ],
        'difficulty_i18n' => [
            'type' => 'textarea',
            'label' => 'Difficulty (i18n)',
            'rules' => ['nullable'],
            'help' => 'Difficulty description in multiple languages'
        ]
    ],
    'media' => [
        'geohub_id' => [
            'type' => 'number',
            'label' => 'Geohub ID',
            'rules' => ['nullable', 'integer'],
            'help' => 'This field is automatically generated',
            'readonly' => true
        ],
        'geohub_synced_at' => [
            'type' => 'text',
            'label' => 'Geohub Synced At',
            'rules' => ['nullable', 'date'],
            'help' => 'This field is automatically generated',
            'readonly' => true
        ],
    ]
];
