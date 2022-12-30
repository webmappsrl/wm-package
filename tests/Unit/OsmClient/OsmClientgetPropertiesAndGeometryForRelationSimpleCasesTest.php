<?php

namespace Tests\Unit\Providers;

use Illuminate\Support\Facades\Http;
use Wm\WmPackage\Exceptions\OsmClientExceptionRelationHasInvalidGeometry;
use Wm\WmPackage\Facades\OsmClient;
use Wm\WmPackage\Tests\TestCase;

class OsmClientgetPropertiesAndGeometryForRelationSimpleCasesTest extends TestCase
{
    /** @test */
    public function simple_case_it_works()
    {
        // Prepare data
        $input = <<<'EOF'
        {
            "elements": [
                { "type": "node", "id": 11, "lon": 11.1, "lat": 11.2, "timestamp": "2020-01-01T01:01:01Z" },
                { "type": "node", "id": 12, "lon": 12.1, "lat": 12.2, "timestamp": "2020-02-02T02:02:02Z" },
                { "type": "node", "id": 13, "lon": 13.1, "lat": 13.2, "timestamp": "2020-02-02T03:03:03Z" },
                { "type": "way", "id": 21, "timestamp": "2020-01-01T01:01:01Z", "nodes": [11,12] },
                { "type": "way", "id": 22, "timestamp": "2020-01-01T01:01:01Z", "nodes": [12,13] },
                { "type": "relation", "id": 31, "timestamp": "2020-01-01T01:01:01Z",
                "members": [
                    { "type": "way", "ref": 21, "role": "" },
                    { "type": "way", "ref": 22, "role": "" }
                ],
                "tags": { "key1": "val1", "key2": "val2" }
                }
            ]
        }
        EOF;

        $this->checkInput($input);
    }

    /** @test */
    public function node_order1_it_works()
    {
        // Prepare data
        $input = <<<'EOF'
        {
            "elements": [
                { "type": "node", "id": 11, "lon": 11.1, "lat": 11.2, "timestamp": "2020-01-01T01:01:01Z" },
                { "type": "node", "id": 12, "lon": 12.1, "lat": 12.2, "timestamp": "2020-02-02T02:02:02Z" },
                { "type": "node", "id": 13, "lon": 13.1, "lat": 13.2, "timestamp": "2020-02-02T03:03:03Z" },
                { "type": "way", "id": 21, "timestamp": "2020-01-01T01:01:01Z", "nodes": [12,11] },
                { "type": "way", "id": 22, "timestamp": "2020-01-01T01:01:01Z", "nodes": [12,13] },
                { "type": "relation", "id": 31, "timestamp": "2020-01-01T01:01:01Z",
                "members": [
                    { "type": "way", "ref": 21, "role": "" },
                    { "type": "way", "ref": 22, "role": "" }
                ],
                "tags": { "key1": "val1", "key2": "val2" }
                }
            ]
        }
        EOF;

        $this->checkInput($input);
    }

    /** @test */
    public function node_order2_it_works()
    {
        // Prepare data
        $input = <<<'EOF'
        {
            "elements": [
                { "type": "node", "id": 11, "lon": 11.1, "lat": 11.2, "timestamp": "2020-01-01T01:01:01Z" },
                { "type": "node", "id": 12, "lon": 12.1, "lat": 12.2, "timestamp": "2020-02-02T02:02:02Z" },
                { "type": "node", "id": 13, "lon": 13.1, "lat": 13.2, "timestamp": "2020-02-02T03:03:03Z" },
                { "type": "way", "id": 21, "timestamp": "2020-01-01T01:01:01Z", "nodes": [11,12] },
                { "type": "way", "id": 22, "timestamp": "2020-01-01T01:01:01Z", "nodes": [13,12] },
                { "type": "relation", "id": 31, "timestamp": "2020-01-01T01:01:01Z",
                "members": [
                    { "type": "way", "ref": 21, "role": "" },
                    { "type": "way", "ref": 22, "role": "" }
                ],
                "tags": { "key1": "val1", "key2": "val2" }
                }
            ]
        }
        EOF;

        $this->checkInput($input);
    }

    /** @test */
    public function node_order3_it_works()
    {
        // Prepare data
        $input = <<<'EOF'
        {
            "elements": [
                { "type": "node", "id": 11, "lon": 11.1, "lat": 11.2, "timestamp": "2020-01-01T01:01:01Z" },
                { "type": "node", "id": 12, "lon": 12.1, "lat": 12.2, "timestamp": "2020-02-02T02:02:02Z" },
                { "type": "node", "id": 13, "lon": 13.1, "lat": 13.2, "timestamp": "2020-02-02T03:03:03Z" },
                { "type": "way", "id": 21, "timestamp": "2020-01-01T01:01:01Z", "nodes": [12,11] },
                { "type": "way", "id": 22, "timestamp": "2020-01-01T01:01:01Z", "nodes": [13,12] },
                { "type": "relation", "id": 31, "timestamp": "2020-01-01T01:01:01Z",
                "members": [
                    { "type": "way", "ref": 21, "role": "" },
                    { "type": "way", "ref": 22, "role": "" }
                ],
                "tags": { "key1": "val1", "key2": "val2" }
                }
            ]
        }
        EOF;

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

        /** @test */
        public function simple_case_inverted_it_works()
        {
            // Prepare data
            $input = <<<'EOF'
            {
                "elements": [
                    { "type": "node", "id": 11, "lon": 11.1, "lat": 11.2, "timestamp": "2020-01-01T01:01:01Z" },
                    { "type": "node", "id": 12, "lon": 12.1, "lat": 12.2, "timestamp": "2020-02-02T02:02:02Z" },
                    { "type": "node", "id": 13, "lon": 13.1, "lat": 13.2, "timestamp": "2020-02-02T03:03:03Z" },
                    { "type": "way", "id": 21, "timestamp": "2020-01-01T01:01:01Z", "nodes": [11,12] },
                    { "type": "way", "id": 22, "timestamp": "2020-01-01T01:01:01Z", "nodes": [12,13] },
                    { "type": "relation", "id": 31, "timestamp": "2020-01-01T01:01:01Z",
                    "members": [
                        { "type": "way", "ref": 22, "role": "" },
                        { "type": "way", "ref": 21, "role": "" }
                    ],
                    "tags": { "key1": "val1", "key2": "val2" }
                    }
                ]
            }
            EOF;
    
            $this->checkInputInverted($input);
        }
    
    

    private function checkInputInverted($input) {
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
                [13.1,13.2],
                [12.1,12.2],
                [11.1,11.2]
            ]]
        ];

        // Asserts
        $this->assertEquals($properties_expected,$properties);
        $this->assertEquals($geometry_expected,$geometry);

    }
}
