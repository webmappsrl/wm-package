<?php

namespace Tests\Unit\Services\GeoJsonService;

use Wm\WmPackage\Tests\TestCase;
use Wm\WmPackage\Services\GeoJsonService;

abstract class AbstractGeoJsonTest extends TestCase
{
    const INVALID_JSON = '{"Whatever": : : :}';
    const EMPTY_JSON = '';
    const INVALID_VALUE = 'Whatever';
    const NULL_VALUE = null;

    const INVALID_JSON_MISSING_TYPE = '{"name":"first"}';
    
    const VALID_JSON_FEATURE_TYPE = '{"type":"Feature"}';
    const VALID_JSON_FEATURE_COLLECTION_TYPE = '{"type":"FeatureCollection"}';
    const GEOJSON_FEATURE_COLLECTION_EXAMPLE = '{"type":"FeatureCollection", "features":[{"name":"first", "type":"Feature"}, {"name":"second", "type":"Feature"}, {"name":"third", "type":"Feature"}]}';
    const GEOJSON_FIRST_FEATURE_CONTENT = '{"name":"first","type":"Feature"}';
    const GEOJSON_FEATURE_POLYGON_EXAMPLE = '{"type":"Feature","geometry":{"type":"Polygon","coordinates":[[[100.0,0.0],[101.0,0.0],[101.0,1.0],[100.0,1.0],[100.0,0.0]]]}}';
    const GEOJSON_MULTI_POLYGON_EXAMPLE = '{"type":"Feature","geometry":{"type":"MultiPolygon","coordinates":[[[[100.0,0.0],[101.0,0.0],[101.0,1.0],[100.0,1.0],[100.0,0.0]]]]}}';
    const GEOJSON_MULTI_POLYGON_PROCESSED = '{"type":"Feature","geometry":{"type":"MultiPolygon","coordinates":[[[[100,0],[101,0],[101,1],[100,1],[100,0]]]]}}';
    const GEOJSON_POINT_EXAMPLE = '{"type":"Feature","geometry":{"type":"Point","coordinates":[100.0,0.0]}}';
    protected GeoJsonService $geoJsonService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->geoJsonService = new GeoJsonService();
    }
}
