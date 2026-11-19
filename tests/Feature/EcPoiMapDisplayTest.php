<?php

declare(strict_types=1);

use Wm\WmPackage\Models\EcPoi;
use Illuminate\Support\Facades\Http;

// Creare un contenuto geolocalizzato interroga osmfeatures per le aree amministrative e il DEM
// per i dati tecnici: qui non si verificano quei due servizi, si fingono perche' il test non
// esca in rete.
beforeEach(function () {
    Http::fake([
        '*osmfeatures*' => Http::response(['features' => []]),
        '*dem*' => Http::response([
            'ele' => 0,
            'geometry' => ['type' => 'LineString', 'coordinates' => []],
            'properties' => [],
        ]),
    ]);
});

it('returns show_image_on_map from properties when set to true', function () {
    $poi = EcPoi::factory()->create([
        'properties' => ['show_image_on_map' => true],
    ]);

    expect($poi->getShowImageOnMap())->toBeTrue();
});

it('returns show_image_on_map from properties when set to false', function () {
    $poi = EcPoi::factory()->create([
        'properties' => ['show_image_on_map' => false],
    ]);

    expect($poi->getShowImageOnMap())->toBeFalse();
});

it('returns null when show_image_on_map is not set on ec poi', function () {
    $poi = EcPoi::factory()->create([
        'properties' => [],
    ]);

    expect($poi->getShowImageOnMap())->toBeNull();
});

it('resolveShowImageOnMap returns false when not set', function () {
    $poi = EcPoi::factory()->create([
        'properties' => [],
    ]);

    expect($poi->resolveShowImageOnMap())->toBeFalse();
});

it('resolveShowImageOnMap returns true when explicitly set', function () {
    $poi = EcPoi::factory()->create([
        'properties' => ['show_image_on_map' => true],
    ]);

    expect($poi->resolveShowImageOnMap())->toBeTrue();
});
