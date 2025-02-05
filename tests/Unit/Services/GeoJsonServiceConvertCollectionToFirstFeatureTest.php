<?php

namespace Tests\Unit\Services;

use Wm\WmPackage\Services\GeoJsonService;
use Wm\WmPackage\Tests\TestCase;

class GeoJsonServiceConvertCollectionToFirstFeatureTest extends TestCase
{
    protected GeoJsonService $geoJsonService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->geoJsonService = new GeoJsonService();
    }

    /** @test */
    public function it_returns_the_first_feature_if_collection()
    {
        $json = '{"type":"FeatureCollection", "features":[{"name":"first", "type":"Feature"}, {"name":"second", "type":"Feature"}, {"name":"third", "type":"Feature"}]}';
        $expected = '{"name":"first","type":"Feature"}';
        $this->assertEquals($expected, $this->geoJsonService->convertCollectionToFirstFeature($json));
    }

    /** @test */
    public function it_returns_the_feature_if_only_feature()
    {
        $json = '{"name":"first","type":"Feature"}';
        $expected = '{"name":"first","type":"Feature"}';
        $this->assertEquals($expected, $this->geoJsonService->convertCollectionToFirstFeature($json));
    }

    /** @test */
    public function it_returns_null_if_not_feature_or_feature_collection()
    {
        $json = '{"name":"first"}';
        $this->assertNull($this->geoJsonService->convertCollectionToFirstFeature($json));
    }

    /** @test */
    public function it_returns_null_if_not_valid_json()
    {
        $json = 'Whatever';
        $this->assertNull($this->geoJsonService->convertCollectionToFirstFeature($json));
    }
}
