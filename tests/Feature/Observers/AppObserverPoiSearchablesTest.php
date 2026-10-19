<?php

namespace Wm\WmPackage\Tests\Feature\Observers;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Wm\WmPackage\Jobs\BuildAppPoisGeojsonJob;
use Wm\WmPackage\Models\App;

/**
 * oc:8677: searchable si calcola quando si genera pois.geojson, quindi il file va rigenerato
 * quando cambia «POI Search In» dell'app.
 */
class AppObserverPoiSearchablesTest extends TestCase
{
    use DatabaseTransactions;

    private App $shardApp;

    protected function setUp(): void
    {
        parent::setUp();

        config(['wm-package.shard_name' => 'test_shard']);
        Storage::fake('pois');
        Storage::fake('wmfe');
        Storage::fake('conf');
        // BuildAppPoisGeojsonJob è ShouldBeUnique con lock su Redis: Bus::fake() non evita
        // l'acquisizione del lock, e con il TestCase di maphub finirebbe nel Redis vero.
        config(['cache.stores.redis.driver' => 'array']);
        app('cache')->forgetDriver('redis');
        Bus::fake();

        $this->shardApp = App::factory()->createQuietly(['poi_searchables' => '[]']);
    }

    public function test_cambio_di_poi_searchables_rigenera_il_file(): void
    {
        $this->shardApp->update(['poi_searchables' => '["name"]']);

        Bus::assertDispatchedTimes(BuildAppPoisGeojsonJob::class, 1);
    }

    public function test_salvataggio_senza_cambio_non_rigenera(): void
    {
        $this->shardApp->update(['name' => ['it' => 'Altro '.uniqid()]]);

        Bus::assertNotDispatched(BuildAppPoisGeojsonJob::class);
    }
}
