<?php

namespace Tests\Unit\Services\GeoJsonService;
class IsGeoJsonFeatureCollection extends AbstractGeoJsonTest
{
    /** @test */
    public function is_geojson_feature_collection_returns_true_if_geojson_feature_collection()
    {
        $this->assertTrue($this->geoJsonService->isGeojsonFeatureCollection(self::VALID_JSON_FEATURE_COLLECTION_TYPE));
    }

    /** @test */
    public function is_geojson_feature_collection_returns_false_if_not_geojson_feature_collection()
    {
        $this->assertFalse($this->geoJsonService->isGeojsonFeatureCollection(self::VALID_JSON_FEATURE_TYPE));
    }

    /** @test */
    public function is_geojson_feature_collection_returns_false_if_missing_type()
    {
        $this->assertFalse($this->geoJsonService->isGeojsonFeatureCollection(self::INVALID_JSON_MISSING_TYPE));
    }

    /** @test */
    public function is_geojson_feature_collection_returns_false_if_malformed_json()
    {
        $this->assertFalse($this->geoJsonService->isGeojsonFeatureCollection(self::INVALID_JSON));
    }

    /** @test */
    public function is_geojson_feature_collection_returns_false_if_json_is_missing()
    {
        $this->assertFalse($this->geoJsonService->isGeojsonFeatureCollection(self::NULL_VALUE));
    }
}
