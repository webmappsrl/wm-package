<?php

use Illuminate\Support\Facades\DB;
use Wm\WmPackage\TrailRegistry\Enums\TrailCodeStatus;
use Wm\WmPackage\TrailRegistry\TrailRegistryService;

beforeEach(function () {
    runTrailRegistryStubs();
});

it('elenca tutti i cento numeri quando il settore e vuoto', function () {
    $numbers = app(TrailRegistryService::class)->numbersWithAvailableVariants('ZNUB5');

    expect($numbers)->toHaveCount(100)
        ->and($numbers[0])->toBe(0)
        ->and($numbers[99])->toBe(99);
});

it('tiene il numero occupato perche le sue varianti sono libere', function () {
    makeCode(['number' => 13, 'variant' => '0', 'status' => TrailCodeStatus::Assigned]);

    $numbers = app(TrailRegistryService::class)->numbersWithAvailableVariants('ZNUB5');

    // E' il caso di Saba: il 213 e' occupato, ma 213A si puo' ancora fare.
    expect($numbers)->toContain(13)->toHaveCount(100);
});

it('toglie il numero saturo', function () {
    foreach (array_merge(['0'], range('A', 'Z')) as $variant) {
        makeCode(['number' => 13, 'variant' => $variant, 'status' => TrailCodeStatus::Assigned]);
    }

    $numbers = app(TrailRegistryService::class)->numbersWithAvailableVariants('ZNUB5');

    expect($numbers)->not->toContain(13)->toHaveCount(99);
});

it('non guarda gli altri settori', function () {
    foreach (array_merge(['0'], range('A', 'Z')) as $variant) {
        makeCode(['number' => 13, 'variant' => $variant, 'sector' => '6', 'status' => TrailCodeStatus::Assigned]);
    }

    expect(app(TrailRegistryService::class)->numbersWithAvailableVariants('ZNUB5'))->toContain(13);
});

it('non fa una query per numero', function () {
    makeCode(['number' => 13, 'variant' => '0', 'status' => TrailCodeStatus::Assigned]);

    DB::enableQueryLog();
    app(TrailRegistryService::class)->numbersWithAvailableVariants('ZNUB5');
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect(count($queries))->toBeLessThanOrEqual(2);
});

it('ordina per vicinanza i numeri offerti per la sostituzione', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');

    foreach ([11, 12, 13] as $number) {
        makeCode([
            'number' => $number,
            'status' => TrailCodeStatus::Assigned,
            'geometry_wkt' => 'LINESTRING Z (1 1 0, 1.001 1.001 0)',
        ]);
    }

    $numbers = app(TrailRegistryService::class)->numbersWithAvailableVariants(
        'ZNUB5',
        'MULTILINESTRING Z ((1 1 0, 1.002 1.002 0))',
    );

    // Il cluster piu' vicino e' 11-13, tutti a distanza zero dalla traccia:
    // sono candidati legittimi quanto un numero libero, perche' un numero
    // gia' occupato con una lettera ancora libera concorre per vicinanza
    // come gli altri (es. ZNUB511A accanto a ZNUB511 e' un'offerta sensata).
    // Vengono prima dei liberi adiacenti 10 e 14, che distano 1.
    expect($numbers[0])->toBe(11)
        ->and($numbers[1])->toBe(12)
        ->and($numbers[2])->toBe(13)
        ->and($numbers[3])->toBe(10)
        ->and($numbers[4])->toBe(14)
        ->and($numbers)->toContain(11)
        ->and($numbers)->toHaveCount(100);
});
