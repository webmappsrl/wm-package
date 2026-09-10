<?php

use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\TrailRegistry\Enums\TrailCodeStatus;
use Wm\WmPackage\TrailRegistry\Exceptions\SectorExhaustedException;
use Wm\WmPackage\TrailRegistry\TrailRegistryService;

beforeEach(function () {
    runTrailRegistryStubs();
});

it('propone il primo numero libero del settore', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');

    $proposal = app(TrailRegistryService::class)
        ->propose('MULTILINESTRING((1 1, 2 2))');

    expect($proposal['number'])->toBe(0)
        ->and($proposal['variant'])->toBe('0')
        ->and($proposal['region'])->toBe('Z')
        ->and($proposal['province'])->toBe('NU')
        ->and($proposal['area'])->toBe('B')
        ->and($proposal['sector'])->toBe('5');
});

it('salta i numeri occupati, riservati o assegnati', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');

    foreach ([[0, TrailCodeStatus::Assigned], [1, TrailCodeStatus::Reserved]] as [$number, $status]) {
        makeCode(['number' => $number, 'status' => $status]);
    }

    expect(app(TrailRegistryService::class)->propose('MULTILINESTRING((1 1, 2 2))')['number'])
        ->toBe(2);
});

it('considera libero un numero liberato', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');
    makeCode(['number' => 0, 'status' => TrailCodeStatus::Released]);

    expect(app(TrailRegistryService::class)->propose('MULTILINESTRING((1 1, 2 2))')['number'])
        ->toBe(0);
});

it('non confonde i settori: lo spazio dei numeri e per settore', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');
    makeCode(['sector' => '4', 'number' => 0, 'status' => TrailCodeStatus::Assigned]);

    expect(app(TrailRegistryService::class)->propose('MULTILINESTRING((1 1, 2 2))')['number'])
        ->toBe(0);
});

it('elenca i numeri liberi di un settore', function () {
    makeCode(['number' => 0, 'status' => TrailCodeStatus::Assigned]);
    makeCode(['number' => 1, 'status' => TrailCodeStatus::Reserved]);

    $free = app(TrailRegistryService::class)->availableNumbers('ZNUB5');

    expect($free)->not->toContain(0)->not->toContain(1)
        ->and($free[0])->toBe(2)
        ->and(count($free))->toBe(98);
});

it('con ZNUB500 occupato propone il numero successivo, non la variante A dello stesso numero', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');
    makeCode(['number' => 0, 'variant' => '0', 'status' => TrailCodeStatus::Assigned]);

    $proposal = app(TrailRegistryService::class)->propose('MULTILINESTRING((1 1, 2 2))');

    expect($proposal['number'])->toBe(1)
        ->and($proposal['variant'])->toBe('0');
});

it('passa alle lettere solo quando tutti i cento numeri in variante 0 sono occupati', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');

    // Stesso approccio efficiente del test di esaurimento: un solo detentore
    // condiviso, INSERT diretto, nessun indebolimento del vincolo di
    // coerenza stato/detentore.
    $track = EcTrack::factory()->createQuietly();

    $rows = [];
    foreach (range(0, 99) as $n) {
        $rows[] = [
            'region' => 'Z',
            'province' => 'NU',
            'area' => 'B',
            'sector' => '5',
            'number' => $n,
            'variant' => '0',
            'status' => TrailCodeStatus::Assigned->value,
            'ec_track_id' => $track->id,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
    DB::table('trail_registry_codes')->insert($rows);

    $proposal = app(TrailRegistryService::class)->propose('MULTILINESTRING((1 1, 2 2))');

    expect($proposal['number'])->toBe(0)
        ->and($proposal['variant'])->toBe('A');
});

it('solleva un errore esplicito quando il settore e esaurito', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');

    // Riempire le 2700 posizioni (100 numeri x 27 varianti) via makeCode()
    // creerebbe altrettante istanze/track: troppo lento. Si popola con un
    // unico ec_track (stesso detentore per tutte le righe, come richiesto
    // dal vincolo trail_registry_codes_holder_check) e un INSERT diretto.
    $track = EcTrack::factory()->createQuietly();

    $rows = [];
    foreach (range(0, 99) as $n) {
        foreach (array_merge(['0'], range('A', 'Z')) as $variant) {
            $rows[] = [
                'region' => 'Z',
                'province' => 'NU',
                'area' => 'B',
                'sector' => '5',
                'number' => $n,
                'variant' => $variant,
                'status' => TrailCodeStatus::Assigned->value,
                'ec_track_id' => $track->id,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
    }

    foreach (array_chunk($rows, 200) as $chunk) {
        DB::table('trail_registry_codes')->insert($chunk);
    }

    app(TrailRegistryService::class)->propose('MULTILINESTRING((1 1, 2 2))');
})->throws(SectorExhaustedException::class);
