<?php

namespace Tests\Unit\Services;

use Wm\WmPackage\Tests\TestCase;
use Wm\WmPackage\Services\GeoJsonService;

class GeoJsonServiceTestIsGeoJson extends TestCase
{
    protected GeoJsonService $geoJsonService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->geoJsonService = new GeoJsonService();
    }

    /** @test */
    public function is_geojson_returns_true_if_geojson()
    {
        $this->assertTrue($this->geoJsonService->isGeojson('{"type":"Whatever"}'));
    }

    /** @test */
    public function is_geojson_returns_false_if_missing_type()
    {
        $this->assertFalse($this->geoJsonService->isGeojson('{"name":"Whatever"}'));
    }

    /** @test */
    public function is_geojson_returns_false_if_malformed_json()
    {
        $this->assertFalse($this->geoJsonService->isGeojson('{"Whatever": : :}'));
    }

    /** @test */
    public function is_geojson_returns_false_if_json_is_missing()
    {
        $this->assertFalse($this->geoJsonService->isGeojson(null));
    }
}
