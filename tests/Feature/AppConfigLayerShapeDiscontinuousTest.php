<?php

declare(strict_types=1);

use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\Models\App\AppConfigService;

it('never exposes shape_discontinuous in MAP.layers of config.json', function () {
    // Il package non conosce "shape_discontinuous": è un consumer (camminiditalia,
    // via env WM_INTERNAL_ATTRIBUTE_KEYS) a dichiararla interna. Qui la
    // dichiariamo esplicitamente perché Testbench non carica .env/.env.testing.
    config(['wm-package.internal_attribute_keys' => ['shape_discontinuous']]);

    /** @var App $app */
    $app = App::factory()->createQuietly();

    Layer::factory()->create([
        'app_id' => $app->id,
        'properties' => [
            'attributes' => [
                'shape' => ['value' => 'linear', 'name' => ['it' => 'Lineare', 'en' => 'Linear']],
                'shape_discontinuous' => true,
            ],
        ],
    ]);

    $config = (new AppConfigService($app))->config();
    $layerItem = collect($config['MAP']['layers'])->first();

    expect($layerItem['attributes'])->toHaveKey('shape')
        ->and($layerItem['attributes'])->not->toHaveKey('shape_discontinuous');
});
