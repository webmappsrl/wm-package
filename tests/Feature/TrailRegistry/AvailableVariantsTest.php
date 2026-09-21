<?php

use Wm\WmPackage\TrailRegistry\Enums\TrailCodeStatus;
use Wm\WmPackage\TrailRegistry\TrailRegistryService;

beforeEach(function () {
    runTrailRegistryStubs();
});

it('offre tutte le varianti quando il numero e libero', function () {
    $variants = app(TrailRegistryService::class)->availableVariants('ZNUB5', 13);

    expect($variants)->toHaveCount(27)
        ->and($variants[0])->toBe('0')
        ->and($variants)->toContain('A')->toContain('Z');
});

it('toglie la variante zero quando il numero puro e occupato', function () {
    makeCode(['number' => 13, 'variant' => '0', 'status' => TrailCodeStatus::Reserved]);

    $variants = app(TrailRegistryService::class)->availableVariants('ZNUB5', 13);

    expect($variants)->not->toContain('0')
        ->and($variants[0])->toBe('A')
        ->and($variants)->toHaveCount(26);
});

it('offre la variante zero se il numero e libero ma una lettera e presa', function () {
    makeCode(['number' => 13, 'variant' => 'A', 'status' => TrailCodeStatus::Assigned]);

    $variants = app(TrailRegistryService::class)->availableVariants('ZNUB5', 13);

    expect($variants)->toContain('0')->not->toContain('A')
        ->and($variants)->toHaveCount(26);
});

it('non offre nulla quando ogni variante e occupata', function () {
    foreach (array_merge(['0'], range('A', 'Z')) as $variant) {
        makeCode(['number' => 13, 'variant' => $variant, 'status' => TrailCodeStatus::Assigned]);
    }

    expect(app(TrailRegistryService::class)->availableVariants('ZNUB5', 13))->toBe([]);
});

it('considera occupata anche una variante numerica di archivio', function () {
    makeCode(['number' => 13, 'variant' => '3', 'status' => TrailCodeStatus::Assigned]);

    $variants = app(TrailRegistryService::class)->availableVariants('ZNUB5', 13);

    // La variante numerica non e' fra quelle offerte, quindi il conteggio
    // resta 27: quel che conta e' che non venga scambiata per libera.
    expect($variants)->toHaveCount(27)->not->toContain('3');
});

it('ignora le righe liberate', function () {
    makeCode(['number' => 13, 'variant' => 'A', 'status' => TrailCodeStatus::Released]);

    expect(app(TrailRegistryService::class)->availableVariants('ZNUB5', 13))->toContain('A');
});

it('non guarda i numeri vicini', function () {
    makeCode(['number' => 14, 'variant' => 'A', 'status' => TrailCodeStatus::Assigned]);

    expect(app(TrailRegistryService::class)->availableVariants('ZNUB5', 13))->toContain('A');
});
