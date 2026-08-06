<?php

namespace Wm\WmPackage\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\PostHog\AnalyticsService;
use Wm\WmPackage\Tests\TestCase;

class AnalyticsServiceUserPresenceGeoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.posthog.host' => 'https://posthog.example.com',
            'services.posthog.project_id' => '1',
            'services.posthog.personal_api_key' => 'phx_test',
            'wm-package.shard_name' => 'test-shard',
            'wm-package.layer_user_presence_distance_meters' => 50,
            // Default del package è 'App\Models\EcTrack' (pensato per i consumer) — non esiste
            // nell'autoload standalone di wm-package.
            'wm-package.ec_track_model' => EcTrack::class,
        ]);
    }

    // -------------------------------------------------------------------------
    // layerTracksBoundingBox()
    // -------------------------------------------------------------------------

    public function test_bounding_box_is_null_when_layer_has_no_tracks(): void
    {
        $layer = Model::withoutEvents(function () {
            App::factory()->create();

            return Layer::factory()->create();
        });

        $service = new AnalyticsService;
        $bbox = $this->callPrivateMethod($service, 'layerTracksBoundingBox', [$layer]);

        $this->assertNull($bbox);
    }

    public function test_bounding_box_includes_margin_around_track_geometry(): void
    {
        [$layer] = Model::withoutEvents(function () {
            App::factory()->create();
            $track = EcTrack::factory()->create([
                'geometry' => \DB::raw("ST_GeomFromText('LINESTRING(10.400 43.700, 10.410 43.700)', 4326)"),
            ]);
            $layer = Layer::factory()->create();
            $layer->ecTracks()->attach($track->id);

            return [$layer];
        });

        $service = new AnalyticsService;
        $bbox = $this->callPrivateMethod($service, 'layerTracksBoundingBox', [$layer]);

        $this->assertNotNull($bbox);
        // Margine positivo attorno alle coordinate esatte della traccia (10.400-10.410, 43.700)
        $this->assertLessThan(10.400, $bbox['min_lng']);
        $this->assertGreaterThan(10.410, $bbox['max_lng']);
        $this->assertLessThan(43.700, $bbox['min_lat']);
        $this->assertGreaterThan(43.700, $bbox['max_lat']);
    }

    // -------------------------------------------------------------------------
    // queryUserMovedPointsNearLayer() — nessuna chiamata HTTP se il layer non ha tracce
    // -------------------------------------------------------------------------

    public function test_query_points_near_layer_returns_empty_without_http_call_when_no_tracks(): void
    {
        Http::fake();

        $layer = Model::withoutEvents(function () {
            App::factory()->create();

            return Layer::factory()->create();
        });

        $service = new AnalyticsService;
        $points = $this->callPrivateMethod($service, 'queryUserMovedPointsNearLayer', [$layer, 'last_30_days']);

        $this->assertSame([], $points);
        Http::assertNothingSent();
    }

    public function test_query_points_near_layer_maps_raw_rows(): void
    {
        [$layer] = Model::withoutEvents(function () {
            App::factory()->create();
            $track = EcTrack::factory()->create();
            $layer = Layer::factory()->create();
            $layer->ecTracks()->attach($track->id);

            return [$layer];
        });

        Http::fake(['*' => Http::response(['results' => [['person-1', 43.7, 10.4]]])]);

        $service = new AnalyticsService;
        $points = $this->callPrivateMethod($service, 'queryUserMovedPointsNearLayer', [$layer, 'last_30_days']);

        $this->assertSame([['person_id' => 'person-1', 'lat' => 43.7, 'lng' => 10.4]], $points);
    }

    // -------------------------------------------------------------------------
    // countPersonsNearLayerTracks() — match geografico reale via PostGIS
    // -------------------------------------------------------------------------

    public function test_counts_only_points_within_distance_of_layer_tracks(): void
    {
        [$layer] = Model::withoutEvents(function () {
            App::factory()->create();
            // Traccia rettilinea nota: da (10.400, 43.700) a (10.410, 43.700)
            $track = EcTrack::factory()->create([
                'geometry' => \DB::raw("ST_GeomFromText('LINESTRING(10.400 43.700, 10.410 43.700)', 4326)"),
            ]);
            $layer = Layer::factory()->create();
            $layer->ecTracks()->attach($track->id);

            return [$layer];
        });

        $points = [
            // ~5m dalla traccia -> dentro soglia 50m
            ['person_id' => 'near-1', 'lat' => 43.70004, 'lng' => 10.405],
            // ~5km dalla traccia -> fuori soglia
            ['person_id' => 'far-1', 'lat' => 43.75, 'lng' => 10.405],
            // stesso utente near-1 rilevato due volte -> contato una sola volta
            ['person_id' => 'near-1', 'lat' => 43.70003, 'lng' => 10.406],
        ];

        $service = new AnalyticsService;
        $count = $this->callPrivateMethod($service, 'countPersonsNearLayerTracks', [$layer, $points]);

        $this->assertSame(1, $count);
    }

    public function test_returns_zero_when_layer_has_no_tracks(): void
    {
        $layer = Model::withoutEvents(function () {
            App::factory()->create();

            return Layer::factory()->create();
        });

        $service = new AnalyticsService;
        $count = $this->callPrivateMethod($service, 'countPersonsNearLayerTracks', [$layer, [
            ['person_id' => 'x', 'lat' => 43.7, 'lng' => 10.4],
        ]]);

        $this->assertSame(0, $count);
    }

    public function test_returns_zero_when_points_list_is_empty(): void
    {
        $layer = Model::withoutEvents(function () {
            App::factory()->create();
            $track = EcTrack::factory()->create();
            $layer = Layer::factory()->create();
            $layer->ecTracks()->attach($track->id);

            return $layer;
        });

        $service = new AnalyticsService;
        $count = $this->callPrivateMethod($service, 'countPersonsNearLayerTracks', [$layer, []]);

        $this->assertSame(0, $count);
    }

    // -------------------------------------------------------------------------
    // getUserMovedStats() — orchestrazione end-to-end
    // -------------------------------------------------------------------------

    public function test_get_user_moved_stats_returns_zero_when_layer_has_no_tracks(): void
    {
        Http::fake();

        $layer = Model::withoutEvents(function () {
            App::factory()->create();

            return Layer::factory()->create();
        });

        $service = new AnalyticsService;
        $result = $service->getUserMovedStats($layer, 'last_30_days');

        $this->assertSame(0, $result);
        Http::assertNothingSent();
    }

    public function test_get_user_moved_stats_returns_null_on_http_failure(): void
    {
        Http::fake(['*' => Http::response('', 500)]);

        [$layer] = Model::withoutEvents(function () {
            App::factory()->create();
            $track = EcTrack::factory()->create();
            $layer = Layer::factory()->create();
            $layer->ecTracks()->attach($track->id);

            return [$layer];
        });

        $service = new AnalyticsService;
        $result = $service->getUserMovedStats($layer, 'last_30_days');

        $this->assertNull($result);
    }

    public function test_get_user_moved_stats_counts_real_geographic_match(): void
    {
        [$layer] = Model::withoutEvents(function () {
            App::factory()->create();
            $track = EcTrack::factory()->create([
                'geometry' => \DB::raw("ST_GeomFromText('LINESTRING(10.400 43.700, 10.410 43.700)', 4326)"),
            ]);
            $layer = Layer::factory()->create();
            $layer->ecTracks()->attach($track->id);

            return [$layer];
        });

        Http::fake(['*' => Http::response(['results' => [
            ['near-1', 43.70004, 10.405],
            ['far-1', 43.75, 10.405],
        ]])]);

        $service = new AnalyticsService;
        $result = $service->getUserMovedStats($layer, 'last_30_days');

        $this->assertSame(1, $result);
    }

    // -------------------------------------------------------------------------
    // getAllLayersUserPresence() — ranking globale ("Cammini più frequentati")
    // -------------------------------------------------------------------------

    public function test_get_all_layers_user_presence_attributes_counts_to_the_right_layer(): void
    {
        [$layerA, $layerB] = Model::withoutEvents(function () {
            App::factory()->create();

            // Layer A: traccia a Firenze
            $trackA = EcTrack::factory()->create([
                'geometry' => \DB::raw("ST_GeomFromText('LINESTRING(11.250 43.770, 11.260 43.770)', 4326)"),
            ]);
            $layerA = Layer::factory()->create();
            $layerA->ecTracks()->attach($trackA->id);

            // Layer B: traccia a Roma, lontana da A
            $trackB = EcTrack::factory()->create([
                'geometry' => \DB::raw("ST_GeomFromText('LINESTRING(12.480 41.890, 12.490 41.890)', 4326)"),
            ]);
            $layerB = Layer::factory()->create();
            $layerB->ecTracks()->attach($trackB->id);

            return [$layerA, $layerB];
        });

        Http::fake(['*' => Http::response(['results' => [
            // 2 persone vicine alla traccia del layer A
            ['fi-1', 43.77004, 11.255],
            ['fi-2', 43.77003, 11.256],
            // 1 persona vicina alla traccia del layer B
            ['rm-1', 41.89004, 12.485],
        ]])]);

        $service = new AnalyticsService;
        $ranking = $service->getAllLayersUserPresence('last_30_days');

        $byLayerId = [];
        foreach ($ranking as $row) {
            $byLayerId[$row['layer_id']] = $row;
        }

        $this->assertSame(2, $byLayerId[$layerA->id]['total']);
        $this->assertSame(1, $byLayerId[$layerB->id]['total']);
        $this->assertNotEmpty($byLayerId[$layerA->id]['name']);
    }

    public function test_get_all_layers_user_presence_returns_empty_when_no_tracks_exist(): void
    {
        Http::fake();

        $ranking = (new AnalyticsService)->getAllLayersUserPresence('last_30_days');

        $this->assertSame([], $ranking);
        Http::assertNothingSent();
    }

    protected function callPrivateMethod(object $object, string $method, array $args = []): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($object, $args);
    }
}
