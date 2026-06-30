<?php

namespace Wm\WmPackage\Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Tests\TestCase;

class LayerGetFeatureCollectionMapEcPoisTest extends TestCase
{
    use DatabaseTransactions;

    public function test_ec_poi_with_geometry_appears_as_point_feature_in_map(): void
    {
        $app = App::factory()->createQuietly();
        $layer = Layer::factory()->createQuietly(['app_id' => $app->id]);
        $poi = EcPoi::factory()->createQuietly(['app_id' => $app->id]);

        $layer->ecPois()->attach($poi->id);

        $result = $layer->fresh()->getFeatureCollectionMap();

        $this->assertSame('FeatureCollection', $result['type']);

        $pointFeatures = array_values(array_filter($result['features'], function ($f) {
            return isset($f['geometry']['type']) && $f['geometry']['type'] === 'Point';
        }));

        $this->assertNotEmpty($pointFeatures, 'Nessuna feature Point trovata nella FeatureCollection');

        $feature = $pointFeatures[0];
        $this->assertArrayHasKey('tooltip', $feature['properties']);
        $this->assertNotEmpty($feature['properties']['tooltip']);
        $this->assertStringContainsString('ec-pois/'.$poi->id, $feature['properties']['link']);
    }

    public function test_ec_poi_without_geometry_is_not_included_in_map(): void
    {
        $app = App::factory()->createQuietly();
        $layer = Layer::factory()->createQuietly(['app_id' => $app->id]);
        $poi = EcPoi::factory()->createQuietly(['app_id' => $app->id]);

        DB::table('ec_pois')->where('id', $poi->id)->update(['geometry' => null]);

        $layer->ecPois()->attach($poi->id);

        $result = $layer->fresh()->getFeatureCollectionMap();

        $pointFeatures = array_filter($result['features'], function ($f) {
            return isset($f['geometry']['type']) && $f['geometry']['type'] === 'Point';
        });

        $this->assertEmpty($pointFeatures, 'EcPoi senza geometria non dovrebbe produrre feature Point');
    }

    public function test_layer_without_ec_pois_returns_valid_feature_collection(): void
    {
        $app = App::factory()->createQuietly();
        $layer = Layer::factory()->createQuietly(['app_id' => $app->id]);

        $result = $layer->fresh()->getFeatureCollectionMap();

        $this->assertSame('FeatureCollection', $result['type']);
        $this->assertIsArray($result['features']);
    }
}
