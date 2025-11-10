<?php

return [
    'properties' => [
        'label' => [
            'it' => 'creato da UGC',
            'en' => 'created from UGC',
        ],
        'fields' => [
            [
                'name' => 'conversion_date',
                'type' => 'date',
                'required' => false,
                'translatable' => false,
                'label' => [
                    'it' => 'Data conversione',
                    'en' => 'Conversion date',
                ],
            ],
            [
                'name' => 'ugc_user_id',
                'type' => 'user',
                'required' => false,
                'translatable' => false,
                'label' => [
                    'it' => 'Utente',
                    'en' => 'User',
                ],
            ],
            [
                'name' => 'ugc_poi_id',
                'type' => 'ugc_poi',
                'required' => false,
                'translatable' => false,
                'label' => [
                    'it' => 'poi',
                    'en' => 'poi',
                ],
            ],
        ],
    ],
];
