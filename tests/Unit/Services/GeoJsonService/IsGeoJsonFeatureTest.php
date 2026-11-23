<?php

namespace Tests\Unit\Services\GeoJsonService;

use PHPUnit\Framework\Attributes\Test;

class IsGeoJsonFeature extends AbstractGeoJsonTest
{
    #[Test]
    public function is_geojson_feature_returns_true_if_geojson_feature()
    {
        $this->assertTrue($this->geoJsonService->isGeojsonFeature(self::VALID_JSON_FEATURE_TYPE));
    }

    #[Test]
    public function is_geojson_feature_returns_false_if_not_geojson_feature()
    {
        $this->assertFalse($this->geoJsonService->isGeojsonFeature(self::VALID_JSON_FEATURE_COLLECTION_TYPE));
    }

    #[Test]
    public function is_geojson_feature_returns_false_if_missing_type()
    {
        $this->assertFalse($this->geoJsonService->isGeojsonFeature(self::INVALID_JSON_MISSING_TYPE));
    }

    #[Test]
    public function is_geojson_feature_returns_false_if_anything_else()
    {
        $this->assertFalse($this->geoJsonService->isGeojsonFeature(self::INVALID_JSON));
        $this->assertFalse($this->geoJsonService->isGeojsonFeature(self::EMPTY_JSON));
        $this->assertFalse($this->geoJsonService->isGeojsonFeature(self::INVALID_VALUE));
        $this->assertFalse($this->geoJsonService->isGeojsonFeature(self::NULL_VALUE));
    }
}
