<?php

namespace Tests\Unit\Providers;

use Illuminate\Support\Facades\Http;
use Wm\WmPackage\Exceptions\OsmClientExceptionNoElements;
use Wm\WmPackage\Facades\OsmClient;
use Wm\WmPackage\Tests\TestCase;

class OsmClientgetPropertiesAndGeometryForRelationCheckInputTest extends TestCase
{
    /** @test */
    public function with_no_elements_throw_proper_exception()
    {
        $input = <<<'EOF'
        { "version" : "0.6" }
        EOF;

        $this->checkException($input, OsmClientExceptionNoElements::class);
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
