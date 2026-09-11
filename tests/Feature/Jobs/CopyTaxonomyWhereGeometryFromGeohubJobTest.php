<?php

namespace Wm\WmPackage\Tests\Feature\Jobs;

require_once __DIR__.'/../../Concerns/SharesGeohubConnectionWithLocal.php';

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;
use Wm\WmPackage\Jobs\TaxonomyWhere\CopyTaxonomyWhereGeometryFromGeohubJob;
use Wm\WmPackage\Models\TaxonomyWhere;
use Wm\WmPackage\Tests\Concerns\SharesGeohubConnectionWithLocal;

class CopyTaxonomyWhereGeometryFromGeohubJobTest extends TestCase
{
    use DatabaseTransactions, SharesGeohubConnectionWithLocal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shareGeohubConnectionWithLocal();
    }

    public function test_copies_geometry_from_geohub_to_local_taxonomy_where(): void
    {
        // Identifier dinamico: il DB di sviluppo condiviso contiene dati QA
        // reali con identifier fissi ('corsica' incluso) — un valore letterale
        // collide con l'indice unique. Stesso pattern usato altrove nel piano
        // oc:8486 (Task 1/2 e Task 3).
        $identifier = 'corsica-'.Str::lower(Str::random(8));

        $geohubRowId = DB::connection('geohub')->table('taxonomy_wheres')->insertGetId([
            'name' => json_encode(['it' => 'Corsica', 'en' => 'Corsica']),
            'identifier' => $identifier,
            'properties' => json_encode(['source' => 'geohub']),
            'geometry' => DB::raw("ST_GeomFromGeoJSON('{\"type\":\"Polygon\",\"coordinates\":[[[8.53,41.33],[9.56,41.33],[9.56,43.03],[8.53,43.03],[8.53,41.33]]]}')"),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $taxonomyWhere = TaxonomyWhere::create([
            'name' => 'Corsica',
            'properties' => ['source' => 'geohub', 'geohub_id' => $geohubRowId],
        ]);

        (new CopyTaxonomyWhereGeometryFromGeohubJob($taxonomyWhere->id, $geohubRowId))->handle();

        $geometry = DB::selectOne(
            'SELECT ST_AsGeoJSON(geometry) as geojson FROM taxonomy_wheres WHERE id = ?',
            [$taxonomyWhere->id]
        );

        $this->assertNotNull($geometry->geojson);
        $decoded = json_decode($geometry->geojson, true);
        // La colonna taxonomy_wheres.geometry e' geography(multipolygon,4326)
        // (vedi migration create_taxonomy_wheres_table): il typmod promuove
        // automaticamente un Polygon a MultiPolygon sia sulla connessione
        // geohub (stesso schema) sia su quella locale in scrittura.
        $this->assertSame('MultiPolygon', $decoded['type']);
    }

    public function test_logs_warning_and_does_not_throw_when_geohub_geometry_is_missing(): void
    {
        Log::spy();

        $geohubRowId = DB::connection('geohub')->table('taxonomy_wheres')->insertGetId([
            'name' => json_encode(['it' => 'Europe']),
            'identifier' => 'europe',
            'properties' => json_encode(['source' => 'geohub']),
            'geometry' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $taxonomyWhere = TaxonomyWhere::create([
            'name' => 'Europe',
            'properties' => ['source' => 'geohub', 'geohub_id' => $geohubRowId],
        ]);

        (new CopyTaxonomyWhereGeometryFromGeohubJob($taxonomyWhere->id, $geohubRowId))->handle();

        Log::shouldHaveReceived('warning')->once();
        $this->assertNull($taxonomyWhere->fresh()->geometry);
    }
}
