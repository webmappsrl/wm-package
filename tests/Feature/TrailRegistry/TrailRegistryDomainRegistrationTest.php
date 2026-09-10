<?php

use Wm\WmPackage\Services\FeaturesService;
use Wm\WmPackage\TrailRegistry\Commands\TrailRegistryNormalizeCommand;
use Wm\WmPackage\TrailRegistry\Nova\TrailApplication;
use Wm\WmPackage\TrailRegistry\Nova\TrailRegistryAnomaly;
use Wm\WmPackage\TrailRegistry\Nova\TrailRegistryCode;

it('a dominio spento non registra le resource del catasto', function () {
    config(['wm-package.features.trail_registry.enabled' => false]);

    expect(FeaturesService::isEnabled('trail_registry'))->toBeFalse();
    expect(FeaturesService::enabledDomains())->not->toContain('trail_registry');
});

it('non mette le resource del dominio sotto src/Nova, che Nova scandisce', function () {
    // src/Nova viene letta ricorsivamente da Nova::resourcesIn(): una resource
    // del dominio la' dentro verrebbe registrata anche a interruttore spento.
    $paths = glob(__DIR__.'/../../../src/Nova/TrailRegistry*');

    expect($paths)->toBe([]);
});

it('dichiara le resource e il comando del dominio', function () {
    expect(config('wm-package.features.trail_registry.nova_resources'))->toEqualCanonicalizing([
        TrailApplication::class,
        TrailRegistryCode::class,
        TrailRegistryAnomaly::class,
    ]);

    expect(config('wm-package.features.trail_registry.commands'))->toContain(
        TrailRegistryNormalizeCommand::class,
    );
});
