<?php

declare(strict_types=1);

use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\LayerMapWebComponentService;

/*
 * Lo snippet `wm-layer-map` e il markup del bottone di copia, condivisi fra la Resource Layer e
 * la Resource EcTrack (oc:8944). Modelli non salvati: il service legge solo attributi e la
 * relazione `appOwner`.
 */

function unsavedLayerWithApp(?bool $enabled, int $appId = 101, int $layerId = 201): Layer
{
    $layer = new Layer;
    $layer->id = $layerId;
    $layer->app_id = $appId;

    if ($enabled === null) {
        $layer->setRelation('appOwner', null);

        return $layer;
    }

    $app = new App;
    $app->id = $appId;
    $app->properties = ['layer_web_component_enabled' => $enabled];
    $layer->setRelation('appOwner', $app);

    return $layer;
}

beforeEach(function () {
    config(['wm-package.shard_name' => 'camminiditalia']);
    $this->service = new LayerMapWebComponentService;
});

it('builds the layer snippet without track-id', function () {
    $layer = unsavedLayerWithApp(true);

    expect($this->service->snippet($layer))
        ->toContain('<wm-layer-map')
        ->toContain('shard="camminiditalia"')
        ->toContain('app-id="101"')
        ->toContain('layer-id="201"')
        // Letterale indipendente dalla costante: se qualcuno la cambiasse per errore il test se ne accorge.
        ->toContain('https://cdn.jsdelivr.net/gh/webmappsrl/wm-elements@dist/wm-layer-map/wm-layer-map.js')
        ->not->toContain('track-id');
});

it('adds track-id right after layer-id when given', function () {
    $layer = unsavedLayerWithApp(true);

    expect($this->service->snippet($layer, 1186))
        ->toContain("layer-id=\"201\"\n  track-id=\"1186\"");
});

it('is enabled only when the app owner has the flag on', function () {
    expect($this->service->isEnabledFor(unsavedLayerWithApp(true)))->toBeTrue()
        ->and($this->service->isEnabledFor(unsavedLayerWithApp(false)))->toBeFalse()
        ->and($this->service->isEnabledFor(unsavedLayerWithApp(null)))->toBeFalse();
});

it('renders no select with a single option', function () {
    $html = $this->service->renderCopyButton([['label' => 'A', 'snippet' => 'x']]);

    expect($html)
        ->not->toContain('<select')
        ->toContain('data-snippet="x"')
        ->toContain('https://html.onlineviewer.net/');
});

it('renders one option per entry, the first one selected', function () {
    $html = $this->service->renderCopyButton([
        ['label' => 'A', 'snippet' => 's1'],
        ['label' => 'B', 'snippet' => 's2'],
    ]);

    expect($html)
        ->toContain('<select')
        ->toMatch('/<option[^>]*data-snippet="s1"[^>]*selected/')
        ->toMatch('/<option[^>]*data-snippet="s2"/')
        ->not->toMatch('/<option[^>]*data-snippet="s2"[^>]*selected/');
});

it('escapes labels and snippets', function () {
    $html = $this->service->renderCopyButton([
        ['label' => "Cammino dell'Alta Via <b>", 'snippet' => '<wm-layer-map layer-id="1">'],
        ['label' => 'B', 'snippet' => 's2'],
    ]);

    expect($html)
        ->toContain('Cammino dell&#039;Alta Via &lt;b&gt;')
        ->toContain('data-snippet="&lt;wm-layer-map layer-id=&quot;1&quot;&gt;"')
        ->not->toContain('<b>');
});
