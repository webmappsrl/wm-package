<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\TrailRegistry\Enums\TrailApplicationStatus;
use Wm\WmPackage\TrailRegistry\Enums\TrailCodeStatus;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryAnomaly;
use Wm\WmPackage\TrailRegistry\Nova\Fields\TrailRegistryMap;
use Wm\WmPackage\TrailRegistry\Nova\TrailApplication as TrailApplicationResource;
use Wm\WmPackage\TrailRegistry\Nova\TrailRegistryAnomaly as TrailRegistryAnomalyResource;
use Wm\WmPackage\TrailRegistry\Nova\TrailRegistryCode as TrailRegistryCodeResource;
use Wm\WmPackage\TrailRegistry\TrailRegistryService;

beforeEach(function () {
    runTrailRegistryStubs();
    Bus::fake();
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');
});

function mapFieldOf(array $fields): ?TrailRegistryMap
{
    return collect($fields)->first(fn ($f) => $f instanceof TrailRegistryMap);
}

function applicationForMap(): TrailApplication
{
    $application = TrailApplication::factory()->create();
    DB::statement('UPDATE trail_applications SET geometry = ST_GeomFromText(?, 4326) WHERE id = ?', ['MULTILINESTRING Z ((1 1 0, 2 2 0))', $application->id]);

    return $application->fresh();
}

it('la versione della mappa cambia quando si sostituisce il numero', function () {
    $application = applicationForMap();
    $service = app(TrailRegistryService::class);
    $code = $service->reserve($application);
    $prima = $application->fresh()->mapVersion();

    $service->replaceNumber($code->fresh(), 90, '0', null);

    expect($application->fresh()->mapVersion())->not->toBe($prima);
});

it('la versione della mappa cambia quando cambia lo stato del codice, anche a parita di id', function () {
    $application = applicationForMap();
    $code = app(TrailRegistryService::class)->reserve($application);
    $prima = $application->fresh()->mapVersion();

    // Stesso secondo, stesso id: deve bastare lo stato (e' il caso di «Rifiuta»).
    $code->update(['status' => TrailCodeStatus::Released]);

    expect($application->fresh()->mapVersion())->not->toBe($prima);
});

it('la versione della mappa cambia quando cambia lo stato dell istanza', function () {
    $application = applicationForMap();
    app(TrailRegistryService::class)->reserve($application);
    $prima = $application->fresh()->mapVersion();

    $application->update(['status' => TrailApplicationStatus::Rejected]);

    expect($application->fresh()->mapVersion())->not->toBe($prima);
});

it('il campo espone la versione della mappa come meta', function () {
    $application = applicationForMap();
    app(TrailRegistryService::class)->reserve($application);

    $field = TrailRegistryMap::make('Mappa', 'geometry');
    $field->resolve($application->fresh());

    expect($field->meta['mapVersion'] ?? null)->toBe($application->fresh()->mapVersion());
});

it('il campo nasce senza profilo altimetrico', function () {
    expect(TrailRegistryMap::make('Mappa', 'geometry')->jsonSerialize()['enableSlopeChart'])->toBeFalse();
});

it('istanza e codice accendono il profilo, le anomalie no', function () {
    $application = applicationForMap();
    $code = app(TrailRegistryService::class)->reserve($application);
    $request = NovaRequest::create('/');

    expect(mapFieldOf((new TrailApplicationResource($application->fresh()))->fields($request))->jsonSerialize()['enableSlopeChart'])->toBeTrue()
        ->and(mapFieldOf((new TrailRegistryCodeResource($code->fresh()))->fields($request))->jsonSerialize()['enableSlopeChart'])->toBeTrue()
        ->and(mapFieldOf((new TrailRegistryAnomalyResource(new TrailRegistryAnomaly))->fields($request))->jsonSerialize()['enableSlopeChart'])->toBeFalse();
});
