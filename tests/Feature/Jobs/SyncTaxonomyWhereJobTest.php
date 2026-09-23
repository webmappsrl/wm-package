<?php

namespace Wm\WmPackage\Tests\Feature\Jobs;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Wm\WmPackage\Jobs\TaxonomyWhere\SyncTaxonomyWhereJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\TaxonomyWhere;

class SyncTaxonomyWhereJobTest extends TestCase
{
    use DatabaseTransactions;

    private function createCorsicaTaxonomyWhere(): TaxonomyWhere
    {
        $identifier = 'corsica-'.Str::lower(Str::random(8));

        $taxonomyWhere = new TaxonomyWhere([
            'name' => 'Corsica',
            'properties' => ['source' => 'geohub'],
        ]);
        $taxonomyWhere->identifier = $identifier;
        $taxonomyWhere->save();

        DB::statement(
            'UPDATE taxonomy_wheres SET geometry = ST_GeomFromGeoJSON(?) WHERE id = ?',
            ['{"type":"Polygon","coordinates":[[[8.53,41.33],[9.56,41.33],[9.56,43.03],[8.53,43.03],[8.53,41.33]]]}', $taxonomyWhere->id]
        );

        return $taxonomyWhere;
    }

    public function test_populates_taxonomy_where_on_intersecting_tracks(): void
    {
        $this->createCorsicaTaxonomyWhere();

        $app = App::factory()->create();

        $trackId = DB::table('ec_tracks')->insertGetId([
            'name' => json_encode(['it' => 'Track in Corsica']),
            'app_id' => $app->id,
            'user_id' => $app->user_id,
            'geometry' => DB::raw("ST_GeomFromGeoJSON('{\"type\":\"MultiLineString\",\"coordinates\":[[[9.0,42.0,0],[9.1,42.1,0]]]}')"),
            'properties' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        (new SyncTaxonomyWhereJob)->handle();

        $track = EcTrack::find($trackId);
        $this->assertNotEmpty($track->properties['taxonomy_where'] ?? []);
    }

    public function test_populates_taxonomy_where_on_intersecting_pois(): void
    {
        $this->createCorsicaTaxonomyWhere();

        $app = App::factory()->create();

        $poiId = DB::table('ec_pois')->insertGetId([
            'name' => json_encode(['it' => 'Poi in Corsica']),
            'app_id' => $app->id,
            'user_id' => $app->user_id,
            'geometry' => DB::raw("ST_GeomFromGeoJSON('{\"type\":\"Point\",\"coordinates\":[9.05,42.05,0]}')"),
            'properties' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        (new SyncTaxonomyWhereJob)->handle();

        $poi = EcPoi::find($poiId);
        $this->assertNotEmpty($poi->properties['taxonomy_where'] ?? []);
    }
}
