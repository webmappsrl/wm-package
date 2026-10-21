<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Services\AppOpenService;

// Base TestCase (Wm\WmPackage\Tests\TestCase, with RefreshDatabase) is applied globally by
// tests/Pest.php — no explicit uses() needed here.
// Il toggle dei deep link resta spento: acceso, scriverebbe sul registro SFTP condiviso.

const OPEN_UA_IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';
const OPEN_UA_ANDROID = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Mobile Safari/537.36';
const OPEN_UA_WINDOWS = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';
const OPEN_IOS_LINK = 'https://apps.apple.com/it/app/cammini-di-italia/id1643119047';
const OPEN_ANDROID_LINK = 'https://play.google.com/store/apps/details?id=it.webmapp.cammini';

beforeEach(function () {
    config(['app.name' => 'test_app', 'wm-package.shard_name' => 'test_shard']);
    Storage::fake('pois');
    Storage::fake('wmfe');
    Storage::fake('conf');
});

function openApp(array $attributes = []): App
{
    return App::factory()->create(array_merge([
        'name' => 'Cammini di Italia',
        'website_url' => null,
        'ios_store_link' => OPEN_IOS_LINK,
        'android_store_link' => OPEN_ANDROID_LINK,
    ], $attributes));
}

it('ios_is_redirected_to_app_store', function () {
    $app = openApp();

    $this->get("/app/{$app->id}/open", ['User-Agent' => OPEN_UA_IPHONE])
        ->assertRedirect(OPEN_IOS_LINK);
});

it('android_gets_page_with_intent_and_fallback', function () {
    $app = openApp();
    $intent = "intent://{$app->id}.test_app.webmapp.it/#Intent;scheme=https;package=it.webmapp.cammini;S.browser_fallback_url=".rawurlencode(OPEN_ANDROID_LINK).';end';

    $this->get("/app/{$app->id}/open", ['User-Agent' => OPEN_UA_ANDROID])
        ->assertOk()
        ->assertSee(json_encode($intent), false)
        ->assertSee('}, '.AppOpenService::ANDROID_FALLBACK_DELAY_MS.');', false)
        ->assertSee(e(OPEN_ANDROID_LINK), false);
});

it('android_with_invalid_package_shows_only_play_button', function () {
    $app = openApp([
        'sku' => 'it.app;S.x=y',
        'android_store_link' => 'https://play.google.com/store/apps/details',
    ]);

    $this->get("/app/{$app->id}/open", ['User-Agent' => OPEN_UA_ANDROID])
        ->assertOk()
        ->assertDontSee('intent://', false)
        ->assertSee('https://play.google.com/store/apps/details', false);
});

it('desktop_gets_webapp_page_with_ipad_check', function () {
    $app = openApp();

    $this->get("/app/{$app->id}/open", ['User-Agent' => OPEN_UA_WINDOWS])
        ->assertOk()
        ->assertSee(json_encode("https://{$app->id}.test_app.webmapp.it"), false)
        ->assertSee('href="https://'.$app->id.'.test_app.webmapp.it"', false)
        ->assertSee(json_encode(OPEN_IOS_LINK), false)
        ->assertSee('maxTouchPoints', false);
});

it('desktop_uses_custom_website_url_for_webapp', function () {
    $app = openApp(['website_url' => 'https://cammini.example.it/']);

    $this->get("/app/{$app->id}/open", ['User-Agent' => OPEN_UA_WINDOWS])
        ->assertOk()
        ->assertSee('href="https://cammini.example.it"', false);
});

it('desktop_without_ios_link_skips_ipad_check', function () {
    $app = openApp(['ios_store_link' => null]);

    $this->get("/app/{$app->id}/open", ['User-Agent' => OPEN_UA_WINDOWS])
        ->assertOk()
        ->assertSee('href="https://'.$app->id.'.test_app.webmapp.it"', false)
        ->assertDontSee('maxTouchPoints', false);
});

it('empty_user_agent_gets_webapp_page', function () {
    $app = openApp();

    $this->get("/app/{$app->id}/open", ['User-Agent' => ''])
        ->assertOk()
        ->assertSee('href="https://'.$app->id.'.test_app.webmapp.it"', false);
});

it('iphone_without_ios_link_sees_not_available_message', function () {
    $app = openApp(['ios_store_link' => null]);

    $this->get("/app/{$app->id}/open", ['User-Agent' => OPEN_UA_IPHONE])
        ->assertOk()
        ->assertSee('Not yet available for iOS')
        ->assertSee(e(OPEN_ANDROID_LINK), false)
        ->assertDontSee('maxTouchPoints', false);
});

it('app_without_store_links_shows_unavailable_page', function () {
    $app = openApp(['ios_store_link' => null, 'android_store_link' => null]);

    $this->get("/app/{$app->id}/open", ['User-Agent' => OPEN_UA_IPHONE])
        ->assertOk()
        ->assertSee('The app is not currently available on the stores, please try again later.');
});

it('unknown_app_returns_404', function () {
    $this->get('/app/999999/open', ['User-Agent' => OPEN_UA_IPHONE])
        ->assertNotFound();
});

it('responses_have_no_cache_headers_and_no_session_cookie', function () {
    $app = openApp();

    foreach ([OPEN_UA_IPHONE, OPEN_UA_WINDOWS] as $userAgent) {
        $response = $this->get("/app/{$app->id}/open", ['User-Agent' => $userAgent]);

        expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
        expect($response->headers->get('Vary'))->toContain('User-Agent');
        $response->assertCookieMissing(config('session.cookie'));
    }
});

it('store_link_with_ampersand_is_escaped_in_href', function () {
    $app = openApp([
        'ios_store_link' => 'https://apps.apple.com/app/id1?l=it&mt=8',
        'android_store_link' => null,
    ]);

    // Android senza il suo link: pagina con il solo pulsante App Store.
    $this->get("/app/{$app->id}/open", ['User-Agent' => OPEN_UA_ANDROID])
        ->assertOk()
        ->assertSee('href="https://apps.apple.com/app/id1?l=it&amp;mt=8"', false);
});

it('unsupported_browser_language_falls_back_to_english', function () {
    $app = openApp();

    $this->get("/app/{$app->id}/open", ['User-Agent' => OPEN_UA_ANDROID, 'Accept-Language' => 'ja'])
        ->assertOk()
        ->assertSee('<html lang="en">', false)
        ->assertSee('Open the app');
});

it('supported_browser_language_is_used', function () {
    $app = openApp();

    $this->get("/app/{$app->id}/open", ['User-Agent' => OPEN_UA_ANDROID, 'Accept-Language' => 'it-IT,it;q=0.9'])
        ->assertOk()
        ->assertSee('<html lang="it">', false);
});

it('android_without_android_link_sees_not_available_message', function () {
    $app = openApp(['android_store_link' => null]);

    $this->get("/app/{$app->id}/open", ['User-Agent' => OPEN_UA_ANDROID])
        ->assertOk()
        ->assertSee('Not yet available for Android')
        ->assertSee(e(OPEN_IOS_LINK), false);
});

it('app_id_beyond_integer_range_returns_404', function () {
    $this->get('/app/99999999999999999999/open', ['User-Agent' => OPEN_UA_IPHONE])
        ->assertNotFound();
});
