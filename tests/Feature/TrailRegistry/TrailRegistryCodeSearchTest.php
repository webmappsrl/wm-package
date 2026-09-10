<?php

use Illuminate\Support\Facades\DB;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\TrailRegistry\Enums\TrailCodeStatus;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryCode;
use Wm\WmPackage\TrailRegistry\Nova\TrailRegistryCode as TrailRegistryCodeResource;

beforeEach(function () {
    runTrailRegistryStubs();
});

function searchCodes(string $needle): array
{
    return TrailRegistryCodeResource::applySearch(TrailRegistryCode::query(), $needle)
        ->get()
        ->map(fn (TrailRegistryCode $c) => $c->code.':'.$c->status->value)
        ->sort()
        ->values()
        ->all();
}

it('cercando una posizione mostra chi la occupa, storia dei rilasci compresa', function () {
    // Chi resta senza numero non e' nel registro: quella meta' del racconto
    // vive nelle anomalie. Qui si cerca una posizione e si vede chi la porta,
    // insieme a chi l'ha portata prima.
    makeCode(['number' => 11, 'variant' => '0', 'status' => TrailCodeStatus::Assigned]);
    makeCode(['number' => 11, 'variant' => '0', 'status' => TrailCodeStatus::Released]);

    $found = searchCodes('ZNUB511');

    expect($found)->toHaveCount(2)
        ->and($found)->toContain('ZNUB511:assigned')
        ->and($found)->toContain('ZNUB511:released');
});

it('cerca per prefisso, quindi un settore elenca i suoi codici', function () {
    makeCode(['number' => 11, 'status' => TrailCodeStatus::Assigned]);
    makeCode(['number' => 42, 'status' => TrailCodeStatus::Assigned]);
    makeCode(['sector' => '9', 'number' => 11, 'status' => TrailCodeStatus::Assigned]);

    expect(searchCodes('ZNUB5'))->toHaveCount(2);
});

it('non trova un codice di un altro settore', function () {
    makeCode(['number' => 11, 'status' => TrailCodeStatus::Assigned]);

    expect(searchCodes('ZCAC511'))->toBeEmpty();
});

it('trova la variante solo cercandola, e il codice nudo non la include', function () {
    makeCode(['number' => 11, 'variant' => '0', 'status' => TrailCodeStatus::Assigned]);
    makeCode(['number' => 11, 'variant' => 'A', 'status' => TrailCodeStatus::Assigned]);

    expect(searchCodes('ZNUB511A'))->toBe(['ZNUB511A:assigned']);
    expect(searchCodes('ZNUB511'))->toHaveCount(2);
});

it('e insensibile alle minuscole e agli spazi attorno', function () {
    makeCode(['number' => 11, 'status' => TrailCodeStatus::Assigned]);

    expect(searchCodes('  znub511  '))->toBe(['ZNUB511:assigned']);
});

it('con una ricerca vuota non filtra nulla', function () {
    makeCode(['number' => 11, 'status' => TrailCodeStatus::Assigned]);

    expect(searchCodes('   '))->toHaveCount(1);
});

it('cercando, elenca i codici in ordine, col 9 prima del 10', function () {
    // Il numero e' un intero: ordinando la stringa senza lo zero davanti, il
    // 10 verrebbe prima del 9.
    makeCode(['number' => 10, 'status' => TrailCodeStatus::Assigned]);
    makeCode(['number' => 9, 'status' => TrailCodeStatus::Assigned]);
    makeCode(['number' => 9, 'variant' => 'A', 'status' => TrailCodeStatus::Assigned]);
    makeCode(['sector' => '1', 'number' => 99, 'status' => TrailCodeStatus::Assigned]);

    $ordered = TrailRegistryCodeResource::indexQuery(
        NovaRequest::create('/?search=Z'),
        TrailRegistryCode::query(),
    )->get()->map(fn (TrailRegistryCode $c) => $c->code)->all();

    expect($ordered)->toBe(['ZNUB199', 'ZNUB509', 'ZNUB509A', 'ZNUB510']);
});

it('senza ricerca elenca prima i piu recenti', function () {
    // Chi apre il registro vuole vedere cosa e' stato assegnato di nuovo, non
    // l'inizio dell'alfabeto — che a codici in ordine fisso resta lo stesso
    // per sempre.
    $vecchio = makeCode(['number' => 1, 'status' => TrailCodeStatus::Assigned]);
    $nuovo = makeCode(['number' => 2, 'status' => TrailCodeStatus::Assigned]);

    DB::table('trail_registry_codes')->where('id', $vecchio)
        ->update(['created_at' => now()->subDay()]);

    $ordered = TrailRegistryCodeResource::indexQuery(
        NovaRequest::create('/'),
        TrailRegistryCode::query(),
    )->get()->map(fn (TrailRegistryCode $c) => $c->id)->all();

    expect($ordered)->toBe([$nuovo, $vecchio]);
});

it('lascia stare l ordine quando l utente clicca su una colonna', function () {
    makeCode(['number' => 5, 'status' => TrailCodeStatus::Assigned]);

    $query = TrailRegistryCodeResource::indexQuery(
        NovaRequest::create('/?orderBy=number&orderByDirection=asc'),
        TrailRegistryCode::query(),
    );

    expect($query->getQuery()->orders)->toBeNull();
});
