<?php

namespace Tests\Unit\Providers;


use Wm\WmPackage\Http\OsmClient;
use Wm\WmPackage\Tests\TestCase;
use App\Providers\OsmServiceProvider;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Foundation\Testing\RefreshDatabase;

class OsmClientCheckOsmIdTest extends TestCase
{
    /**
     * A basic feature test example.
     *
     * @return void
     */
    public function test_some_cases()
    {
        $osmp = new OsmClient;
        $this->assertTrue($osmp->checkOsmId('node/1234'));
        $this->assertTrue($osmp->checkOsmId('way/1234'));
        $this->assertTrue($osmp->checkOsmId('relation/1234'));

        $this->assertFalse($osmp->checkOsmId('node/1234a'));
        $this->assertFalse($osmp->checkOsmId('way/1234a'));
        $this->assertFalse($osmp->checkOsmId('relation/1234a'));

        $this->assertFalse($osmp->checkOsmId('xxx/1234'));
    }
}
