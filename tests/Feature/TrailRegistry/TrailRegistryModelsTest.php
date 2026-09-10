<?php

use Illuminate\Support\Facades\Schema;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\TrailRegistry\Enums\TrailApplicationStatus;
use Wm\WmPackage\TrailRegistry\Enums\TrailCodeStatus;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryCode;

/**
 * runTrailRegistryStubs() e' condivisa in tests/Pest.php: applica tutti e
 * quattro gli stub del dominio (non raccolti da `migrate`, estensione
 * .php.stub).
 */
beforeEach(function () {
    runTrailRegistryStubs();
});

it('compone il codice omettendo la variante zero', function () {
    $code = TrailRegistryCode::find(makeCode());

    expect($code->code)->toBe('ZNUB535');
});

it('compone il codice con la variante quando c e', function () {
    $code = TrailRegistryCode::find(makeCode(['variant' => 'A']));

    expect($code->code)->toBe('ZNUB535A');
});

it('riempie il numero con lo zero davanti', function () {
    $code = TrailRegistryCode::find(makeCode(['number' => 7]));

    expect($code->code)->toBe('ZNUB507');
});

it('espone il full code del settore', function () {
    $code = TrailRegistryCode::find(makeCode());

    expect($code->fullCode)->toBe('ZNUB5');
});

it('non conserva codice e full code come colonne', function () {
    $columns = Schema::getColumnListing('trail_registry_codes');

    expect($columns)->not->toContain('code')->not->toContain('full_code');
});

it('prende la denominazione dal sentiero quando assegnato, altrimenti dall istanza', function () {
    $user = User::factory()->create();

    $application = TrailApplication::create([
        'user_id' => $user->id,
        'source' => 'api',
        'status' => TrailApplicationStatus::UnderReview,
        'name' => 'Domanda del monte',
    ]);

    $code = TrailRegistryCode::find(makeCode([
        'trail_application_id' => $application->id,
        'status' => TrailCodeStatus::Reserved->value,
    ]));
    expect($code->denomination)->toBe('Domanda del monte');

    $track = EcTrack::factory()->createQuietly(['name' => 'Sentiero del monte']);
    $code->update([
        'status' => TrailCodeStatus::Assigned,
        'ec_track_id' => $track->id,
    ]);

    expect($code->fresh()->denomination)->toBe('Sentiero del monte');
});
