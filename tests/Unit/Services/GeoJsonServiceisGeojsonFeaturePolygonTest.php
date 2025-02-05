<?php

namespace Tests\Unit\Services;

use Wm\WmPackage\Tests\TestCase;
use Wm\WmPackage\Services\GeoJsonService;
class GeoJsonServiceTestIsGeojsonFeaturePolygon extends TestCase
{
    protected GeoJsonService $geoJsonService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->geoJsonService = new GeoJsonService();
    }

    /** @test */
    public function it_returns_true_if_geojson_feature_polygon()
    {
        $json = '{"type":"Feature","geometry":{"type":"Polygon","coordinates":[[[100.0,0.0],[101.0,0.0],[101.0,1.0],[100.0,1.0],[100.0,0.0]]]}}';
        $this->assertTrue($this->geoJsonService->isGeojsonFeaturePolygon($json));
    }

    /** @test */
    public function it_returns_false_if_not_geojson_feature_polygon()
    {
        $json = '{"type":"Feature","geometry":{"type":"Point","coordinates":[100.0,0.0]}}';
        $this->assertFalse($this->geoJsonService->isGeojsonFeaturePolygon($json));
    }

    /** @test */
    public function it_returns_false_if_anything_else()
    {
        $this->assertFalse($this->geoJsonService->isGeojsonFeaturePolygon('{"Whatever": : : :}'));
        $this->assertFalse($this->geoJsonService->isGeojsonFeaturePolygon(''));
        $this->assertFalse($this->geoJsonService->isGeojsonFeaturePolygon('Whatever'));
        $this->assertFalse($this->geoJsonService->isGeojsonFeaturePolygon(null));
    }
}
