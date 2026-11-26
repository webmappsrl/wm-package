<?php

use Wm\WmPackage\Models\TaxonomyActivity;
use Wm\WmPackage\Models\TaxonomyPoiType;
use Wm\WmPackage\Services\Models\UgcTrackCleanupService;
use Wm\WmPackage\Services\Models\UgcTrackStatsService;
use Wm\WmPackage\TrailRegistry\Commands\TrailRegistryNormalizeCommand;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryAnomaly;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryCode;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryCodeEvent;

// config for Wm/WmPackage
return [
    'version' => '1.6.3', // x-release-please-version
    'shard_name' => env('SHARD_NAME', env('APP_NAME')),
    'analytics_shard_name' => env('ANALYTICS_SHARD_NAME'),
    // oc:8997: versioni del JSON della traccia scritte su wmfe, in ordine, separate da virgola.
    // Sta in una variabile d'ambiente e non nel config pubblicato dallo shard: quello congelerebbe
    // uno snapshot dell'intero file e le chiavi che il package aggiungerà in futuro non lo
    // raggiungerebbero più (`mergeConfigFrom` unisce solo il primo livello).
    // Vuota o assente: resta attiva la sola `legacy`, il file senza versione che leggono le app
    // installate. Un nome sconosciuto fa fallire la scrittura, non scrivere su un prefisso muto.
    'wmfe_track_versions' => env('WMFE_TRACK_VERSIONS', 'legacy'),
    'route_filter_analytics_enabled' => env('ROUTE_FILTER_ANALYTICS_ENABLED', false),
    'layer_user_presence_distance_meters' => env('LAYER_USER_PRESENCE_DISTANCE_METERS', 50),
    // oc:8719: un punto GPS di una traccia UGC con accuracy oltre questa soglia (metri) è sospetto
    // quando la geometria viene ricostruita da properties.locations. Vuota o <= 0: vale il default.
    'ugc_track_max_accuracy_meters' => (float) env('UGC_TRACK_MAX_ACCURACY_METERS', UgcTrackCleanupService::DEFAULT_MAX_ACCURACY_METERS),
    // oc:8719: un punto sospetto si scarta solo se dista più di questi metri dal tratto fra il punto
    // tenuto precedente e il primo punto buono successivo. Vuota o <= 0: vale il default.
    'ugc_track_max_deviation_meters' => (float) env('UGC_TRACK_MAX_DEVIATION_METERS', UgcTrackCleanupService::DEFAULT_MAX_DEVIATION_METERS),
    // oc:8742: percentile del campo speed dei punti tenuti usato come velocità massima di una
    // traccia UGC. Vuota, <= 0 o > 100: vale il default.
    'ugc_track_max_speed_percentile' => (float) env('UGC_TRACK_MAX_SPEED_PERCENTILE', UgcTrackStatsService::DEFAULT_MAX_SPEED_PERCENTILE),
    // oc:8742: un tratto fra due punti conta come movimento se la sua velocità (km/h) è almeno
    // questa. Vuota o <= 0: vale il default.
    'ugc_track_moving_min_speed_kmh' => (float) env('UGC_TRACK_MOVING_MIN_SPEED_KMH', UgcTrackStatsService::DEFAULT_MOVING_MIN_SPEED_KMH),
    // oc:8637: mostra nome, cognome e link alla scheda Nova dell'utente sul marker live della mappa
    // del layer. Default false (marker anonimo, come da oc:8586): ogni shard lo accende dal proprio
    // .env. Con il flag acceso l'identità è visibile a ogni utente che supera il gate Nova del
    // consumer, su qualunque layer (l'endpoint della mappa non ha autorizzazione per singolo layer).
    // Letto con FILTER_VALIDATE_BOOLEAN: accesi solo true/1/on/yes, ogni altro valore lo lascia spento.
    'analytics_show_live_user_identity' => env('ANALYTICS_SHOW_LIVE_USER_IDENTITY', false),
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
            // script_url intenzionalmente assente: il default vive come
            // costante privata in Wm\WmPackage\Nova\Layer (oc:8590) — questo
            // config resta un dato primitivo, senza dipendere da una Nova
            // Resource solo per leggere un URL. Un consumer che vuole un URL
            // diverso può comunque sovrascrivere 'fallback.script_url' qui.
            'fallback' => [
                'tag_name' => 'wm-layer-map',
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
            // Secondi di attesa del servizio DEM per i dati tecnici, senza retry.
            // E' il limite della chiamata sincrona (dentro una richiesta HTTP):
            // tenerlo basso, un servizio lento non deve occupare i worker.
            'timeout_seconds' => (int) env('DEM_TIMEOUT_SECONDS', 5),
            // Come sopra, ma per i job in coda, dove aspettare va bene.
            'async_timeout_seconds' => (int) env('DEM_ASYNC_TIMEOUT_SECONDS', 30),
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
    | Le tassonomie che vivono ancora in tabelle proprie, descritte come se fossero tassonomie del
    | modello unico (oc:8910). Serve a mostrarle nella scheda di un contenuto con lo stesso campo
    | dei tassonomie — stessa icona, stesso modo di scegliere — finché non confluiscono in
    | `taxonomy_terms`. Le tre informazioni che per una tassonomia sono una riga di tabella (nome,
    | tab, scelta singola) qui si dichiarano, perché quelle tabelle non le hanno.
    |
    | `relation` è il metodo del modello EC; `label` è la chiave di traduzione dell'etichetta.
    | Uno shard può aggiungerne o toglierne senza toccare il package.
    */
    'legacy_taxonomies' => [
        [
            'key' => 'activities',
            'relation' => 'taxonomyActivities',
            'model' => TaxonomyActivity::class,
            'label' => 'Activities',
            'tab' => 'Activities',
            'single_choice' => false,
            // Righe che esistono in tabella ma non si offrono in scelta: restano perché altro
            // codice le usa — i layer associano le tracce con `whereHas('taxonomyActivities')`
            // (`Layer::…`), quindi cancellarle spegnerebbe i layer che le nominano.
            'hidden' => [],
        ],
        [
            // I Tipi POI descrivono solo un punto di interesse, e `models` è ciò che lo dice:
            // la relazione `taxonomyPoiTypes()` sta nel trait `TaxonomyAbleModel`, quindi
            // risponde anche una EcTrack e senza questo elenco il campo compariva anche lì
            // (oc:8932).
            'key' => 'poi_types',
            'relation' => 'taxonomyPoiTypes',
            'model' => TaxonomyPoiType::class,
            'models' => ['EcPoi'],
            'label' => 'Poi Types',
            'tab' => 'General',
            'single_choice' => false,
            'hidden' => [],
        ],
    ],

    /*
    | Corrispondenza (matching) fra UgcTrack ed EcTrack per segmenti (oc:8773). Default dei parametri per App: ogni
    | App li può sovrascrivere da Nova (properties ugc_track_matching_*). Cast espliciti: env()
    | restituisce stringhe.
    */
    'ugc_track_matching' => [
        'segment_length_meters' => (int) env('UGC_TRACK_MATCHING_SEGMENT_LENGTH_METERS', 400),
        'max_distance_meters' => (int) env('UGC_TRACK_MATCHING_MAX_DISTANCE_METERS', 50),
        'queue' => env('UGC_TRACK_MATCHING_QUEUE', 'ugc-track-matching'),
    ],

    /*
    | Chiavi di un array di attributi (es. properties->attributes di un Layer,
    | o di un altro modello in futuro) che un consumer considera riservate a
    | un uso interno e mai da esporre pubblicamente (config.json, endpoint
    | layer()). Vuoto di default: il package non sa e non deve sapere quali
    | concetti "interni" esistano per un consumer specifico. Generico per
    | costruzione: non è legato a Layer né a nessun modello specifico — vedi
    | withoutInternalConfigKeys() in src/helpers.php.
    |
    | Un consumer la popola con un proprio config/wm-package.php (il
    | pacchetto fa mergeConfigFrom(): quel file vince sulla stessa chiave,
    | le altre chiavi restano quelle di qui) — versionato, a differenza di
    | un .env di produzione che nessun test legge (es. camminiditalia,
    | 'shape_discontinuous', oc:8463). Nessuna env var qui: sarebbe
    | un secondo modo di fare la stessa cosa, mai usato da nessun consumer.
    */
    'internal_attribute_keys' => [],

    /*
    | Stampa PDF delle schede di tracce e POI (oc:8982). Non è un dominio opzionale: il codice c'è su
    | ogni shard, e la stampa vale per gli elementi di un'App con `properties.ec_pdf_enabled` acceso
    | (PrintService::enabledFor()). Lo shard che la usa deve avere TeX Live nell'immagine PHP, la coda
    | `print` in Horizon e il token del servizio mappe: docs/resources/Print.md.
    */
    'print' => [
        // Coda dei job di stampa.
        'queue' => env('WM_PRINT_QUEUE', 'print'),

        // Servizio delle mappe di stampa su wm-tiles (oc:9011): `fake` per lo sviluppo, `http` per il
        // servizio vero, `url` = `https://origine.tiles.maphub.it/v1/print/map`, token per shard.
        'map_service' => [
            'driver' => env('WM_PRINT_MAP_DRIVER', 'fake'),
            'url' => env('WM_PRINT_MAP_SERVICE_URL'),
            'token' => env('WM_PRINT_MAP_SERVICE_TOKEN'),
            // Una pagina A4 a 300 dpi costa 4-5 s; oltre, il Job si rimette in coda.
            'timeout' => 60,
            'dpi' => 300,
            'style_id' => 'webmapp',
            // Scala, nord e attribuzioni disegnati dal servizio sulla mappa.
            'map_info' => true,
            // Spessore della traccia in pixel css (il servizio lo moltiplica per la stampa).
            'stroke_width' => 4,
            // Colore della traccia per tutto lo shard (`#rrggbb`), che vince sul colore del layer.
            // Null: quello del layer della traccia, altrimenti EcTrack::DEFAULT_COLOR_HEX.
            'stroke_color' => env('WM_PRINT_MAP_STROKE_COLOR'),
            // Zoom oltre il quale la mappa non si ingrandisce, anche su una traccia corta (0-19). Null:
            // quello del servizio, 16, che su un A4 lascia un sentiero di 1 km a circa 1:21.000.
            'max_zoom' => null,
            // Margine attorno alle geometrie, in mm di carta, per tutte le mappe. Null: quello del
            // servizio, 8 mm. La mappa della scheda ha il suo, `map.padding_mm`.
            'padding_mm' => null,
        ],

        // Limiti della compilazione del PDF.
        'compile' => [
            'timeout' => 120,
            'memory_mb' => 1024,
        ],

        // Limiti delle foto scaricate per la scheda: oltre, la foto si salta (warning sul canale
        // `print`). Un esaurimento di memoria in GD è fatale e non intercettabile.
        'photos' => [
            'max_bytes' => 30 * 1024 * 1024,
            'max_pixels' => 50_000_000,
            // Budget complessivo del download delle foto di una scheda, in secondi: esaurito, le
            // foto restanti si saltano. Ogni foto ha una scadenza sull'intero download pari al
            // residuo (mai oltre 30 s): superata, la foto si salta. Entra nel timeout di GeneratePrintPdfJob.
            'total_timeout' => 60,
        ],

        // Dimensioni (mm) della mappa nella scheda di sentiero e POI: la richiesta al servizio mappe
        // e la view le usano insieme, così uno shard che la vuole grande la chiede grande.
        'map' => [
            'width_mm' => 210,
            'height_mm' => 297,
            // Zoom massimo della mappa della scheda di un POI: l'inquadratura di un punto arriva sempre allo
            // zoom massimo, e con il 16 del servizio il POI resta piccolo in mezzo al territorio
            // (circa 1:21.000 su un A4). 18 è circa 1:5.400. Null: `map_service.max_zoom`.
            'poi_max_zoom' => 18,
            // Margine attorno alla traccia nella mappa della scheda, in mm di carta: 42 la tiene fuori
            // dal riquadro di logo e titolo della copertina del template di default. Null: quello di
            // `map_service.padding_mm`.
            'padding_mm' => 42,
        ],

        // Massimo di tracce in uno zip.
        'zip' => [
            'max_tracks' => 50,
            // Attesa massima dello ZIP per le schede da generare, in secondi.
            'max_wait_seconds' => 1800,
        ],

        // Scala massima della mappa di insieme.
        'overview_map' => [
            'max_scale' => 50000,
        ],

        // Versione delle dipendenze di compilazione attesa dal marcatore:
        // contenuto di docker/render/VERSION se il file esiste, altrimenti null.
        'deps_version' => file_exists(__DIR__.'/../docker/render/VERSION')
            ? trim((string) file_get_contents(__DIR__.'/../docker/render/VERSION'))
            : null,
    ],

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

            // Il canale API del dominio. Spento di default: chi accende il
            // Catasto per lavorarci solo da Nova non deve subire le regole di
            // un canale che non usa.
            'api' => [
                // Il riferimento della pratica presso il sistema esterno
                // (colonna `reference`) e' obbligatorio alla creazione, e due
                // creazioni con lo stesso riferimento non fanno due domande:
                // la seconda ritrova la prima, se porta lo stesso tracciato.
                //
                // Si accende dove un sistema esterno c'e' davvero. Senza un
                // riferimento quel comportamento non avrebbe appiglio, quindi
                // le due cose vanno insieme e non si configurano separate.
                'require_reference' => env('WM_TRAIL_REGISTRY_REQUIRE_REFERENCE', false),
            ],

            // Comandi artisan del dominio: registrati solo a dominio acceso.
            // Le risorse Nova del Catasto le registra lo shard (come EcTrack):
            // il package non ha piu' una chiave `nova_resources` qui. Le tre
            // Resource base restano in src/TrailRegistry/Nova, estendibili
            // ma mai registrate da nessuno se lo shard non le monta.
            'commands' => [
                TrailRegistryNormalizeCommand::class,
            ],

            // Classi dei modelli del dominio. Uno shard le sostituisce con una propria
            // sottoclasse impostando la chiave (es. in AppServiceProvider::register()):
            // relazioni, service, comandi e Resource leggono da qui. Vedi
            // docs/howto/attivare-catasto-sentieri.md.
            'models' => [
                'code' => TrailRegistryCode::class,
                'event' => TrailRegistryCodeEvent::class,
                'application' => TrailApplication::class,
                'anomaly' => TrailRegistryAnomaly::class,
            ],

            // Tipi di anomalia dichiarati da uno shard, accanto a quelli
            // fissi del catasto (TrailRegistryAnomalyType): chiave => classe
            // che implementa AnomalyTypeDefinition. Vuota di default, il
            // package non ne conosce nessuno.
            'anomaly_types' => [],

            // Tipi di **incongruenza catastale** dichiarati da uno shard
            // (oc:8989): chiave => classe che implementa
            // InconsistencyTypeDefinition. Vuota di default, e il package non
            // ne ha di propri. Non sono anomalie: un'anomalia riguarda il
            // codice e si corregge alla fonte, un'incongruenza e' due dati
            // pubblicati che insieme non stanno in piedi e si risolve qui.
            'inconsistency_types' => [],

            // Quale famiglia di tassonomia e' la percorribilita' (oc:8989).
            // Il default funziona: chi accende il dominio e migra se la ritrova
            // creata, accesa per le app e pronta. Uno shard che vuole un'altra
            // scala cambia questo nome **prima** di migrare, e i termini
            // nascono con il suo prefisso.
            'walkability_taxonomy' => env('WM_TRAIL_WALKABILITY_TAXONOMY', 'walkability-status'),

            // Quale famiglia di tassonomia marca l'attivita' primaria di un
            // sentiero (oc:9038). Stesso criterio della percorribilita': il
            // default funziona, e uno shard che vuole un altro nome lo imposta
            // **prima** di migrare.
            'primary_activity_taxonomy' => env('WM_TRAIL_PRIMARY_ACTIVITY_TAXONOMY', 'primary-activity'),

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
            //
            // `trail_application` e' diverso: la Resource delle istanze e'
            // del dominio, e il suo uriKey() e' fisso nella classe base
            // (`trail-applications`) ed ereditato dalla sottoclasse dello
            // shard. Questo valore deve coincidere con quello: cambiarlo da
            // solo rompe i link della mappa, non sposta la Resource.
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
