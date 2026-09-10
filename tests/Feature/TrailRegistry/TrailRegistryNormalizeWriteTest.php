<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\TrailRegistry\Commands\TrailRegistryNormalizeCommand;
use Wm\WmPackage\TrailRegistry\Enums\TrailCodeStatus;
use Wm\WmPackage\TrailRegistry\Enums\TrailRegistryAnomalyType;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryAnomaly;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryCode;

beforeEach(function () {
    runTrailRegistryStubs();

    // Il comando appartiene a un dominio opzionale: nei test del package non
    // e' registrato dal service provider, che lo carica solo a dominio acceso.
    $this->app[Kernel::class]->registerCommand(new TrailRegistryNormalizeCommand);

    // EcTrackObserver mette in coda UpdateEcTrack3DDemJob, che chiamerebbe
    // davvero un servizio esterno alla creazione dei dati di prova.
    Bus::fake();
});

function trackWithRef(string $ref, string $wkt): EcTrack
{
    $track = EcTrack::factory()->createQuietly(['properties' => ['ref' => $ref]]);

    DB::statement(
        'UPDATE ec_tracks SET geometry = ST_GeomFromText(?, 4326) WHERE id = ?',
        [$wkt, $track->id]
    );

    return $track->refresh();
}

it('carica nel registro un codice leggibile, legandolo al sentiero', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');
    $track = trackWithRef('Z-NU-B-535', 'MULTILINESTRING Z((1 1 0, 2 2 0))');

    $this->artisan('wm-package:trail-registry-normalize --force')->assertExitCode(0);

    $code = TrailRegistryCode::firstOrFail();

    expect($code->code)->toBe('ZNUB535')
        ->and($code->status)->toBe(TrailCodeStatus::Assigned)
        ->and($code->ec_track_id)->toBe($track->id)
        ->and($code->taxonomy_where_id)->not->toBeNull();
});

it('lascia fuori dal registro il secondo codice che occupa la stessa posizione', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');
    $holder = trackWithRef('Z-NU-B-535', 'MULTILINESTRING Z((1 1 0, 2 2 0))');
    $contested = trackWithRef('535', 'MULTILINESTRING Z((3 3 0, 4 4 0))');

    $this->artisan('wm-package:trail-registry-normalize --force')->assertExitCode(0);

    expect(TrailRegistryCode::where('status', TrailCodeStatus::Assigned->value)->count())->toBe(1);
    expect(TrailRegistryCode::count())->toBe(1);

    // Il caso non si perde: vive fra le anomalie, con accanto il sentiero
    // che quel numero lo porta gia'.
    $anomaly = TrailRegistryAnomaly::where('ec_track_id', $contested->id)
        ->where('type', TrailRegistryAnomalyType::CodiceGiaAssegnato->value)
        ->firstOrFail();

    expect($anomaly->related_ec_track_id)->toBe($holder->id);
});

it('non carica un codice fuori forma e lo elenca', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');
    $track = trackWithRef('sentiero del monte', 'MULTILINESTRING Z((1 1 0, 2 2 0))');

    $this->artisan('wm-package:trail-registry-normalize --force')
        ->expectsOutputToContain('codice non interpretabile: 1')
        ->assertExitCode(0);

    expect(TrailRegistryCode::count())->toBe(0);
});

it('non carica un sentiero la cui geometria non ricade in alcun settore', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 1, 1 1, 1 0, 0 0))');
    trackWithRef('Z-NU-B-535', 'MULTILINESTRING Z((50 50 0, 51 51 0))');

    $this->artisan('wm-package:trail-registry-normalize --force')
        ->expectsOutputToContain('fuori da ogni settore: 1')
        ->assertExitCode(0);

    expect(TrailRegistryCode::count())->toBe(0);
});

it('e idempotente: due esecuzioni non creano due righe per lo stesso sentiero', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');
    trackWithRef('Z-NU-B-535', 'MULTILINESTRING Z((1 1 0, 2 2 0))');

    $this->artisan('wm-package:trail-registry-normalize --force');
    $this->artisan('wm-package:trail-registry-normalize --force');

    expect(TrailRegistryCode::count())->toBe(1);
});

it('con --dry-run non scrive niente, come prima', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');
    trackWithRef('Z-NU-B-535', 'MULTILINESTRING Z((1 1 0, 2 2 0))');

    $this->artisan('wm-package:trail-registry-normalize --dry-run')->assertExitCode(0);

    expect(TrailRegistryCode::count())->toBe(0);
});
