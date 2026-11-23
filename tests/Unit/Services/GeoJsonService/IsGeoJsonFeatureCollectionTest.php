<?php

namespace Tests\Unit\Services\GeoJsonService;

use PHPUnit\Framework\Attributes\Test;

class IsGeoJsonFeatureCollection extends AbstractGeoJsonTest
{
    #[Test]
    public function is_geojson_feature_collection_returns_true_if_geojson_feature_collection()
    {
        $this->assertTrue($this->geoJsonService->isGeojsonFeatureCollection(self::VALID_JSON_FEATURE_COLLECTION_TYPE));
    }

    #[Test]
    public function is_geojson_feature_collection_returns_false_if_not_geojson_feature_collection()
    {
        $this->assertFalse($this->geoJsonService->isGeojsonFeatureCollection(self::VALID_JSON_FEATURE_TYPE));
    }

    #[Test]
    public function is_geojson_feature_collection_returns_false_if_missing_type()
    {
        $this->assertFalse($this->geoJsonService->isGeojsonFeatureCollection(self::INVALID_JSON_MISSING_TYPE));
    }

    #[Test]
    public function is_geojson_feature_collection_returns_false_if_malformed_json()
    {
        $this->assertFalse($this->geoJsonService->isGeojsonFeatureCollection(self::INVALID_JSON));
    }

    #[Test]
    public function is_geojson_feature_collection_returns_false_if_json_is_missing()
    {
        $this->assertFalse($this->geoJsonService->isGeojsonFeatureCollection(self::NULL_VALUE));
    }
}
