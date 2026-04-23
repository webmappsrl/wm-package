<?php

namespace Wm\WmPackage\Tests\Unit;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use ReflectionMethod;
use Wm\WmPackage\Models\App as AppModel;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Nova\App as NovaAppResource;
use Wm\WmPackage\Nova\Fields\PropertiesPanel;
use Wm\WmPackage\Tests\TestCase;

class EcDescriptionTiptapTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'wm-package.shard_name' => 'test_shard',
            'wm-package.ec_track_model' => EcTrack::class,
            'cache.stores.redis.driver' => 'array',
        ]);

        Storage::fake('wmfe');
        Storage::fake('conf');
        Storage::fake('pois');

        Bus::fake();
        Queue::fake();
    }

    public function test_wm_schemas_expose_description_as_translatable_tiptap_for_track_poi_and_layer(): void
    {
        $trackDesc = collect(config('wm-ec-track-schema.properties.fields'))->firstWhere('name', 'description');
        $poiDesc = collect(config('wm-ec-poi-schema.properties.fields'))->firstWhere('name', 'description');
        $layerDesc = collect(config('wm-layer-schema.properties.fields'))->firstWhere('name', 'description');

        $this->assertSame('tiptap', $trackDesc['type']);
        $this->assertTrue($trackDesc['translatable']);
        $this->assertSame('tiptap', $poiDesc['type']);
        $this->assertTrue($poiDesc['translatable']);
        $this->assertSame('tiptap', $layerDesc['type']);
        $this->assertTrue($layerDesc['translatable']);
    }

    public function test_nova_app_resource_and_properties_panel_use_the_same_tiptap_toolbar_buttons(): void
    {
        $appModel = AppModel::factory()->createQuietly();
        $novaApp = new NovaAppResource($appModel);

        $tiptapOnApp = new ReflectionMethod(NovaAppResource::class, 'tiptapButtons');
        $tiptapOnApp->setAccessible(true);
        $fromApp = $tiptapOnApp->invoke($novaApp);

        $panelProbe = new class extends PropertiesPanel
        {
            public function toolbarForTest(): array
            {
                return $this->tiptapButtons();
            }
        };
        $fromPanel = $panelProbe->toolbarForTest();

        $this->assertSame($fromPanel, $fromApp);
        foreach (['heading', 'bold', 'link', 'editHtml'] as $item) {
            $this->assertContains($item, $fromApp);
        }
    }

    public function test_ec_track_round_trips_plain_string_in_properties_description_translation(): void
    {
        $app = AppModel::factory()->createQuietly();
        $plain = 'Descrizione semplice senza tag HTML';

        $track = EcTrack::factory()->createQuietly([
            'app_id' => $app->id,
        ]);
        $track->setTranslation('properties->description', 'it', $plain);
        $track->save();
        $track->refresh();

        $this->assertSame($plain, $track->getTranslation('properties->description', 'it'));
    }

    public function test_ec_poi_round_trips_plain_string_in_properties_description_translation(): void
    {
        $app = AppModel::factory()->createQuietly();
        $plain = 'Testo POI semplice';

        $geojson = json_encode([
            'type' => 'Point',
            'coordinates' => [9.0, 40.0, 10.0],
        ]);

        $poi = EcPoi::query()->createQuietly([
            'app_id' => $app->id,
            'name' => ['it' => 'POI test', 'en' => 'POI test'],
            'geometry' => DB::raw("ST_GeomFromGeoJSON('{$geojson}')"),
            'properties' => [],
        ]);
        $poi->setTranslation('properties->description', 'it', $plain);
        $poi->save();
        $poi->refresh();

        $this->assertSame($plain, $poi->getTranslation('properties->description', 'it'));
    }

    public function test_layer_round_trips_plain_string_in_properties_description_translation(): void
    {
        $app = AppModel::factory()->createQuietly();
        $plain = 'Descrizione layer come stringa semplice';

        $geojson = json_encode([
            'type' => 'Polygon',
            'coordinates' => [[
                [10.0, 40.0],
                [10.1, 40.0],
                [10.1, 40.1],
                [10.0, 40.1],
                [10.0, 40.0],
            ]],
        ]);

        $layer = Layer::query()->createQuietly([
            'app_id' => $app->id,
            'name' => ['it' => 'Layer test', 'en' => 'Layer test'],
            'geometry' => DB::raw("ST_GeomFromGeoJSON('{$geojson}')"),
            'properties' => [],
        ]);
        $layer->setTranslation('properties->description', 'it', $plain);
        $layer->save();
        $layer->refresh();

        $this->assertSame($plain, $layer->getTranslation('properties->description', 'it'));
    }
}
