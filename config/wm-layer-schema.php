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
                'name' => 'color',
                'type' => 'color',
                'required' => false,
                'label' => [
                    'it' => 'Colore',
                    'en' => 'Color',
                ],
                'helper' => [
                    'it' => 'Colore in formato hex (es: #ff0000)',
                    'en' => 'Color in hex format (eg: #ff0000)',
                ],
            ],
            [
                'name' => 'stroke_width',
                'type' => 'number',
                'required' => false,
                'label' => [
                    'it' => 'Spessore Linea',
                    'en' => 'Stroke Width',
                ],
                'helper' => [
                    'it' => 'Spessore della linea in pixel',
                    'en' => 'Line thickness in pixels',
                ],
            ],
            [
                'name' => 'stroke_opacity',
                'type' => 'number',
                'required' => false,
                'label' => [
                    'it' => 'Opacità Linea',
                    'en' => 'Stroke Opacity',
                ],
                'helper' => [
                    'it' => 'Valore tra 0 (trasparente) e 1 (opaco)',
                    'en' => 'Value between 0 (transparent) and 1 (opaque)',
                ],
            ],
            [
                'name' => 'fill_color',
                'type' => 'color',
                'required' => false,
                'label' => [
                    'it' => 'Colore Riempimento',
                    'en' => 'Fill Color',
                ],
                'helper' => [
                    'it' => 'Colore di riempimento in formato hex (es: #00ff00)',
                    'en' => 'Fill color in hex format (eg: #00ff00)',
                ],
            ],
            [
                'name' => 'fill_opacity',
                'type' => 'number',
                'required' => false,
                'label' => [
                    'it' => 'Opacità Riempimento',
                    'en' => 'Fill Opacity',
                ],
                'helper' => [
                    'it' => 'Valore tra 0 (trasparente) e 1 (opaco)',
                    'en' => 'Value between 0 (transparent) and 1 (opaque)',
                ],
            ],
            [
                'name' => 'min_zoom',
                'type' => 'number',
                'required' => false,
                'label' => [
                    'it' => 'Zoom Minimo',
                    'en' => 'Minimum Zoom',
                ],
                'helper' => [
                    'it' => 'Livello di zoom minimo per visualizzare il layer',
                    'en' => 'Minimum zoom level to display the layer',
                ],
            ],
            [
                'name' => 'max_zoom',
                'type' => 'number',
                'required' => false,
                'label' => [
                    'it' => 'Zoom Massimo',
                    'en' => 'Maximum Zoom',
                ],
                'helper' => [
                    'it' => 'Livello di zoom massimo per visualizzare il layer',
                    'en' => 'Maximum zoom level to display the layer',
                ],
            ],
            [
                'name' => 'visible',
                'type' => 'select',
                'required' => false,
                'label' => [
                    'it' => 'Visibile',
                    'en' => 'Visible',
                ],
                'values' => [
                    [
                        'value' => 'true',
                        'label' => [
                            'it' => 'Sì',
                            'en' => 'Yes',
                        ],
                    ],
                    [
                        'value' => 'false',
                        'label' => [
                            'it' => 'No',
                            'en' => 'No',
                        ],
                    ],
                ],
            ],
            [
                'name' => 'zindex',
                'type' => 'number',
                'required' => false,
                'label' => [
                    'it' => 'Z-Index',
                    'en' => 'Z-Index',
                ],
                'helper' => [
                    'it' => 'Ordine di visualizzazione del layer (più alto = in primo piano)',
                    'en' => 'Layer display order (higher = foreground)',
                ],
            ],
        ],
    ],
];
