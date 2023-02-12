<?php

namespace Tests\Unit\Providers;

use Wm\WmPackage\Facades\OsmClient;
use Wm\WmPackage\Tests\TestCase;

class OsmClientgetOsmApiUrlByOsmIdTest extends TestCase
{
    public function test_with_node()
    {
        $osmid = 'node/1234';
        $url = 'https://api.openstreetmap.org/api/0.6/'.$osmid.'.json';
        $this->assertEquals($url, OsmClient::getOsmApiUrlByOsmId($osmid));
    }

    public function test_with_way()
    {
        $osmid = 'way/1234';
        $url = 'https://api.openstreetmap.org/api/0.6/'.$osmid.'.json';
        $this->assertEquals($url, OsmClient::getOsmApiUrlByOsmId($osmid));
    }

    public function test_with_relation()
    {
        $osmid = 'relation/1234';
        $url = 'https://api.openstreetmap.org/api/0.6/'.$osmid.'.json';
        $this->assertEquals($url, OsmClient::getOsmApiUrlByOsmId($osmid));
    }

    // TODO: test invalid case it must return a OsmClientExceptionInvalidOsmId exception
}
