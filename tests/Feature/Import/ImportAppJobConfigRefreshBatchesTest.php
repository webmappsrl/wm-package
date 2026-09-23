<?php

declare(strict_types=1);

use Illuminate\Bus\Batch;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Wm\WmPackage\Jobs\Import\ImportAppJob;
use Wm\WmPackage\Jobs\Import\ImportEcMediaJob;
use Wm\WmPackage\Jobs\Import\ImportEcPoiJob;
use Wm\WmPackage\Jobs\Import\ImportEcTrackJob;
use Wm\WmPackage\Jobs\Import\ImportLayerJob;
use Wm\WmPackage\Jobs\Import\ImportTaxonomyActivityJob;
use Wm\WmPackage\Jobs\TaxonomyWhere\SyncTaxonomyWhereJob;
use Wm\WmPackage\Jobs\UpdateAppConfigJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Services\Import\GeohubImportService;

uses(DatabaseTransactions::class);

/**
 * Inietta un GeohubImportService mockato in un ImportAppJob (proprietà protected, non
 * esposta da nessun setter) e invoca un metodo protected/private su di esso.
 *
 * Estratto per evitare di ripetere lo stesso blocco reflection in ogni test di questo file.
 */
function withMockedGeohubService(ImportAppJob $job, GeohubImportService $service): void
{
    $prop = new ReflectionProperty($job, 'geohubImportService');
    $prop->setAccessible(true);
    $prop->setValue($job, $service);
}

function invokeProtected(object $object, string $method, mixed ...$arguments): mixed
{
    $reflection = new ReflectionMethod($object, $method);
    $reflection->setAccessible(true);

    return $reflection->invoke($object, ...$arguments);
}

/**
 * Finding 4 (review post-oc:8488): config_section_map() legge getAllPoiTaxonomies() (ec_poi +
 * taxonomy_activity/poi_types), il feature_image per-layer (ec_media) e MAP.bbox/
 * MAP.filters.activities (ec_track) — batch indipendenti dal batch layer, senza garanzia di
 * completamento relativa tra loro. finalizeAppImport() (agganciato SOLO al batch layer) può
 * quindi scrivere un config fresco rispetto ai layer ma ancora privo di queste sezioni. Fix: i
 * batch di ImportAppJob::CONFIG_DEPENDENT_BATCHES (ec_media, ec_poi, ec_track,
 * taxonomy_activity, taxonomy_poi_types — NON taxonomy_theme, che non alimenta nessuna chiave
 * di config_section_map(), rimosso in un secondo giro: review post-oc:8488, punto 6) agganciano
 * anche loro un finally() che accoda (dispatch, non dispatchSync) un fresh UpdateAppConfigJob
 * per lo stesso app id — SOLO se ImportAppJob::layerBatchIsPublishReady() lo conferma sicuro
 * (punto 5 della stessa review: senza questo gate, questo refresh scriveva il config
 * incondizionatamente, ignorando lo stato del batch layer e annullando il gate di
 * finalizeAppImport()). Con nessun sentinel in cache (nessuna dipendenza 'layer' in questo
 * import, il caso di default in questi test), il gate è sempre true.
 *
 * Bus::fake() è lo strumento corretto qui (a differenza del test di serializzazione in
 * ImportAppJobFinalizeTest.php): questo è puro wiring — non stiamo verificando che il closure
 * sia serializzabile, solo che sia collegato al punto giusto. Bus::fake() intercetta anche
 * Bus::batch(...)->dispatch(), restituendo un PendingBatchFake che espone i finally callback
 * registrati (finallyCallbacks()) senza mai eseguirli automaticamente — li invochiamo a mano
 * per simulare il completamento del batch.
 */
it('queues a fresh UpdateAppConfigJob when the ec_media batch finishes', function () {
    Bus::fake();

    $app = App::factory()->createQuietly();

    $service = Mockery::mock(GeohubImportService::class);
    $service->shouldReceive('getGeohubIdsToImport')
        ->once()
        ->with('ec_media', Mockery::any(), Mockery::any())
        ->andReturn([777]);
    $service->shouldReceive('createJob')
        ->once()
        ->with('ec_media', 777, Mockery::any())
        ->andReturn(new ImportEcMediaJob(777, ['app_id' => $app->id]));

    $job = new ImportAppJob($app->id, []);

    withMockedGeohubService($job, $service);

    invokeProtected($job, 'queueEntityImport', 'ec_media', $app->user_id, 'user_id', $app->id);

    $batches = Bus::batched(fn ($batch) => true);
    expect($batches)->toHaveCount(1);

    $finallyCallbacks = $batches->first()->finallyCallbacks();
    expect($finallyCallbacks)->toHaveCount(1);

    // Simula il completamento del batch: invoca il finally() registrato, come farebbe
    // Horizon/il worker della coda quando l'ultimo job del batch termina.
    ($finallyCallbacks[0])(Mockery::mock(Batch::class));

    Bus::assertDispatched(UpdateAppConfigJob::class, fn (UpdateAppConfigJob $dispatched) => $dispatched->appId === $app->id);
});

/**
 * Copertura anche per una delle due taxonomy rimaste (rappresentativa dell'altra, stesso
 * meccanismo — vedi ImportAppJob::CONFIG_DEPENDENT_BATCHES). taxonomy_theme è stata rimossa
 * dalla lista (punto 6): non ha alcun punto di lettura in config_section_map().
 */
it('queues a fresh UpdateAppConfigJob when a taxonomy batch finishes', function () {
    Bus::fake();

    $app = App::factory()->createQuietly();

    $service = Mockery::mock(GeohubImportService::class);
    $service->shouldReceive('getUsedTaxonomyGeohubIdsForApp')
        ->once()
        ->andReturn([888]);
    $service->shouldReceive('getGeohubIdsToImport')
        ->once()
        ->with('taxonomy_activity', Mockery::any(), Mockery::any())
        ->andReturn([888]);
    $service->shouldReceive('createJob')
        ->once()
        ->with('taxonomy_activity', 888, Mockery::any())
        ->andReturn(new ImportTaxonomyActivityJob(888, []));

    $job = new ImportAppJob($app->id, []);

    withMockedGeohubService($job, $service);

    invokeProtected($job, 'queueEntityImport', 'taxonomy_activity', $app->user_id, 'user_id', $app->id);

    $batches = Bus::batched(fn ($batch) => true);
    expect($batches)->toHaveCount(1);

    $finallyCallbacks = $batches->first()->finallyCallbacks();
    expect($finallyCallbacks)->toHaveCount(1);

    ($finallyCallbacks[0])(Mockery::mock(Batch::class));

    Bus::assertDispatched(UpdateAppConfigJob::class, fn (UpdateAppConfigJob $dispatched) => $dispatched->appId === $app->id);
});

/**
 * Finding 6 (review post-oc:8488): ec_track alimenta MAP.bbox (fallback quando map_bbox è
 * null) e MAP.filters.activities — aggiunto a CONFIG_DEPENDENT_BATCHES, verificato qui non
 * diversamente dagli altri.
 */
it('queues a fresh UpdateAppConfigJob and a SyncTaxonomyWhereJob when the ec_track batch finishes', function () {
    Bus::fake();

    $app = App::factory()->createQuietly();

    $service = Mockery::mock(GeohubImportService::class);
    $service->shouldReceive('getGeohubIdsToImport')
        ->once()
        ->with('ec_track', Mockery::any(), Mockery::any())
        ->andReturn([555]);
    $service->shouldReceive('createJob')
        ->once()
        ->with('ec_track', 555, Mockery::any())
        ->andReturn(new ImportEcTrackJob(555, ['app_id' => $app->id]));

    $job = new ImportAppJob($app->id, []);

    withMockedGeohubService($job, $service);

    invokeProtected($job, 'queueEntityImport', 'ec_track', $app->user_id, 'user_id', $app->id);

    $batches = Bus::batched(fn ($batch) => true);
    expect($batches)->toHaveCount(1);

    $finallyCallbacks = $batches->first()->finallyCallbacks();
    expect($finallyCallbacks)->toHaveCount(2);

    foreach ($finallyCallbacks as $callback) {
        $callback(Mockery::mock(Batch::class));
    }

    Bus::assertDispatched(UpdateAppConfigJob::class, fn (UpdateAppConfigJob $dispatched) => $dispatched->appId === $app->id);
    Bus::assertDispatched(SyncTaxonomyWhereJob::class);
});

it('queues a SyncTaxonomyWhereJob when the ec_poi batch finishes', function () {
    Bus::fake();

    $app = App::factory()->createQuietly();

    $service = Mockery::mock(GeohubImportService::class);
    $service->shouldReceive('getGeohubIdsToImport')
        ->once()
        ->with('ec_poi', Mockery::any(), Mockery::any())
        ->andReturn([444]);
    $service->shouldReceive('createJob')
        ->once()
        ->with('ec_poi', 444, Mockery::any())
        ->andReturn(new ImportEcPoiJob(444, ['app_id' => $app->id]));

    $job = new ImportAppJob($app->id, []);

    withMockedGeohubService($job, $service);

    invokeProtected($job, 'queueEntityImport', 'ec_poi', $app->user_id, 'user_id', $app->id);

    $batches = Bus::batched(fn ($batch) => true);
    expect($batches)->toHaveCount(1);

    $finallyCallbacks = $batches->first()->finallyCallbacks();
    expect($finallyCallbacks)->toHaveCount(2);

    foreach ($finallyCallbacks as $callback) {
        $callback(Mockery::mock(Batch::class));
    }

    Bus::assertDispatched(UpdateAppConfigJob::class, fn (UpdateAppConfigJob $dispatched) => $dispatched->appId === $app->id);
    Bus::assertDispatched(SyncTaxonomyWhereJob::class);
});

/**
 * Punto 5 della review post-oc:8488: il refresh da CONFIG_DEPENDENT_BATCHES non deve scrivere
 * se il batch layer dello STESSO import è ancora in corso — altrimenti pubblica un config con
 * MAP.layers potenzialmente incompleto, esattamente il caso che finalizeAppImport() esiste per
 * evitare. Simula lo stato "pending" scritto da processDependencies() prima di dispatchare
 * qualunque batch.
 */
it('does not queue an UpdateAppConfigJob while the layer batch of the same import is still pending', function () {
    Bus::fake();

    $app = App::factory()->createQuietly();

    Cache::put("wm-package:import-layer-batch:{$app->id}", 'pending', now()->addHours(6));

    $service = Mockery::mock(GeohubImportService::class);
    $service->shouldReceive('getGeohubIdsToImport')
        ->once()
        ->with('ec_media', Mockery::any(), Mockery::any())
        ->andReturn([777]);
    $service->shouldReceive('createJob')
        ->once()
        ->with('ec_media', 777, Mockery::any())
        ->andReturn(new ImportEcMediaJob(777, ['app_id' => $app->id]));

    $job = new ImportAppJob($app->id, []);

    withMockedGeohubService($job, $service);

    invokeProtected($job, 'queueEntityImport', 'ec_media', $app->user_id, 'user_id', $app->id);

    $batches = Bus::batched(fn ($batch) => true);
    ($batches->first()->finallyCallbacks()[0])(Mockery::mock(Batch::class));

    Bus::assertNotDispatched(UpdateAppConfigJob::class);
});

/**
 * Stesso punto 5, ma testato direttamente su layerBatchIsPublishReady() invece che attraverso
 * il dispatch di un batch CONFIG_DEPENDENT_BATCHES: quella parte del meccanismo (il cablaggio
 * "il finally() chiama il gate") è già coperta dal test "pending" sopra. Qui si verifica la
 * risposta del gate contro un batch REALE (riga in job_batches, non un Mock di Batch) — niente
 * Bus::fake(): Bus::findBatch() legge lo stato vero dalla tabella, come farebbe in produzione.
 * Una riga fallita: batch "finito" ma con failed_jobs > 0.
 */
it('reports the gate as unsafe once the layer batch has genuinely failed', function () {
    $app = App::factory()->createQuietly();

    DB::table('job_batches')->insert([
        'id' => 'layer-batch-failed',
        'name' => 'app-dependencies-layer-import-batch',
        'total_jobs' => 2,
        'pending_jobs' => 0,
        'failed_jobs' => 1,
        'failed_job_ids' => '[]',
        'options' => serialize([]),
        'cancelled_at' => null,
        'created_at' => now()->getTimestamp(),
        'finished_at' => now()->getTimestamp(),
    ]);

    Cache::put("wm-package:import-layer-batch:{$app->id}", 'layer-batch-failed', now()->addHours(6));

    expect(ImportAppJob::layerBatchIsPublishReady($app->id))->toBeFalse();
});

/**
 * Simmetrico: una riga completata senza fallimenti riporta il gate come sicuro.
 */
it('reports the gate as safe once the layer batch has genuinely completed successfully', function () {
    $app = App::factory()->createQuietly();

    DB::table('job_batches')->insert([
        'id' => 'layer-batch-success',
        'name' => 'app-dependencies-layer-import-batch',
        'total_jobs' => 2,
        'pending_jobs' => 0,
        'failed_jobs' => 0,
        'failed_job_ids' => '[]',
        'options' => serialize([]),
        'cancelled_at' => null,
        'created_at' => now()->getTimestamp(),
        'finished_at' => now()->getTimestamp(),
    ]);

    Cache::put("wm-package:import-layer-batch:{$app->id}", 'layer-batch-success', now()->addHours(6));

    expect(ImportAppJob::layerBatchIsPublishReady($app->id))->toBeTrue();
});

/**
 * Una riga ancora in corso (nessun finished_at) riporta il gate come non sicuro — non basta
 * "nessun fallimento finora", il batch deve essere davvero concluso.
 */
it('reports the gate as unsafe while the layer batch row exists but has not finished yet', function () {
    $app = App::factory()->createQuietly();

    DB::table('job_batches')->insert([
        'id' => 'layer-batch-running',
        'name' => 'app-dependencies-layer-import-batch',
        'total_jobs' => 2,
        'pending_jobs' => 1,
        'failed_jobs' => 0,
        'failed_job_ids' => '[]',
        'options' => serialize([]),
        'cancelled_at' => null,
        'created_at' => now()->getTimestamp(),
        'finished_at' => null,
    ]);

    Cache::put("wm-package:import-layer-batch:{$app->id}", 'layer-batch-running', now()->addHours(6));

    expect(ImportAppJob::layerBatchIsPublishReady($app->id))->toBeFalse();
});

/**
 * processDependencies() scrive il sentinel 'pending' PRIMA di dispatchare qualunque batch
 * (review post-oc:8488, punto 5) — chiude la corsa in cui un batch di CONFIG_DEPENDENT_BATCHES
 * finisce prima che il batch layer sia anche solo dispatchato. Verifica diretta: quando 'layer'
 * è tra le dipendenze e ha id da importare, subito DOPO processDependencies() (batch layer
 * dispatchato ma non ancora completato, Bus::fake() non lo esegue mai) il gate deve risultare
 * "non sicuro" — prova che il sentinel non è mai rimasto vuoto/permissivo durante la finestra
 * tra dispatch del batch layer e suo completamento.
 */
it('leaves the gate unsafe right after processDependencies() dispatches a real layer batch', function () {
    Bus::fake();

    $app = App::factory()->createQuietly();

    $service = Mockery::mock(GeohubImportService::class);
    $service->shouldReceive('getGeohubIdsToImport')
        ->with('layer', Mockery::any(), Mockery::any())
        ->andReturn([333]);
    $service->shouldReceive('createJob')
        ->with('layer', 333, Mockery::any())
        ->andReturn(new ImportLayerJob(333, ['app_id' => $app->id]));

    $job = new ImportAppJob($app->id, ['allowed_dependencies' => ['layer']]);

    withMockedGeohubService($job, $service);

    invokeProtected($job, 'processDependencies', ['user_id' => $app->user_id, 'tiles' => null], $app);

    expect(ImportAppJob::layerBatchIsPublishReady($app->id))->toBeFalse();
});

/**
 * Simmetrico: quando 'layer' è tra le dipendenze ma non ha id da importare, processDependencies()
 * finisce sul ramo sincrono (finalizeAppImport($appId, null) + Cache::forget()) — il gate torna
 * subito sicuro, nessun batch da aspettare.
 */
it('leaves the gate safe right after processDependencies() when layer has no ids to import', function () {
    Bus::fake();

    // Questo ramo (nessun id per 'layer') chiama finalizeAppImport($appId, null), che scrive
    // il config SINCRONO (non in coda) — senza fake dello storage tenta I/O reale con
    // wm-package.shard_name non configurato in questo ambiente di test (stesso gap noto di
    // ImportAppJobFinalizeTest.php::fakeAppConfigStorage()).
    config(['wm-package.shard_name' => 'wm-package-test']);
    Storage::fake('wmfe');
    Storage::fake('conf');

    $app = App::factory()->createQuietly();

    $service = Mockery::mock(GeohubImportService::class);
    $service->shouldReceive('getGeohubIdsToImport')->andReturn([]);

    $job = new ImportAppJob($app->id, ['allowed_dependencies' => ['layer']]);

    withMockedGeohubService($job, $service);

    invokeProtected($job, 'processDependencies', ['user_id' => $app->user_id, 'tiles' => null], $app);

    expect(ImportAppJob::layerBatchIsPublishReady($app->id))->toBeTrue();
});

/**
 * Il batch layer NON deve accodare un secondo UpdateAppConfigJob via CONFIG_DEPENDENT_BATCHES
 * — ha già il proprio finally() dedicato (finalizeAppImport(), coperto in
 * ImportAppJobFinalizeTest.php). Regressione: se 'layer' finisse anche nella lista
 * CONFIG_DEPENDENT_BATCHES per errore, questo test lo rileverebbe (due finally() registrati
 * invece di uno).
 */
it('does not double-register a finally() callback for the layer batch itself', function () {
    Bus::fake();

    $app = App::factory()->createQuietly();

    $service = Mockery::mock(GeohubImportService::class);
    $service->shouldReceive('getGeohubIdsToImport')
        ->once()
        ->with('layer', Mockery::any(), Mockery::any())
        ->andReturn([999]);
    $service->shouldReceive('createJob')
        ->once()
        ->andReturn(new ImportLayerJob(999, ['app_id' => $app->id]));

    $job = new ImportAppJob($app->id, []);

    withMockedGeohubService($job, $service);

    invokeProtected($job, 'queueEntityImport', 'layer', $app->user_id, 'app_id', $app->id);

    $batches = Bus::batched(fn ($batch) => true);
    expect($batches)->toHaveCount(1)
        ->and($batches->first()->finallyCallbacks())->toHaveCount(1);
});
