<?php

use Illuminate\Support\Facades\Bus;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\TrailRegistry\Enums\TrailCodeStatus;
use Wm\WmPackage\TrailRegistry\Exceptions\InvalidTrailCodeTransitionException;
use Wm\WmPackage\TrailRegistry\Exceptions\NumberOccupiedException;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryCode;
use Wm\WmPackage\TrailRegistry\TrailRegistryService;

beforeEach(function () {
    runTrailRegistryStubs();

    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');

    $this->application = TrailApplication::factory()->create();

    DB::statement(
        'UPDATE trail_applications SET geometry = ST_GeomFromText(?, 4326) WHERE id = ?',
        ['MULTILINESTRING Z ((1 1 0, 2 2 0))', $this->application->id]
    );

    $this->application->refresh();
    $this->service = app(TrailRegistryService::class);
});

it('riserva un numero e registra il passaggio nella storia', function () {
    $code = $this->service->reserve($this->application);

    expect($code->status)->toBe(TrailCodeStatus::Reserved)
        ->and($code->code)->toBe('ZNUB500')
        ->and($code->trail_application_id)->toBe($this->application->id)
        ->and($code->ec_track_id)->toBeNull();

    expect($code->events)->toHaveCount(1);
    expect($code->events->first()->to_status)->toBe(TrailCodeStatus::Reserved);
    expect($code->events->first()->reason)->toBe('reserved_on_application');
});

it('conferma il codice sulla stessa riga, senza cambiare il numero', function () {
    $code = $this->service->reserve($this->application);
    // App::factory()->create() (non ->createQuietly()) fa scattare
    // AppObserver::saved(), che tenta di scrivere la config su storage e
    // fallisce in questo ambiente di test (shard_name non configurato) —
    // stesso problema documentato in makeCode() (tests/Pest.php).
    App::factory()->createQuietly();
    // EcTrackObserver::created() dispatcha una Bus::chain() (DEM, OSM, aws,
    // ecc.): senza fake quella catena esegue per davvero e chiama servizi
    // esterni, che in questo ambiente falliscono.
    Bus::fake();
    $track = EcTrack::factory()->create();

    $confirmed = $this->service->confirm($code, $track);

    expect($confirmed->id)->toBe($code->id)
        ->and($confirmed->code)->toBe('ZNUB500')
        ->and($confirmed->status)->toBe(TrailCodeStatus::Assigned)
        ->and($confirmed->ec_track_id)->toBe($track->id)
        // il legame con l'istanza resta: si sa sempre da quale domanda il
        // codice e' nato
        ->and($confirmed->trail_application_id)->toBe($this->application->id);

    expect($confirmed->events)->toHaveCount(2);
});

it('libera il codice con la causa scritta nella storia', function () {
    $code = $this->service->reserve($this->application);

    $released = $this->service->release($code, 'application_rejected');

    expect($released->status)->toBe(TrailCodeStatus::Released);
    expect($released->events->last()->reason)->toBe('application_rejected');
    expect($released->events->last()->from_status)->toBe(TrailCodeStatus::Reserved);
});

it('rende un numero liberato di nuovo proponibile', function () {
    $code = $this->service->reserve($this->application);
    $this->service->release($code, 'application_rejected');

    $other = TrailApplication::factory()->create();
    DB::statement(
        'UPDATE trail_applications SET geometry = ST_GeomFromText(?, 4326) WHERE id = ?',
        ['MULTILINESTRING Z ((1 1 0, 2 2 0))', $other->id]
    );

    expect($this->service->reserve($other->refresh())->code)->toBe('ZNUB500');
});

it('sostituisce il numero in una sola transazione', function () {
    $code = $this->service->reserve($this->application);

    $replaced = $this->service->replaceNumber($code, 42);

    expect($replaced->code)->toBe('ZNUB542')
        ->and($replaced->status)->toBe(TrailCodeStatus::Reserved);

    // il vecchio codice risulta liberato e riproponibile
    expect(TrailRegistryCode::where('number', 0)->first()->status)
        ->toBe(TrailCodeStatus::Released);
});

it('rifiuta la sostituzione con un numero occupato', function () {
    $code = $this->service->reserve($this->application);
    makeCode(['number' => 42, 'status' => TrailCodeStatus::Assigned]);

    $this->service->replaceNumber($code, 42);
})->throws(NumberOccupiedException::class);

it('rifiuta confirm() su un codice non riservato', function () {
    $code = $this->service->reserve($this->application);
    $this->service->release($code, 'application_rejected');

    App::factory()->createQuietly();
    Bus::fake();
    $track = EcTrack::factory()->create();

    $this->service->confirm($code->refresh(), $track);
})->throws(InvalidTrailCodeTransitionException::class);

it('rifiuta una doppia conferma dello stesso codice (gia assegnato)', function () {
    $code = $this->service->reserve($this->application);

    App::factory()->createQuietly();
    Bus::fake();
    $trackA = EcTrack::factory()->create();
    $trackB = EcTrack::factory()->create();

    $this->service->confirm($code, $trackA);

    $this->service->confirm($code->refresh(), $trackB);
})->throws(InvalidTrailCodeTransitionException::class);

it('rifiuta release() su un codice gia liberato', function () {
    $code = $this->service->reserve($this->application);
    $this->service->release($code, 'application_rejected');

    $this->service->release($code->refresh(), 'application_rejected');
})->throws(InvalidTrailCodeTransitionException::class);

it('rifiuta replaceNumber() su un codice gia assegnato', function () {
    $code = $this->service->reserve($this->application);

    App::factory()->createQuietly();
    Bus::fake();
    $track = EcTrack::factory()->create();
    $this->service->confirm($code, $track);

    $this->service->replaceNumber($code->refresh(), 42);
})->throws(InvalidTrailCodeTransitionException::class);
