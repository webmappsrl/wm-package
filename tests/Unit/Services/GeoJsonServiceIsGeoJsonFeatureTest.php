<?php

namespace Tests\Unit\Services;

use Wm\WmPackage\Tests\TestCase;
use Wm\WmPackage\Services\GeoJsonService;

class GeoJsonServiceIsGeoJsonFeatureTest extends TestCase
{
    protected GeoJsonService $geoJsonService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->geoJsonService = new GeoJsonService();
    }

    /** @test */
    public function is_geojson_feature_returns_true_if_geojson_feature()
    {
        $this->assertTrue($this->geoJsonService->isGeojsonFeature('{"type":"Feature"}'));
    }

    /** @test */
    public function is_geojson_feature_returns_false_if_not_geojson_feature()
    {
        $this->assertFalse($this->geoJsonService->isGeojsonFeature('{"type":"FeatureCollection"}'));
    }

    /** @test */
    public function is_geojson_feature_returns_false_if_missing_type()
    {
        $this->assertFalse($this->geoJsonService->isGeojsonFeature('{"name":"Feature"}'));
    }

    /** @test */
    public function is_geojson_feature_returns_false_if_anything_else()
    {
        $this->assertFalse($this->geoJsonService->isGeojsonFeature('{"Whatever": : :}'));
        $this->assertFalse($this->geoJsonService->isGeojsonFeature(''));
        $this->assertFalse($this->geoJsonService->isGeojsonFeature('Whatever'));
        $this->assertFalse($this->geoJsonService->isGeojsonFeature(null));
    }
}
