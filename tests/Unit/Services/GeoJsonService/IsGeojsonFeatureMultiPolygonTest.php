<?php

namespace Tests\Unit\Services\GeoJsonService;

class TestIsGeojsonFeatureMultiPolygon extends AbstractGeoJsonTest
{
    /** @test */
    public function is_geojson_feature_multi_polygon_returns_true_if_geojson_feature_multi_polygon()
    {
        $this->assertTrue($this->geoJsonService->isGeojsonFeatureMultiPolygon(self::GEOJSON_MULTI_POLYGON_EXAMPLE));
    }

    /** @test */
    public function is_geojson_feature_multi_polygon_returns_false_if_not_geojson_feature_multi_polygon()
    {
        $this->assertFalse($this->geoJsonService->isGeojsonFeatureMultiPolygon(self::GEOJSON_POINT_EXAMPLE));
    }

    /** @test */
    public function is_geojson_feature_multi_polygon_returns_false_if_anything_else()
    {
        $this->assertFalse($this->geoJsonService->isGeojsonFeatureMultiPolygon(self::INVALID_JSON));
        $this->assertFalse($this->geoJsonService->isGeojsonFeatureMultiPolygon(self::EMPTY_JSON));
        $this->assertFalse($this->geoJsonService->isGeojsonFeatureMultiPolygon(self::INVALID_VALUE));
        $this->assertFalse($this->geoJsonService->isGeojsonFeatureMultiPolygon(self::NULL_VALUE));
    }
}
