<?php

namespace Tests\Unit\Services;

use Wm\WmPackage\Models\App;
use Wm\WmPackage\Services\AppOpenService;
use Wm\WmPackage\Tests\TestCase;

class AppOpenServiceTest extends TestCase
{
    private const UA_IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';

    private const UA_IPAD = 'Mozilla/5.0 (iPad; CPU OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1';

    private const UA_IPOD = 'Mozilla/5.0 (iPod touch; CPU iPhone OS 15_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/15.7 Mobile/15E148 Safari/604.1';

    private const UA_ANDROID = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Mobile Safari/537.36';

    private const UA_WINDOWS = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';

    private AppOpenService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.name' => 'test_app']);

        $this->service = new AppOpenService;
    }

    /** @test */
    public function detects_ios_for_iphone_ipad_and_ipod()
    {
        $this->assertSame('ios', $this->service->detectPlatform(self::UA_IPHONE));
        $this->assertSame('ios', $this->service->detectPlatform(self::UA_IPAD));
        $this->assertSame('ios', $this->service->detectPlatform(self::UA_IPOD));
    }

    /** @test */
    public function detects_android()
    {
        $this->assertSame('android', $this->service->detectPlatform(self::UA_ANDROID));
    }

    /** @test */
    public function falls_back_to_other_for_desktop_empty_and_null()
    {
        $this->assertSame('other', $this->service->detectPlatform(self::UA_WINDOWS));
        $this->assertSame('other', $this->service->detectPlatform(''));
        $this->assertSame('other', $this->service->detectPlatform(null));
    }

    /** @test */
    public function android_package_comes_from_store_link_id()
    {
        $app = App::factory()->make([
            'android_store_link' => 'https://play.google.com/store/apps/details?id=it.webmapp.cammini&hl=it',
            'sku' => 'altro.sku',
        ]);

        $this->assertSame('it.webmapp.cammini', $this->service->androidPackage($app));
    }

    /** @test */
    public function android_package_falls_back_to_sku()
    {
        $app = App::factory()->make([
            'android_store_link' => 'https://play.google.com/store/apps/details',
            'sku' => 'it.webmapp.x',
        ]);

        $this->assertSame('it.webmapp.x', $this->service->androidPackage($app));
    }

    /** @test */
    public function android_package_is_null_when_invalid()
    {
        $app = App::factory()->make([
            'android_store_link' => 'https://play.google.com/store/apps/details',
            'sku' => 'it.app;S.x=y',
        ]);

        $this->assertNull($this->service->androidPackage($app));
    }

    /** @test */
    public function intent_url_targets_deep_link_domain_with_encoded_fallback()
    {
        $app = App::factory()->make([
            'website_url' => null,
            'android_store_link' => 'https://play.google.com/store/apps/details?id=it.webmapp.cammini',
        ]);
        $app->id = 1;

        $this->assertSame(
            'intent://1.test_app.webmapp.it/#Intent;scheme=https;package=it.webmapp.cammini;S.browser_fallback_url=https%3A%2F%2Fplay.google.com%2Fstore%2Fapps%2Fdetails%3Fid%3Dit.webmapp.cammini;end',
            $this->service->androidIntentUrl($app)
        );
    }

    /** @test */
    public function intent_url_is_null_without_android_link()
    {
        $app = App::factory()->make(['android_store_link' => null]);

        $this->assertNull($this->service->androidIntentUrl($app));
    }
}
