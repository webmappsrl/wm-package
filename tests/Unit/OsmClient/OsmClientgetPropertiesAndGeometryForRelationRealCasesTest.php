<?php

namespace Tests\Unit\Providers;

use Illuminate\Support\Facades\Http;
use Wm\WmPackage\Exceptions\OsmClientExceptionRelationHasInvalidGeometry;
use Wm\WmPackage\Facades\OsmClient;
use Wm\WmPackage\Tests\TestCase;
/**
 * Some real (short) cases taken from OSM2CAI project with SDA=4
 * SQL: select relation_id,distance from hiking_routes where distance > 0 AND distance <1 AND osm2cai_status=4 order by distance asc limit 50;
 *  relation_id | distance 
 * -------------+----------
 *     12254933 |     0.04
 *     12254949 |     0.07
 *     14448538 |    0.521
 *     14336243 |    0.547 
 * 
 *  How to build fixtures file for relation 12254933
 *  1) Download https://www.openstreetmap.org/api/0.6/relation/12254933/full.json and save it to .fixtures/12254933.json
 *  2) Use Overpass API: https://overpass-turbo.eu/s/1pz2 to create output and save it to .fixtures/12254933.geojson
 */
class OsmClientgetPropertiesAndGeometryForRelationRealCasesTest extends TestCase
{
    /** @test */
    public function simple_case_it_works()
    {
        // Simple artificial case
        $this->checkInput(31);
    }
    /** @test */
    public function real_case_case_with_relation_12254933_it_works()
    {
        // Simple artificial case
        $this->checkInput(12254933);
    }

    private function checkInput($relation_id) {
        $input = file_get_contents(__DIR__."/fixtures/$relation_id.json");

        $osmid = "relation/$relation_id";
        $url = 'https://api.openstreetmap.org/api/0.6/relation/31/full.json';

        // Mock HTTP call
        Http::fake([
            $url => Http::sequence()->push(json_decode($input, true), 200),
        ]);

        // Fire and prepare output
        $r = OsmClient::getPropertiesAndGeometry($osmid);
        $properties = $r[0];
        $geometry = $r[1];

        $expected = json_decode(file_get_contents(__DIR__."/fixtures/$relation_id.geojson"),true);
        // Prepare Expected value
        $properties_expected = $expected['properties'];
        $geometry_expected = $expected['geometry'];
        // Asserts
        $this->assertEquals($properties_expected,$properties);
        $this->assertEquals($geometry_expected,$geometry);

    }
 
}
