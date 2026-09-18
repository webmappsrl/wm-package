<?php

declare(strict_types=1);

use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;

it('never exposes shape_discontinuous from the layer() API endpoint', function () {
    // Il package non conosce "shape_discontinuous": è un consumer (camminiditalia,
    // via env WM_INTERNAL_ATTRIBUTE_KEYS) a dichiararla interna. Qui la
    // dichiariamo esplicitamente perché Testbench non carica .env/.env.testing.
    config(['wm-package.internal_attribute_keys' => ['shape_discontinuous']]);

    /** @var App $app */
    $app = App::factory()->createQuietly();

    /** @var Layer $layer */
    $layer = Layer::factory()->create([
        'app_id' => $app->id,
        'properties' => [
            'attributes' => [
                'shape' => ['value' => 'linear', 'name' => ['it' => 'Lineare', 'en' => 'Linear']],
                'shape_discontinuous' => true,
            ],
        ],
    ]);

    $response = $this->getJson("/api/app/webapp/{$app->id}/layer/{$layer->id}");

    $response->assertOk();
    expect($response->json('properties.attributes'))->toHaveKey('shape')
        ->and($response->json('properties.attributes'))->not->toHaveKey('shape_discontinuous');
});
