<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Tests\TestCase;
use Wm\WmPackage\TrailRegistry\Enums\TrailCodeStatus;
use Wm\WmPackage\TrailRegistry\Jobs\UpdateTrailApplicationDemJob;

uses(TestCase::class)->in(__DIR__);

// Ogni istanza creata accoda il calcolo DEM (oc:8571): nei test del catasto il
// job e' finto per default, cosi' nessun test esce verso il servizio DEM. Chi
// vuole il job vero lo esegue a mano con Http::fake().
uses()->beforeEach(function () {
    // UpdateTrailApplicationDemJob usa uniqueVia() su Redis (oc:8564): senza
    // isolarlo qui, anche un test che non dispatcha mai il job (perche' e'
    // fake) dipenderebbe da un Redis reale solo per l'acquisizione del lock.
    config(['cache.stores.redis.driver' => 'array']);

    Bus::fake([
        UpdateTrailApplicationDemJob::class,
    ]);
})->in('Feature/TrailRegistry');

/**
 * Crea un settore (riga taxonomy_wheres) con una geometria poligonale reale,
 * usata dai test del catasto sentieri (trail_registry) come prefisso da cui
 * deriva un trail_registry_codes.taxonomy_where_id.
 */
function makeSector(string $fullCode, string $polygonWkt, string $source = 'osm2cai'): int
{
    $properties = json_encode(['full_code' => $fullCode, 'source' => $source]);

    return DB::selectOne(<<<'SQL'
        INSERT INTO taxonomy_wheres (name, properties, geometry, created_at, updated_at)
        VALUES (:name, :properties::jsonb, ST_GeomFromText(:wkt, 4326)::geography, now(), now())
        RETURNING id
    SQL, [
        'name' => $fullCode,
        'properties' => $properties,
        'wkt' => $polygonWkt,
    ])->id;
}

/**
 * Crea un utente minimo per i test del catasto sentieri (owner di
 * trail_applications / autore di un trail_registry_code_events).
 */
function makeTrailRegistryTestUser(): int
{
    return DB::table('users')->insertGetId([
        'name' => 'Trail Registry Test User',
        'email' => 'trail-registry-'.uniqid().'@example.test',
        'password' => bcrypt('password'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * Crea una riga trail_registry_codes scrivendo direttamente con il query
 * builder (i modelli Eloquent del dominio non esistono ancora, arrivano col
 * Task 4). Quando lo status e' attivo (TrailCodeStatus::active()) crea anche
 * l'istanza/traccia richieste dal CHECK trail_registry_codes_holder_check —
 * altrimenti l'insert violerebbe il vincolo che il Task 2 stesso introduce:
 * 'reserved' richiede trail_application_id, 'assigned' richiede ec_track_id.
 */
function makeCode(array $attributes = []): int
{
    $status = $attributes['status'] ?? TrailCodeStatus::Reserved->value;
    $status = $status instanceof TrailCodeStatus ? $status->value : $status;

    // Geometria del detentore, per i test che misurano distanze: senza questa
    // via ogni codice nasce con la stessa geometria fissa e ogni distanza
    // risulta uguale, rendendo non verificabile qualunque ordinamento per
    // vicinanza (oc:8570). Il default esiste perché in produzione un'istanza
    // ce l'ha sempre; un Reserved senza geometria esplicita null verrebbe
    // escluso in silenzio dal calcolo COALESCE(t.geometry, a.geometry) IS NOT NULL.
    // geometry_wkt assente → usa il default (situazione di produzione);
    // geometry_wkt => null esplicito → crea il detentore senza geometria (caso degenere, deliberato).
    $geometryWkt = array_key_exists('geometry_wkt', $attributes)
        ? $attributes['geometry_wkt']
        : 'LINESTRING Z (9 40 0, 9.01 40.01 0)';
    unset($attributes['geometry_wkt']);

    if ($status === TrailCodeStatus::Reserved->value && ! array_key_exists('trail_application_id', $attributes)) {
        if ($geometryWkt === null) {
            // Caso degenere: istanza senza geometria, deliberatamente richiesto
            $attributes['trail_application_id'] = DB::table('trail_applications')->insertGetId([
                'user_id' => makeTrailRegistryTestUser(),
                'source' => 'office',
                'status' => 'under_review',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            // Caso normale: inserisci con geometria (default o esplicita)
            // Attenzione: questa conversione da LINESTRING a MULTILINESTRING accetta
            // solo un LINESTRING Z — usa str_replace su '(' e ')' e non un parser WKT,
            // quindi passare qui un WKT già MULTILINESTRING (o con parentesi annidate)
            // produce una stringa rovinata e un errore PostGIS poco leggibile.
            $attributes['trail_application_id'] = DB::selectOne(<<<'SQL'
                INSERT INTO trail_applications (user_id, source, status, geometry, created_at, updated_at)
                VALUES (:user_id, 'office', 'under_review', ST_GeomFromText(:wkt, 4326)::geography, now(), now())
                RETURNING id
            SQL, [
                'user_id' => makeTrailRegistryTestUser(),
                'wkt' => 'MULTILINESTRING Z (('.trim(str_replace(['LINESTRING Z (', ')'], '', $geometryWkt)).'))',
            ])->id;
        }
    }

    if ($status === TrailCodeStatus::Assigned->value && ! array_key_exists('ec_track_id', $attributes)) {
        // geometry e' NOT NULL senza default (create_ec_tracks_table.php.stub):
        // va valorizzata nello stesso INSERT, un insertGetId() seguito da un
        // UPDATE separato viola il vincolo prima di arrivare all'UPDATE.
        // App::factory()->create() (non ->createQuietly()) fa scattare
        // AppObserver::saved(), che tenta di scrivere la config su storage e
        // fallisce in questo ambiente di test (shard_name non configurato) —
        // un side-effect indesiderato per un semplice detentore di test.
        if ($geometryWkt === null) {
            throw new InvalidArgumentException(
                'geometry_wkt non può essere null per uno stato Assigned: '.
                'la traccia richiede una geometria (ec_tracks.geometry è NOT NULL)'
            );
        }

        $appId = DB::table('apps')->value('id') ?? App::factory()->createQuietly()->id;

        $attributes['ec_track_id'] = DB::selectOne(<<<'SQL'
            INSERT INTO ec_tracks (properties, name, app_id, geometry, created_at, updated_at)
            VALUES (:properties::jsonb, :name, :app_id, ST_GeomFromText(:wkt, 4326)::geography, now(), now())
            RETURNING id
        SQL, [
            'properties' => json_encode([]),
            'name' => 'Trail Registry Test Track',
            'app_id' => $appId,
            'wkt' => $geometryWkt,
        ])->id;
    }

    $defaults = [
        'region' => 'Z',
        'province' => 'NU',
        'area' => 'B',
        'sector' => '5',
        'number' => 35,
        'variant' => '0',
        'status' => $status,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    return DB::table('trail_registry_codes')->insertGetId(array_merge($defaults, $attributes));
}

/**
 * Scrive direttamente su apps.config_home (colonna text) una HOME con un box "layer" per
 * ciascun id passato, bypassando Nova/il resolver — usata dai test di oc:8488 sul remap
 * degli id layer (ImportAppJobFinalizeTest, UpdateAppConfigHomeLayerIdsJobTest) per
 * costruire lo stato "prima" senza passare dal form.
 */
function setConfigHome(App $app, array $layerIds): void
{
    $home = array_map(
        static fn (int $id) => ['box_type' => 'layer', 'layer' => $id, 'title' => ['it' => 'x']],
        $layerIds
    );

    DB::table('apps')->where('id', $app->id)->update(['config_home' => json_encode(['HOME' => $home])]);
}

/**
 * Simmetrico a setConfigHome(): legge gli id layer scritti in HOME per verificare l'esito
 * del remap.
 */
function homeLayerIds(App $app): array
{
    $raw = DB::table('apps')->where('id', $app->id)->value('config_home');

    return array_column(json_decode($raw, true)['HOME'], 'layer');
}

/**
 * Applica tutti e quattro gli stub del dominio trail_registry: hanno
 * estensione .php.stub e non sono raccolti da `migrate`, quindi vanno
 * inclusi ed eseguiti esplicitamente. Condivisa fra tutti i test del
 * dominio (schema, modelli, e i task successivi) per non duplicarla.
 */
function runTrailRegistryStubs(): void
{
    $stubs = [
        'zz_2026_09_09_000001_create_trail_applications_table.php.stub',
        'zz_2026_09_09_000002_create_trail_registry_codes_table.php.stub',
        'zz_2026_09_09_000003_create_trail_registry_code_events_table.php.stub',
        'zz_2026_09_09_000004_add_gist_index_to_taxonomy_wheres.php.stub',
        'zz_2026_09_10_000001_create_trail_registry_anomalies_table.php.stub',
    ];

    foreach ($stubs as $stub) {
        $migration = require __DIR__."/../database/migrations/trail_registry/{$stub}";
        $migration->up();
    }
}
