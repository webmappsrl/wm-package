<?php

declare(strict_types=1);

use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\Models\App\AppConfigService;

it('associa i layer di filtro tramite pivot app_filter_layers e li esporta in config', function () {
    $fakeS3 = [
        'driver' => 's3',
        'key' => 'testing',
        'secret' => 'testing',
        'region' => 'eu-west-1',
        'bucket' => 'test',
        'url' => 'http://127.0.0.1:9000',
        'endpoint' => 'http://127.0.0.1:9000',
        'use_path_style_endpoint' => true,
    ];
    config([
        'wm-package.shard_name' => 'webmapp',
        'filesystems.disks.s3' => $fakeS3,
        'filesystems.disks.wmfe' => array_merge($fakeS3, ['bucket' => 'wmfe']),
        'filesystems.disks.wmdumps' => array_merge($fakeS3, ['bucket' => 'wmdumps']),
        'filesystems.disks.s3-osfmedia' => array_merge($fakeS3, ['bucket' => 'osfmedia']),
    ]);

    /** @var App $app */
    $app = App::factory()->create([
        'filter_layer' => true,
        'filter_layer_label' => ['it' => 'Layer', 'en' => 'Layer'],
    ]);

    /** @var Layer $layer1 */
    $layer1 = Layer::factory()->create(['app_id' => $app->id]);
    /** @var Layer $layer2 */
    $layer2 = Layer::factory()->create(['app_id' => $app->id]);

    $app->filterLayers()->sync([$layer1->id, $layer2->id]);

    $service = new AppConfigService($app);
    $config = $service->config();

    $optionIds = collect($config['MAP']['filters']['layers']['options'] ?? [])
        ->pluck('id')
        ->sort()
        ->values()
        ->all();

    expect($app->filterLayers)->toHaveCount(2)
        ->and($config['MAP']['filters']['layers']['name']['it'] ?? null)->toBe('Layer')
        ->and($config['MAP']['filters']['layers']['options'] ?? [])->toHaveCount(2)
        ->and($optionIds)->toBe(collect([$layer1->id, $layer2->id])->sort()->values()->all());
});
