<?php

namespace Tests\Unit\Services\GeoJsonService;

class ConvertPolygonToMultiPolygonTest extends AbstractGeoJsonTest
{
    /** @test */
    public function convert_polygon_to_multi_polygon_returns_the_multi_polygon_if_geojson_feature_multi_polygon()
    {
        $json = self::GEOJSON_MULTI_POLYGON_EXAMPLE;
        $this->assertEquals($json, $this->geoJsonService->convertPolygonToMultiPolygon($json));
    }

    /** @test */
    public function convert_polygon_to_multi_polygon_returns_the_multi_polygon_if_geojson_feature_polygon()
    {
        $json = self::GEOJSON_FEATURE_POLYGON_EXAMPLE;
        $expected = self::GEOJSON_MULTI_POLYGON_PROCESSED;
        $this->assertEquals($expected, $this->geoJsonService->convertPolygonToMultiPolygon($json));
    }

    /** @test */
    public function convert_polygon_to_multi_polygon_returns_false_if_anything_else()
    {
        $this->assertNull($this->geoJsonService->convertPolygonToMultiPolygon(self::INVALID_JSON));
        $this->assertNull($this->geoJsonService->convertPolygonToMultiPolygon(self::EMPTY_JSON));
        $this->assertNull($this->geoJsonService->convertPolygonToMultiPolygon(self::INVALID_VALUE));
        $this->assertNull($this->geoJsonService->convertPolygonToMultiPolygon(self::NULL_VALUE));
    }
}
