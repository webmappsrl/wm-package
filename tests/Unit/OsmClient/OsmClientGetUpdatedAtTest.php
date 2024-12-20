<?php

namespace Tests\Unit\Providers;

use Exception;
use Wm\WmPackage\Facades\OsmClient;
use Wm\WmPackage\Tests\TestCase;

class OsmClientGetUpdatedAtTest extends TestCase
{
    // Exceptions
    /** @test */
    public function no_elements_throw_exception()
    {
        // $osmp = app(OsmServiceProvider::class);
        $json = [];
        $this->expectException(Exception::class);
        OsmClient::getUpdatedAt($json);
    }

    /** @test */
    public function no_timestamp_throw_exception()
    {
        // $osmp = app(OsmServiceProvider::class);
        $json = [
            'elements' => [
                [
                    'type' => 'node',
                ],
            ],
        ];
        $this->expectException(Exception::class);
        OsmClient::getUpdatedAt($json);

        $json = [
            'elements' => [
                [
                    'type' => 'node',
                ],
                [
                    'type' => 'node',
                    'timestamp' => '2000-01-01T12:30:40Z',
                ],
            ],
        ];
        $this->expectException(Exception::class);
        OsmClient::getUpdatedAt($json);
    }

    // NODE
    /** @test */
    public function with_node_it_returns_timestamp()
    {
        // $osmp = app(OsmServiceProvider::class);
        $json = [
            'elements' => [
                [
                    'type' => 'node',
                    'timestamp' => '2000-01-01T12:30:40Z',
                ],
            ],
        ];
        $this->assertEquals('2000-01-01 12:30:40', OsmClient::getUpdatedAt($json));
    }

    // WAY
    /** @test */
    public function with_way_with_older_node_it_returns_way_timestamp()
    {
        // $osmp = app(OsmServiceProvider::class);
        $json = [
            'elements' => [
                [
                    'type' => 'node',
                    'timestamp' => '2000-01-01T12:30:40Z',
                ],
                [
                    'type' => 'way',
                    'timestamp' => '2001-01-01T12:30:40Z',
                ],
            ],
        ];
        $this->assertEquals('2001-01-01 12:30:40', OsmClient::getUpdatedAt($json));
    }

    /** @test */
    public function with_way_with_older_way_it_returns_node_timestamp()
    {
        // $osmp = app(OsmServiceProvider::class);
        $json = [
            'elements' => [
                [
                    'type' => 'node',
                    'timestamp' => '2001-01-01T12:30:40Z',
                ],
                [
                    'type' => 'way',
                    'timestamp' => '2000-01-01T12:30:40Z',
                ],
            ],
        ];
        $this->assertEquals('2001-01-01 12:30:40', OsmClient::getUpdatedAt($json));
    }

    // RELATION
    /** @test */
    public function with_relation_with_relation_more_recent_it_returns_relation_timestamp()
    {
        // $osmp = app(OsmServiceProvider::class);
        $json = [
            'elements' => [
                [
                    'type' => 'node',
                    'timestamp' => '2000-01-01T12:30:40Z',
                ],
                [
                    'type' => 'way',
                    'timestamp' => '2000-01-01T12:30:40Z',
                ],
                [
                    'type' => 'relation',
                    'timestamp' => '2001-01-01T12:30:40Z',
                ],
            ],
        ];
        $this->assertEquals('2001-01-01 12:30:40', OsmClient::getUpdatedAt($json));
    }

    /** @test */
    public function with_relation_with_way_more_recent_it_returns_way_timestamp()
    {
        // $osmp = app(OsmServiceProvider::class);
        $json = [
            'elements' => [
                [
                    'type' => 'node',
                    'timestamp' => '2000-01-01T12:30:40Z',
                ],
                [
                    'type' => 'way',
                    'timestamp' => '2001-01-01T12:30:40Z',
                ],
                [
                    'type' => 'relation',
                    'timestamp' => '2000-01-01T12:30:40Z',
                ],
            ],
        ];
        $this->assertEquals('2001-01-01 12:30:40', OsmClient::getUpdatedAt($json));
    }

    /** @test */
    public function with_relation_with_node_more_recent_it_returns_node_timestamp()
    {
        // $osmp = app(OsmServiceProvider::class);
        $json = [
            'elements' => [
                [
                    'type' => 'node',
                    'timestamp' => '2001-01-01T12:30:40Z',
                ],
                [
                    'type' => 'way',
                    'timestamp' => '2000-01-01T12:30:40Z',
                ],
                [
                    'type' => 'relation',
                    'timestamp' => '2000-01-01T12:30:40Z',
                ],
            ],
        ];
        $this->assertEquals('2001-01-01 12:30:40', OsmClient::getUpdatedAt($json));
    }
}
