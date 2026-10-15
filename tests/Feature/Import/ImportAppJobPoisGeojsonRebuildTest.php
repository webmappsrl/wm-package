<?php

namespace Wm\WmPackage\Tests\Feature\Import;

use Illuminate\Support\Facades\Bus;
use Tests\TestCase;
use Wm\WmPackage\Jobs\BuildAppPoisGeojsonJob;
use Wm\WmPackage\Jobs\Import\ImportAppJob;
use Wm\WmPackage\Jobs\TaxonomyWhere\SyncTaxonomyWhereJob;

/**
 * oc:8677: l'import salva senza observer, quindi a fine import dei POI pois.geojson va rigenerato
 * esplicitamente, dopo il ricalcolo delle località che il file usa.
 */
class ImportAppJobPoisGeojsonRebuildTest extends TestCase
{
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
