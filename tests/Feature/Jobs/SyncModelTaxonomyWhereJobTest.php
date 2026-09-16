<?php

namespace Wm\WmPackage\Tests\Feature\Jobs;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Wm\WmPackage\Jobs\TaxonomyWhere\SyncModelTaxonomyWhereJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\TaxonomyWhere;

class SyncModelTaxonomyWhereJobTest extends TestCase
{
    use DatabaseTransactions;

    public function test_populates_taxonomy_where_on_the_single_ec_poi_passed_to_the_job(): void
    {
        $taxonomyWhere = new TaxonomyWhere([
            'name' => 'Corsica',
            'properties' => ['source' => 'geohub', 'admin_level' => 4],
        ]);
        $taxonomyWhere->identifier = 'corsica-'.Str::lower(Str::random(8));
        $taxonomyWhere->save();

        DB::statement(
            'UPDATE taxonomy_wheres SET geometry = ST_GeomFromGeoJSON(?) WHERE id = ?',
            ['{"type":"Polygon","coordinates":[[[8.53,41.33],[9.56,41.33],[9.56,43.03],[8.53,43.03],[8.53,41.33]]]}', $taxonomyWhere->id]
        );

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

        $poi = EcPoi::find($poiId);

        (new SyncModelTaxonomyWhereJob($poi))->handle(app(\Wm\WmPackage\Services\GeometryComputationService::class));

        $this->assertNotEmpty($poi->fresh()->properties['taxonomy_where'] ?? []);
    }
}

