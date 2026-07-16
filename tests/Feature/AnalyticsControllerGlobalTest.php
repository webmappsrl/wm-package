<?php

namespace Wm\WmPackage\Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Wm\WmPackage\Services\RolesAndPermissionsService;
use Wm\WmPackage\Tests\TestCase;

class AnalyticsControllerGlobalTest extends TestCase
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

    public function test_administrator_receives_aggregated_data_structure(): void
    {
        Http::fake(['*' => Http::response(['results' => []])]);

        $admin = User::factory()->create();
        $admin->assignRole('Administrator');

        $response = $this->actingAs($admin)->getJson('/nova-vendor/layer-analytics/global');

        $response->assertOk();
        $response->assertJsonStructure(['total', 'unique_users', 'daily_breakdown', 'ranking_layers', 'ranking_tracks']);
    }

    public function test_non_administrator_receives_403(): void
    {
        Http::fake(['*' => Http::response(['results' => []])]);

        $validator = User::factory()->create();
        $validator->assignRole('Validator');

        $response = $this->actingAs($validator)->getJson('/nova-vendor/layer-analytics/global');

        $response->assertStatus(403);
    }

    public function test_guest_without_role_receives_403(): void
    {
        $response = $this->getJson('/nova-vendor/layer-analytics/global');

        $response->assertStatus(403);
    }
}
