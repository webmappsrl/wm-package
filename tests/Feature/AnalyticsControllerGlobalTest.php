<?php

namespace Wm\WmPackage\Tests\Feature;

use App\Models\User;
use Illuminate\Http\Client\Request;
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
        $response->assertJsonStructure(['total', 'unique_users', 'daily_breakdown', 'ranking_layers', 'ranking_tracks', 'ranking_route_filters']);
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

    public function test_administrator_receives_502_when_posthog_query_fails(): void
    {
        Http::fake(['*' => Http::response('Internal Server Error', 500)]);

        $admin = User::factory()->create();
        $admin->assignRole('Administrator');

        $response = $this->actingAs($admin)->getJson('/nova-vendor/layer-analytics/global');

        $response->assertStatus(502);
        $response->assertJson(['error' => 'analytics_query_failed']);
    }

    public function test_administrator_receives_502_when_route_filter_query_fails(): void
    {
        // Abilita esplicitamente il flag opt-in (default disabilitato) — senza questo,
        // getRouteFilterUsage() non verrebbe mai chiamato e il test non eserciterebbe nulla.
        config(['wm-package.route_filter_analytics_enabled' => true]);

        // Fa fallire solo la query di getRouteFilterUsage() (riconosciuta dal contenuto SQL),
        // tutte le altre metriche di global() rispondono ok — verifica che global() propaghi
        // comunque un 502 anche quando l'unica query a fallire è quella nuova, non solo quando
        // fallisce la prima (già coperto da test_administrator_receives_502_when_posthog_query_fails).
        Http::fake(function (Request $request) {
            $sql = $request->data()['query']['query'] ?? '';
            if (str_contains($sql, "event = 'filterUsed'") && str_contains($sql, "filter_type = 'route'")) {
                return Http::response('Internal Server Error', 500);
            }

            return Http::response(['results' => []]);
        });

        $admin = User::factory()->create();
        $admin->assignRole('Administrator');

        $response = $this->actingAs($admin)->getJson('/nova-vendor/layer-analytics/global');

        $response->assertStatus(502);
        $response->assertJson(['error' => 'analytics_query_failed']);
    }

    public function test_route_filters_are_null_when_analytics_disabled_by_default(): void
    {
        // Nessun config esplicito qui: il flag deve essere disabilitato di default, coerente con
        // il pattern già usato da internal_attribute_keys (oc:8463) — opt-in, mai il default.
        Http::fake(function (Request $request) {
            $sql = $request->data()['query']['query'] ?? '';
            if (str_contains($sql, "event = 'filterUsed'") && str_contains($sql, "filter_type = 'route'")) {
                throw new \RuntimeException('getRouteFilterUsage() non deve essere chiamato quando il flag è disabilitato');
            }

            return Http::response(['results' => []]);
        });

        $admin = User::factory()->create();
        $admin->assignRole('Administrator');

        $response = $this->actingAs($admin)->getJson('/nova-vendor/layer-analytics/global');

        $response->assertOk();
        $response->assertJson(['ranking_route_filters' => null]);
    }

    public function test_route_filters_are_populated_when_analytics_enabled(): void
    {
        config(['wm-package.route_filter_analytics_enabled' => true]);

        Http::fake(function (Request $request) {
            $sql = $request->data()['query']['query'] ?? '';
            if (str_contains($sql, "event = 'filterUsed'") && str_contains($sql, "filter_type = 'route'")) {
                return Http::response(['results' => [['distance', 's1', 'web', '2026-06-01 10:00:00']]]);
            }

            return Http::response(['results' => []]);
        });

        $admin = User::factory()->create();
        $admin->assignRole('Administrator');

        $response = $this->actingAs($admin)->getJson('/nova-vendor/layer-analytics/global');

        $response->assertOk();
        $response->assertJsonCount(7, 'ranking_route_filters');
    }
}
