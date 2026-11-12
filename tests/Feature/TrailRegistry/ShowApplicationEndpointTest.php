<?php

use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Laravel\Nova\Fields\ActionFields;
use Wm\WmPackage\Http\Controllers\Api\TrailRegistry\ShowApplicationController;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Tests\Feature\TrailRegistry\Support\ShardTrailApplication;
use Wm\WmPackage\TrailRegistry\Enums\TrailApplicationStatus;
use Wm\WmPackage\TrailRegistry\Enums\TrailCodeStatus;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication;
use Wm\WmPackage\TrailRegistry\Nova\Actions\ApproveTrailApplication;
use Wm\WmPackage\TrailRegistry\TrailRegistryService;

/**
 * L'endpoint di stato di una domanda (oc:8968).
 *
 * E' l'unico punto da cui chi chiama legge la domanda, e non cambia nulla: si
 * interroga in polling. Le rotte vivono nello shard e nel package non
 * esistono: qui se ne registra una al volo, con un nome neutro.
 */
beforeEach(function () {
    runTrailRegistryStubs();
    config(['wm-package.features.trail_registry.enabled' => true]);

    Bus::fake();
    DB::statement('ALTER SEQUENCE apps_id_seq RESTART WITH 1');
    config(['wm-package.shard_name' => 'wm_package_testing']);
    App::factory()->createQuietly();
    Storage::fake(config('media-library.disk_name', 'wmfe'));
    Storage::fake('wmfe');
    Storage::fake('public');

    Route::get('/test-trail-registry/applications/{application}', ShowApplicationController::class)
        ->middleware(['auth:api', SubstituteBindings::class])
        ->name('trail-registry-test.applications.show');

    config(['jwt.secret' => 'segreto-di-prova-per-il-guard-api']);
    $this->actingAs(User::factory()->create(), 'api');
});

function showApplication(TrailApplication|int $application)
{
    $id = $application instanceof TrailApplication ? $application->getKey() : $application;

    return test()->getJson(route('trail-registry-test.applications.show', $id));
}

/**
 * Scrive `dem_data` da SQL, come fa il calcolo vero.
 */
function statusDemData(TrailApplication $application, array $demData): void
{
    DB::update(
        "UPDATE trail_applications SET properties = jsonb_set(CASE WHEN jsonb_typeof(properties) = 'object' THEN properties ELSE '{}'::jsonb END, '{dem_data}', ?::jsonb) WHERE id = ?",
        [json_encode($demData), $application->getKey()]
    );
}

/**
 * Scrive la geometria da SQL, come il resto del package: mai dall'ORM.
 */
function statusGeometry(TrailApplication $application, string $wkt): void
{
    DB::update('UPDATE trail_applications SET geometry = ST_GeomFromText(?, 4326)::geography WHERE id = ?', [$wkt, $application->getKey()]);
}

/**
 * Una domanda con il suo codice riservato, portati entrambi nello stato
 * indicato. Lo stato si scrive da SQL: il modello e' geometrico, e risalvarlo
 * via Eloquent corrompe la geometria.
 */
function applicationWithCode(TrailApplicationStatus $status, TrailCodeStatus $codeStatus, ?string $rejectionReason = null): TrailApplication
{
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');

    $application = TrailApplication::factory()->complete()->create();
    statusGeometry($application, 'MULTILINESTRING Z ((1 1 100, 2 2 200))');
    $code = app(TrailRegistryService::class)->reserve($application->refresh());

    $code->newQuery()->whereKey($code->getKey())->update(['status' => $codeStatus->value]);
    $application->newQuery()->whereKey($application->getKey())->update([
        'status' => $status->value,
        'rejection_reason' => $rejectionReason,
    ]);

    return $application->refresh();
}

it('legge una domanda in compilazione senza numero e senza motivo', function () {
    $application = TrailApplication::factory()->complete()->create();

    showApplication($application)
        ->assertOk()
        ->assertJsonPath('id', $application->getKey())
        ->assertJsonPath('status', 'under_compilation')
        ->assertJsonPath('code', null)
        ->assertJsonPath('rejection_reason', null)
        ->assertJsonStructure(['id', 'status', 'dem_data', 'dem_status', 'code', 'rejection_reason']);
});

it('non trasmette una domanda completa: leggere non cambia nulla', function () {
    // E' la ragione per cui la lettura e' un endpoint a se': una domanda
    // completa in compilazione, letta, non deve finire in istruttoria.
    $application = TrailApplication::factory()->complete()->create();

    showApplication($application)->assertOk();
    showApplication($application)->assertOk();

    expect($application->refresh()->status)->toBe(TrailApplicationStatus::UnderCompilation);
});

it('non mostra il numero riservato durante l istruttoria', function () {
    // Il riservato puo' ancora cambiare: mostrato ora, chi chiama lo
    // tratterebbe come definitivo.
    $application = applicationWithCode(TrailApplicationStatus::UnderReview, TrailCodeStatus::Reserved);

    showApplication($application)
        ->assertOk()
        ->assertJsonPath('status', 'under_review')
        ->assertJsonPath('code', null);
});

it('mostra il numero del sentiero a domanda approvata', function () {
    // Approvata con l'action vera, non scrivendo lo stato: un codice assegnato
    // deve appartenere a un sentiero (vincolo `holder_check`), e il sentiero
    // lo crea l'approvazione.
    $application = applicationWithCode(TrailApplicationStatus::UnderReview, TrailCodeStatus::Reserved);
    (new ApproveTrailApplication)->handle(new ActionFields(collect(), collect()), collect([$application]));
    $application->refresh();

    showApplication($application)
        ->assertOk()
        ->assertJsonPath('status', 'approved')
        ->assertJsonPath('code', 'ZNUB500')
        ->assertJsonPath('rejection_reason', null);
});

it('mostra il motivo a domanda respinta, e nessun numero', function () {
    $application = applicationWithCode(TrailApplicationStatus::Rejected, TrailCodeStatus::Released, 'Tracciato sovrapposto al sentiero 105');

    showApplication($application)
        ->assertOk()
        ->assertJsonPath('status', 'rejected')
        ->assertJsonPath('rejection_reason', 'Tracciato sovrapposto al sentiero 105')
        ->assertJsonPath('code', null);
});

it('non mostra un motivo rimasto scritto su una domanda che non e respinta', function () {
    // Il motivo appartiene al respingimento: se una domanda torna in
    // istruttoria con il campo ancora valorizzato, non e' un esito.
    $application = applicationWithCode(TrailApplicationStatus::UnderReview, TrailCodeStatus::Reserved, 'Motivo di un esito precedente');

    showApplication($application)->assertOk()->assertJsonPath('rejection_reason', null);
});

// `dem_status` segue `needsDem()`, il criterio che decide se il calcolo viene
// riaccodato: con una geometria valida e i dati assenti il calcolo e' davvero in
// arrivo.
it('dichiara il calcolo in elaborazione se i dati tecnici mancano e la geometria e valida', function () {
    $application = TrailApplication::factory()->complete()->create();
    statusGeometry($application, 'MULTILINESTRING Z ((1 1 100, 2 2 200))');

    showApplication($application)
        ->assertOk()
        ->assertJsonPath('dem_status', 'processing')
        ->assertJsonPath('dem_data', null);
});

it('non promette il calcolo se la geometria manca, perche nessun ricalcolo ripartirebbe', function () {
    $application = TrailApplication::factory()->complete()->create();

    showApplication($application)
        ->assertOk()
        ->assertJsonPath('dem_status', 'computed')
        ->assertJsonPath('dem_data', null);
});

it('non promette il calcolo se la geometria non e valida', function () {
    $application = TrailApplication::factory()->complete()->create();
    statusGeometry($application, 'MULTILINESTRING Z ((1 1 0, 1 1 0))');
    expect(DB::selectOne('SELECT ST_IsValid(geometry::geometry) AS v FROM trail_applications WHERE id = ?', [$application->getKey()])->v)->toBeFalse();

    showApplication($application)
        ->assertOk()
        ->assertJsonPath('dem_status', 'computed')
        ->assertJsonPath('dem_data', null);
});

it('dichiara il calcolo in elaborazione se i dati ci sono ma le quote sono tutte a zero', function () {
    $application = TrailApplication::factory()->complete()->create();
    statusGeometry($application, 'MULTILINESTRING Z ((1 1 0, 2 2 0))');
    statusDemData($application, ['distance' => 12.5]);

    showApplication($application)
        ->assertOk()
        ->assertJsonPath('dem_status', 'processing')
        ->assertJsonPath('dem_data.distance', 12.5);
});

it('restituisce i dati tecnici gia calcolati', function () {
    $application = TrailApplication::factory()->complete()->create();
    statusGeometry($application, 'MULTILINESTRING Z ((1 1 100, 2 2 200))');
    statusDemData($application, ['distance' => 12.5, 'ascent' => 340]);

    showApplication($application)
        ->assertOk()
        ->assertJsonPath('dem_status', 'computed')
        ->assertJsonPath('dem_data.distance', 12.5)
        ->assertJsonPath('dem_data.ascent', 340);
});

it('su un risultato parziale restituisce cio che c e senza promettere il completamento', function () {
    $application = TrailApplication::factory()->complete()->create();
    statusGeometry($application, 'MULTILINESTRING Z ((1 1 100, 2 2 200))');
    statusDemData($application, ['distance' => 12.5, 'ascent' => null, 'duration_forward' => null]);

    showApplication($application)
        ->assertOk()
        ->assertJsonPath('dem_status', 'computed')
        ->assertJsonPath('dem_data.distance', 12.5)
        ->assertJsonPath('dem_data.ascent', null);
});

it('risponde allo stesso modo su una domanda d ufficio e su un identificatore inesistente', function () {
    $office = TrailApplication::factory()->complete()->create(['source' => 'office']);
    $missingId = $office->getKey() + 1000;

    $onOffice = showApplication($office);
    $onMissing = showApplication($missingId);

    expect($onOffice->status())->toBe(404)
        ->and($onMissing->status())->toBe(404);

    // Il corpo e' lo stesso a meno dell'identificatore, quando l'handler lo cita.
    $normalize = fn ($response, $id) => str_replace((string) $id, '{id}', $response->getContent());
    expect($normalize($onOffice, $office->getKey()))->toBe($normalize($onMissing, $missingId));
});

it('legge la domanda anche con la classe che lo shard ha configurato', function () {
    $application = TrailApplication::factory()->complete()->create();
    config(['wm-package.features.trail_registry.models.application' => ShardTrailApplication::class]);

    showApplication($application)
        ->assertOk()
        ->assertJsonPath('id', $application->getKey())
        ->assertJsonPath('status', 'under_compilation');
});
