<?php

namespace Tests\Feature;

use App\Nova\Layer as NovaLayer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer as LayerModel;

class LayerWebComponentCopyButtonTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'wm-package.shard_name' => 'camminiditalia',
            'wm-package.web_components.layer_map.example_url' => 'https://example.test/wm-layer-map.html',
            'wm-package.web_components.layer_map.cache_ttl' => 1800,
            'wm-package.web_components.layer_map.timeout' => 10,
            'wm-package.web_components.layer_map.fallback' => [
                'tag_name' => 'wm-layer-map',
                'script_url' => 'https://cdn.jsdelivr.net/gh/webmappsrl/wm-layer-map@refs/heads/main/src/wm-layer-map.js',
                'default_style' => 'display:block;width:100%;height:600px',
            ],
        ]);
    }

    public function test_copy_button_is_visible_only_when_frontend_flag_is_enabled(): void
    {
        $enabledLayer = $this->makeUnsavedLayerWithAppOwner(true, 101, 201);

        $enabledResource = new NovaLayer($enabledLayer);

        $this->assertTrue(
            $this->invokeProtected($enabledResource, 'shouldShowLayerWebComponentCopyButton', [$enabledLayer])
        );

        $disabledLayer = $this->makeUnsavedLayerWithAppOwner(false, 102, 202);

        $disabledResource = new NovaLayer($disabledLayer);

        $this->assertFalse(
            $this->invokeProtected($disabledResource, 'shouldShowLayerWebComponentCopyButton', [$disabledLayer])
        );
    }

    public function test_copied_snippet_uses_remote_script_url_and_dynamic_layer_values(): void
    {
        Http::fake([
            'https://example.test/wm-layer-map.html' => Http::response(<<<'HTML'
<!DOCTYPE html>
<html lang="it">
  <body>
    <wm-layer-map></wm-layer-map>
    <script
      type="module"
      src="https://cdn.example.test/wm-layer-map.js"
    ></script>
  </body>
</html>
HTML),
        ]);

        Cache::shouldReceive('remember')
            ->once()
            ->andReturnUsing(function ($key, $ttl, $callback) {
                return $callback();
            });

        $layer = $this->makeUnsavedLayerWithAppOwner(true, 103, 203);

        $resource = new NovaLayer($layer);
        $snippet = $this->invokeProtected($resource, 'buildLayerWebComponentSnippet', [$layer]);

        $this->assertStringContainsString('<wm-layer-map', $snippet);
        $this->assertStringContainsString('shard="camminiditalia"', $snippet);
        $this->assertStringContainsString('app-id="'.$layer->app_id.'"', $snippet);
        $this->assertStringContainsString('layer-id="'.$layer->id.'"', $snippet);
        $this->assertStringContainsString('src="https://cdn.example.test/wm-layer-map.js"', $snippet);
    }

    public function test_rendered_button_contains_helper_and_uses_fallback_when_remote_example_fails(): void
    {
        Http::fake([
            'https://example.test/wm-layer-map.html' => Http::response('Not Found', 404),
        ]);

        Cache::shouldReceive('remember')
            ->twice()
            ->andReturnUsing(function ($key, $ttl, $callback) {
                return $callback();
            });

        $layer = $this->makeUnsavedLayerWithAppOwner(true, 104, 204);

        $resource = new NovaLayer($layer);
        $buttonHtml = $this->invokeProtected($resource, 'renderLayerWebComponentCopyButton', [$layer]);
        $snippet = $this->invokeProtected($resource, 'buildLayerWebComponentSnippet', [$layer]);

        $this->assertStringContainsString((string) __('Copy web component code'), $buttonHtml);
        $this->assertStringContainsString((string) __('Test the copied code by pasting it into'), $buttonHtml);
        $this->assertStringContainsString('https://html.onlineviewer.net/', $buttonHtml);
        $this->assertStringContainsString(
            'src="https://cdn.jsdelivr.net/gh/webmappsrl/wm-layer-map@refs/heads/main/src/wm-layer-map.js"',
            $snippet
        );
    }

    private function invokeProtected(object $object, string $method, array $args = [])
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($object, $args);
    }

    private function makeUnsavedLayerWithAppOwner(bool $enabled, int $appId, int $layerId): LayerModel
    {
        $app = new App;
        $app->id = $appId;
        $app->properties = ['layer_web_component_enabled' => $enabled];

        $layer = new LayerModel;
        $layer->id = $layerId;
        $layer->app_id = $appId;
        $layer->setRelation('appOwner', $app);

        return $layer;
    }
}
