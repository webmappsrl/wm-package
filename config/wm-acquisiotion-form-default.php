<?php

return [
    'poi' => [
        [
            'id' => 'poi',
            'helper' => [
                'it' => 'sono helper di Punto di interesse',
                'en' => 'helper of Point of interest'
            ],
            'label' => [
                'it' => 'Punto di interesse',
                'en' => 'Point of interest'
            ],
            'fields' => [
                [
                    'name' => 'title',
                    'type' => 'text',
                    'placeholder' => [
                        'it' => 'Inserisci un titolo',
                        'en' => 'Add a title'
                    ],
                    'required' => true,
                    'label' => [
                        'it' => 'Titolo',
                        'en' => 'Title'
                    ]
                ],
                [
                    'name' => 'waypointtype',
                    'type' => 'select',
                    'required' => true,
                    'label' => [
                        'it' => 'Tipo punto di interesse',
                        'en' => 'Point of interest type'
                    ],
                    'values' => [
                        [
                            'value' => 'landscape',
                            'label' => [
                                'it' => 'Panorama',
                                'en' => 'Landscape'
                            ]
                        ],
                        [
                            'value' => 'place_to_eat',
                            'label' => [
                                'it' => 'Luogo dove mangiare',
                                'en' => 'Place to eat'
                            ]
                        ],
                        [
                            'value' => 'place_to_sleep',
                            'label' => [
                                'it' => 'Luogo dove dormire',
                                'en' => 'Place to sleep'
                            ]
                        ],
                        [
                            'value' => 'natural',
                            'label' => [
                                'it' => 'Luogo di interesse naturalistico',
                                'en' => 'Place of naturalistic interest'
                            ]
                        ],
                        [
                            'value' => 'cultural',
                            'label' => [
                                'it' => 'Luogo di interesse culturale',
                                'en' => 'Place of cultural interest'
                            ]
                        ],
                        [
                            'value' => 'other',
                            'label' => [
                                'it' => 'Altri tipi di luoghi di interesse',
                                'en' => 'Other types of Point of interest'
                            ]
                        ]
                    ]
                ],
                [
                    'name' => 'description',
                    'type' => 'textarea',
                    'placeholder' => [
                        'it' => 'Se vuoi puoi aggiungere una descrizione',
                        'en' => 'You can add a description if you want'
                    ],
                    'required' => false,
                    'label' => [
                        'it' => 'Descrizione',
                        'en' => 'Description'
                    ]
                ]
            ]
        ]
    ],
    'track' => [
        [
            'id' => 'track',
            'helper' => [
                'it' => 'sono helper di track',
                'en' => 'helper of track'
            ],
            'label' => [
                'it' => 'traccia',
                'en' => 'track'
            ],
            'fields' => [
                [
                    'name' => 'title',
                    'type' => 'text',
                    'placeholder' => [
                        'it' => 'Inserisci un titolo',
                        'en' => 'Add a title'
                    ],
                    'required' => true,
                    'label' => [
                        'it' => 'Titolo',
                        'en' => 'Title'
                    ]
                ],
                [
                    'name' => 'tracktype',
                    'type' => 'select',
                    'required' => true,
                    'label' => [
                        'it' => 'Tipo traccia',
                        'en' => 'Track type'
                    ],
                    'values' => [
                        [
                            'value' => 'hiking',
                            'label' => [
                                'it' => 'Escursionismo',
                                'en' => 'Hiking'
                            ]
                        ],
                        [
                            'value' => 'cycling',
                            'label' => [
                                'it' => 'Ciclismo',
                                'en' => 'Cycling'
                            ]
                        ],
                        [
                            'value' => 'running',
                            'label' => [
                                'it' => 'Corsa',
                                'en' => 'Running'
                            ]
                        ],
                        [
                            'value' => 'other',
                            'label' => [
                                'it' => 'Altri tipi di traccia',
                                'en' => 'Other types of track'
                            ]
                        ]
                    ]
                ],
                [
                    'name' => 'description',
                    'type' => 'textarea',
                    'placeholder' => [
                        'it' => 'Se vuoi puoi aggiungere una descrizione',
                        'en' => 'You can add a description if you want'
                    ],
                    'required' => false,
                    'label' => [
                        'it' => 'Descrizione',
                        'en' => 'Description'
                    ]
                ]
            ]
        ]
    ]
];