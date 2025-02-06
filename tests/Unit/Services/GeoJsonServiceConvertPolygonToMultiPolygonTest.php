<?php

namespace Tests\Unit\Services;

use Wm\WmPackage\Tests\TestCase;
use Wm\WmPackage\Services\GeoJsonService;
class GeoJsonServiceConvertPolygonToMultiPolygonTest extends TestCase
{
    protected GeoJsonService $geoJsonService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->geoJsonService = new GeoJsonService();
    }

    /** @test */
    public function convert_polygon_to_multi_polygon_returns_the_multi_polygon_if_geojson_feature_multi_polygon()
    {
        $json = '{"type":"Feature","geometry":{"type":"MultiPolygon","coordinates":[[[[100.0,0.0],[101.0,0.0],[101.0,1.0],[100.0,1.0],[100.0,0.0]]]]}}';
        $this->assertEquals($json, $this->geoJsonService->convertPolygonToMultiPolygon($json));
    }

    /** @test */
    public function convert_polygon_to_multi_polygon_returns_the_multi_polygon_if_geojson_feature_polygon()
    {
        $json = '{"type":"Feature","geometry":{"type":"Polygon","coordinates":[[[100.0,0.0],[101.0,0.0],[101.0,1.0],[100.0,1.0],[100.0,0.0]]]}}';
        $expected = '{"type":"Feature","geometry":{"type":"MultiPolygon","coordinates":[[[[100,0],[101,0],[101,1],[100,1],[100,0]]]]}}';
        $this->assertEquals($expected, $this->geoJsonService->convertPolygonToMultiPolygon($json));
    }

    /** @test */
    public function convert_polygon_to_multi_polygon_returns_false_if_anything_else()
    {
        $this->assertFalse($this->geoJsonService->convertPolygonToMultiPolygon('{"Whatever": : : :}'));
        $this->assertFalse($this->geoJsonService->convertPolygonToMultiPolygon(''));
        $this->assertFalse($this->geoJsonService->convertPolygonToMultiPolygon('Whatever'));
        $this->assertFalse($this->geoJsonService->convertPolygonToMultiPolygon(null));
    }
}
