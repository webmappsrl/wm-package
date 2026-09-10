<?php

use Wm\WmPackage\TrailRegistry\Exceptions\SectorNotFoundException;
use Wm\WmPackage\TrailRegistry\TrailRegistryService;

beforeEach(function () {
    runTrailRegistryStubs();
});

it('trova il settore che contiene la traccia', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');

    $sector = app(TrailRegistryService::class)
        ->resolveSector('MULTILINESTRING((1 1, 2 2))');

    expect($sector->properties['full_code'])->toBe('ZNUB5');
});

it('ignora i poligoni amministrativi che non sono settori CAI', function () {
    // Sul database reale una traccia interseca anche il proprio comune, la
    // provincia e la regione: 420 poligoni estranei alla numerazione.
    makeSector('', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))', 'osmfeatures');
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');

    $sector = app(TrailRegistryService::class)
        ->resolveSector('MULTILINESTRING((1 1, 2 2))');

    expect($sector->properties['full_code'])->toBe('ZNUB5');
});

it('scegli il settore in cui la traccia corre piu a lungo', function () {
    // I tre segnali (id, ordine di inserimento, lunghezza) sono resi
    // deliberatamente discordanti: il settore vincitore (ZNUB2) ha l'id
    // intermedio, non il minimo ne' il massimo, quindi ORDER BY id ASC
    // sceglierebbe ZNUB1 (id minore) e ORDER BY id DESC sceglierebbe ZNUB3
    // (id maggiore) — solo l'ordinamento per lunghezza dell'intersezione
    // porta al vincitore reale.
    makeSector('ZNUB1', 'POLYGON((0 0, 0 10, 1 10, 1 0, 0 0))'); // id minore, tratto corto
    makeSector('ZNUB2', 'POLYGON((1 0, 1 10, 9 10, 9 0, 1 0))'); // id intermedio, tratto lungo (vincitore atteso)
    makeSector('ZNUB3', 'POLYGON((9 0, 9 10, 10 10, 10 0, 9 0))'); // id maggiore, tratto corto

    // La traccia entra appena in ZNUB1, corre a lungo in ZNUB2, ed esce
    // brevemente in ZNUB3.
    $sector = app(TrailRegistryService::class)
        ->resolveSector('MULTILINESTRING((0.9 5, 9.5 5))');

    expect($sector->properties['full_code'])->toBe('ZNUB2');
});

it('solleva un errore esplicito se nessun settore contiene la traccia', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 1, 1 1, 1 0, 0 0))');

    app(TrailRegistryService::class)->resolveSector('MULTILINESTRING((50 50, 51 51))');
})->throws(SectorNotFoundException::class);
