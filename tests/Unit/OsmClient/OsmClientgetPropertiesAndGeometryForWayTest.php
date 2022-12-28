<?php

namespace Tests\Unit\Providers;

use Exception;
use Illuminate\Support\Facades\Http;
use Wm\WmPackage\Exceptions\OsmClientExceptionNodeHasNoLat;
use Wm\WmPackage\Exceptions\OsmClientExceptionNodeHasNoLon;
use Wm\WmPackage\Exceptions\OsmClientExceptionNoTags;
use Wm\WmPackage\Exceptions\OsmClientExceptionWayHasNoNodes;
use Wm\WmPackage\Facades\OsmClient;
use Wm\WmPackage\Tests\TestCase;

class OsmClientgetPropertiesAndGeometryForWayTest extends TestCase
{
    private function getJsonWay(): string
    {
        return json_encode([
            'version' => '0.6',
            'elements' => [
                [
                    'id' => 2,
                    'type' => 'node',
                    'lat' => 44,
                    'lon' => 10,
                    'timestamp' => '2021-09-13T14:57:20Z',
                    'tags' => [
                        'name' => 'Name of node with id 2',
                    ],
                ],
                [
                    'id' => 3,
                    'type' => 'node',
                    'lat' => 45,
                    'lon' => 11,
                    'timestamp' => '2020-09-13T14:57:20Z',
                    'tags' => [
                        'name' => 'Name of node with id 3',
                    ],
                ],
                [
                    'id' => 1,
                    'type' => 'way',
                    'nodes' => [
                        2,
                        3,
                    ],
                    'timestamp' => '2018-09-13T14:57:20Z',
                    'tags' => [
                        'name' => 'Name of way with id 1',
                    ],
                ],
            ],
        ]);
    }

    // Exceptions
    /** @test */
    public function no_elements_throw_exception()
    {
        $osmid = 'way/1';
        $return = json_encode([
            'version' => '0.6',
        ]);
        $url = 'https://api.openstreetmap.org/api/0.6/way/1/full.json';
        // $mock = $this->mock(Http::class, function (MockInterface $mock) use ($url,$return) {
        //     $mock->shouldReceive('get')
        //          ->once()
        //          ->with($url)
        //          ->andReturn($return);
        // });

        Http::fake([
            $url => Http::sequence()->push($return, 200),
        ]);

        $this->expectException(Exception::class);
        OsmClient::getPropertiesAndGeometry($osmid);
        $this->assertTrue(false);
    }

    /** @test */
    public function no_tags_throw_exception()
    {
        $osmid = 'way/1';
        $return = json_encode([
            'version' => '0.6',
            'elements' => [
                [
                    'id' => 2,
                    'type' => 'node',
                    'lat' => 44,
                    'lon' => 10,
                ],
                [
                    'id' => 3,
                    'type' => 'node',
                    'lat' => 45,
                    'lon' => 11,
                ],
                [
                    'id' => 1,
                    'type' => 'way',
                    'nodes' => [
                        2,
                        3,
                    ],
                ],
            ],

        ]);
        $url = 'https://api.openstreetmap.org/api/0.6/way/1/full.json';
        // $mock = $this->mock(Http::class, function (MockInterface $mock) use ($url, $return) {
        //     $mock->shouldReceive('get')
        //         ->once()
        //         ->with($url)
        //         ->andReturn($return);
        // });

        Http::fake([
            $url => Http::sequence()->push($return, 200),
        ]);

        $this->expectException(OsmClientExceptionNoTags::class);
        OsmClient::getPropertiesAndGeometry($osmid);
        $this->assertTrue(false);
    }

    /** @test */
    public function no_nodes_throw_exception()
    {
        $osmid = 'way/1';
        $return = json_encode([
            'version' => '0.6',
            'elements' => [
                [
                    'id' => 2,
                    'type' => 'node',
                    'lat' => 44,
                    'lon' => 10,
                ],
                [
                    'id' => 3,
                    'type' => 'node',
                    'lat' => 45,
                    'lon' => 11,
                ],
                [
                    'id' => 1,
                    'type' => 'way',
                    'tags' => [
                        'name' => 'name of way',
                    ],
                ],
            ],

        ]);
        $url = 'https://api.openstreetmap.org/api/0.6/way/1/full.json';
        // $mock = $this->mock(Http::class, function (MockInterface $mock) use ($url, $return) {
        //     $mock->shouldReceive('get')
        //         ->once()
        //         ->with($url)
        //         ->andReturn($return);
        // });
        Http::fake([
            $url => Http::sequence()->push($return, 200),
        ]);
        $this->expectException(OsmClientExceptionWayHasNoNodes::class);
        OsmClient::getPropertiesAndGeometry($osmid);
        $this->assertTrue(false);
    }

    /** @test */
    public function no_lon_throw_exception()
    {
        $osmid = 'way/1';
        $return = json_encode([
            'version' => '0.6',
            'elements' => [
                [
                    'id' => 2,
                    'type' => 'node',
                    'lat' => 44,
                ],
                [
                    'id' => 3,
                    'type' => 'node',
                    'lat' => 45,
                    'lon' => 11,
                ],
                [
                    'id' => 1,
                    'type' => 'way',
                    'tags' => [
                        'name' => 'name of way',
                    ],
                    'nodes' => [
                        2,
                        3,
                    ],
                ],
            ],

        ]);
        $url = 'https://api.openstreetmap.org/api/0.6/way/1/full.json';
        // $mock = $this->mock(CurlServiceProvider::class, function (MockInterface $mock) use ($url, $return) {
        //     $mock->shouldReceive('exec')
        //         ->once()
        //         ->with($url)
        //         ->andReturn($return);
        // });
        // $osmp = app(OsmServiceProvider::class);

        Http::fake([
            $url => Http::sequence()->push($return, 200),
        ]);

        $this->expectException(OsmClientExceptionNodeHasNoLon::class);
        OsmClient::getPropertiesAndGeometry($osmid);
        $this->assertTrue(false);
    }

    /** @test */
    public function no_lat_throw_exception()
    {
        $osmid = 'way/1';
        $return = json_encode([
            'version' => '0.6',
            'elements' => [
                [
                    'id' => 2,
                    'type' => 'node',
                    'lon' => 10,
                    'timestamp' => '2020-09-13T14:57:20Z',
                ],
                [
                    'id' => 3,
                    'type' => 'node',
                    'lat' => 45,
                    'lon' => 11,
                    'timestamp' => '2021-09-13T14:57:20Z',
                ],
                [
                    'id' => 1,
                    'type' => 'way',
                    'timestamp' => '2018-09-13T14:57:20Z',
                    'tags' => [
                        'name' => 'name of way',
                    ],
                    'nodes' => [
                        2,
                        3,
                    ],
                ],
            ],

        ]);
        $url = 'https://api.openstreetmap.org/api/0.6/way/1/full.json';
        // $mock = $this->mock(CurlServiceProvider::class, function (MockInterface $mock) use ($url, $return) {
        //     $mock->shouldReceive('exec')
        //         ->once()
        //         ->with($url)
        //         ->andReturn($return);
        // });
        // $osmp = app(OsmServiceProvider::class);
        Http::fake([
            $url => Http::sequence()->push($return, 200),
        ]);

        $this->expectException(OsmClientExceptionNodeHasNoLat::class);
        OsmClient::getPropertiesAndGeometry($osmid);
        $this->assertTrue(false);
    }

    // Positive test
    /** @test */
    public function with_proper_json_has_proper_properties_and_geometry()
    {
        $osmid = 'way/1';
        $return = $this->getJsonWay();
        $url = 'https://api.openstreetmap.org/api/0.6/way/1/full.json';
        // $mock = $this->mock(CurlServiceProvider::class, function (MockInterface $mock) use ($url, $return) {
        //     $mock->shouldReceive('exec')
        //         ->once()
        //         ->with($url)
        //         ->andReturn($return);
        // });
        // $osmp = app(OsmServiceProvider::class);

        Http::fake([
            $url => Http::sequence()->push($return, 200),
        ]);

        $result = OsmClient::getPropertiesAndGeometry($osmid);

        $expected = [
            [
                'name' => 'Name of way with id 1',
                '_updated_at' => '2021-09-13 14:57:20',
            ],
            [
                'type' => 'LineString',
                'coordinates' => [
                    [10, 44],
                    [11, 45],
                ],
            ],
        ];

        // TODO: add updated_at test
        $this->assertEquals($expected, $result);
    }
}
