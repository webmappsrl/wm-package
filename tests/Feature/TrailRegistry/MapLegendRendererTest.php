<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\TrailRegistry\Enums\TrailCodeStatus;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryCode;
use Wm\WmPackage\TrailRegistry\Nova\MapLegendRenderer;

beforeEach(function () {
    runTrailRegistryStubs();
    Bus::fake();
});

it('mostra la voce dei vicini solo quando ce n e almeno uno', function () {
    $sectorId = makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');

    $code = TrailRegistryCode::findOrFail(makeCode([
        'status' => TrailCodeStatus::Assigned,
        'taxonomy_where_id' => $sectorId,
        'number' => 62,
    ]));

    DB::statement(
        'UPDATE ec_tracks SET geometry = ST_GeomFromText(?, 4326) WHERE id = ?',
        ['MULTILINESTRING Z((1 1 0, 2 2 0))', $code->ec_track_id],
    );

    expect(MapLegendRenderer::render($code->fresh()))->not->toContain('Other trails');

    $vicino = TrailRegistryCode::findOrFail(makeCode([
        'status' => TrailCodeStatus::Assigned,
        'taxonomy_where_id' => $sectorId,
        'number' => 63,
    ]));

    DB::statement(
        'UPDATE ec_tracks SET geometry = ST_GeomFromText(?, 4326) WHERE id = ?',
        ['MULTILINESTRING Z((5 5 0, 6 6 0))', $vicino->ec_track_id],
    );

    expect(MapLegendRenderer::render($code->fresh()))->toContain('Other trails');
});

it('non nomina il sentiero quando il codice e solo riservato', function () {
    $sectorId = makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');

    $code = TrailRegistryCode::findOrFail(makeCode([
        'status' => TrailCodeStatus::Reserved,
        'taxonomy_where_id' => $sectorId,
    ]));

    $html = MapLegendRenderer::render($code);

    expect($html)->toContain('Sector the prefix comes from')
        ->and($html)->toContain('Application track')
        ->and($html)->not->toContain('Trail the code is assigned to');
});

it('spiega i segnavia presenti sulla mappa', function () {
    $sectorId = makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');
    $code = TrailRegistryCode::findOrFail(makeCode(['status' => TrailCodeStatus::Reserved, 'taxonomy_where_id' => $sectorId, 'number' => 66]));
    makeCode(['status' => TrailCodeStatus::Assigned, 'taxonomy_where_id' => $sectorId, 'number' => 63]);
    DB::statement('UPDATE ec_tracks SET geometry = ST_GeomFromText(?, 4326)', ['MULTILINESTRING Z((1 1 0, 2 2 0))']);

    $html = MapLegendRenderer::render($code->fresh());

    expect($html)->toContain('Number of this code')
        ->and($html)->toContain('Number of a validated trail')
        ->and($html)->not->toContain('Number proposed by another')
        ->and($html)->not->toContain('Released number');
});

it('su un codice liberato spiega il numero barrato', function () {
    $sectorId = makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');
    $code = TrailRegistryCode::findOrFail(makeCode(['status' => TrailCodeStatus::Reserved, 'taxonomy_where_id' => $sectorId, 'number' => 59]));
    $code->update(['status' => TrailCodeStatus::Released]);

    $html = MapLegendRenderer::render($code->fresh());

    expect($html)->toContain('Released number')
        ->and($html)->not->toContain('Number of this code');
});

it('dice di quale linea e il profilo altimetrico', function () {
    $sectorId = makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');
    $code = TrailRegistryCode::findOrFail(makeCode(['status' => TrailCodeStatus::Reserved, 'taxonomy_where_id' => $sectorId]));

    $html = MapLegendRenderer::render($code);

    expect($html)->toContain('Elevation profile')
        ->and($html)->toContain('application track');
});
