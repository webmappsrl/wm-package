<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\FeatureCollection;
use Wm\WmPackage\Services\Models\App\AppConfigService;
use Wm\WmPackage\Tests\TestCase;

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

it('feature_collection overlay box does not throw when the theme primary color is a non-6-digit hex value', function () {
    $app = App::factory()->createQuietly([
        'properties' => [
            'theme' => [
                'primary_color' => '#de1',
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

    expect($overlay['fillColor'])->toBe(hexToRgba('#000000'));
    expect($overlay['strokeColor'])->toBe(hexToRgba('#000000'));
});

it('feature_collection overlay box does not throw when its own fill/stroke color is a malformed hex value', function () {
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
        'fill_color' => '#fff',
        'stroke_color' => 'red',
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

it('feature_collection overlay box uses its own valid fill/stroke color instead of the app primary color', function () {
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
        'fill_color' => '#123456',
        'stroke_color' => '#654321',
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

    expect($overlay['fillColor'])->toBe(hexToRgba('#123456'));
    expect($overlay['strokeColor'])->toBe(hexToRgba('#654321'));
});
