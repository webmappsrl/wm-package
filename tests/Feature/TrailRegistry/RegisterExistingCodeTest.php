<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\TrailRegistry\Enums\TrailCodeStatus;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryCode;
use Wm\WmPackage\TrailRegistry\TrailRegistryService;

beforeEach(function () {
    runTrailRegistryStubs();
    Bus::fake();
    $this->service = app(TrailRegistryService::class);
});

function trackWithGeometry(string $wkt): EcTrack
{
    $track = EcTrack::factory()->createQuietly();

    DB::statement(
        'UPDATE ec_tracks SET geometry = ST_GeomFromText(?, 4326) WHERE id = ?',
        [$wkt, $track->id]
    );

    return $track->refresh();
}

it('registra un codice leggibile come assegnato', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');
    $track = trackWithGeometry('MULTILINESTRING Z((1 1 0, 2 2 0))');

    $outcome = $this->service->registerExistingCode(
        $track->id,
        'Z-NU-B-535',
        'MULTILINESTRING Z((1 1 0, 2 2 0))'
    );

    expect($outcome->status)->toBe('assigned');

    $code = TrailRegistryCode::firstOrFail();
    expect($code->code)->toBe('ZNUB535')
        ->and($code->status)->toBe(TrailCodeStatus::Assigned)
        ->and($code->ec_track_id)->toBe($track->id);
});

it('non scrive nulla quando quel codice ce l ha gia un altro', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');
    $wkt = 'MULTILINESTRING Z((1 1 0, 2 2 0))';

    $holderTrack = trackWithGeometry($wkt);
    $this->service->registerExistingCode($holderTrack->id, 'Z-NU-B-535', $wkt);
    $second = $this->service->registerExistingCode(trackWithGeometry($wkt)->id, '535', $wkt);

    expect($second->status)->toBe('alreadyAssigned')
        ->and($second->code)->toBeNull();

    // Il registro resta con la sola riga di chi la posizione ce l'ha davvero.
    expect(TrailRegistryCode::count())->toBe(1);
});

it('porta nell esito il sentiero che quel codice lo porta gia', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');
    $wkt = 'MULTILINESTRING Z((1 1 0, 2 2 0))';

    $holderTrack = trackWithGeometry($wkt);
    $this->service->registerExistingCode($holderTrack->id, 'Z-NU-B-535', $wkt);

    $outcome = $this->service->registerExistingCode(trackWithGeometry($wkt)->id, '535', $wkt);

    expect($outcome->holder)->not->toBeNull()
        ->and($outcome->holder->ec_track_id)->toBe($holderTrack->id);
});

it('non riscrive un sentiero gia registrato', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');
    $wkt = 'MULTILINESTRING Z((1 1 0, 2 2 0))';
    $track = trackWithGeometry($wkt);

    $this->service->registerExistingCode($track->id, 'Z-NU-B-535', $wkt);
    $again = $this->service->registerExistingCode($track->id, 'Z-NU-B-535', $wkt);

    expect($again->status)->toBe('alreadyRegistered');
    expect(TrailRegistryCode::count())->toBe(1);
});

it('riporta un ref non interpretabile senza scrivere', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');
    $wkt = 'MULTILINESTRING Z((1 1 0, 2 2 0))';

    $outcome = $this->service->registerExistingCode(trackWithGeometry($wkt)->id, 'sentiero del monte', $wkt);

    expect($outcome->status)->toBe('unparsableRef');
    expect(TrailRegistryCode::count())->toBe(0);
});

it('riporta una geometria fuori da ogni settore senza scrivere', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 1, 1 1, 1 0, 0 0))');
    $wkt = 'MULTILINESTRING Z((50 50 0, 51 51 0))';

    $outcome = $this->service->registerExistingCode(trackWithGeometry($wkt)->id, 'Z-NU-B-535', $wkt);

    expect($outcome->status)->toBe('noSector');
    expect(TrailRegistryCode::count())->toBe(0);
});

it('non accumula righe a ogni esecuzione su un sentiero rimasto senza numero', function () {
    // Chi resta senza numero non lascia riga, quindi ogni esecuzione
    // ritenta: e' voluto (se la fonte viene corretta, il numero gli spetta
    // senza alcun intervento).
    // Cio' che non deve mai accadere e' che quei tentativi lascino qualcosa
    // in tabella — era il bug delle 18 righe diventate 36.
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');
    $wkt = 'MULTILINESTRING Z((1 1 0, 2 2 0))';
    $conflictingTrack = trackWithGeometry($wkt);

    // Occupa la posizione con un altro sentiero.
    $this->service->registerExistingCode(trackWithGeometry($wkt)->id, 'Z-NU-B-535', $wkt);

    $first = $this->service->registerExistingCode($conflictingTrack->id, 'Z-NU-B-535', $wkt);
    $second = $this->service->registerExistingCode($conflictingTrack->id, 'Z-NU-B-535', $wkt);

    expect($first->status)->toBe('alreadyAssigned')
        ->and($second->status)->toBe('alreadyAssigned');
    expect(TrailRegistryCode::where('ec_track_id', $conflictingTrack->id)->count())->toBe(0);
    expect(TrailRegistryCode::count())->toBe(1);
});

it('non registra quando il settore dedotto non coincide con quello scritto nel codice', function () {
    // Il codice dice settore 3, la geometria dice 5: e' una delle 13 anomalie
    // misurate sui dati reali. Un codice che contraddice la geometria non
    // entra nel registro — non si saprebbe a quale dei due credere.
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');
    $wkt = 'MULTILINESTRING Z((1 1 0, 2 2 0))';

    $outcome = $this->service->registerExistingCode(trackWithGeometry($wkt)->id, '332', $wkt);

    expect($outcome->status)->toBe('sectorMismatch')
        ->and($outcome->sectorMismatch)->toBeTrue()
        ->and($outcome->fullCode)->toBe('ZNUB5');

    expect(TrailRegistryCode::count())->toBe(0);
});
