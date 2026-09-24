<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\TrailRegistry\Enums\TrailApplicationStatus;
use Wm\WmPackage\TrailRegistry\Jobs\UpdateTrailApplicationDemJob;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication;
use Wm\WmPackage\TrailRegistry\TrailRegistryService;

beforeEach(function () {
    runTrailRegistryStubs();
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');
});

function applicationWithGeometry(array $properties = []): TrailApplication
{
    $application = TrailApplication::factory()->create(['properties' => $properties]);
    DB::statement(
        'UPDATE trail_applications SET geometry = ST_GeomFromText(?, 4326) WHERE id = ?',
        ['MULTILINESTRING Z ((1 1 0, 2 2 0))', $application->id]
    );

    return $application->fresh();
}

it('accoda il job DEM alla creazione', function () {
    $application = TrailApplication::factory()->create();

    Bus::assertDispatched(UpdateTrailApplicationDemJob::class, fn ($job) => $job->applicationId === $application->id);
});

it('accoda il job DEM dopo il commit', function () {
    // Sotto Bus::fake() il rinvio al commit non e' osservabile: BusFake::
    // dispatch() registra il job subito, senza guardare il livello di
    // transazione ne' $command->afterCommit (la deferral vive solo nel
    // Dispatcher reale, mai interpellato qui). Verifichiamo quindi che il
    // job sia marcato afterCommit = true: lo scarto al rollback lo
    // garantisce poi il dispatcher vero di Laravel, non questo test.
    $application = TrailApplication::factory()->create();

    Bus::assertDispatched(
        UpdateTrailApplicationDemJob::class,
        fn ($job) => $job->applicationId === $application->id && $job->afterCommit === true
    );
});

it('serve il DEM solo con geometria valida e dem_data vuoto', function () {
    expect(applicationWithGeometry()->needsDem())->toBeTrue()
        ->and(applicationWithGeometry(['dem_data' => []])->needsDem())->toBeTrue()
        ->and(applicationWithGeometry(['dem_data' => ['ascent' => 10]])->needsDem())->toBeFalse();
});

it('non rilancia il job dove dem_data esiste gia', function () {
    $application = applicationWithGeometry(['dem_data' => ['ascent' => 10]]);
    Bus::fake([UpdateTrailApplicationDemJob::class]);

    $application->dispatchDemIfMissing();

    Bus::assertNotDispatched(UpdateTrailApplicationDemJob::class);
});

it('rilancia il job dove dem_data manca', function () {
    $application = applicationWithGeometry();
    Bus::fake([UpdateTrailApplicationDemJob::class]);
    // La creazione ha gia' accodato il job (ShouldBeUnique) per questo stesso
    // id: sotto Bus::fake il lock viene comunque acquisito (oc:8564) e vive
    // in un array separato dallo storage, che flush() non tocca (ArrayStore).
    // Un driver nuovo di zecca riparte con locks vuoti.
    app('cache')->forgetDriver('redis');

    $application->dispatchDemIfMissing();

    Bus::assertDispatched(UpdateTrailApplicationDemJob::class);
});

it('la mappa dell istanza e quella del suo codice', function () {
    $application = applicationWithGeometry();
    $code = app(TrailRegistryService::class)->reserve($application);

    expect($application->fresh()->getFeatureCollectionMap())->toBe($code->fresh()->getFeatureCollectionMap());
});

it('un istanza rifiutata usa l ultimo codice', function () {
    $application = applicationWithGeometry();
    $service = app(TrailRegistryService::class);
    $code = $service->reserve($application);
    // Stessa chiamata di RejectTrailApplication::handle().
    $service->release($code, 'application_rejected', auth()->id());
    $application->update(['status' => TrailApplicationStatus::Rejected]);

    expect($application->fresh()->mapCode()?->id)->toBe($code->id);
});

it('senza codici la mappa e la sola traccia', function () {
    $application = applicationWithGeometry();

    expect($application->mapCode())->toBeNull()
        ->and($application->getFeatureCollectionMap()['features'])->toHaveCount(1);
});
