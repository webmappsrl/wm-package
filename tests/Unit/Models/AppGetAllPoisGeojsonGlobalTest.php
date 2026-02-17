<?php

namespace Tests\Unit\Models;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Tests\Unit\Services\EcTrackService\MockDemClient;
use Wm\WmPackage\Http\Clients\DemClient;
use Wm\WmPackage\Http\Resources\EcPoiResource;
use Wm\WmPackage\Http\Resources\EcTrackResource;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Tests\TestCase;

class AppGetAllPoisGeojsonGlobalTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // Imposta uno shard_name valido per evitare errori in StorageService
        config(['wm-package.shard_name' => 'test_shard']);

        // Evita accessi reali a S3/disk durante i test
        Storage::fake('pois');
        Storage::fake('wmfe');
        Storage::fake('conf');

        // Mock DemClient per evitare chiamate HTTP reali
        $this->app->bind(DemClient::class, MockDemClient::class);
    }

    /** @test */
    public function poi_with_global_true_is_included_in_get_all_pois_geojson()
    {
        // Crea un'app
        $app = App::factory()->create();

        // Crea un POI con global = true
        $globalPoi = EcPoi::factory()->createQuietly([
            'app_id' => $app->id,
            'global' => true,
        ]);

        // Ricarica l'app per assicurarsi che la relazione sia aggiornata
        $app->refresh();

        // Verifica che il POI sia presente in getAllPoisGeojson
        $poisGeojson = $app->getAllPoisGeojson();

        $this->assertCount(1, $poisGeojson);
        $this->assertEquals($globalPoi->id, $poisGeojson[0]['properties']['id']);
    }

    /** @test */
    public function poi_with_global_false_is_not_included_in_get_all_pois_geojson()
    {
        // Crea un'app
        $app = App::factory()->create();

        // Crea un POI con global = false
        $nonGlobalPoi = EcPoi::factory()->createQuietly([
            'app_id' => $app->id,
            'global' => false,
        ]);

        // Ricarica l'app per assicurarsi che la relazione sia aggiornata
        $app->refresh();

        // Verifica che il POI NON sia presente in getAllPoisGeojson
        $poisGeojson = $app->getAllPoisGeojson();

        $this->assertCount(0, $poisGeojson);
    }

    /** @test */
    public function poi_with_global_true_is_included_in_ec_track_related_pois()
    {
        // Crea un'app
        $app = App::factory()->create();

        // Crea una track
        $track = EcTrack::factory()->createQuietly([
            'app_id' => $app->id,
        ]);

        // Crea un POI con global = true e associalo alla track
        $globalPoi = EcPoi::factory()->createQuietly([
            'app_id' => $app->id,
            'global' => true,
        ]);

        $track->ecPois()->attach($globalPoi->id);

        // Ricarica la track con le relazioni
        $track->refresh();
        $track->load('ecPois');

        // Verifica che il POI sia presente in related_pois dell'EcTrack
        $trackResource = new EcTrackResource($track);
        $trackGeojson = $trackResource->toArray(request());

        // Normalizza i related_pois in array plain, gestendo il caso in cui siano EcPoiResource
        $trackGeojson['properties']['related_pois'] = array_map(function ($poi) {
            if ($poi instanceof EcPoiResource) {
                return $poi->toArray(request());
            }

            return $poi;
        }, $trackGeojson['properties']['related_pois']);

        $this->assertArrayHasKey('related_pois', $trackGeojson['properties']);
        $this->assertCount(1, $trackGeojson['properties']['related_pois']);
        $this->assertEquals($globalPoi->id, $trackGeojson['properties']['related_pois'][0]['properties']['id']);
    }

    /** @test */
    public function poi_with_global_false_is_included_in_ec_track_related_pois()
    {
        // Crea un'app
        $app = App::factory()->create();

        // Crea una track
        $track = EcTrack::factory()->createQuietly([
            'app_id' => $app->id,
        ]);

        // Crea un POI con global = false e associalo alla track
        $nonGlobalPoi = EcPoi::factory()->createQuietly([
            'app_id' => $app->id,
            'global' => false,
        ]);

        $track->ecPois()->attach($nonGlobalPoi->id);

        // Ricarica la track con le relazioni
        $track->refresh();
        $track->load('ecPois');

        // Verifica che il POI sia presente in related_pois dell'EcTrack
        $trackResource = new EcTrackResource($track);
        $trackGeojson = $trackResource->toArray(request());

        // Normalizza i related_pois in array plain, gestendo il caso in cui siano EcPoiResource
        $trackGeojson['properties']['related_pois'] = array_map(function ($poi) {
            if ($poi instanceof EcPoiResource) {
                return $poi->toArray(request());
            }

            return $poi;
        }, $trackGeojson['properties']['related_pois']);

        $this->assertArrayHasKey('related_pois', $trackGeojson['properties']);
        $this->assertCount(1, $trackGeojson['properties']['related_pois']);
        $this->assertEquals($nonGlobalPoi->id, $trackGeojson['properties']['related_pois'][0]['properties']['id']);
    }

    /** @test */
    public function mixed_global_pois_are_filtered_correctly_in_get_all_pois_geojson_but_all_in_ec_track()
    {
        // Crea un'app
        $app = App::factory()->create();

        // Crea una track
        $track = EcTrack::factory()->createQuietly([
            'app_id' => $app->id,
        ]);

        // Crea un POI con global = true
        $globalPoi = EcPoi::factory()->createQuietly([
            'app_id' => $app->id,
            'global' => true,
        ]);

        // Crea un POI con global = false
        $nonGlobalPoi = EcPoi::factory()->createQuietly([
            'app_id' => $app->id,
            'global' => false,
        ]);

        // Associa entrambi i POI alla track
        $track->ecPois()->attach([$globalPoi->id, $nonGlobalPoi->id]);

        // Ricarica l'app per assicurarsi che la relazione sia aggiornata
        $app->refresh();

        // Verifica che solo il POI globale sia presente in getAllPoisGeojson
        $poisGeojson = $app->getAllPoisGeojson();
        $poiIds = array_column(array_column($poisGeojson, 'properties'), 'id');

        $this->assertCount(1, $poisGeojson);
        $this->assertContains($globalPoi->id, $poiIds);
        $this->assertNotContains($nonGlobalPoi->id, $poiIds);

        // Verifica che entrambi i POI siano presenti in related_pois dell'EcTrack
        $track->refresh();
        $track->load('ecPois');

        $trackResource = new EcTrackResource($track);
        $trackGeojson = $trackResource->toArray(request());

        // Normalizza i related_pois in array plain, gestendo il caso in cui siano EcPoiResource
        $trackGeojson['properties']['related_pois'] = array_map(function ($poi) {
            if ($poi instanceof EcPoiResource) {
                return $poi->toArray(request());
            }

            return $poi;
        }, $trackGeojson['properties']['related_pois']);

        $this->assertArrayHasKey('related_pois', $trackGeojson['properties']);
        $this->assertCount(2, $trackGeojson['properties']['related_pois']);

        $relatedPoiIds = array_column(array_column($trackGeojson['properties']['related_pois'], 'properties'), 'id');
        $this->assertContains($globalPoi->id, $relatedPoiIds);
        $this->assertContains($nonGlobalPoi->id, $relatedPoiIds);
    }
}
