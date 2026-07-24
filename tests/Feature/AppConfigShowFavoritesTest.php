<?php

declare(strict_types=1);

use Wm\WmPackage\Models\App;
use Wm\WmPackage\Services\Models\App\AppConfigService;

it('defaults show_favorites to false when not set in properties', function () {
    $app = App::factory()->createQuietly(['properties' => []]);

    $config = (new AppConfigService($app))->config();

    expect($config['OPTIONS']['showFavorites'])->toBeFalse();
});

it('reads show_favorites true from properties', function () {
    $app = App::factory()->createQuietly(['properties' => ['show_favorites' => true]]);

    $config = (new AppConfigService($app))->config();

    expect($config['OPTIONS']['showFavorites'])->toBeTrue();
});
