<?php

namespace Tests\Unit\Models;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Tests\TestCase;

class AppDeepLinkUrlTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        config(['wm-package.shard_name' => 'test_shard']);
        config(['app.name' => 'test_app']);

        Storage::fake('pois');
        Storage::fake('wmfe');
        Storage::fake('conf');
    }

    /** @test */
    public function default_domain_uses_app_id_and_app_name_when_website_url_is_empty()
    {
        $app = App::factory()->create(['website_url' => null]);

        $this->assertSame($app->id.'.test_app.webmapp.it', $app->getDeepLinkDomain());
    }

    /** @test */
    public function custom_website_url_overrides_default_domain()
    {
        $app = App::factory()->create(['website_url' => 'https://cammini.example.it/']);

        $this->assertSame('cammini.example.it', $app->getDeepLinkDomain());
    }

    /** @test */
    public function deep_link_url_is_built_for_track()
    {
        $app = App::factory()->create(['website_url' => null]);

        $this->assertSame(
            'https://'.$app->id.'.test_app.webmapp.it/map?track=42',
            $app->getDeepLinkUrl('track', 42)
        );
    }

    /** @test */
    public function deep_link_url_is_built_for_poi()
    {
        $app = App::factory()->create(['website_url' => 'https://cammini.example.it']);

        $this->assertSame(
            'https://cammini.example.it/map?poi=7',
            $app->getDeepLinkUrl('poi', 7)
        );
    }

    /** @test */
    public function invalid_type_throws_exception()
    {
        $app = App::factory()->create();

        $this->expectException(\InvalidArgumentException::class);
        $app->getDeepLinkUrl('invalid', 1);
    }
}
