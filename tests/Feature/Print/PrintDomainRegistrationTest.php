<?php

use Tests\TestCase;
use Wm\WmPackage\Services\FeaturesService;

uses(TestCase::class);

// Le rotte reali (`/print/{type}/{id}`) arrivano al Task 9: qui si verifica il
// cablaggio del dominio, come fa OptionalDomainRegistrationTest per trail_registry.

it('print domain is declared and disabled by default', function () {
    expect(FeaturesService::declaredDomains())->toContain('print');
    expect(config('wm-package.features.print.enabled'))->toBeFalse();
    expect(FeaturesService::isEnabled('print'))->toBeFalse();
    expect(FeaturesService::enabledDomains())->not->toContain('print');
});

it('print domain is enabled when the switch is turned on', function () {
    config(['wm-package.features.print.enabled' => true]);

    expect(FeaturesService::isEnabled('print'))->toBeTrue();
    expect(FeaturesService::enabledDomains())->toContain('print');
});

it('print domain exposes the expected defaults', function () {
    $print = config('wm-package.features.print');

    expect($print['queue'])->toBe('print')
        ->and($print['map_service']['driver'])->toBe('fake')
        ->and($print['map_service']['timeout'])->toBe(60)
        ->and($print['compile']['timeout'])->toBe(120)
        ->and($print['compile']['memory_mb'])->toBe(1024)
        ->and($print['zip']['max_tracks'])->toBe(50)
        ->and($print['overview_map']['max_scale'])->toBe(50000)
        ->and($print)->toHaveKey('deps_version');
});

it('print domain ships its routes file picked up by registerEnabledDomains', function () {
    expect(file_exists(__DIR__.'/../../../routes/domains/print.php'))->toBeTrue();
});
