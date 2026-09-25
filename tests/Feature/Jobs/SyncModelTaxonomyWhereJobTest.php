<?php

namespace Wm\WmPackage\Tests\Feature\Jobs;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;
use Wm\WmPackage\Http\Clients\OsmfeaturesClient;
use Wm\WmPackage\Jobs\TaxonomyWhere\SyncModelTaxonomyWhereJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\TaxonomyWhere;
use Wm\WmPackage\Services\GeometryComputationService;

class SyncModelTaxonomyWhereJobTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Crea una TaxonomyWhere con geometria poligonale nota (bbox Corsica), identifier
     * randomizzato per non collidere con dati QA reali del DB di sviluppo condiviso.
     */
    private function createCorsicaTaxonomyWhere(): TaxonomyWhere
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

        return $taxonomyWhere->fresh();
    }

    public function test_populates_taxonomy_where_on_the_single_ec_poi_passed_to_the_job(): void
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

        $poi = EcPoi::find($poiId);

        (new SyncModelTaxonomyWhereJob($poi))->handle(
            app(GeometryComputationService::class),
            app(OsmfeaturesClient::class)
        );

        $this->assertNotEmpty($poi->fresh()->properties['taxonomy_where'] ?? []);
    }

    public function test_falls_back_to_osmfeatures_when_the_local_sync_finds_no_taxonomy_where(): void
    {
        Http::fake([
            '*/api/v1/features/admin-areas/geojson' => Http::response([
                'features' => [
                    [
                        'properties' => [
                            'osmfeatures_id' => 'R617447',
                            'osm_tags' => [
                                'name' => 'Toscana',
                                'name:it' => 'Toscana',
                                'name:en' => 'Tuscany',
                                'admin_level' => '4',
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $app = App::factory()->create();

        // Golfo di Guinea: nessuna TaxonomyWhere locale lo copre (vedi nota nel test gemello
        // del service), il ramo locale di syncTaxonomyWhere() non trova nulla e deve scattare
        // il fallback via OSMFeatures.
        $poiId = DB::table('ec_pois')->insertGetId([
            'name' => json_encode(['it' => 'Poi senza copertura locale']),
            'app_id' => $app->id,
            'user_id' => $app->user_id,
            'geometry' => DB::raw("ST_GeomFromGeoJSON('{\"type\":\"Point\",\"coordinates\":[0.0,0.0,0]}')"),
            'properties' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $poi = EcPoi::find($poiId);

        (new SyncModelTaxonomyWhereJob($poi))->handle(
            app(GeometryComputationService::class),
            app(OsmfeaturesClient::class)
        );

        $taxonomyWhere = $poi->fresh()->properties['taxonomy_where'] ?? [];
        $this->assertArrayHasKey('R617447', $taxonomyWhere);
        $this->assertEquals(
            ['it' => 'Toscana', 'en' => 'Tuscany', '_admin_level' => 4, '_source' => SyncModelTaxonomyWhereJob::SOURCE_OSMFEATURES],
            $taxonomyWhere['R617447']
        );
    }

    public function test_does_not_call_osmfeatures_when_the_local_sync_already_found_a_taxonomy_where(): void
    {
        Http::fake();

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
        $poi = EcPoi::find($poiId);

        (new SyncModelTaxonomyWhereJob($poi))->handle(
            app(GeometryComputationService::class),
            app(OsmfeaturesClient::class)
        );

        $this->assertNotEmpty($poi->fresh()->properties['taxonomy_where'] ?? []);
        Http::assertNothingSent();
    }

    public function test_does_not_crash_when_the_model_has_no_geojson(): void
    {
        Http::fake();

        $app = App::factory()->create();

        // Golfo di Guinea: nessuna copertura locale, quindi il ramo locale di
        // syncTaxonomyWhere() non trova nulla e il job arriva al punto in cui
        // chiamerebbe OSMFeatures.
        $poiId = DB::table('ec_pois')->insertGetId([
            'name' => json_encode(['it' => 'Poi senza geojson']),
            'app_id' => $app->id,
            'user_id' => $app->user_id,
            'geometry' => DB::raw("ST_GeomFromGeoJSON('{\"type\":\"Point\",\"coordinates\":[0.0,0.0,0]}')"),
            'properties' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $poi = EcPoi::find($poiId);

        // getGeojson() ritorna null se il modello non ha una geometria valida
        // (GeoJsonService::getModelAsGeojson()) — simulato con una sottoclasse anonima
        // perché la colonna geometry è NOT NULL a schema e non è riproducibile con un
        // insert reale. Una sottoclasse (invece di un mock Mockery) preserva get_class()
        // e getTable(), da cui dipende internamente syncTaxonomyWhere().
        $poiSubclass = new class extends EcPoi
        {
            public function getGeojson(): ?array
            {
                return null;
            }

            // Eloquent deriva il nome tabella dal basename della classe: per una classe
            // anonima produce un nome non valido, va fissato esplicitamente.
            public function getTable()
            {
                return 'ec_pois';
            }
        };
        $poiWithNullGeojson = $poiSubclass->newInstance($poi->getAttributes(), true);

        (new SyncModelTaxonomyWhereJob($poiWithNullGeojson))->handle(
            app(GeometryComputationService::class),
            app(OsmfeaturesClient::class)
        );

        $this->assertEmpty($poi->fresh()->properties['taxonomy_where'] ?? []);
        Http::assertNothingSent();
    }

    public function test_does_not_overwrite_a_taxonomy_where_that_appears_while_the_osmfeatures_call_is_in_flight(): void
    {
        $app = App::factory()->create();

        $poiId = DB::table('ec_pois')->insertGetId([
            'name' => json_encode(['it' => 'Poi con copertura concorrente']),
            'app_id' => $app->id,
            'user_id' => $app->user_id,
            'geometry' => DB::raw("ST_GeomFromGeoJSON('{\"type\":\"Point\",\"coordinates\":[0.0,0.0,0]}')"),
            'properties' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $poi = EcPoi::find($poiId);

        // Simula un altro processo (es. un resync concorrente) che scrive una
        // taxonomy_where reale sulla stessa riga esattamente nella finestra fra la
        // lettura fresh() del job e la scrittura finale del fallback — la chiamata
        // mockata a OSMFeatures è il punto in cui, nella realtà, il job è in attesa
        // della risposta HTTP.
        $concurrentWrite = [
            'R000001' => ['name' => ['it' => 'Scritta da un altro processo'], 'admin_level' => 4, 'source' => 'osmfeatures'],
        ];
        $osmfeaturesClient = Mockery::mock(OsmfeaturesClient::class);
        $osmfeaturesClient->shouldReceive('getWheresByGeojson')
            ->once()
            ->andReturnUsing(function () use ($poiId, $concurrentWrite) {
                DB::statement(
                    "UPDATE ec_pois SET properties = jsonb_set(properties, '{taxonomy_where}', ?::jsonb) WHERE id = ?",
                    [json_encode($concurrentWrite), $poiId]
                );

                return ['R999999' => ['it' => 'Dato OSM stantio', '_admin_level' => 4]];
            });

        (new SyncModelTaxonomyWhereJob($poi))->handle(
            app(GeometryComputationService::class),
            $osmfeaturesClient
        );

        $taxonomyWhere = $poi->fresh()->properties['taxonomy_where'] ?? [];
        $this->assertArrayHasKey('R000001', $taxonomyWhere);
        $this->assertArrayNotHasKey('R999999', $taxonomyWhere);
    }

    public function test_leaves_taxonomy_where_empty_when_nothing_is_found_locally_nor_via_osmfeatures(): void
    {
        Http::fake([
            '*/api/v1/features/admin-areas/geojson' => Http::response(['features' => []], 200),
        ]);

        $app = App::factory()->create();

        // Golfo di Guinea: nessuna copertura locale, e la risposta OSMFeatures mockata
        // sopra non trova nulla nemmeno lì — è il record al suo primo sync, mai avuto
        // un valore precedente da preservare.
        $poiId = DB::table('ec_pois')->insertGetId([
            'name' => json_encode(['it' => 'Poi senza copertura da nessuna parte']),
            'app_id' => $app->id,
            'user_id' => $app->user_id,
            'geometry' => DB::raw("ST_GeomFromGeoJSON('{\"type\":\"Point\",\"coordinates\":[0.0,0.0,0]}')"),
            'properties' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $poi = EcPoi::find($poiId);

        (new SyncModelTaxonomyWhereJob($poi))->handle(
            app(GeometryComputationService::class),
            app(OsmfeaturesClient::class)
        );

        $this->assertEmpty($poi->fresh()->properties['taxonomy_where'] ?? []);
    }

    public function test_clears_a_previously_populated_taxonomy_where_when_a_recalculation_finds_nothing_anywhere(): void
    {
        Http::fake([
            '*/api/v1/features/admin-areas/geojson' => Http::response(['features' => []], 200),
        ]);

        $app = App::factory()->create();

        // Il POI aveva già una taxonomy_where (es. da un giro precedente, o da un match
        // locale ormai non più valido). Un aggiornamento fa ripartire la chain: se il
        // ricalcolo non trova nulla né in locale né via API, il campo va azzerato — non
        // preservato a prescindere (decisione esplicita del developer, oc:8487).
        $poiId = DB::table('ec_pois')->insertGetId([
            'name' => json_encode(['it' => 'Poi con where ormai stantia']),
            'app_id' => $app->id,
            'user_id' => $app->user_id,
            'geometry' => DB::raw("ST_GeomFromGeoJSON('{\"type\":\"Point\",\"coordinates\":[0.0,0.0,0]}')"),
            'properties' => json_encode([
                'taxonomy_where' => [
                    'R999999' => ['name' => ['it' => 'Regione Stantia'], 'admin_level' => 4, 'source' => 'osmfeatures'],
                ],
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $poi = EcPoi::find($poiId);

        (new SyncModelTaxonomyWhereJob($poi))->handle(
            app(GeometryComputationService::class),
            app(OsmfeaturesClient::class)
        );

        $this->assertEmpty($poi->fresh()->properties['taxonomy_where'] ?? []);
    }

    public function test_discards_an_osmfeatures_where_with_no_name_translation(): void
    {
        Http::fake([
            '*/api/v1/features/admin-areas/geojson' => Http::response([
                'features' => [
                    // Ha admin_level ma nessun tag name/name:xx — OsmfeaturesClient::getWheresByGeojson()
                    // produce per questa un'entry con solo `_admin_level`, senza traduzioni del nome.
                    [
                        'properties' => [
                            'osmfeatures_id' => 'R000002',
                            'osm_tags' => [
                                'admin_level' => '8',
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $app = App::factory()->create();

        $poiId = DB::table('ec_pois')->insertGetId([
            'name' => json_encode(['it' => 'Poi con feature OSM senza nome']),
            'app_id' => $app->id,
            'user_id' => $app->user_id,
            'geometry' => DB::raw("ST_GeomFromGeoJSON('{\"type\":\"Point\",\"coordinates\":[0.0,0.0,0]}')"),
            'properties' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $poi = EcPoi::find($poiId);

        (new SyncModelTaxonomyWhereJob($poi))->handle(
            app(GeometryComputationService::class),
            app(OsmfeaturesClient::class)
        );

        $taxonomyWhere = $poi->fresh()->properties['taxonomy_where'] ?? [];
        $this->assertArrayNotHasKey('R000002', $taxonomyWhere);
        $this->assertEmpty($taxonomyWhere);
    }
}
