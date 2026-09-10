<?php

use Wm\WmPackage\TrailRegistry\TrailCodeParser;

dataset('codici reali', [
    // forma completa (361 casi sul database)
    ['Z-NU-B-535A', 35, 'A', '5'],
    // variante staccata (37 casi)
    ['Z-NU-G-310-A', 10, 'A', '3'],
    // area e numero (66 casi)
    ['C-402', 2, '0', '4'],
    ['T-513A', 13, 'A', '5'],
    // solo numero (90 casi)
    ['206', 6, '0', '2'],
    ['328A', 28, 'A', '3'],
    // numero tondo: lo zero finale e' cifra, non variante
    ['Z-NU-B-440', 40, '0', '4'],
    // spazi come separatore
    ['D 700', 0, '0', '7'],
]);

it('estrae numero e variante da tutte le forme', function (string $raw, int $number, string $variant) {
    expect(TrailCodeParser::parseTail($raw))
        ->toBe(['number' => $number, 'variant' => $variant]);
})->with('codici reali');

it('estrae la cifra del settore scritta nel codice', function (string $raw, int $n, string $v, string $sector) {
    expect(TrailCodeParser::sectorDigitFrom($raw))->toBe($sector);
})->with('codici reali');

it('rifiuta una coda di quattro cifre, che sarebbe un sottosentiero', function () {
    expect(TrailCodeParser::parseTail('3111'))->toBeNull();
});

it('rifiuta testo non interpretabile', function (string $raw) {
    expect(TrailCodeParser::parseTail($raw))->toBeNull();
})->with(['', 'sentiero del monte', 'Z-NU-B', 'AB']);
