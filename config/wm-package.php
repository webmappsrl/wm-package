<?php

use Wm\WmPackage\TrailRegistry\Commands\TrailRegistryNormalizeCommand;
use Wm\WmPackage\TrailRegistry\Nova\TrailApplication;
use Wm\WmPackage\TrailRegistry\Nova\TrailRegistryAnomaly;
use Wm\WmPackage\TrailRegistry\Nova\TrailRegistryCode;

// config for Wm/WmPackage
return [
    'version' => '1.5.0', // x-release-please-version
    'shard_name' => env('SHARD_NAME', env('APP_NAME')),
    'analytics_shard_name' => env('ANALYTICS_SHARD_NAME'),
    'layer_user_presence_distance_meters' => env('LAYER_USER_PRESENCE_DISTANCE_METERS', 50),
    'services' => [
        'geometry_computation' => [
            'neighbours_distance' => env('WM_NEIGHBOURS_DISTANCE', 500),
        ],
        'nodejs' => [
            'executable' => env('WM_NODE_EXECUTABLE', '/usr/bin/node'),
        ],
        'image' => [
            'thumbnail_sizes' => [
                ['width' => 108, 'height' => 148],
                ['width' => 108, 'height' => 137],
                ['width' => 150, 'height' => 150],
                ['width' => 225, 'height' => 100],
                ['width' => 118, 'height' => 138],
                ['width' => 108, 'height' => 139],
                ['width' => 118, 'height' => 117],
                ['width' => 335, 'height' => 250],
                ['width' => 400, 'height' => 200],
                ['width' => 1440, 'height' => 500],
                ['width' => 1920, 'height' => 1080],
                ['width' => 250, 'height' => 150],
            ],
        ],
        'pbf' => [
            'min_zoom' => env('PBF_MIN_ZOOM', 5),
            'max_zoom' => env('PBF_MAX_ZOOM', 13),
            'zoom_treshold' => env('PBF_ZOOM_TRESHOLD', 6),
            'pbf_layer' => env('PBF_LAYER', false),
        ],
    ],
    'web_components' => [
        'layer_map' => [
            'example_url' => 'https://raw.githubusercontent.com/webmappsrl/wm-layer-map/refs/heads/main/test/index.html',
            'cache_ttl' => 1800,
            'timeout' => 10,
            'fallback' => [
                'tag_name' => 'wm-layer-map',
                'script_url' => 'https://cdn.jsdelivr.net/gh/webmappsrl/wm-layer-map@refs/heads/main/src/wm-layer-map.js',
                'default_style' => 'display:block;width:100%;height:600px',
            ],
        ],
    ],
    'clients' => [
        'dem' => [
            'host' => env('DEM_HOST', 'https://dem.maphub.it'),
            'ele_api' => env('DEM_ELE_API', 'api/v1/elevation'),
            'tech_data_api' => env('DEM_TECH_DATA_API', 'api/v1/track'),
            '3d_data_api' => env('DEM_3D_DATA_API', 'api/v1/track3d'),
            'point_matrix_api' => env('DEM_POINT_MATRIX_API', 'api/v1/feature-collection/point-matrix'),
        ],
        'cai' => [
            'basic_auth_user' => env('CAI_BASIC_AUTH_USER'),
            'basic_auth_password' => env('CAI_BASIC_AUTH_PASSWORD'),
        ],
        'osmfeatures' => [
            'host' => env('OSMFEATURES_HOST', 'https://osmfeatures.maphub.it'),
        ],
        'geohub' => [
            'host' => env('GEOHUB_HOST', 'https://geohub.webmapp.it'),
        ],
        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'model' => env('OPENAI_TRANSLATION_MODEL', 'gpt-4o-mini'),
        ],
    ],
    'ec_track_table' => env('EC_TRACK_TABLE', 'ec_tracks'),
    'ec_track_model' => env('EC_TRACK_MODEL', 'App\Models\EcTrack'),
    'ec_poi_track_pivot_table' => env('EC_POI_TRACK_PIVOT_TABLE', 'ec_poi_ec_track'),
    'default_layer_mode' => env('DEFAULT_LAYER_MODE', 'auto'),

    /*
    | Domini opzionali del package {@see \Wm\WmPackage\Services\FeaturesService}.
    | A dominio spento il package si comporta come se il dominio non esistesse:
    | i suoi stub di migration non sono considerati dai comandi, i suoi comandi
    | e le sue risorse Nova non vengono registrati.
    | Guida: docs/resources/OptionalDomains.md
    */
    'features' => [
        'trail_registry' => [
            'enabled' => env('WM_TRAIL_REGISTRY_ENABLED', false),

            // Comandi artisan e risorse Nova del dominio: registrati solo a
            // dominio acceso. Le risorse Nova NON possono stare in src/Nova,
            // che viene scandita integralmente da Nova::resourcesIn().
            'commands' => [
                TrailRegistryNormalizeCommand::class,
            ],
            'nova_resources' => [
                TrailRegistryCode::class,
                TrailApplication::class,
                TrailRegistryAnomaly::class,
            ],

            // In quale proprieta' del tracciato vive il codice storico.
            // Su forestas e' `ref`, ereditato dall'import da Sardegna
            // Sentieri; un altro catasto la chiamera' altrimenti. Il service
            // non la usa — riceve il codice come parametro — ma la usano i
            // suoi chiamanti: il comando di normalizzazione e l'import.
            'legacy_code_property' => env('WM_TRAIL_LEGACY_CODE_PROPERTY', 'ref'),

            // Dove sta, nelle proprieta' del tracciato, l'indirizzo della sua
            // scheda sulla piattaforma di origine. Notazione con il punto per
            // i valori annidati (su forestas: `forestas.url`). Serve alla
            // lista delle anomalie: le correzioni si fanno alla fonte, e un
            // collegamento diretto evita al gestore di cercare la scheda a
            // mano.
            //
            // **Vuota di default**, e non `forestas.url`: il package non puo'
            // dare per scontato ne' il nome dello shard ne' che una scheda di
            // origine esista. Chi ha una fonte esterna la imposta; chi non ce
            // l'ha non vede il collegamento, che e' il comportamento giusto.
            'source_url_property' => env('WM_TRAIL_SOURCE_URL_PROPERTY'),

            // Come si chiama la piattaforma di origine, per chi ci deve
            // andare a lavorare: finisce nel titolo del collegamento («Apri
            // su Drupal»). Vuota: si ripiega su una formula generica.
            'source_label' => env('WM_TRAIL_SOURCE_LABEL'),

            // Da quale sorgente arrivano i settori del catasto, in
            // `taxonomy_wheres.properties->source`. Il default vale per i
            // catasti che importano i settori CAI da osm2cai; uno shard che
            // li carica da un'altra parte cambia questa chiave, altrimenti
            // nessun settore viene mai trovato e ogni sentiero risulta
            // «fuori da ogni settore».
            'sector_source' => env('WM_TRAIL_SECTOR_SOURCE', 'osm2cai'),

            // Le chiavi con cui Nova indirizza le Resource collegate dalle
            // mappe e dalle anomalie. Sono quelle che Nova ricava dal nome
            // della classe, ma un consumer puo' sovrascrivere `uriKey()`: se
            // lo fa, senza queste chiavi i collegamenti porterebbero a pagine
            // inesistenti.
            'nova_uri_keys' => [
                'ec_track' => env('WM_TRAIL_URI_KEY_EC_TRACK', 'ec-tracks'),
                'taxonomy_where' => env('WM_TRAIL_URI_KEY_TAXONOMY_WHERE', 'taxonomy-wheres'),
                'trail_application' => env('WM_TRAIL_URI_KEY_TRAIL_APPLICATION', 'trail-applications'),
            ],

            // Come si riconosce un codice scritto dentro il nome del
            // sentiero. Il default sono le PARENTESI FINALI — `(G 106)`,
            // `(D 180 A)` — la convenzione osservata sui dati di Sardegna
            // Sentieri; il primo gruppo di cattura e' il codice. Una fonte
            // con un'altra convenzione cambia l'espressione; una che non ne
            // ha nessuna la svuota, e i codici si leggono solo dal campo
            // dedicato.
            'name_code_pattern' => env('WM_TRAIL_NAME_CODE_PATTERN', '/\(([^()]*)\)\s*$/'),

            // Formato del codice: impostabile, non scritto nel codice. Se il
            // formato reale confermato da Forestas divergesse, questo e' il
            // punto da cambiare.
            'code_format' => [
                'number_digits' => 2,
                'number_max' => 99,
                // `0` significa «senza variante» e in uscita si omette.
                'variant_none' => '0',
                'variant_letters' => true,
            ],

            // Identificatore della tassonomia che marca un tracciato come
            // sentiero: e' un dato del consumer, non del package. Se non e'
            // configurato (o la tassonomia non esiste) l'approvazione di
            // un'istanza prosegue comunque, registrando un avviso nel log.
            'trail_type_identifier' => env('WM_TRAIL_REGISTRY_TRAIL_TYPE_IDENTIFIER'),
        ],
    ],

    /*
    | Configurazione per la feature QR code deep link (oc:8251).
    | - apple_team_id: Apple Developer Team ID di default, usato per comporre l'appID
    |   (TEAMID.bundle_id) nell'entry apple-app-site-association quando la singola App non
    |   ha un Team ID proprio impostato in Nova (properties->apple_team_id) — alcune app
    |   potrebbero non essere pubblicate sotto l'account Developer Webmapp, da qui la
    |   possibilità di override per-app. Nessun override da env: è una costante di codice.
    */
    'deep_link' => [
        'apple_team_id' => 'BSTW6XXE23',
    ],

    /*
    | Email allowlist super-admin {@see \Wm\WmPackage\Services\RolesAndPermissionsService} (comma-separated).
    | Fallback env: WM_SUPER_ADMIN_EMAILS → default team@webmapp.it.
    */
    'super_admin_emails' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('WM_SUPER_ADMIN_EMAILS', 'team@webmapp.it'))
    ))),
];
