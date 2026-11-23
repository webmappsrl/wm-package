<?php

namespace Tests\Unit\Services\GeoJsonService;

use PHPUnit\Framework\Attributes\Test;

class IsGeojsonFeaturePolygon extends AbstractGeoJsonTest
{
    #[Test]
    public function is_geojson_feature_polygon_returns_true_if_geojson_feature_polygon()
    {
        $json = '{"type":"Feature","geometry":{"type":"Polygon","coordinates":[[[100.0,0.0],[101.0,0.0],[101.0,1.0],[100.0,1.0],[100.0,0.0]]]}}';
        $this->assertTrue($this->geoJsonService->isGeojsonFeaturePolygon($json));
    }

    #[Test]
    public function is_geojson_feature_polygon_returns_false_if_not_geojson_feature_polygon()
    {
        $json = '{"type":"Feature","geometry":{"type":"Point","coordinates":[100.0,0.0]}}';
        $this->assertFalse($this->geoJsonService->isGeojsonFeaturePolygon($json));
    }

    #[Test]
    public function is_geojson_feature_polygon_returns_false_if_anything_else()
    {
        $this->assertFalse($this->geoJsonService->isGeojsonFeaturePolygon(self::INVALID_JSON));
        $this->assertFalse($this->geoJsonService->isGeojsonFeaturePolygon(self::EMPTY_JSON));
        $this->assertFalse($this->geoJsonService->isGeojsonFeaturePolygon(self::INVALID_VALUE));
        $this->assertFalse($this->geoJsonService->isGeojsonFeaturePolygon(self::NULL_VALUE));
    }
}
