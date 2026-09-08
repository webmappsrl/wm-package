<?php

namespace Wm\WmPackage\Tests\Unit;

use Tests\TestCase;
use Wm\WmPackage\Services\FeaturesService;

class FeaturesServiceTest extends TestCase
{
    public function test_declared_domains_lists_config_keys(): void
    {
        config(['wm-package.features' => [
            'trail_registry' => ['enabled' => false],
            'osmfeatures' => ['enabled' => true],
        ]]);

        $this->assertSame(['trail_registry', 'osmfeatures'], FeaturesService::declaredDomains());
    }

    public function test_is_enabled_reads_the_enabled_key(): void
    {
        config(['wm-package.features' => [
            'trail_registry' => ['enabled' => true],
            'osmfeatures' => ['enabled' => false],
        ]]);

        $this->assertTrue(FeaturesService::isEnabled('trail_registry'));
        $this->assertFalse(FeaturesService::isEnabled('osmfeatures'));
    }

    public function test_unknown_domain_is_not_enabled(): void
    {
        config(['wm-package.features' => []]);

        $this->assertFalse(FeaturesService::isEnabled('inesistente'));
    }

    public function test_enabled_domains_returns_only_the_active_ones(): void
    {
        config(['wm-package.features' => [
            'trail_registry' => ['enabled' => true],
            'osmfeatures' => ['enabled' => false],
        ]]);

        $this->assertSame(['trail_registry'], FeaturesService::enabledDomains());
    }

    public function test_missing_features_key_yields_no_domains(): void
    {
        config(['wm-package.features' => null]);

        $this->assertSame([], FeaturesService::declaredDomains());
        $this->assertSame([], FeaturesService::enabledDomains());
    }
}
