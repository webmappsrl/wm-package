<?php

namespace Wm\WmPackage\Tests\Feature\Import;

use Illuminate\Bus\Batch;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Tests\TestCase;
use Wm\WmPackage\Jobs\BuildAppPoisGeojsonJob;
use Wm\WmPackage\Jobs\Import\ImportAppJob;
use Wm\WmPackage\Jobs\TaxonomyWhere\SyncTaxonomyWhereJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Services\StorageService;

/**
 * oc:8677: l'import salva senza observer, quindi a fine import dei POI pois.geojson va rigenerato
 * esplicitamente, dopo il ricalcolo delle località che il file usa.
 */
class ImportAppJobPoisGeojsonRebuildTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // BuildAppPoisGeojsonJob è ShouldBeUnique con lock su Redis: Bus::fake() non evita
        // l'acquisizione del lock, e con il TestCase di maphub finirebbe nel Redis vero.
        config(['cache.stores.redis.driver' => 'array']);
        app('cache')->forgetDriver('redis');
        Bus::fake();
    }

    public function test_fine_import_poi_ricalcola_localita_e_poi_rigenera_il_file(): void
    {
        ImportAppJob::dispatchAfterEcImportBatch('ec_poi', 42);

        Bus::assertChained([
            SyncTaxonomyWhereJob::class,
            fn (BuildAppPoisGeojsonJob $job) => $job->uniqueId() === 'build_pois_geojson_42',
        ]);
    }

    public function test_se_il_ricalcolo_fallisce_il_file_si_rigenera_comunque(): void
    {
        ImportAppJob::dispatchAfterEcImportBatch('ec_poi', 42);

        // Su dev SyncTaxonomyWhereJob è già andato in timeout: la catena si ferma, ma il file
        // deve rigenerarsi lo stesso, con le località vecchie e con nome e testo di ricerca.
        $sync = Bus::dispatched(SyncTaxonomyWhereJob::class)->first();
        $this->assertNotNull($sync);
        Bus::assertNotDispatched(BuildAppPoisGeojsonJob::class);

        $sync->invokeChainCatchCallbacks(new \RuntimeException('timeout'));

        Bus::assertDispatched(
            BuildAppPoisGeojsonJob::class,
            fn (BuildAppPoisGeojsonJob $job) => $job->uniqueId() === 'build_pois_geojson_42'
        );
    }

    public function test_se_fallisce_il_build_della_catena_non_ne_lancia_un_secondo(): void
    {
        ImportAppJob::dispatchAfterEcImportBatch('ec_poi', 42);
        $sync = Bus::dispatched(SyncTaxonomyWhereJob::class)->first();

        $job = Mockery::mock(Job::class);
        $job->shouldReceive('resolveName')->andReturn(BuildAppPoisGeojsonJob::class);
        $sync->invokeChainCatchCallbacks(TimeoutExceededException::forJob($job));

        Bus::assertNotDispatched(BuildAppPoisGeojsonJob::class);
    }

    public function test_se_il_build_della_catena_va_in_errore_non_ne_lancia_un_secondo(): void
    {
        // Un errore vero del build (qui lo storage): l'eccezione non porta il job, lo riconosce
        // il frame BuildAppPoisGeojsonJob->handle() nello stack.
        $app = App::factory()->createQuietly();
        $storage = Mockery::mock(StorageService::class);
        // L'eccezione va creata dentro la chiamata: lo stack si fissa alla creazione, non al throw.
        $storage->shouldReceive('storePois')->andReturnUsing(
            fn () => throw new \RuntimeException('storage non raggiungibile')
        );
        $this->app->instance(StorageService::class, $storage);

        $errore = null;
        try {
            (new BuildAppPoisGeojsonJob($app->id))->handle();
        } catch (\RuntimeException $e) {
            $errore = $e;
        }
        $this->assertNotNull($errore);

        ImportAppJob::dispatchAfterEcImportBatch('ec_poi', $app->id);
        Bus::dispatched(SyncTaxonomyWhereJob::class)->first()->invokeChainCatchCallbacks($errore);

        Bus::assertNotDispatched(BuildAppPoisGeojsonJob::class);
    }

    public function test_se_il_ricalcolo_va_in_timeout_il_file_si_rigenera(): void
    {
        ImportAppJob::dispatchAfterEcImportBatch('ec_poi', 42);
        $sync = Bus::dispatched(SyncTaxonomyWhereJob::class)->first();

        $job = Mockery::mock(Job::class);
        $job->shouldReceive('resolveName')->andReturn(SyncTaxonomyWhereJob::class);
        $sync->invokeChainCatchCallbacks(TimeoutExceededException::forJob($job));

        Bus::assertDispatched(BuildAppPoisGeojsonJob::class);
    }

    public function test_il_batch_delle_immagini_aggancia_la_rigenerazione_a_fine_import(): void
    {
        $batch = Bus::batch([]);
        $method = new \ReflectionMethod(ImportAppJob::class, 'attachBatchCompletionCallback');
        $method->invoke(new ImportAppJob(28), $batch, 'ec_media', 42);

        foreach ($batch->finallyCallbacks() as $callback) {
            $callback(Mockery::mock(Batch::class));
        }

        Bus::assertDispatched(
            BuildAppPoisGeojsonJob::class,
            fn (BuildAppPoisGeojsonJob $job) => $job->uniqueId() === 'build_pois_geojson_42'
        );
    }

    public function test_fine_import_immagini_rigenera_il_file(): void
    {
        ImportAppJob::dispatchAfterEcImportBatch('ec_media', 42);

        Bus::assertDispatched(
            BuildAppPoisGeojsonJob::class,
            fn (BuildAppPoisGeojsonJob $job) => $job->uniqueId() === 'build_pois_geojson_42'
        );
        Bus::assertNotDispatched(SyncTaxonomyWhereJob::class);
    }

    public function test_fine_import_tracce_ricalcola_solo_le_localita(): void
    {
        ImportAppJob::dispatchAfterEcImportBatch('ec_track', 42);

        Bus::assertDispatched(SyncTaxonomyWhereJob::class);
        Bus::assertNotDispatched(BuildAppPoisGeojsonJob::class);
    }

    public function test_altre_entita_non_lanciano_nulla(): void
    {
        ImportAppJob::dispatchAfterEcImportBatch('layer', 42);

        Bus::assertNothingDispatched();
    }
}
