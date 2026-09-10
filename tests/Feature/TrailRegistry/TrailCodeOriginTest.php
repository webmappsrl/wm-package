<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Bus;
use Wm\WmPackage\TrailRegistry\Commands\TrailRegistryNormalizeCommand;
use Wm\WmPackage\TrailRegistry\Enums\TrailCodeOrigin;
use Wm\WmPackage\TrailRegistry\Enums\TrailRegistryAnomalyType;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryAnomaly;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryCode;

beforeEach(function () {
    runTrailRegistryStubs();
    $this->app[Kernel::class]->registerCommand(new TrailRegistryNormalizeCommand);
    Bus::fake();
});

it('marca come letto dal campo il codice che viene dalla proprieta dedicata', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');
    trackWith(['ref' => 'Z-NU-B-535'], 'MULTILINESTRING Z((1 1 0, 2 2 0))');

    $this->artisan('wm-package:trail-registry-normalize --force');

    expect(TrailRegistryCode::firstOrFail()->origin)->toBe(TrailCodeOrigin::CampoDedicato);
});

it('registra il codice scritto nel nome, marcandolo come tale', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');
    trackWith([], 'MULTILINESTRING Z((1 1 0, 2 2 0))', 'Nuscale - SP 45 - Janna e Ferulargiu (B 535)');

    $this->artisan('wm-package:trail-registry-normalize --force');

    $code = TrailRegistryCode::firstOrFail();

    expect($code->code)->toBe('ZNUB535')
        ->and($code->origin)->toBe(TrailCodeOrigin::Nome);
});

it('non lascia alcuna anomalia per il codice preso dal nome', function () {
    // La lista di lavoro raccoglie i sentieri rimasti SENZA numero. Questo il
    // numero ce l'ha: che venisse dal nome lo dice la provenienza.
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');
    $track = trackWith([], 'MULTILINESTRING Z((1 1 0, 2 2 0))', 'Nuscale (B 535)');

    $this->artisan('wm-package:trail-registry-normalize --force');

    expect(TrailRegistryCode::where('ec_track_id', $track->id)->exists())->toBeTrue();
    expect(TrailRegistryAnomaly::where('ec_track_id', $track->id)->exists())->toBeFalse();
});

it('il codice dal nome sottosta alle stesse regole degli altri', function () {
    // Se la posizione e' gia' presa resta senza numero come chiunque altro:
    // venire dal nome non da' precedenza. Niente riga nel registro, e il caso
    // compare fra le anomalie insieme al sentiero che quel numero lo porta.
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');
    $holder = trackWith(['ref' => 'Z-NU-B-535'], 'MULTILINESTRING Z((1 1 0, 2 2 0))');
    $fromName = trackWith([], 'MULTILINESTRING Z((3 3 0, 4 4 0))', 'Altro sentiero (B 535)');

    $this->artisan('wm-package:trail-registry-normalize --force');

    expect(TrailRegistryCode::where('ec_track_id', $fromName->id)->exists())->toBeFalse();

    $contested = TrailRegistryAnomaly::where('ec_track_id', $fromName->id)
        ->where('type', TrailRegistryAnomalyType::CodiceGiaAssegnato->value)
        ->firstOrFail();

    expect($contested->related_ec_track_id)->toBe($holder->id);
});

it('non registra nulla se dal nome non esce un codice', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');
    trackWith([], 'MULTILINESTRING Z((1 1 0, 2 2 0))', 'C100T - Via Catalana');

    $this->artisan('wm-package:trail-registry-normalize --force');

    expect(TrailRegistryCode::count())->toBe(0);
    expect(TrailRegistryAnomaly::count())->toBe(0);
});
