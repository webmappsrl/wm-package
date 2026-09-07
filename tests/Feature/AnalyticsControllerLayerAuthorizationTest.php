<?php

namespace Wm\WmPackage\Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\RolesAndPermissionsService;
use Wm\WmPackage\Tests\TestCase;

class AnalyticsControllerLayerAuthorizationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RolesAndPermissionsService::seedDatabase();

        config([
            'services.posthog.host' => 'https://posthog.example.com',
            'services.posthog.project_id' => '1',
            'services.posthog.personal_api_key' => 'phx_test',
        ]);
    }

    public function test_non_owner_validator_receives_403(): void
    {
        Http::fake(['*' => Http::response(['results' => []])]);

        $app = App::factory()->create();
        $owner = User::factory()->create();
        $owner->assignRole('Validator');
        $otherValidator = User::factory()->create();
        $otherValidator->assignRole('Validator');
        $layer = Layer::factory()->create(['app_id' => $app->id, 'user_id' => $owner->id]);

        $response = $this->actingAs($otherValidator)
            ->getJson("/nova-vendor/layer-analytics/{$layer->id}");

        $response->assertStatus(403);
    }

    public function test_owner_validator_can_view_own_layer_analytics(): void
    {
        Http::fake(['*' => Http::response(['results' => []])]);

        $app = App::factory()->create();
        $owner = User::factory()->create();
        $owner->assignRole('Validator');
        $layer = Layer::factory()->create(['app_id' => $app->id, 'user_id' => $owner->id]);

        $response = $this->actingAs($owner)
            ->getJson("/nova-vendor/layer-analytics/{$layer->id}");

        $response->assertOk();
    }

    public function test_administrator_can_view_any_layer_analytics(): void
    {
        Http::fake(['*' => Http::response(['results' => []])]);

        $app = App::factory()->create();
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('Administrator');
        $layer = Layer::factory()->create(['app_id' => $app->id, 'user_id' => $owner->id]);

        $response = $this->actingAs($admin)
            ->getJson("/nova-vendor/layer-analytics/{$layer->id}");

        $response->assertOk();
    }
}
