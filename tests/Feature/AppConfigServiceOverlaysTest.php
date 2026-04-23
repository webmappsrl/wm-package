<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\FeatureCollection;
use Wm\WmPackage\Services\Models\App\AppConfigService;

uses(TestCase::class, DatabaseTransactions::class);

it('includes enabled feature collections in MAP.controls.overlays ordered by config_overlays', function () {
    $app = App::factory()->createQuietly([
        'primary_color' => '#FF0000',
    ]);

    $fc1 = FeatureCollection::factory()->createQuietly([
        'app_id' => $app->id,
        'enabled' => true,
        'mode' => 'external',
        'external_url' => 'https://example.com/fc1.geojson',
        'label' => ['it' => 'Overlay 1'],
        'clickable' => true,
    ]);

    $fc2 = FeatureCollection::factory()->createQuietly([
        'app_id' => $app->id,
        'enabled' => true,
        'mode' => 'external',
        'external_url' => 'https://example.com/fc2.geojson',
        'label' => ['it' => 'Overlay 2'],
    ]);

    $app->config_overlays = [$fc2->id, $fc1->id]; // fc2 first
    $app->save();

    $service = new AppConfigService($app);
    $config = $service->config();

    $overlays = $config['MAP']['controls']['overlays'] ?? [];
    $buttons = collect($overlays)->where('type', 'button')->values();

    expect($buttons)->toHaveCount(2);
    expect($buttons[0]['url'])->toBe('https://example.com/fc2.geojson');
    expect($buttons[1]['url'])->toBe('https://example.com/fc1.geojson');
});

it('excludes disabled feature collections from overlays', function () {
    $app = App::factory()->createQuietly();

    $fc = FeatureCollection::factory()->createQuietly([
        'app_id' => $app->id,
        'enabled' => false,
        'mode' => 'external',
        'external_url' => 'https://example.com/fc.geojson',
        'label' => ['it' => 'Disabled'],
    ]);

    $app->config_overlays = [$fc->id];
    $app->save();

    $service = new AppConfigService($app);
    $config = $service->config();

    $overlays = collect($config['MAP']['controls']['overlays'] ?? [])->where('type', 'button')->values();
    expect($overlays)->toHaveCount(0);
});
