<?php

namespace Tests\Unit\Services;

use Wm\WmPackage\Tests\TestCase;
use Wm\WmPackage\Services\GeoJsonService;

class GeoJsonServiceTestIsGeoJsonFeatureCollection extends TestCase
{
    protected GeoJsonService $geoJsonService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->geoJsonService = new GeoJsonService();
    }

    /** @test */
    public function is_geojson_feature_collection_returns_true_if_geojson_feature_collection()
    {
        $this->assertTrue($this->geoJsonService->isGeojsonFeatureCollection('{"type":"FeatureCollection"}'));
    }

    /** @test */
    public function is_geojson_feature_collection_returns_false_if_not_geojson_feature_collection()
    {
        $this->assertFalse($this->geoJsonService->isGeojsonFeatureCollection('{"type":"Feature"}'));
    }

    /** @test */
    public function is_geojson_feature_collection_returns_false_if_missing_type()
    {
        $this->assertFalse($this->geoJsonService->isGeojsonFeatureCollection('{"name":"FeatureCollection"}'));
    }

    /** @test */
    public function is_geojson_feature_collection_returns_false_if_malformed_json()
    {
        $this->assertFalse($this->geoJsonService->isGeojsonFeatureCollection('{"Whatever": : :}'));
    }

    /** @test */
    public function is_geojson_feature_collection_returns_false_if_json_is_missing()
    {
        $this->assertFalse($this->geoJsonService->isGeojsonFeatureCollection(null));
    }
}
