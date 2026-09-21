<?php

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

    \Illuminate\Support\Facades\DB::enableQueryLog();
    app(TrailRegistryService::class)->numbersWithAvailableVariants('ZNUB5');
    $queries = \Illuminate\Support\Facades\DB::getQueryLog();
    \Illuminate\Support\Facades\DB::disableQueryLog();

    expect(count($queries))->toBeLessThanOrEqual(2);
});
