<?php

namespace Wm\WmPackage\Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Nova\Fields\LayerFeatures\Http\Controllers\LayerFeatureController;
use Wm\WmPackage\Services\Models\LayerService;

class LayerAssignPoisByTaxonomyTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // Evita che i job reali (PBF, ricalcolo geometria) girino in sincrono nei test.
        Queue::fake();
    }

    public function test_layer_is_auto_poi_mode_by_default(): void
    {
        $layer = Layer::factory()->createQuietly();

        $this->assertTrue($layer->isAutoPoiMode());
    }

    public function test_assign_pois_by_taxonomy_attaches_poi_sharing_activity(): void
    {
        $app = App::factory()->createQuietly();
        $poi = EcPoi::factory()->createQuietly(['app_id' => $app->id, 'properties' => []]);

        $layer = Layer::factory()->createQuietly([
            'app_id' => $app->id,
            'configuration' => ['poi_mode' => 'auto'],
        ]);

        $taxonomyId = DB::table('taxonomy_activities')->insertGetId([
            'name' => json_encode(['it' => 'panorama']),
            'description' => null,
            'excerpt' => null,
            'identifier' => 'panorama-'.uniqid(),
            'properties' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('taxonomy_activityables')->insert([
            'taxonomy_activity_id' => $taxonomyId,
            'taxonomy_activityable_type' => 'App\\Models\\Layer',
            'taxonomy_activityable_id' => $layer->id,
            'duration_forward' => 0,
            'duration_backward' => 0,
        ]);

        DB::table('taxonomy_activityables')->insert([
            'taxonomy_activity_id' => $taxonomyId,
            'taxonomy_activityable_type' => 'App\\Models\\EcPoi',
            'taxonomy_activityable_id' => $poi->id,
            'duration_forward' => 0,
            'duration_backward' => 0,
        ]);

        /** @var LayerService $layerService */
        $layerService = app(LayerService::class);
        $layerService->assignPoisByTaxonomy($layer->fresh());

        $this->assertTrue(
            $layer->fresh()->ecPois()->where('ec_pois.id', $poi->id)->exists(),
            'Il POI deve essere sincronizzato quando condivide la taxonomy activity col layer'
        );
    }

    public function test_assign_pois_by_taxonomy_is_noop_when_layer_is_manual(): void
    {
        $app = App::factory()->createQuietly();
        $poi = EcPoi::factory()->createQuietly(['app_id' => $app->id, 'properties' => []]);

        $layer = Layer::factory()->createQuietly([
            'app_id' => $app->id,
            'configuration' => ['poi_mode' => 'manual'],
        ]);

        $layer->ecPois()->attach($poi->id, ['created_at' => now(), 'updated_at' => now()]);

        /** @var LayerService $layerService */
        $layerService = app(LayerService::class);
        $layerService->assignPoisByTaxonomy($layer->fresh());

        $this->assertTrue(
            $layer->fresh()->ecPois()->where('ec_pois.id', $poi->id)->exists(),
            'In poi_mode manuale assignPoisByTaxonomy() non deve toccare la pivot esistente'
        );
    }

    public function test_sync_endpoint_auto_mode_does_not_wipe_manual_poi_associations(): void
    {
        $app = App::factory()->createQuietly([
            'map_bbox' => json_encode([10.39637, 43.71683, 10.52729, 43.84512]),
        ]);
        $poi = EcPoi::factory()->createQuietly(['app_id' => $app->id, 'properties' => []]);

        $layer = Layer::factory()->createQuietly([
            'app_id' => $app->id,
            'configuration' => ['poi_mode' => 'auto'],
        ]);

        $taxonomyId = DB::table('taxonomy_activities')->insertGetId([
            'name' => json_encode(['it' => 'storico']),
            'description' => null,
            'excerpt' => null,
            'identifier' => 'storico-'.uniqid(),
            'properties' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('taxonomy_activityables')->insert([
            'taxonomy_activity_id' => $taxonomyId,
            'taxonomy_activityable_type' => 'App\\Models\\Layer',
            'taxonomy_activityable_id' => $layer->id,
            'duration_forward' => 0,
            'duration_backward' => 0,
        ]);

        DB::table('taxonomy_activityables')->insert([
            'taxonomy_activity_id' => $taxonomyId,
            'taxonomy_activityable_type' => 'App\\Models\\EcPoi',
            'taxonomy_activityable_id' => $poi->id,
            'duration_forward' => 0,
            'duration_backward' => 0,
        ]);

        $request = Request::create('/nova-vendor/layer-features/sync/'.$layer->id, 'POST', [
            'features' => [],
            'model' => EcPoi::class,
            'auto' => true,
        ]);

        $controller = app(LayerFeatureController::class);
        $response = $controller->sync($request, $layer->id);

        $this->assertSame(200, $response->getStatusCode());

        // Prima del fix, il branch "auto" per ecPois cadeva nell'else e faceva sync([]),
        // svuotando la pivot. Con il fix, il POI condiviso via taxonomy resta associato.
        $this->assertTrue(
            $layer->fresh()->ecPois()->where('ec_pois.id', $poi->id)->exists(),
            'Il toggle automatico su ecPois non deve svuotare la pivot ma ricalcolarla via taxonomy'
        );
    }

    public function test_sync_endpoint_manual_mode_syncs_exact_ids_for_pois(): void
    {
        $app = App::factory()->createQuietly([
            'map_bbox' => json_encode([10.39637, 43.71683, 10.52729, 43.84512]),
        ]);
        $poiToKeep = EcPoi::factory()->createQuietly(['app_id' => $app->id, 'properties' => []]);
        $poiToDrop = EcPoi::factory()->createQuietly(['app_id' => $app->id, 'properties' => []]);

        $layer = Layer::factory()->createQuietly([
            'app_id' => $app->id,
            'configuration' => ['poi_mode' => 'manual'],
        ]);

        $layer->ecPois()->attach($poiToDrop->id, ['created_at' => now(), 'updated_at' => now()]);

        $request = Request::create('/nova-vendor/layer-features/sync/'.$layer->id, 'POST', [
            'features' => [$poiToKeep->id],
            'model' => EcPoi::class,
        ]);

        $controller = app(LayerFeatureController::class);
        $controller->sync($request, $layer->id);

        $fresh = $layer->fresh();
        $this->assertTrue($fresh->ecPois()->where('ec_pois.id', $poiToKeep->id)->exists());
        $this->assertFalse($fresh->ecPois()->where('ec_pois.id', $poiToDrop->id)->exists());
    }
}
