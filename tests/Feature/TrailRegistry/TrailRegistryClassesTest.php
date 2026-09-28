<?php

use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryCode;
use Wm\WmPackage\TrailRegistry\TrailRegistryClasses;

class ShardTrailRegistryCode extends TrailRegistryCode {}

it('senza configurazione usa i modelli del package', function () {
    expect(TrailRegistryClasses::code())->toBe(TrailRegistryCode::class);
});

it('usa il modello dichiarato dallo shard, anche nelle relazioni', function () {
    config(['wm-package.features.trail_registry.models.code' => ShardTrailRegistryCode::class]);

    expect(TrailRegistryClasses::code())->toBe(ShardTrailRegistryCode::class);
    expect((new TrailApplication)->codes()->getRelated())
        ->toBeInstanceOf(ShardTrailRegistryCode::class);
});

it('rifiuta una classe che non esiste', function () {
    config(['wm-package.features.trail_registry.models.code' => 'App\\Models\\Refuso']);

    TrailRegistryClasses::assertValid();
})->throws(InvalidArgumentException::class, 'models.code');

it('rifiuta una classe che non estende quella del package', function () {
    config(['wm-package.features.trail_registry.models.code' => EcTrack::class]);

    TrailRegistryClasses::assertValid();
})->throws(InvalidArgumentException::class, 'deve estendere');
