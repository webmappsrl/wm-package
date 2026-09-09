<?php

declare(strict_types=1);

use Illuminate\Bus\Batch;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Wm\WmPackage\Jobs\Import\ImportAppJob;
use Wm\WmPackage\Jobs\Import\ImportLayerJob;
use Wm\WmPackage\Jobs\UpdateAppConfigJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\Import\GeohubImportService;

uses(DatabaseTransactions::class);

it('remaps HOME and writes the config when there is no batch (--skip-dependencies path)', function () {
    fakeAppConfigStorage();

    $app = App::factory()->createQuietly();
    $layer = Layer::factory()->createQuietly(['app_id' => $app->id, 'properties' => ['geohub_id' => 133]]);
    DB_setConfigHome($app, [133]);

    ImportAppJob::finalizeAppImport($app->id, null);

    expect(DB_homeLayerIds($app))->toBe([$layer->id]);
});

it('remaps HOME and writes the config when the layer batch completed without failures', function () {
    fakeAppConfigStorage();

    $app = App::factory()->createQuietly();
    $layer = Layer::factory()->createQuietly(['app_id' => $app->id, 'properties' => ['geohub_id' => 133]]);
    DB_setConfigHome($app, [133]);

    ImportAppJob::finalizeAppImport($app->id, fakeBatch(hasFailures: false, cancelled: false));

    expect(DB_homeLayerIds($app))->toBe([$layer->id]);
});

it('still remaps HOME but skips the config write when the layer batch has failures, and logs a warning', function () {
    // ->channel() è chiamato prima di ->warning() in finalizeAppImport(); andReturnSelf()
    // tiene la chain sullo stesso mock così l'expectation su ->warning() può matchare.
    // Stesso pattern verificato in tests/Feature/FetchGravatarAvatarJobTest.php. ->info()
    // è ammesso a piacere: UpdateAppConfigHomeLayerIdsJob logga un Log::info() quando il
    // remap trova davvero un match (qui succede, a differenza della versione precedente di
    // questo test che non creava alcun Layer).
    Log::shouldReceive('channel')->once()->with('wm-package-failed-jobs')->andReturnSelf();
    Log::shouldReceive('warning')->once();
    Log::shouldReceive('info')->zeroOrMoreTimes();

    $app = App::factory()->createQuietly();
    $layer = Layer::factory()->createQuietly(['app_id' => $app->id, 'properties' => ['geohub_id' => 133]]);
    DB_setConfigHome($app, [133]);

    // Nessun fakeAppConfigStorage(): se il fix regredisse e la scrittura config girasse
    // comunque, UpdateAppConfigJob::handle() tenterebbe I/O reale verso StorageService e
    // fallirebbe (shard_name non configurato in questo test) — prova indiretta ma sufficiente
    // che il config write è stato davvero saltato.
    ImportAppJob::finalizeAppImport($app->id, fakeBatch(hasFailures: true, cancelled: false));

    // Causa 2 (fix post-review): un batch con failures NON deve più bloccare il remap HOME,
    // solo la scrittura del config. Prima del fix questo asseriva l'opposto ([133] invariato).
    expect(DB_homeLayerIds($app))->toBe([$layer->id]);
});

it('still remaps HOME but skips the config write when the layer batch was cancelled', function () {
    Log::shouldReceive('channel')->once()->with('wm-package-failed-jobs')->andReturnSelf();
    Log::shouldReceive('warning')->once();
    Log::shouldReceive('info')->zeroOrMoreTimes();

    $app = App::factory()->createQuietly();
    $layer = Layer::factory()->createQuietly(['app_id' => $app->id, 'properties' => ['geohub_id' => 133]]);
    DB_setConfigHome($app, [133]);

    ImportAppJob::finalizeAppImport($app->id, fakeBatch(hasFailures: false, cancelled: true));

    expect(DB_homeLayerIds($app))->toBe([$layer->id]);
});

/**
 * Regressione critica (review post-oc:8488): il closure passato a finally() in
 * queueEntityImport() DEVE poter essere serializzato da BatchRepository::store() quando
 * $batch->dispatch() gira per davvero — un `fn (Batch $batch) => $this->finalizeAppImport(...)`
 * cattura implicitamente $this (ImportAppJob → GeohubImportService → Connection PDO/Logger,
 * entrambi non serializzabili) e fa fallire l'intero batch layer con "Serialization of
 * 'Pdo\Pgsql' is not allowed" — nessun batch viene mai dispatchato, l'import si rompe per
 * intero. Bus::fake() avrebbe nascosto il bug (mai attraversa BatchRepository::store()):
 * qui NON si faka il Bus di proposito, si guida per davvero queueEntityImport() con un solo
 * id da importare, e ci si affida al fatto che serialize($batch->options) gira, nell'ordine
 * reale di DatabaseBatchRepository::store(), PRIMA della query INSERT — quindi un
 * regressione sulla serializzazione si manifesta comunque anche se la tabella job_batches
 * fosse assente. Qui è presente (`jobs`/`job_batches`/`failed_jobs`, migrate di default da
 * Orchestra\Testbench\Attributes\WithMigration su Tests\TestCase), quindi il batch arriva
 * fino in fondo: nessuna eccezione, il job resta in coda (QUEUE_CONNECTION forzata a
 * 'database' per non eseguirlo per davvero in questo test).
 */
it('does not throw a serialization error when the layer batch is actually dispatched (regression — do not Bus::fake() here)', function () {
    config(['queue.default' => 'database']);

    // La connessione 'geohub' punta di default a un host/DB non raggiungibile in questo
    // ambiente (GEOHUB_DB_HOST non configurato) — il suo $pdo resta una Closure lazy MAI
    // risolta finché nessuna query gira, e Laravel\SerializableClosure sa avvolgere una
    // Closure innocua senza errori. La produzione invece rompe con un PDO REALE già
    // risolto (checkUserExistence() lo risolve prima, altrove in transformData()) — per
    // riprodurre lo stesso sintomo puntiamo 'geohub' alla stessa connessione pgsql di
    // test (raggiungibile per davvero in questo container) e forziamo la risoluzione del
    // PDO con getPdo(), cosa che una GeohubImportService reale farebbe comunque appena
    // esegue una query verso Geohub.
    config(['database.connections.geohub' => [
        'driver' => 'pgsql',
        'host' => env('DB_HOST', 'db'),
        'port' => env('DB_PORT', '5432'),
        'database' => env('DB_DATABASE', 'wm_package'),
        'username' => env('DB_USERNAME', 'wm_package'),
        'password' => env('DB_PASSWORD', 'wm_package'),
        'charset' => 'utf8',
        'prefix' => '',
    ]]);
    // WmPackageServiceProvider::register() risolve/cachea 'geohub' all'avvio del container
    // testbench con l'host di default (vuoto in questo ambiente) — senza un purge esplicito
    // DB::connection('geohub') riusa quella connessione già cachata, ignorando l'override
    // config() appena fatto sopra.
    DB::purge('geohub');

    $app = App::factory()->createQuietly();

    // Sottoclasse NOMINATA (FakeLayerImportServiceForFinalizeTest, in fondo a questo file),
    // non un mock Mockery e non una classe anonima:
    //  - Mockery::mock() DISABILITA il costruttore originale per default (anche con
    //    ->makePartial() — verificato dal vivo: con un mock Mockery questo test passava
    //    anche con il bug reintrodotto, falso negativo), quindi $this->geohubImportService
    //    risulterebbe un oggetto "vuoto" banalmente serializzabile.
    //  - Una classe ANONIMA (`new class extends ... {}`) è invece SEMPRE rifiutata da
    //    serialize(), indipendentemente dal PDO annidato — anche questa dava un falso
    //    negativo (verificato dal vivo: throw generico "class@anonymous" con la versione
    //    static, MA nessun throw affatto con la versione col bug reintrodotto, attraverso
    //    il ramo isBindingRequired()/wrapClosures() di Laravel\SerializableClosure — la
    //    combinazione dei due comportamenti la rende inutilizzabile come reproducer).
    // Una sottoclasse nominata fa girare per davvero il costruttore ereditato di
    // GeohubImportService, che assegna $dbConnection = DB::connection('geohub') — una
    // Illuminate\Database\Connection reale — senza introdurre il confondimento "classe
    // anonima" nella catena di serializzazione.
    $service = new FakeLayerImportServiceForFinalizeTest;
    // Forza la risoluzione del PDO lazy: senza questa riga il test non riproduce il bug
    // (verificato dal vivo — vedi commento sopra), esattamente come una query reale verso
    // Geohub farebbe nel path di produzione prima che il batch layer venga costruito.
    $service->getDbConnection()->getPdo();

    $job = new ImportAppJob($app->id, []);

    $serviceProp = new ReflectionProperty($job, 'geohubImportService');
    $serviceProp->setAccessible(true);
    $serviceProp->setValue($job, $service);

    $queueEntityImport = new ReflectionMethod($job, 'queueEntityImport');
    $queueEntityImport->setAccessible(true);

    try {
        $queueEntityImport->invoke($job, 'layer', $app->user_id, 'app_id', $app->id);
    } catch (\Throwable $e) {
        // La forma esatta di questa asserzione non è cosmetica: expect(fn () => ...)
        // ->not->toThrow() qui NON intercetta l'eccezione in modo affidabile (verificato
        // dal vivo — con la stessa identica riproduzione del bug il test con quella forma
        // passava comunque, falso negativo). fail() esplicito, testato per riprodurre
        // davvero "Serialization of 'Pdo\Pgsql' is not allowed" con il bug reintrodotto.
        test()->fail('queueEntityImport() threw: '.get_class($e).': '.$e->getMessage());
    }

    expect(true)->toBeTrue();
});

/**
 * finalizeAppImport() nel percorso "successo" chiama UpdateAppConfigJob::handle() in modo
 * sincrono, che scrive per davvero su StorageService (dischi 'wmfe'/'conf') e legge
 * wm-package.shard_name — non impostato di default in questo ambiente di test. Fake dei
 * dischi + shard_name esplicito, per non tentare I/O reale né incappare in un TypeError
 * su un config assente non correlato a questo task.
 */
function fakeAppConfigStorage(): void
{
    config(['wm-package.shard_name' => 'wm-package-test']);
    Storage::fake('wmfe');
    Storage::fake('conf');
}

function fakeBatch(bool $hasFailures, bool $cancelled): Batch
{
    return Mockery::mock(Batch::class, [
        'hasFailures' => $hasFailures,
        'cancelled' => $cancelled,
        'failedJobs' => $hasFailures ? 3 : 0,
    ]);
}

function DB_setConfigHome(App $app, array $layerIds): void
{
    $home = array_map(
        static fn (int $id) => ['box_type' => 'layer', 'layer' => $id, 'title' => ['it' => 'x']],
        $layerIds
    );
    DB::table('apps')->where('id', $app->id)->update(['config_home' => json_encode(['HOME' => $home])]);
}

function DB_homeLayerIds(App $app): array
{
    $raw = DB::table('apps')->where('id', $app->id)->value('config_home');

    return array_column(json_decode($raw, true)['HOME'], 'layer');
}

/**
 * Test double per il test di serializzazione sopra. Deve essere una classe NOMINATA (non
 * anonima): vedi il commento nel test per il perché.
 */
class FakeLayerImportServiceForFinalizeTest extends GeohubImportService
{
    public function getGeohubIdsToImport(string $modelKey, ?array $wheres, ?array $data = null): array
    {
        return [555];
    }

    public function createJob(string $modelKey, int $geohubModelId, array $data = []): \Wm\WmPackage\Jobs\Import\BaseImportJob
    {
        return new ImportLayerJob($geohubModelId, $data);
    }
}
