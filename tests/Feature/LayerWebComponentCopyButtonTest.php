<?php

namespace Tests\Feature;

use App\Nova\Layer as NovaLayer;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer as LayerModel;

class LayerWebComponentCopyButtonTest extends TestCase
{
    /**
     * Letterale indipendente dalla costante privata Layer::DEFAULT_LAYER_MAP_SCRIPT_URL:
     * un'asserzione contro la costante stessa non si accorgerebbe se qualcuno la
     * cambiasse per errore (oc:8590, review).
     */
    private const EXPECTED_SCRIPT_URL = 'https://cdn.jsdelivr.net/gh/webmappsrl/wm-elements@dist/wm-layer-map/wm-layer-map.js';

    protected function setUp(): void
    {
        parent::setUp();

        config(['wm-package.shard_name' => 'camminiditalia']);
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

    /**
     * Nessun override di web_components.layer_map qui: usa il config reale
     * spedito con il package. Se la costante DEFAULT_LAYER_MAP_SCRIPT_URL
     * tornasse mai al vecchio repo deprecato, questo test (unico a non
     * costruirsi da solo il proprio "atteso") lo intercetterebbe.
     */
    public function test_default_snippet_uses_wm_elements_script_url(): void
    {
        $layer = $this->makeUnsavedLayerWithAppOwner(true, 103, 203);

        $resource = new NovaLayer($layer);
        $snippet = $this->invokeProtected($resource, 'buildLayerWebComponentSnippet', [$layer]);

        $this->assertStringContainsString('<wm-layer-map', $snippet);
        $this->assertStringContainsString('shard="camminiditalia"', $snippet);
        $this->assertStringContainsString('app-id="'.$layer->app_id.'"', $snippet);
        $this->assertStringContainsString('layer-id="'.$layer->id.'"', $snippet);
        $this->assertStringContainsString('src="'.self::EXPECTED_SCRIPT_URL.'"', $snippet);
        $this->assertStringNotContainsString('webmappsrl/wm-layer-map@', $snippet);
    }

    /**
     * Un override esplicito e completo del fallback deve riflettersi nello
     * snippet — verifica la plumbing config -> snippet con un URL diverso da
     * quello di produzione, per non sovrapporsi al test precedente.
     */
    public function test_snippet_reflects_explicit_fallback_config_override(): void
    {
        config(['wm-package.web_components.layer_map' => [
            'fallback' => [
                'tag_name' => 'wm-layer-map',
                'script_url' => 'https://example.test/custom-widget.js',
                'default_style' => 'display:block;width:100%;height:600px',
            ],
        ]]);

        $layer = $this->makeUnsavedLayerWithAppOwner(true, 106, 206);

        $resource = new NovaLayer($layer);
        $snippet = $this->invokeProtected($resource, 'buildLayerWebComponentSnippet', [$layer]);

        $this->assertStringContainsString('src="https://example.test/custom-widget.js"', $snippet);
    }

    /**
     * Un consumer può sovrascrivere solo script_url senza ridichiarare
     * tag_name/default_style: i due default PHP non devono sparire.
     */
    public function test_fallback_partial_override_keeps_other_defaults(): void
    {
        config(['wm-package.web_components.layer_map' => [
            'fallback' => [
                'script_url' => 'https://example.test/custom-widget.js',
            ],
        ]]);

        $layer = $this->makeUnsavedLayerWithAppOwner(true, 107, 207);

        $resource = new NovaLayer($layer);
        $snippet = $this->invokeProtected($resource, 'buildLayerWebComponentSnippet', [$layer]);

        $this->assertStringContainsString('<wm-layer-map', $snippet);
        $this->assertStringContainsString('style="display:block;width:100%;height:600px"', $snippet);
        $this->assertStringContainsString('src="https://example.test/custom-widget.js"', $snippet);
    }

    /**
     * Un consumer con la chiave layer_map del tutto assente (non solo
     * fallback.script_url) deve comunque ricadere sul letterale corretto,
     * non sul vecchio URL deprecato.
     */
    public function test_fallback_uses_default_url_when_config_key_is_missing(): void
    {
        config(['wm-package.web_components.layer_map' => []]);

        $layer = $this->makeUnsavedLayerWithAppOwner(true, 105, 205);

        $resource = new NovaLayer($layer);
        $snippet = $this->invokeProtected($resource, 'buildLayerWebComponentSnippet', [$layer]);

        $this->assertStringContainsString('src="'.self::EXPECTED_SCRIPT_URL.'"', $snippet);
    }

    public function test_rendered_button_contains_helper_link(): void
    {
        $layer = $this->makeUnsavedLayerWithAppOwner(true, 104, 204);

        $resource = new NovaLayer($layer);
        $buttonHtml = $this->invokeProtected($resource, 'renderLayerWebComponentCopyButton', [$layer]);

        $this->assertStringContainsString((string) __('Copy web component code'), $buttonHtml);
        $this->assertStringContainsString((string) __('Test the copied code by pasting it into'), $buttonHtml);
        $this->assertStringContainsString('https://html.onlineviewer.net/', $buttonHtml);
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
