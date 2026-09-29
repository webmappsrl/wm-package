<?php

use Illuminate\Support\Facades\DB;
use Wm\WmPackage\TrailRegistry\Enums\TrailApplicationStatus;
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
