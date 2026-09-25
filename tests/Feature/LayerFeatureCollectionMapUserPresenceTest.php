<?php

namespace Wm\WmPackage\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Tests\TestCase;

class LayerFeatureCollectionMapUserPresenceTest extends TestCase
{
    /** Tooltip del marker anonimo, definito in Layer::getFeatureCollectionMap(). */
    private const ANONYMOUS_TOOLTIP = 'Posizione utente (ultimi 30 minuti)';

    /** user_id senza User corrispondente nel DB (es. utente cancellato). */
    private const MISSING_USER_ID = 999999;

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
     * Estrae i soli marker di posizione utente (riconoscibili da checkpointRouteColors).
     *
     * @return list<array<string, mixed>>
     */
    private function userPositionFeatures(Layer $layer): array
    {
        return array_values(array_filter(
            $layer->getFeatureCollectionMap()['features'],
            fn ($f) => isset($f['properties']['checkpointRouteColors'])
        ));
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
            fn ($f) => ($f['properties']['tooltip'] ?? null) === self::ANONYMOUS_TOOLTIP
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
            fn ($f) => ($f['properties']['tooltip'] ?? null) === self::ANONYMOUS_TOOLTIP
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
            fn ($f) => ($f['properties']['tooltip'] ?? null) === self::ANONYMOUS_TOOLTIP
        );

        $this->assertCount(0, $userPositionFeatures);
    }

    /**
     * oc:8637: senza impostare la config, il default del package (analytics_show_live_user_identity =
     * false) tiene il marker anonimo e senza link anche con un utente risolvibile. Non impostare
     * la config in questo test: è il controllo che il default resti false.
     */
    public function test_default_config_keeps_marker_anonymous_even_when_user_is_resolvable(): void
    {
        // La chiave deve esistere nel config del package: senza, Layer ricadrebbe su null (anonimo)
        // e questo test passerebbe anche se la chiave venisse rimossa per errore.
        $this->assertArrayHasKey('analytics_show_live_user_identity', config('wm-package'));
        $this->assertFalse(config('wm-package.analytics_show_live_user_identity'));

        $user = User::factory()->create(['name' => 'Maria', 'surname' => 'Rossi']);

        Http::fake([
            '*' => Http::response(['results' => [
                ['near-1', 43.70004, 10.405, $user->id],
            ]]),
        ]);

        $layer = $this->createLayerWithTrack();

        $userPositionFeatures = $this->userPositionFeatures($layer);

        $this->assertCount(1, $userPositionFeatures);
        $this->assertSame(self::ANONYMOUS_TOOLTIP, $userPositionFeatures[0]['properties']['tooltip']);
        $this->assertArrayNotHasKey('link', $userPositionFeatures[0]['properties']);
    }

    /**
     * oc:8637: con il default (flag spento) un user_id senza utente corrispondente dà tooltip
     * generico e nessun link.
     */
    public function test_default_config_falls_back_to_default_label_when_user_is_missing(): void
    {
        Http::fake([
            '*' => Http::response(['results' => [
                ['near-1', 43.70004, 10.405, self::MISSING_USER_ID],
            ]]),
        ]);

        $layer = $this->createLayerWithTrack();

        $userPositionFeatures = $this->userPositionFeatures($layer);

        $this->assertCount(1, $userPositionFeatures);
        $this->assertSame(self::ANONYMOUS_TOOLTIP, $userPositionFeatures[0]['properties']['tooltip']);
        $this->assertArrayNotHasKey('link', $userPositionFeatures[0]['properties']);
    }

    /**
     * oc:8637: con il default (flag spento) anche un utente con nome vuoto dà tooltip generico e
     * nessun link; con il flag acceso il link c'è (vedi
     * test_flag_enabled_keeps_link_with_default_label_when_user_has_blank_name).
     */
    public function test_default_config_keeps_marker_anonymous_when_user_has_blank_name(): void
    {
        $user = User::factory()->create(['name' => '', 'surname' => null]);

        Http::fake([
            '*' => Http::response(['results' => [
                ['near-1', 43.70004, 10.405, $user->id],
            ]]),
        ]);

        $layer = $this->createLayerWithTrack();

        $userPositionFeatures = $this->userPositionFeatures($layer);

        $this->assertCount(1, $userPositionFeatures);
        $this->assertSame(self::ANONYMOUS_TOOLTIP, $userPositionFeatures[0]['properties']['tooltip']);
        $this->assertArrayNotHasKey('link', $userPositionFeatures[0]['properties']);
    }

    /**
     * oc:8637: con il flag acceso il marker torna a mostrare nome e cognome e il link alla
     * scheda Nova dell'utente, come prima di oc:8586.
     */
    public function test_flag_enabled_shows_full_name_and_link_when_user_is_resolvable(): void
    {
        config(['wm-package.analytics_show_live_user_identity' => true]);
        $user = User::factory()->create(['name' => 'Maria', 'surname' => 'Rossi']);

        Http::fake([
            '*' => Http::response(['results' => [
                ['near-1', 43.70004, 10.405, $user->id],
            ]]),
        ]);

        $features = $this->userPositionFeatures($this->createLayerWithTrack());

        $this->assertCount(1, $features);
        $this->assertSame('Maria Rossi', $features[0]['properties']['tooltip']);
        $this->assertSame(url('nova/resources/users/'.$user->id), $features[0]['properties']['link']);
    }

    /**
     * oc:8637: utente esistente senza nome e cognome — tooltip generico ma link presente: se
     * l'utente esiste il link c'è (decisione del dev, comportamento precedente a oc:8586).
     */
    public function test_flag_enabled_keeps_link_with_default_label_when_user_has_blank_name(): void
    {
        config(['wm-package.analytics_show_live_user_identity' => true]);
        $user = User::factory()->create(['name' => '', 'surname' => null]);

        Http::fake([
            '*' => Http::response(['results' => [
                ['near-1', 43.70004, 10.405, $user->id],
            ]]),
        ]);

        $features = $this->userPositionFeatures($this->createLayerWithTrack());

        $this->assertCount(1, $features);
        $this->assertSame(self::ANONYMOUS_TOOLTIP, $features[0]['properties']['tooltip']);
        $this->assertSame(url('nova/resources/users/'.$user->id), $features[0]['properties']['link']);
    }

    /**
     * oc:8637: flag acceso ma user_id senza utente corrispondente (es. utente cancellato) —
     * tooltip generico e nessun link.
     */
    public function test_flag_enabled_falls_back_to_default_label_without_link_when_user_is_missing(): void
    {
        config(['wm-package.analytics_show_live_user_identity' => true]);

        Http::fake([
            '*' => Http::response(['results' => [
                ['near-1', 43.70004, 10.405, self::MISSING_USER_ID],
            ]]),
        ]);

        $features = $this->userPositionFeatures($this->createLayerWithTrack());

        $this->assertCount(1, $features);
        $this->assertSame(self::ANONYMOUS_TOOLTIP, $features[0]['properties']['tooltip']);
        $this->assertArrayNotHasKey('link', $features[0]['properties']);
    }

    /**
     * oc:8637: più posizioni nella stessa risposta — ogni marker porta il proprio nome e link,
     * nessuna mescolanza fra utenti.
     */
    public function test_flag_enabled_assigns_each_position_its_own_user(): void
    {
        config(['wm-package.analytics_show_live_user_identity' => true]);
        $maria = User::factory()->create(['name' => 'Maria', 'surname' => 'Rossi']);
        $luca = User::factory()->create(['name' => 'Luca', 'surname' => 'Bianchi']);

        Http::fake([
            '*' => Http::response(['results' => [
                ['near-1', 43.70004, 10.405, $maria->id],
                ['near-2', 43.70003, 10.407, $luca->id],
            ]]),
        ]);

        $features = $this->userPositionFeatures($this->createLayerWithTrack());

        $this->assertCount(2, $features);
        $byTooltip = collect($features)->keyBy(fn ($f) => $f['properties']['tooltip']);
        $this->assertSame(url('nova/resources/users/'.$maria->id), $byTooltip['Maria Rossi']['properties']['link']);
        $this->assertSame(url('nova/resources/users/'.$luca->id), $byTooltip['Luca Bianchi']['properties']['link']);
    }

    /**
     * oc:8637: con il flag acceso una riga senza user_id (colonna assente o null) resta un marker
     * anonimo e senza link, senza errori.
     */
    public function test_flag_enabled_keeps_marker_anonymous_when_position_has_no_user_id(): void
    {
        config(['wm-package.analytics_show_live_user_identity' => true]);

        Http::fake([
            '*' => Http::response(['results' => [
                ['near-1', 43.70004, 10.405],
                ['near-2', 43.70003, 10.407, null],
            ]]),
        ]);

        $features = $this->userPositionFeatures($this->createLayerWithTrack());

        $this->assertCount(2, $features);
        foreach ($features as $feature) {
            $this->assertSame(self::ANONYMOUS_TOOLTIP, $feature['properties']['tooltip']);
            $this->assertArrayNotHasKey('link', $feature['properties']);
        }
    }

    /**
     * oc:8637: con il flag spento non parte nessuna query sulla tabella users, anche se le
     * posizioni portano user_id risolvibili.
     */
    public function test_default_config_does_not_query_users(): void
    {
        $user = User::factory()->create(['name' => 'Maria', 'surname' => 'Rossi']);

        Http::fake([
            '*' => Http::response(['results' => [
                ['near-1', 43.70004, 10.405, $user->id],
            ]]),
        ]);

        $layer = $this->createLayerWithTrack();

        DB::enableQueryLog();
        $this->userPositionFeatures($layer);
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        $this->assertEmpty(array_filter($queries, fn ($q) => preg_match('/from\s+"users"/i', $q)));
    }

    /**
     * oc:8637: valori "umani" di spegnimento (off, no) non devono accendere il flag — su un flag
     * di privacy un valore ambiguo deve lasciare il marker anonimo.
     */
    public function test_flag_stays_off_for_human_readable_false_values(): void
    {
        $user = User::factory()->create(['name' => 'Maria', 'surname' => 'Rossi']);

        Http::fake([
            '*' => Http::response(['results' => [
                ['near-1', 43.70004, 10.405, $user->id],
            ]]),
        ]);

        $layer = $this->createLayerWithTrack();

        foreach (['off', 'no', 'false', '0', ''] as $value) {
            config(['wm-package.analytics_show_live_user_identity' => $value]);

            $features = $this->userPositionFeatures($layer);

            $this->assertSame(self::ANONYMOUS_TOOLTIP, $features[0]['properties']['tooltip'], "valore '{$value}'");
            $this->assertArrayNotHasKey('link', $features[0]['properties'], "valore '{$value}'");
        }
    }

    /**
     * oc:8637: il link alla scheda utente segue il path Nova del consumer, non un "nova/" fisso.
     */
    public function test_flag_enabled_link_follows_nova_path(): void
    {
        config([
            'wm-package.analytics_show_live_user_identity' => true,
            'nova.path' => '/admin',
        ]);
        $user = User::factory()->create(['name' => 'Maria', 'surname' => 'Rossi']);

        Http::fake([
            '*' => Http::response(['results' => [
                ['near-1', 43.70004, 10.405, $user->id],
            ]]),
        ]);

        $features = $this->userPositionFeatures($this->createLayerWithTrack());

        $this->assertSame(url('admin/resources/users/'.$user->id), $features[0]['properties']['link']);
    }
}
