<?php

namespace Tests\Unit\Providers;

use Illuminate\Support\Facades\Http;
use Wm\WmPackage\Exceptions\OsmClientExceptionRelationHasInvalidGeometry;
use Wm\WmPackage\Facades\OsmClient;
use Wm\WmPackage\Tests\TestCase;

class OsmClientgetPropertiesAndGeometryForRelationRealCasesTest extends TestCase
{
    /** @test */
    public function simple_case_it_works()
    {
        // Simple artificial case
        $this->checkInput(31);
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
