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
        // Prepare data
        $input = file_get_contents(__DIR__.'/fixtures/simple.json');
        $this->checkInput($input);
    }

    private function checkInput($input) {
        $osmid = 'relation/31';
        $url = 'https://api.openstreetmap.org/api/0.6/relation/31/full.json';

        // Mock HTTP call
        Http::fake([
            $url => Http::sequence()->push(json_decode($input, true), 200),
        ]);

        // Fire and prepare output
        $r = OsmClient::getPropertiesAndGeometry($osmid);
        $properties = $r[0];
        $geometry = $r[1];

        // Prepare Expected value
        $properties_expected = [
            'key1' => 'val1',
            'key2' => 'val2',
            '_roundtrip' => false,
            '_updated_at' => '2020-02-02 03:03:03'
        ];
        $geometry_expected = [
            'type' => 'MultiLineString',
            'coordinates' => [[
                [11.1,11.2],
                [12.1,12.2],
                [13.1,13.2]
            ]]
        ];

        // Asserts
        $this->assertEquals($properties_expected,$properties);
        $this->assertEquals($geometry_expected,$geometry);

    }
 
}
