<?php

namespace Wm\WmPackage\Tests\Concerns;

use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Models\App;

/**
 * Boilerplate INSERT condiviso da piu' test TaxonomyWhere per creare un EcPoi/EcTrack con
 * geometria PostGIS via query builder, bypassando Eloquent (oc:8588, review): estratto per
 * evitare le quattro copie quasi identiche che c'erano sparse tra i file di
 * tests/Feature/TaxonomyWhere/ (poiWithWheres, resyncPoi, trackForSearch, legacyPoi).
 *
 * Restano funzioni globali Pest distinte per file (stesso nome in piu' file confliggerebbe): qui
 * ci sono solo i due inserimenti di riga grezza, richiamati da quelle funzioni tramite test(),
 * l'helper Pest che risolve l'istanza di TestCase corrente anche fuori da una closure it()/test().
 */
trait InsertsGeometryFixtures
{
    protected function insertEcPoiWithGeometry(App $app, array $properties, string $pointGeojson = '{"type":"Point","coordinates":[13.7,41.4,0]}'): int
    {
        return DB::table('ec_pois')->insertGetId([
            'name' => json_encode(['it' => 'Poi']),
            'app_id' => $app->id,
            'user_id' => $app->user_id,
            'geometry' => DB::raw("ST_GeomFromGeoJSON('{$pointGeojson}')"),
            'properties' => json_encode($properties),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function insertEcTrackWithGeometry(App $app, array $properties, string $lineGeojson = '{"type":"MultiLineString","coordinates":[[[13.7,41.4,0],[13.8,41.5,0]]]}'): int
    {
        return DB::table('ec_tracks')->insertGetId([
            'name' => json_encode(['it' => 'Tappa']),
            'app_id' => $app->id,
            'user_id' => $app->user_id,
            'geometry' => DB::raw("ST_GeomFromGeoJSON('{$lineGeojson}')"),
            'properties' => json_encode($properties),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
