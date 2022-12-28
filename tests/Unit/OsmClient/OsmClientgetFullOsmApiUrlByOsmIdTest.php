<?php

namespace Tests\Unit\Providers;

use Wm\WmPackage\Tests\TestCase;
use Wm\WmPackage\Facades\OsmClient;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Foundation\Testing\RefreshDatabase;

class OsmClientgetFullOsmApiUrlByOsmIdTest extends TestCase
{
    public function test_with_node()
    {
        $osmid = 'node/1234';
        $url = 'https://api.openstreetmap.org/api/0.6/'.$osmid.'.json';
        $this->assertEquals($url,OsmClient::getFullOsmApiUrlByOsmId($osmid));
    }
    public function test_with_way()
    {
        $osmid = 'way/1234';
        $url = 'https://api.openstreetmap.org/api/0.6/'.$osmid.'/full.json';
        $this->assertEquals($url,OsmClient::getFullOsmApiUrlByOsmId($osmid));

    }
    public function test_with_relation()
    {
        $osmid = 'relation/1234';
        $url = 'https://api.openstreetmap.org/api/0.6/'.$osmid.'/full.json';
        $this->assertEquals($url,OsmClient::getFullOsmApiUrlByOsmId($osmid));
    }
}
