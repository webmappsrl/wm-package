<?php

declare(strict_types=1);

use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\Layer;

it('uses default color when track has no layers and no custom color', function () {
    /** @var EcTrack $track */
    $track = EcTrack::factory()->create([
        'properties' => [],
    ]);

    $provenance = $track->getTrackColorProvenance();

    expect($provenance['source'])->toBe('default')
        ->and($provenance['effective_hex'])->toBe(EcTrack::DEFAULT_COLOR_HEX)
        ->and($provenance['layer'])->toBeNull();
});

it('inherits color from lowest rank layer', function () {
    /** @var Layer $low */
    $low = Layer::factory()->create([
        'rank' => 1,
        'properties' => ['color' => '#00FF00'],
    ]);

    /** @var Layer $high */
    $high = Layer::factory()->create([
        'rank' => 10,
        'properties' => ['color' => '#0000FF'],
    ]);

    /** @var EcTrack $track */
    $track = EcTrack::factory()->create([
        'properties' => [],
    ]);

    $track->layers()->attach([$low->id, $high->id]);

    $provenance = $track->getTrackColorProvenance();

    expect($provenance['source'])->toBe('layer')
        ->and($provenance['inherited_hex'])->toBe('#00FF00')
        ->and($provenance['effective_hex'])->toBe('#00FF00')
        ->and($provenance['layer']['id'])->toBe($low->id);
});

it('uses default when lowest rank layer has no color even if another layer has one', function () {
    /** @var Layer $lowNoColor */
    $lowNoColor = Layer::factory()->create([
        'rank' => 1,
        'properties' => [],
    ]);

    /** @var Layer $highWithColor */
    $highWithColor = Layer::factory()->create([
        'rank' => 10,
        'properties' => ['color' => '#00FF00'],
    ]);

    /** @var EcTrack $track */
    $track = EcTrack::factory()->create([
        'properties' => [],
    ]);

    $track->layers()->attach([$lowNoColor->id, $highWithColor->id]);

    $provenance = $track->getTrackColorProvenance();

    expect($provenance['source'])->toBe('layer')
        ->and($provenance['layer']['id'])->toBe($lowNoColor->id)
        ->and($provenance['inherited_hex'])->toBe(EcTrack::DEFAULT_COLOR_HEX)
        ->and($provenance['effective_hex'])->toBe(EcTrack::DEFAULT_COLOR_HEX);
});

it('marks color as custom when properties color differs from inherited', function () {
    /** @var Layer $layer */
    $layer = Layer::factory()->create([
        'rank' => 5,
        'properties' => ['color' => '#FF0000'],
    ]);

    /** @var EcTrack $track */
    $track = EcTrack::factory()->create([
        'properties' => ['color' => '#00FF00'],
    ]);

    $track->layers()->attach($layer->id);

    $provenance = $track->getTrackColorProvenance();

    expect($provenance['source'])->toBe('custom')
        ->and($provenance['stored_hex'])->toBe('#00FF00')
        ->and($provenance['inherited_hex'])->toBe('#FF0000')
        ->and($provenance['effective_hex'])->toBe('#00FF00');
});
