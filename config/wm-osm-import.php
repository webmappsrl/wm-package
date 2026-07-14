<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Delay between HTTP requests to the OSM API and the next one (POI import)
    |--------------------------------------------------------------------------
    |
    | Reduces burst traffic to api.openstreetmap.org. Value in milliseconds.
    | Set to 0 to disable (testing only or very low volumes).
    |
    */
    'request_delay_ms' => max(0, (int) env('WM_OSM_IMPORT_REQUEST_DELAY_MS', 350)),

    /*
    |--------------------------------------------------------------------------
    | Maximum number of OSM IDs processed per import run
    |--------------------------------------------------------------------------
    |
    | If the list exceeds this value, extra IDs are ignored and the report
    | shows how many were omitted. 0 means no limit.
    |
    */
    'max_ids_per_run' => max(0, (int) env('WM_OSM_IMPORT_MAX_IDS_PER_RUN', 500)),

];
