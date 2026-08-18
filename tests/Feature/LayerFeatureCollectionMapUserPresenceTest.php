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
     * Layer con una singola EcTrack rettilinea nota (da 10.400,43.700 a 10.410,43.700) —
     * setup condiviso da ogni test che verifica il match di prossimità ST_DWithin.
     */
    private function createLayerWithTrack(): Layer
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

        return $layer;
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

        $layer = $this->createLayerWithTrack();

        $geojson = $layer->getFeatureCollectionMap();

        $userPositionFeatures = array_values(array_filter(
            $geojson['features'],
            fn ($f) => ($f['properties']['tooltip'] ?? null) === 'Posizione utente (ultimi 30 minuti)'
        ));

        $this->assertCount(1, $userPositionFeatures);
        $this->assertSame('Point', $userPositionFeatures[0]['geometry']['type']);
        $this->assertStringContainsString('34, 197, 94', $userPositionFeatures[0]['properties']['pointFillColor']);
        // Profilo ad anelli concentrici, non solo colore: distinzione non-cromatica dagli EcPoi
        // (cerchio pieno, nessuna checkpointRouteColors), richiesta esplicita di overview.md per
        // l'accessibilità (colore da solo non sufficiente).
        $this->assertArrayHasKey('checkpointRouteColors', $userPositionFeatures[0]['properties']);
        $this->assertGreaterThan(1, count($userPositionFeatures[0]['properties']['checkpointRouteColors']));
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

    /**
     * user_id è una property nuova sull'evento userMoved (oc:8159 follow-up): quando presente e
     * risolvibile a uno User esistente, il marker mostra il nominativo (name + surname) invece
     * del testo anonimo di default ed è cliccabile verso la pagina Nova dello user.
     */
    public function test_position_shows_user_nominativo_and_link_when_user_id_is_present(): void
    {
        $user = \Wm\WmPackage\Models\User::factory()->create(['name' => 'Maria', 'surname' => 'Rossi']);

        Http::fake([
            '*' => Http::response(['results' => [
                ['near-1', 43.70004, 10.405, $user->id],
            ]]),
        ]);

        $layer = $this->createLayerWithTrack();

        $geojson = $layer->getFeatureCollectionMap();

        $userPositionFeatures = array_values(array_filter(
            $geojson['features'],
            fn ($f) => isset($f['properties']['checkpointRouteColors'])
        ));

        $this->assertCount(1, $userPositionFeatures);
        $this->assertSame('Maria Rossi', $userPositionFeatures[0]['properties']['tooltip']);
        $this->assertStringContainsString('nova/resources/users/'.$user->id, $userPositionFeatures[0]['properties']['link']);
    }

    /**
     * user_id presente sull'evento ma senza uno User corrispondente nel DB locale (es. utente
     * cancellato) — deve ricadere sul marker anonimo di default, non produrre un link rotto.
     */
    public function test_position_falls_back_to_default_label_when_user_id_has_no_matching_user(): void
    {
        Http::fake([
            '*' => Http::response(['results' => [
                ['near-1', 43.70004, 10.405, 999999],
            ]]),
        ]);

        $layer = $this->createLayerWithTrack();

        $geojson = $layer->getFeatureCollectionMap();

        $userPositionFeatures = array_values(array_filter(
            $geojson['features'],
            fn ($f) => isset($f['properties']['checkpointRouteColors'])
        ));

        $this->assertCount(1, $userPositionFeatures);
        $this->assertSame('Posizione utente (ultimi 30 minuti)', $userPositionFeatures[0]['properties']['tooltip']);
        $this->assertArrayNotHasKey('link', $userPositionFeatures[0]['properties']);
    }

    /**
     * user_id risolve a uno User reale ma con name/surname vuoti (es. riga creata senza
     * cognome) — il tooltip ricade sul testo anonimo (nessun nominativo da mostrare), ma il
     * link resta presente: lo user esiste ed è comunque identificabile su Nova, il link non
     * deve dipendere dalla stringa del nominativo.
     */
    public function test_position_keeps_link_when_user_is_found_but_nominativo_is_blank(): void
    {
        $user = \Wm\WmPackage\Models\User::factory()->create(['name' => '', 'surname' => null]);

        Http::fake([
            '*' => Http::response(['results' => [
                ['near-1', 43.70004, 10.405, $user->id],
            ]]),
        ]);

        $layer = $this->createLayerWithTrack();

        $geojson = $layer->getFeatureCollectionMap();

        $userPositionFeatures = array_values(array_filter(
            $geojson['features'],
            fn ($f) => isset($f['properties']['checkpointRouteColors'])
        ));

        $this->assertCount(1, $userPositionFeatures);
        $this->assertSame('Posizione utente (ultimi 30 minuti)', $userPositionFeatures[0]['properties']['tooltip']);
        $this->assertStringContainsString('nova/resources/users/'.$user->id, $userPositionFeatures[0]['properties']['link']);
    }
}
