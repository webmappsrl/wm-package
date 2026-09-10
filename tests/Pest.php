<?php

use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Tests\TestCase;
use Wm\WmPackage\TrailRegistry\Enums\TrailCodeStatus;

uses(TestCase::class)->in(__DIR__);

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

    if ($status === TrailCodeStatus::Reserved->value && ! array_key_exists('trail_application_id', $attributes)) {
        $attributes['trail_application_id'] = DB::table('trail_applications')->insertGetId([
            'user_id' => makeTrailRegistryTestUser(),
            'source' => 'office',
            'status' => 'under_review',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    if ($status === TrailCodeStatus::Assigned->value && ! array_key_exists('ec_track_id', $attributes)) {
        // geometry e' NOT NULL senza default (create_ec_tracks_table.php.stub):
        // va valorizzata nello stesso INSERT, un insertGetId() seguito da un
        // UPDATE separato viola il vincolo prima di arrivare all'UPDATE.
        // App::factory()->create() (non ->createQuietly()) fa scattare
        // AppObserver::saved(), che tenta di scrivere la config su storage e
        // fallisce in questo ambiente di test (shard_name non configurato) —
        // un side-effect indesiderato per un semplice detentore di test.
        $appId = DB::table('apps')->value('id') ?? App::factory()->createQuietly()->id;

        $attributes['ec_track_id'] = DB::selectOne(<<<'SQL'
            INSERT INTO ec_tracks (properties, name, app_id, geometry, created_at, updated_at)
            VALUES (:properties::jsonb, :name, :app_id, ST_GeomFromText(:wkt, 4326)::geography, now(), now())
            RETURNING id
        SQL, [
            'properties' => json_encode([]),
            'name' => 'Trail Registry Test Track',
            'app_id' => $appId,
            'wkt' => 'LINESTRING Z (9 40 0, 9.01 40.01 0)',
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
