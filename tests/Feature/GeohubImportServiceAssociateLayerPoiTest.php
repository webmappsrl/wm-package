<?php

namespace Wm\WmPackage\Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\Import\GeohubImportService;

class GeohubImportServiceAssociateLayerPoiTest extends TestCase
{
    use DatabaseTransactions;

    private GeohubImportService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $default = config('database.default');
        config(['database.connections.geohub' => config("database.connections.{$default}")]);
        DB::purge('geohub');

        // DatabaseTransactions wraps only the default connection in a transaction;
        // sharing the same PDO ensures the geohub connection sees uncommitted test data.
        $defaultConn = DB::connection($default);
        $geohubConn = DB::connection('geohub');
        $geohubConn->setPdo($defaultConn->getPdo());
        $geohubConn->setReadPdo($defaultConn->getReadPdo());

        // Prevent UpdateLayerGeometryJob from running synchronously inside the test
        // transaction. The sync queue's transaction management conflicts with
        // DatabaseTransactions when using a shared PDO.
        Queue::fake();

        $this->service = app(GeohubImportService::class);
    }

    public function test_ec_pois_with_shared_taxonomy_poi_type_are_attached_to_layer(): void
    {
        $geohubLayerId = 999;
        $geohubPoiId = 888;

        $layer = Layer::factory()->createQuietly([
            'properties' => ['geohub_id' => $geohubLayerId],
        ]);

        $poi = EcPoi::factory()->createQuietly([
            'properties' => ['geohub_id' => $geohubPoiId],
        ]);

        $taxonomyPoiTypeId = DB::table('taxonomy_poi_types')->insertGetId([
            'name' => json_encode(['it' => 'Test POI Type']),
            'identifier' => 'test-poi-type-'.uniqid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Layer → TaxonomyPoiType (simula GeoHub taxonomy_poi_typeables)
        DB::table('taxonomy_poi_typeables')->insert([
            'taxonomy_poi_type_id' => $taxonomyPoiTypeId,
            'taxonomy_poi_typeable_type' => 'App\\Models\\Layer',
            'taxonomy_poi_typeable_id' => $geohubLayerId,
        ]);

        // EcPoi → TaxonomyPoiType (simula GeoHub taxonomy_poi_typeables)
        DB::table('taxonomy_poi_typeables')->insert([
            'taxonomy_poi_type_id' => $taxonomyPoiTypeId,
            'taxonomy_poi_typeable_type' => 'App\\Models\\EcPoi',
            'taxonomy_poi_typeable_id' => $geohubPoiId,
        ]);

        $this->service->associateLayersWithEcPoi($layer);

        $this->assertTrue(
            $layer->ecPois()->where('ec_pois.id', $poi->id)->exists(),
            'Il POI con lo stesso taxonomy_poi_type del layer deve essere associato'
        );
    }

    public function test_layer_without_taxonomy_poi_types_is_skipped_without_exception(): void
    {
        $layer = Layer::factory()->createQuietly([
            'properties' => ['geohub_id' => 777],
        ]);

        // Nessuna riga in taxonomy_poi_typeables per questo layer
        $this->service->associateLayersWithEcPoi($layer);

        $this->assertCount(0, $layer->ecPois);
    }

    public function test_ec_poi_not_imported_locally_is_skipped_without_exception(): void
    {
        $geohubLayerId = 666;
        $geohubPoiIdNotImported = 555;

        $layer = Layer::factory()->createQuietly([
            'properties' => ['geohub_id' => $geohubLayerId],
        ]);

        $taxonomyPoiTypeId = DB::table('taxonomy_poi_types')->insertGetId([
            'name' => json_encode(['it' => 'Test POI Type']),
            'identifier' => 'test-poi-type-'.uniqid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('taxonomy_poi_typeables')->insert([
            'taxonomy_poi_type_id' => $taxonomyPoiTypeId,
            'taxonomy_poi_typeable_type' => 'App\\Models\\Layer',
            'taxonomy_poi_typeable_id' => $geohubLayerId,
        ]);

        // EcPoi GeoHub che non esiste nel DB locale
        DB::table('taxonomy_poi_typeables')->insert([
            'taxonomy_poi_type_id' => $taxonomyPoiTypeId,
            'taxonomy_poi_typeable_type' => 'App\\Models\\EcPoi',
            'taxonomy_poi_typeable_id' => $geohubPoiIdNotImported,
        ]);

        $this->service->associateLayersWithEcPoi($layer);

        $this->assertCount(0, $layer->ecPois);
    }

    public function test_re_import_does_not_duplicate_already_associated_poi(): void
    {
        $geohubLayerId = 444;
        $geohubPoiId = 333;

        $layer = Layer::factory()->createQuietly([
            'properties' => ['geohub_id' => $geohubLayerId],
        ]);

        $poi = EcPoi::factory()->createQuietly([
            'properties' => ['geohub_id' => $geohubPoiId],
        ]);

        $taxonomyPoiTypeId = DB::table('taxonomy_poi_types')->insertGetId([
            'name' => json_encode(['it' => 'Test POI Type']),
            'identifier' => 'test-poi-type-'.uniqid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('taxonomy_poi_typeables')->insert([
            'taxonomy_poi_type_id' => $taxonomyPoiTypeId,
            'taxonomy_poi_typeable_type' => 'App\\Models\\Layer',
            'taxonomy_poi_typeable_id' => $geohubLayerId,
        ]);

        DB::table('taxonomy_poi_typeables')->insert([
            'taxonomy_poi_type_id' => $taxonomyPoiTypeId,
            'taxonomy_poi_typeable_type' => 'App\\Models\\EcPoi',
            'taxonomy_poi_typeable_id' => $geohubPoiId,
        ]);

        // Primo import
        $this->service->associateLayersWithEcPoi($layer);
        // Secondo import (re-import)
        $this->service->associateLayersWithEcPoi($layer);

        $this->assertCount(1, $layer->fresh()->ecPois);
    }

    public function test_ec_pois_with_shared_taxonomy_theme_are_attached_to_layer(): void
    {
        $geohubLayerId = 9001;
        $geohubPoiId = 9002;

        $layer = Layer::factory()->createQuietly([
            'properties' => ['geohub_id' => $geohubLayerId],
        ]);

        $poi = EcPoi::factory()->createQuietly([
            'properties' => ['geohub_id' => $geohubPoiId],
        ]);

        $themeId = DB::table('taxonomy_themes')->insertGetId([
            'name' => json_encode(['it' => 'Test Theme']),
            'identifier' => 'test-theme-'.uniqid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('taxonomy_themeables')->insert([
            'taxonomy_theme_id' => $themeId,
            'taxonomy_themeable_type' => 'App\\Models\\Layer',
            'taxonomy_themeable_id' => $geohubLayerId,
        ]);

        DB::table('taxonomy_themeables')->insert([
            'taxonomy_theme_id' => $themeId,
            'taxonomy_themeable_type' => 'App\\Models\\EcPoi',
            'taxonomy_themeable_id' => $geohubPoiId,
        ]);

        $this->service->associateLayersWithEcPoi($layer);

        $this->assertTrue(
            $layer->ecPois()->where('ec_pois.id', $poi->id)->exists(),
            'EcPoi con stesso taxonomy_theme del layer deve essere associato via taxonomy_theme'
        );
    }

    public function test_ec_pois_with_shared_taxonomy_where_are_attached_to_layer(): void
    {
        $geohubLayerId = 9003;
        $geohubPoiId = 9004;

        $layer = Layer::factory()->createQuietly([
            'properties' => ['geohub_id' => $geohubLayerId],
        ]);

        $poi = EcPoi::factory()->createQuietly([
            'properties' => ['geohub_id' => $geohubPoiId],
        ]);

        $whereId = DB::table('taxonomy_wheres')->insertGetId([
            'name' => json_encode(['it' => 'Test Where']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('taxonomy_whereables')->insert([
            'taxonomy_where_id' => $whereId,
            'taxonomy_whereable_type' => 'App\\Models\\Layer',
            'taxonomy_whereable_id' => $geohubLayerId,
        ]);

        DB::table('taxonomy_whereables')->insert([
            'taxonomy_where_id' => $whereId,
            'taxonomy_whereable_type' => 'App\\Models\\EcPoi',
            'taxonomy_whereable_id' => $geohubPoiId,
        ]);

        $this->service->associateLayersWithEcPoi($layer);

        $this->assertTrue(
            $layer->ecPois()->where('ec_pois.id', $poi->id)->exists(),
            'EcPoi con stesso taxonomy_where del layer deve essere associato via taxonomy_where'
        );
    }

    public function test_poi_matched_by_multiple_mechanisms_is_attached_only_once(): void
    {
        $geohubLayerId = 9005;
        $geohubPoiId = 9006;

        $layer = Layer::factory()->createQuietly([
            'properties' => ['geohub_id' => $geohubLayerId],
        ]);

        EcPoi::factory()->createQuietly([
            'properties' => ['geohub_id' => $geohubPoiId],
        ]);

        // Associate via taxonomy_theme
        $themeId = DB::table('taxonomy_themes')->insertGetId([
            'name' => json_encode(['it' => 'Theme Multi']),
            'identifier' => 'test-theme-multi-'.uniqid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('taxonomy_themeables')->insert([
            'taxonomy_theme_id' => $themeId,
            'taxonomy_themeable_type' => 'App\\Models\\Layer',
            'taxonomy_themeable_id' => $geohubLayerId,
        ]);
        DB::table('taxonomy_themeables')->insert([
            'taxonomy_theme_id' => $themeId,
            'taxonomy_themeable_type' => 'App\\Models\\EcPoi',
            'taxonomy_themeable_id' => $geohubPoiId,
        ]);

        // Also associate via taxonomy_poi_type (same POI)
        $poiTypeId = DB::table('taxonomy_poi_types')->insertGetId([
            'name' => json_encode(['it' => 'PoiType Multi']),
            'identifier' => 'test-poi-type-multi-'.uniqid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('taxonomy_poi_typeables')->insert([
            'taxonomy_poi_type_id' => $poiTypeId,
            'taxonomy_poi_typeable_type' => 'App\\Models\\Layer',
            'taxonomy_poi_typeable_id' => $geohubLayerId,
        ]);
        DB::table('taxonomy_poi_typeables')->insert([
            'taxonomy_poi_type_id' => $poiTypeId,
            'taxonomy_poi_typeable_type' => 'App\\Models\\EcPoi',
            'taxonomy_poi_typeable_id' => $geohubPoiId,
        ]);

        $this->service->associateLayersWithEcPoi($layer);

        $this->assertCount(1, $layer->fresh()->ecPois, 'Un POI trovato via più meccanismi deve essere allegato una sola volta');
    }
}
