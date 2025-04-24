<?php

namespace Tests\Unit\Services\GeoJsonService;

class ConvertCollectionToFirstFeatureTest extends AbstractGeoJsonTest
{
    /** @test */
    public function convert_collection_to_first_feature_returns_the_first_feature_if_collection()
    {
        $json = self::GEOJSON_FEATURE_COLLECTION_EXAMPLE;
        $expected = self::GEOJSON_FIRST_FEATURE_CONTENT;
        $this->assertEquals($expected, $this->geoJsonService->convertCollectionToFirstFeature($json));
    }

    /** @test */
    public function convert_collection_to_first_feature_returns_the_feature_if_only_feature()
    {
        $json = self::GEOJSON_FIRST_FEATURE_CONTENT;
        $expected = self::GEOJSON_FIRST_FEATURE_CONTENT;
        $this->assertEquals($expected, $this->geoJsonService->convertCollectionToFirstFeature($json));
    }

    /** @test */
    public function convert_collection_to_first_feature_returns_null_if_not_feature_or_feature_collection()
    {
        $json = self::INVALID_JSON_MISSING_TYPE;
        $this->assertNull($this->geoJsonService->convertCollectionToFirstFeature($json));
    }

    /** @test */
    public function convert_collection_to_first_feature_returns_null_if_not_valid_json()
    {
        $json = self::INVALID_VALUE;
        $this->assertNull($this->geoJsonService->convertCollectionToFirstFeature($json));
    }
}
