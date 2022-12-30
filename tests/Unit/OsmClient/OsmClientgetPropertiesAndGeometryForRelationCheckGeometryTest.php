<?php

namespace Tests\Unit\Providers;

use Illuminate\Support\Facades\Http;
use Wm\WmPackage\Exceptions\OsmClientExceptionRelationHasInvalidGeometry;
use Wm\WmPackage\Facades\OsmClient;
use Wm\WmPackage\Tests\TestCase;

class OsmClientgetPropertiesAndGeometryForRelationCheckGeometryTest extends TestCase
{
    /** @test */
    public function with_valid_json_it_works()
    {
        $input = <<<'EOF'
        {
            "elements": [
                { "type": "node", "id": 11, "lon": 11.1, "lat": 11.2, "timestamp": "2020-01-01T01:01:01Z" },
                { "type": "node", "id": 12, "lon": 12.1, "lat": 12.2, "timestamp": "2020-02-02T02:02:02Z" },
                { "type": "node", "id": 13, "lon": 13.1, "lat": 13.2, "timestamp": "2020-02-02T02:02:02Z" },
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

        $osmid = 'relation/31';
        $url = 'https://api.openstreetmap.org/api/0.6/relation/31/full.json';

        Http::fake([
            $url => Http::sequence()->push(json_decode($input, true), 200),
        ]);

        OsmClient::getPropertiesAndGeometry($osmid);
        // No Exception
        $this->assertTrue(true);
    }

    /** @test */
    public function with_not_connected_ways_it_throws_proper_exception()
    {
        $input = <<<'EOF'
        {
            "elements": [
                { "type": "node", "id": 11, "lon": 11.1, "lat": 11.2, "timestamp": "2020-01-01T01:01:01Z" },
                { "type": "node", "id": 12, "lon": 12.1, "lat": 12.2, "timestamp": "2020-02-02T02:02:02Z" },
                { "type": "node", "id": 13, "lon": 13.1, "lat": 13.2, "timestamp": "2020-02-02T02:02:02Z" },
                { "type": "node", "id": 14, "lon": 14.1, "lat": 14.2, "timestamp": "2020-02-02T02:02:02Z" },
                { "type": "way", "id": 21, "timestamp": "2020-01-01T01:01:01Z", "nodes": [11,12] },
                { "type": "way", "id": 22, "timestamp": "2020-01-01T01:01:01Z", "nodes": [13,14] },
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

        $this->checkException($input, OsmClientExceptionRelationHasInvalidGeometry::class);
    }

    /** @test */
    public function with_mustache_it_throws_proper_exception()
    {
        $input = <<<'EOF'
        {
            "elements": [
                { "type": "node", "id": 11, "lon": 11.1, "lat": 11.2, "timestamp": "2020-01-01T01:01:01Z" },
                { "type": "node", "id": 12, "lon": 12.1, "lat": 12.2, "timestamp": "2020-02-02T02:02:02Z" },
                { "type": "node", "id": 13, "lon": 13.1, "lat": 13.2, "timestamp": "2020-02-02T02:02:02Z" },
                { "type": "way", "id": 21, "timestamp": "2020-01-01T01:01:01Z", "nodes": [11,12] },
                { "type": "way", "id": 22, "timestamp": "2020-01-01T01:01:01Z", "nodes": [12,13] },
                { "type": "way", "id": 23, "timestamp": "2020-01-01T01:01:01Z", "nodes": [12,14] },
                { "type": "relation", "id": 31, "timestamp": "2020-01-01T01:01:01Z",
                "members": [
                    { "type": "way", "ref": 21, "role": "" },
                    { "type": "way", "ref": 22, "role": "" },
                    { "type": "way", "ref": 23, "role": "" }
                ],
                "tags": { "key1": "val1", "key2": "val2" }
                }
            ]
        }
        EOF;

        $this->checkException($input, OsmClientExceptionRelationHasInvalidGeometry::class);
    }

    /** TODO: activate test, now does not work */
    public function with_regular_round_trip_no_exception()
    {
        $input = <<<'EOF'
        {
            "elements": [
                { "type": "node", "id": 11, "lon": 11.1, "lat": 11.2, "timestamp": "2020-01-01T01:01:01Z" },
                { "type": "node", "id": 12, "lon": 12.1, "lat": 12.2, "timestamp": "2020-02-02T02:02:02Z" },
                { "type": "node", "id": 13, "lon": 13.1, "lat": 13.2, "timestamp": "2020-02-02T02:02:02Z" },
                { "type": "node", "id": 14, "lon": 14.1, "lat": 14.2, "timestamp": "2020-02-02T02:02:02Z" },
                { "type": "way", "id": 21, "timestamp": "2020-01-01T01:01:01Z", "nodes": [11,12,13] },
                { "type": "way", "id": 22, "timestamp": "2020-01-01T01:01:01Z", "nodes": [13,14,11] },
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

        $osmid = 'relation/31';
        $url = 'https://api.openstreetmap.org/api/0.6/relation/31/full.json';

        Http::fake([
            $url => Http::sequence()->push(json_decode($input, true), 200),
        ]);

        OsmClient::getPropertiesAndGeometry($osmid);
        // No Exception
        $this->assertTrue(true);
    }

    /** @test */
    public function with_valid_json_nodes_order_does_not_count_it_works()
    {
        $input = <<<'EOF'
        {
            "elements": [
                { "type": "node", "id": 11, "lon": 11.1, "lat": 11.2, "timestamp": "2020-01-01T01:01:01Z" },
                { "type": "node", "id": 12, "lon": 12.1, "lat": 12.2, "timestamp": "2020-02-02T02:02:02Z" },
                { "type": "node", "id": 13, "lon": 13.1, "lat": 13.2, "timestamp": "2020-02-02T02:02:02Z" },
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

        $osmid = 'relation/31';
        $url = 'https://api.openstreetmap.org/api/0.6/relation/31/full.json';

        Http::fake([
            $url => Http::sequence()->push(json_decode($input, true), 200),
        ]);

        OsmClient::getPropertiesAndGeometry($osmid);
        // No Exception
        $this->assertTrue(true);
    }

    /** @test */
    public function with_valid_json_members_order_does_not_count_it_works()
    {
        $input = <<<'EOF'
        {
            "elements": [
                { "type": "node", "id": 11, "lon": 11.1, "lat": 11.2, "timestamp": "2020-01-01T01:01:01Z" },
                { "type": "node", "id": 12, "lon": 12.1, "lat": 12.2, "timestamp": "2020-02-02T02:02:02Z" },
                { "type": "node", "id": 13, "lon": 13.1, "lat": 13.2, "timestamp": "2020-02-02T02:02:02Z" },
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

        $osmid = 'relation/31';
        $url = 'https://api.openstreetmap.org/api/0.6/relation/31/full.json';

        Http::fake([
            $url => Http::sequence()->push(json_decode($input, true), 200),
        ]);

        OsmClient::getPropertiesAndGeometry($osmid);
        // No Exception
        $this->assertTrue(true);
    }

    /** @test */
    public function with_valid_json_members_order_and_nodes_does_not_count_it_works()
    {
        $input = <<<'EOF'
        {
            "elements": [
                { "type": "node", "id": 11, "lon": 11.1, "lat": 11.2, "timestamp": "2020-01-01T01:01:01Z" },
                { "type": "node", "id": 12, "lon": 12.1, "lat": 12.2, "timestamp": "2020-02-02T02:02:02Z" },
                { "type": "node", "id": 13, "lon": 13.1, "lat": 13.2, "timestamp": "2020-02-02T02:02:02Z" },
                { "type": "way", "id": 21, "timestamp": "2020-01-01T01:01:01Z", "nodes": [12,11] },
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

        $osmid = 'relation/31';
        $url = 'https://api.openstreetmap.org/api/0.6/relation/31/full.json';

        Http::fake([
            $url => Http::sequence()->push(json_decode($input, true), 200),
        ]);

        OsmClient::getPropertiesAndGeometry($osmid);
        // No Exception
        $this->assertTrue(true);
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
