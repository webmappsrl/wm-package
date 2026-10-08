<?php

declare(strict_types=1);

use Wm\WmPackage\Models\App;
use Wm\WmPackage\Services\Models\App\AppConfigService;

it('espone i parametri dei dati tecnici quando la registrazione è attiva', function () {
    config()->set('wm-package.ugc_track_max_accuracy_meters', 40.0);
    config()->set('wm-package.ugc_track_max_deviation_meters', 50.0);
    config()->set('wm-package.ugc_track_max_speed_percentile', 95.0);
    config()->set('wm-package.ugc_track_moving_min_speed_kmh', 1.0);
    $app = App::factory()->createQuietly(['geolocation_record_enable' => true]);

    $config = (new AppConfigService($app))->config();

    expect($config['GEOLOCATION']['record']['stats'])->toBe([
        'max_accuracy' => 40.0, 'max_deviation' => 50.0, 'max_speed_percentile' => 95.0, 'moving_min_speed' => 1.0,
    ]);
});

it('non espone i parametri se la registrazione non è attiva', function () {
    // Il default della colonna api è 'elbrus', che crea sempre `record`: serve un api diverso.
    $app = App::factory()->createQuietly(['geolocation_record_enable' => false, 'api' => 'other']);

    $config = (new AppConfigService($app))->config();

    expect($config['GEOLOCATION']['record'] ?? null)->toBeNull();
});
