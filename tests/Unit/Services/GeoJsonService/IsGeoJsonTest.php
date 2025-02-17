<?php

namespace Tests\Unit\Services\GeoJsonService;
class IsGeoJson extends AbstractGeoJsonTest
{
    /** @test */
    public function is_geojson_returns_true_if_geojson()
    {
        $this->assertTrue($this->geoJsonService->isGeojson(self::VALID_JSON_FEATURE_TYPE));
    }

    /** @test */
    public function is_geojson_returns_false_if_missing_type()
    {
        $this->assertFalse($this->geoJsonService->isGeojson(self::INVALID_JSON_MISSING_TYPE));
    }

    /** @test */
    public function is_geojson_returns_false_if_malformed_json()
    {
        $this->assertFalse($this->geoJsonService->isGeojson(self::INVALID_JSON));
    }

    /** @test */
    public function is_geojson_returns_false_if_json_is_missing()
    {
        $this->assertFalse($this->geoJsonService->isGeojson(self::NULL_VALUE));
    }
}
