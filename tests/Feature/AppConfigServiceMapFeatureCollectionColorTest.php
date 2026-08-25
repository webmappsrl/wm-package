<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\FeatureCollection;
use Wm\WmPackage\Services\Models\App\AppConfigService;

uses(TestCase::class, DatabaseTransactions::class);

it('feature_collection overlay box without its own colors falls back to the app real primary color', function () {
    $app = App::factory()->createQuietly([
        'properties' => [
            'theme' => [
                'primary_color' => '#abcdef',
            ],
        ],
    ]);

    $fc = FeatureCollection::factory()->createQuietly([
        'app_id' => $app->id,
        'enabled' => true,
        'fill_color' => null,
        'stroke_color' => null,
    ]);

    $app->forceFill([
        'config_overlays' => [
            'OVERLAYS' => [
                [
                    'box_type' => 'feature_collection',
                    'feature_collection' => $fc->id,
                ],
            ],
        ],
    ])->save();

    $config = (new AppConfigService($app))->config();
    $overlay = $config['MAP']['controls']['overlays'][0];

    expect($overlay['fillColor'])->toBe(hexToRgba('#abcdef'));
    expect($overlay['strokeColor'])->toBe(hexToRgba('#abcdef'));
});
