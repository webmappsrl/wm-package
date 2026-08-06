<?php

namespace Wm\WmPackage\Tests\Feature;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Tests\TestCase;

class AnalyticsControllerUserPresenceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.posthog.host' => 'https://posthog.example.com',
            'services.posthog.project_id' => '1',
            'services.posthog.personal_api_key' => 'phx_test',
            'wm-package.ec_track_model' => \Wm\WmPackage\Models\EcTrack::class,
        ]);
    }

    public function test_layer_endpoint_includes_user_presence_key(): void
    {
        Http::fake(['*' => Http::response(['results' => []])]);

        // Owner del layer (non Administrator): evita di passare da Spatie hasRole()/
        // seedDatabase(), che nell'ambiente standalone di wm-package rompe su
        // PermissionRegistrar::$permissionClass null (bug preesistente, non correlato
        // a questa feature — riprodotto anche su AnalyticsControllerLayerAuthorizationTest.php
        // non modificato). abort_unless() ammette anche il solo ownership del layer.
        [$owner, $layer] = Model::withoutEvents(function () {
            $app = App::factory()->create();
            $owner = User::factory()->create();
            $layer = Layer::factory()->create(['app_id' => $app->id, 'user_id' => $owner->id]);

            return [$owner, $layer];
        });

        $response = $this->actingAs($owner)
            ->getJson("/nova-vendor/layer-analytics/{$layer->id}");

        $response->assertOk();
        $response->assertJsonStructure(['user_presence']);
        $this->assertSame(0, $response->json('user_presence'));
    }
}
