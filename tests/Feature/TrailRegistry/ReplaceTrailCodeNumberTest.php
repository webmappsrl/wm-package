<?php

use Illuminate\Support\Facades\DB;
use Wm\WmPackage\TrailRegistry\Enums\TrailCodeStatus;
use Wm\WmPackage\TrailRegistry\Exceptions\InvalidTrailCodeTransitionException;
use Wm\WmPackage\TrailRegistry\Exceptions\NumberOccupiedException;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryCode;
use Wm\WmPackage\TrailRegistry\TrailRegistryService;

beforeEach(function () {
    runTrailRegistryStubs();
});

it('sostituisce con un numero libero, senza variante', function () {
    $code = TrailRegistryCode::find(makeCode(['number' => 83]));

    $new = app(TrailRegistryService::class)->replaceNumber($code, 13);

    expect($new->number)->toBe(13)
        ->and($new->variant)->toBe('0')
        ->and($new->code)->toBe('ZNUB513')
        ->and($new->status)->toBe(TrailCodeStatus::Reserved)
        ->and($code->fresh()->status)->toBe(TrailCodeStatus::Released);
});

it('sostituisce con una variante di un numero gia occupato', function () {
    makeCode(['number' => 13, 'variant' => '0', 'status' => TrailCodeStatus::Assigned]);
    $code = TrailRegistryCode::find(makeCode(['number' => 83]));

    $new = app(TrailRegistryService::class)->replaceNumber($code, 13, 'A');

    expect($new->variant)->toBe('A')
        ->and($new->code)->toBe('ZNUB513A');
});

it('rifiuta una combinazione gia presente', function () {
    makeCode(['number' => 13, 'variant' => 'A', 'status' => TrailCodeStatus::Assigned]);
    $code = TrailRegistryCode::find(makeCode(['number' => 83]));

    expect(fn () => app(TrailRegistryService::class)->replaceNumber($code, 13, 'A'))
        ->toThrow(NumberOccupiedException::class);

    // Il rollback ha rimesso a posto il codice di partenza.
    expect($code->fresh()->status)->toBe(TrailCodeStatus::Reserved);
});

it('non sostituisce un codice gia assegnato', function () {
    $code = TrailRegistryCode::find(makeCode(['number' => 83, 'status' => TrailCodeStatus::Assigned]));

    expect(fn () => app(TrailRegistryService::class)->replaceNumber($code, 13))
        ->toThrow(InvalidTrailCodeTransitionException::class);
});

it('non sostituisce un codice diventato assegnato dopo il caricamento', function () {
    $code = TrailRegistryCode::find(makeCode(['number' => 83]));
    $assigned = TrailRegistryCode::find(makeCode(['number' => 84, 'status' => TrailCodeStatus::Assigned]));

    // La riga in memoria dice ancora Reserved; il database no. E' quel che
    // succede se un altro operatore approva mentre il modale e' aperto.
    // ec_track_id va valorizzato insieme allo status: il vincolo
    // trail_registry_codes_holder_check impone che una riga 'assigned' abbia
    // sempre un ec_track_id.
    DB::table('trail_registry_codes')
        ->where('id', $code->id)
        ->update([
            'status' => TrailCodeStatus::Assigned->value,
            'ec_track_id' => $assigned->ec_track_id,
        ]);

    expect(fn () => app(TrailRegistryService::class)->replaceNumber($code, 13))
        ->toThrow(InvalidTrailCodeTransitionException::class);

    expect(DB::table('trail_registry_codes')->where('number', 13)->count())->toBe(0);
});

it('registra chi ha eseguito la sostituzione', function () {
    $code = TrailRegistryCode::find(makeCode(['number' => 83]));
    $userId = makeTrailRegistryTestUser();

    $new = app(TrailRegistryService::class)->replaceNumber($code, 13, '0', $userId);

    expect($code->fresh()->events->pluck('reason')->all())->toContain('number_replaced')
        ->and($new->events->pluck('reason')->all())->toContain('number_replaced');
});
