<?php

namespace Tests\Unit\Providers;

use Illuminate\Support\Facades\Http;
use Wm\WmPackage\Exceptions\OsmClientExceptionNoElements;
use Wm\WmPackage\Exceptions\OsmClientExceptionRelationHasNoNodes;
use Wm\WmPackage\Exceptions\OsmClientExceptionRelationHasNoRelationElement;
use Wm\WmPackage\Exceptions\OsmClientExceptionRelationHasNoWays;
use Wm\WmPackage\Facades\OsmClient;
use Wm\WmPackage\Tests\TestCase;

class OsmClientgetPropertiesAndGeometryForRelationCheckInputTest extends TestCase
{
    /** @test */
    public function with_no_elements_throws_proper_exception()
    {
        $input = <<<'EOF'
        { "version" : "0.6" }
        EOF;

        $this->checkException($input, OsmClientExceptionNoElements::class);
    }

    /** @test */
    public function with_no_nodes_throws_proper_exception()
    {
        $input = <<<'EOF'
        {
            "elements": [
                { "type": "way", "id": 31, "timestamp": "2020-01-01T01:01:01Z", "nodes": [11,12] },
                { "type": "relation", "id": 31, "timestamp": "2020-01-01T01:01:01Z",
                "members": [
                    { "type": "way", "ref": 11, "role": "" }
                ],
                "tags": { "key1": "val1", "key2": "val2" }
                }
            ]
        }
        EOF;

        $this->checkException($input, OsmClientExceptionRelationHasNoNodes::class);
    }

    /** @test */
    public function with_no_ways_throws_proper_exception()
    {
        $input = <<<'EOF'
        {
            "elements": [
                { "type": "node", "id": 11, "lon": 11.1, "lat": 11.2, "timestamp": "2020-01-01T01:01:01Z" },
                { "type": "node", "id": 12, "lon": 12.1, "lat": 12.2, "timestamp": "2020-02-02T02:02:02Z" },
                { "type": "relation", "id": 31, "timestamp": "2020-01-01T01:01:01Z",
                "members": [
                    { "type": "way", "ref": 11, "role": "" }
                ],
                "tags": { "key1": "val1", "key2": "val2" }
                }
            ]
        }
        EOF;

        $this->checkException($input, OsmClientExceptionRelationHasNoWays::class);
    }

    /** @test */
    public function with_no_relation_element_throws_proper_exception()
    {
        $input = <<<'EOF'
        {
            "elements": [
                { "type": "node", "id": 11, "lon": 11.1, "lat": 11.2, "timestamp": "2020-01-01T01:01:01Z" },
                { "type": "node", "id": 12, "lon": 12.1, "lat": 12.2, "timestamp": "2020-02-02T02:02:02Z" },
                { "type": "way", "id": 31, "timestamp": "2020-01-01T01:01:01Z", "nodes": [11,12] }
            ]
        }
        EOF;

        $this->checkException($input, OsmClientExceptionRelationHasNoRelationElement::class);
    }

    private function checkException($input, $class)
    {
        $osmid = 'relation/31';
        $url = 'https://api.openstreetmap.org/api/0.6/relation/31/full.json';

        Http::fake([
            $url => Http::sequence()->push(json_decode($input, true), 200),
        ]);

        $this->expectException($class);
        OsmClient::getPropertiesAndGeometry($osmid);
    }
}
