<?php

namespace Tests\Unit\Models;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Tests\TestCase;

/**
 * Regression test: EcPoi::app() must resolve to Wm\WmPackage\Models\App, not the
 * Illuminate\Support\Facades\App facade already imported in EcPoi.php. An IDE/linter
 * "simplify to App::class" pass has silently reintroduced this bug once already.
 */
class EcPoiAppRelationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // BuildAppPoisGeojsonJob::uniqueVia() hardcodes Cache::store('redis') for its unique lock;
        // redirect that store to the array driver so the ShouldBeUnique check doesn't need real Redis.
        config(['wm-package.shard_name' => 'test_shard', 'cache.stores.redis.driver' => 'array']);

        Storage::fake('pois');
        Storage::fake('wmfe');
        Storage::fake('conf');
        Bus::fake();
    }

    /** @test */
    public function app_relation_resolves_to_the_wm_package_app_model()
    {
        $app = App::factory()->create();
        $poi = EcPoi::factory()->create(['app_id' => $app->id, 'properties' => []]);

        $this->assertInstanceOf(App::class, $poi->app);
        $this->assertSame($app->id, $poi->app->id);
    }
}
