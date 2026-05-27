<?php

namespace Wm\WmPackage\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Wm\WmPackage\Services\IconSvgService;

class IconSvgServicePhpUnitTest extends TestCase
{
    private function setIconSvgServiceCache(array $pathsByName, int $height): void
    {
        $ref = new ReflectionClass(IconSvgService::class);

        $pathsProp = $ref->getProperty('pathsByName');
        $pathsProp->setAccessible(true);
        $pathsProp->setValue(null, $pathsByName);

        $heightProp = $ref->getProperty('height');
        $heightProp->setAccessible(true);
        $heightProp->setValue(null, $height);
    }

    public function testGeneraSvgIncludendoAttrsLegacy(): void
    {
        $this->setIconSvgServiceCache([
            'muta-tile' => [
                ['d' => 'M0 0h10v10H0z', 'attrs' => ['fill' => 'rgb(1, 2, 3)']],
            ],
        ], 1024);

        $service = new IconSvgService;
        $svg = $service->getSvgByName('muta-tile');

        $this->assertIsString($svg);
        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('viewBox="0 0 1024 1024"', $svg);
        $this->assertStringContainsString('fill="rgb(1, 2, 3)"', $svg);
        $this->assertStringContainsString('d="M0 0h10v10H0z"', $svg);
    }

    public function testGeneraSvgIncludendoAttrsMulticolor(): void
    {
        $this->setIconSvgServiceCache([
            'webmapp-tile' => [
                ['d' => 'M1 1h2v2H1z', 'attrs' => ['fill' => 'red']],
                ['d' => 'M3 3h4v4H3z', 'attrs' => ['fill' => 'blue', 'opacity' => 0.5]],
            ],
        ], 2048);

        $service = new IconSvgService;
        $svg = $service->getSvgByName('webmapp-tile');

        $this->assertIsString($svg);
        $this->assertStringContainsString('viewBox="0 0 2048 2048"', $svg);
        $this->assertStringContainsString('fill="red"', $svg);
        $this->assertStringContainsString('fill="blue"', $svg);
        $this->assertStringContainsString('opacity="0.5"', $svg);
    }
}

