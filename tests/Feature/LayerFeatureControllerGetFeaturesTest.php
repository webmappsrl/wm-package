<?php

namespace Wm\WmPackage\Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Nova\Fields\LayerFeatures\Http\Controllers\LayerFeatureController;
use Wm\WmPackage\Tests\TestCase;

class LayerFeatureControllerGetFeaturesTest extends TestCase
{
    use DatabaseTransactions;

    public function test_manual_mode_returns_tracks_when_layer_is_auto_without_taxonomies(): void
    {
        $app = App::factory()->createQuietly();
        $track = EcTrack::factory()->createQuietly(['app_id' => $app->id]);

        $layer = Layer::factory()->createQuietly([
            'app_id' => $app->id,
            'configuration' => ['track_mode' => 'auto'],
        ]);

        $request = Request::create('/nova-vendor/layer-features/features/'.$layer->id, 'GET', [
            'model' => EcTrack::class,
            'page' => 1,
            'per_page' => 50,
            'view_mode' => 'edit',
            'manual' => '1',
        ]);

        $controller = app(LayerFeatureController::class);
        $response = $controller->getFeatures($request, $layer->id);

        $this->assertSame(200, $response->getStatusCode());

        $payload = $response->getData(true);

        $this->assertIsArray($payload['features']);
        $this->assertNotEmpty($payload['features']);
        $this->assertContains($track->id, array_column($payload['features'], 'id'));
    }

    public function test_auto_mode_returns_empty_when_no_taxonomies_and_no_pivot(): void
    {
        $app = App::factory()->createQuietly();
        EcTrack::factory()->createQuietly(['app_id' => $app->id]);

        $layer = Layer::factory()->createQuietly([
            'app_id' => $app->id,
            'configuration' => ['track_mode' => 'auto'],
        ]);

        $request = Request::create('/nova-vendor/layer-features/features/'.$layer->id, 'GET', [
            'model' => EcTrack::class,
            'page' => 1,
            'per_page' => 50,
            'view_mode' => 'edit',
            'manual' => '0',
        ]);

        $controller = app(LayerFeatureController::class);
        $response = $controller->getFeatures($request, $layer->id);

        $this->assertSame(200, $response->getStatusCode());

        $payload = $response->getData(true);

        $this->assertIsArray($payload['features']);
        $this->assertEmpty($payload['features']);
    }
}
