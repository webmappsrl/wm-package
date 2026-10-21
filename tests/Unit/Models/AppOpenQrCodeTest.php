<?php

namespace Tests\Unit\Models;

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Tests\TestCase;

// Il toggle dei deep link resta spento: acceso, scriverebbe sul registro SFTP condiviso.
class AppOpenQrCodeTest extends TestCase
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

    private function appWithLinks(array $attributes = []): App
    {
        return App::factory()->create(array_merge([
            'sku' => 'it.webmapp.cammini',
            'ios_store_link' => 'https://apps.apple.com/it/app/cammini-di-italia/id1643119047',
            'android_store_link' => 'https://play.google.com/store/apps/details?id=it.webmapp.cammini',
        ], $attributes));
    }

    /** @test */
    public function has_store_links_is_true_with_only_one_link()
    {
        $app = $this->appWithLinks(['ios_store_link' => null]);

        $this->assertTrue($app->hasStoreLinks());
    }

    /** @test */
    public function has_store_links_is_false_without_links()
    {
        $app = $this->appWithLinks(['ios_store_link' => null, 'android_store_link' => '']);

        $this->assertFalse($app->hasStoreLinks());
    }

    /** @test */
    public function open_url_points_to_named_route()
    {
        $app = $this->appWithLinks();

        $this->assertStringEndsWith("/app/{$app->id}/open", $app->getAppOpenUrl());
        $this->assertStringStartsWith('http', $app->getAppOpenUrl());
    }

    /** @test */
    public function webapp_url_uses_deep_link_domain()
    {
        $this->assertSame('https://cammini.example.it', $this->appWithLinks(['website_url' => 'https://cammini.example.it/'])->getWebappUrl());

        $app = $this->appWithLinks(['website_url' => null, 'sku' => 'it.webmapp.altra']);
        $this->assertSame("https://{$app->id}.test_app.webmapp.it", $app->getWebappUrl());
    }

    /** @test */
    public function svg_encodes_open_url()
    {
        $app = $this->appWithLinks();
        $expected = (new QRCode(new QROptions(['outputBase64' => false])))->render($app->getAppOpenUrl());

        $this->assertStringContainsString('<svg', $app->renderAppOpenQrCodeSvg());
        $this->assertSame($expected, $app->renderAppOpenQrCodeSvg());
        $this->assertNotSame(
            (new QRCode(new QROptions(['outputBase64' => false])))->render($app->getWebappUrl()),
            $app->renderAppOpenQrCodeSvg()
        );
    }

    /** @test */
    public function file_names_fall_back_to_app_id_without_sku()
    {
        $app = $this->appWithLinks();
        $app->sku = null;

        $this->assertStringContainsString("app-{$app->id}-qrcode.png", $app->renderAppOpenQrCodeHtml());
    }

    /** @test */
    public function png_is_at_least_the_minimum_size()
    {
        $info = getimagesizefromstring($this->appWithLinks()->renderAppOpenQrCodePng());

        $this->assertNotFalse($info);
        $this->assertSame('image/png', $info['mime']);
        $this->assertGreaterThanOrEqual(App::APP_OPEN_QR_PNG_MIN_SIZE, $info[0]);
        $this->assertGreaterThanOrEqual(App::APP_OPEN_QR_PNG_MIN_SIZE, $info[1]);
    }

    /** @test */
    public function html_is_null_without_store_links()
    {
        $app = $this->appWithLinks(['ios_store_link' => null, 'android_store_link' => null]);

        $this->assertNull($app->renderAppOpenQrCodeHtml());
    }

    /** @test */
    public function html_contains_url_and_both_downloads()
    {
        $app = $this->appWithLinks();
        $html = $app->renderAppOpenQrCodeHtml();

        $this->assertStringContainsString(e($app->getAppOpenUrl()), $html);
        $this->assertStringContainsString('data:image/svg+xml;base64,', $html);
        $this->assertStringContainsString('data:image/png;base64,', $html);
        $this->assertStringContainsString('it.webmapp.cammini-qrcode.svg', $html);
        $this->assertStringContainsString('it.webmapp.cammini-qrcode.png', $html);
    }
}
