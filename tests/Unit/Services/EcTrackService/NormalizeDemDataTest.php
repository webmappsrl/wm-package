<?php

use Wm\WmPackage\Services\Models\EcTrackService;

it('normalizza le durate prendendole dall escursionismo', function () {
    $normalized = app(EcTrackService::class)->normalizeDemData([
        'distance' => 5.2,
        'ascent' => 300,
        'duration_forward_hiking' => 120,
        'duration_backward_hiking' => 110,
        'duration_forward_bike' => 40,
        'duration_backward_bike' => 35,
    ]);

    expect($normalized)
        ->toMatchArray([
            'distance' => 5.2,
            'ascent' => 300,
            'duration_forward' => 120,
            'duration_backward' => 110,
            'duration_forward_bike' => 40,
        ]);
});

it('non inventa le durate se il DEM non le restituisce', function () {
    $normalized = app(EcTrackService::class)->normalizeDemData(['distance' => 1.0]);

    expect($normalized)->toHaveKey('duration_forward', null)
        ->and($normalized)->toHaveKey('duration_backward', null);
});
