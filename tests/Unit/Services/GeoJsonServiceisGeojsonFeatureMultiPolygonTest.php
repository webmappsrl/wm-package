<?php

namespace Tests\Unit\Services;

use Wm\WmPackage\Tests\TestCase;
use Wm\WmPackage\Services\GeoJsonService;
class GeoJsonServiceTestIsGeojsonFeatureMultiPolygon extends TestCase
{
    protected GeoJsonService $geoJsonService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->geoJsonService = new GeoJsonService();
    }

    /** @test */
    public function is_geojson_feature_multi_polygon_returns_true_if_geojson_feature_multi_polygon()
    {
        $json = '{"type":"Feature","geometry":{"type":"MultiPolygon","coordinates":[[[[100.0,0.0],[101.0,0.0],[101.0,1.0],[100.0,1.0],[100.0,0.0]]]]}}';
        $this->assertTrue($this->geoJsonService->isGeojsonFeatureMultiPolygon($json));
    }

    /** @test */
    public function is_geojson_feature_multi_polygon_returns_false_if_not_geojson_feature_multi_polygon()
    {
        $json = '{"type":"Feature","geometry":{"type":"Point","coordinates":[100.0,0.0]}}';
        $this->assertFalse($this->geoJsonService->isGeojsonFeatureMultiPolygon($json));
    }

    /** @test */
    public function is_geojson_feature_multi_polygon_returns_false_if_anything_else()
    {
        $this->assertFalse($this->geoJsonService->isGeojsonFeatureMultiPolygon('{"Whatever": : : :}'));
        $this->assertFalse($this->geoJsonService->isGeojsonFeatureMultiPolygon(''));
        $this->assertFalse($this->geoJsonService->isGeojsonFeatureMultiPolygon('Whatever'));
        $this->assertFalse($this->geoJsonService->isGeojsonFeatureMultiPolygon(null));
    }
}
