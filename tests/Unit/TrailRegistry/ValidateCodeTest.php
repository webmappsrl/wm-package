<?php

use Wm\WmPackage\TrailRegistry\TrailRegistryService;

it('accetta un codice ben formato', function (string $code) {
    expect(app(TrailRegistryService::class)->validate($code))->toBeTrue();
})->with(['ZNUB535', 'ZNUB535A', 'ZNUB500', 'ZCAC412B']);

it('rifiuta un codice mal formato', function (string $code) {
    expect(app(TrailRegistryService::class)->validate($code))->toBeFalse();
})->with([
    'Z-NU-B-535A',  // con trattini: il REI standard non li ha
    'znub535',      // minuscolo
    'ZNUB5',        // manca il numero
    'ZNUB5351',     // variante numerica: sottosentiero, fuori scope
    'ZNUB5350',     // quattro cifre in coda
    'ZNU B535',     // spazio
    '',
]);
