<?php

namespace Wm\WmPackage\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Tests\TestCase;

class LayerFeatureCollectionMapUserPresenceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.posthog.host' => 'https://posthog.example.com',
            'services.posthog.project_id' => '1',
            'services.posthog.personal_api_key' => 'phx_test',
            // Default del package è 'App\Models\EcTrack' (pensato per i consumer) — non esiste
            // nell'autoload standalone di wm-package, getFeatureCollectionMap() lo richiede.
            'wm-package.ec_track_model' => EcTrack::class,
            'wm-package.layer_user_presence_distance_meters' => 50,
        ]);
    }

    /**
     * Verifica di regressione (oc:8159, post-review manuale): senza il filtro ST_DWithin,
     * getRecentUserPositions() mostrava punti a livello di shard, non di layer — un utente
     * a centinaia di km dal cammino comparso come "sul cammino" in una verifica manuale reale.
     */
    public function test_only_positions_near_layer_tracks_are_added_as_point_features(): void
    {
        Http::fake([
            // person_id, lat, lng — near-1 vicino alla traccia, far-1 a ~5km di distanza
            '*' => Http::response(['results' => [
                ['near-1', 43.70004, 10.405],
                ['far-1', 43.75, 10.405],
            ]]),
        ]);

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

        $geojson = $layer->getFeatureCollectionMap();

        $userPositionFeatures = array_values(array_filter(
            $geojson['features'],
            fn ($f) => ($f['properties']['tooltip'] ?? null) === 'Posizione utente (ultimi 30 minuti)'
        ));

        $this->assertCount(1, $userPositionFeatures);
        $this->assertSame('Point', $userPositionFeatures[0]['geometry']['type']);
        $this->assertStringContainsString('34, 197, 94', $userPositionFeatures[0]['properties']['pointFillColor']);
    }

    public function test_no_point_features_added_when_no_recent_positions(): void
    {
        Http::fake(['*' => Http::response(['results' => []])]);

        $layer = Model::withoutEvents(function () {
            App::factory()->create();

            return Layer::factory()->create();
        });

        $geojson = $layer->getFeatureCollectionMap();

        $userPositionFeatures = array_filter(
            $geojson['features'],
            fn ($f) => ($f['properties']['tooltip'] ?? null) === 'Posizione utente (ultimi 30 minuti)'
        );

        $this->assertCount(0, $userPositionFeatures);
    }

    public function test_no_point_features_added_when_layer_has_no_tracks(): void
    {
        Http::fake([
            '*' => Http::response(['results' => [['near-1', 43.70004, 10.405]]]),
        ]);

        $layer = Model::withoutEvents(function () {
            App::factory()->create();

            return Layer::factory()->create();
        });

        $geojson = $layer->getFeatureCollectionMap();

        $userPositionFeatures = array_filter(
            $geojson['features'],
            fn ($f) => ($f['properties']['tooltip'] ?? null) === 'Posizione utente (ultimi 30 minuti)'
        );

        $this->assertCount(0, $userPositionFeatures);
    }
}
