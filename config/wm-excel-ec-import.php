<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Intestazioni valide import/export Excel EcTrack ed EcPoi
    |--------------------------------------------------------------------------
    |
    | Usate per validare e mappare file Excel/CSV (Maatwebsite Excel) in
    | WmPackage; allineate ai template scaricabili da Nova.
    |
    */
    'ecTracks' => [
        'validHeaders' => [
            'id',
            'from',
            'to',
            'ele_from',
            'ele_to',
            'distance',
            'duration_forward',
            'duration_backward',
            'ascent',
            'descent',
            'ele_min',
            'ele_max',
            'difficulty',
        ],
    ],
    'ecPois' => [
        // Intestazioni export/import Excel POI (EcPoiExcelExporter legge da qui).
        'validHeaders' => [
            'id',
            'name_it',
            'name_en',
            'description_it',
            'description_en',
            'excerpt_it',
            'excerpt_en',
            'poi_type',
            'lat',
            'lng',
            'addr_complete',
            'capacity',
            'contact_phone',
            'contact_email',
            'related_url',
            'feature_image',
            'gallery',
            'errors',
        ],
    ],
];
