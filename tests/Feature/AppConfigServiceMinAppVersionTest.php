<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Services\Models\App\AppConfigService;

uses(TestCase::class, DatabaseTransactions::class);

it('config.json does not include minAppVersion when property is not set', function () {
    $app = App::factory()->createQuietly();

    $config = (new AppConfigService($app))->config();

    expect($config['APP'])->not->toHaveKey('minAppVersion');
});

it('config.json does not include minAppVersion when property is an empty string', function () {
    $app = App::factory()->createQuietly([
        'properties' => ['min_app_version' => ''],
    ]);

    $config = (new AppConfigService($app))->config();

    expect($config['APP'])->not->toHaveKey('minAppVersion');
});

it('config.json includes minAppVersion when property is set', function () {
    $app = App::factory()->createQuietly([
        'properties' => ['min_app_version' => '3.1.10'],
    ]);

    $config = (new AppConfigService($app))->config();

    expect($config['APP'])->toHaveKey('minAppVersion');
    expect($config['APP']['minAppVersion'])->toBe('3.1.10');
});
